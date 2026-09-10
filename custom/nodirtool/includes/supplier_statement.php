<?php
/**
 * Выписка по поставщику (хронология счетов/кредит-нот + оплат, с бегущим сальдо) и предоплата
 * поставщику (топ-5 пункт 4, 02.09.2026). См. CLAUDE.md — та же схема, что уже проверена для
 * TeplouxKassa/advance.php на клиентской стороне: кредит-нота без привязки к счёту, просто
 * validate() (не settopaid) — Dolibarr сам корректно учитывает её в общем остатке (getOutstandingBills).
 *
 * Деньги реальные ($) движутся ПАРАЛЛЕЛЬНО, отдельной проводкой (addBankLine) — не через
 * addSupplierInvoicePayment(): "оплатить" кредит-ноту означало бы, что ПОСТАВЩИК платит НАМ, что
 * семантически неверно для предоплаты (мы платим ЕМУ).
 */

/**
 * Хронология: каждый счёт/кредит-нота — своя строка (total_ttc уже содержит нужный знак — у
 * Dolibarr кредит-нота имеет ОТРИЦАТЕЛЬНЫЙ total_ttc, проверено эмпирически), плюс по каждой строке
 * — все оплаты по ней (уменьшают долг, всегда со знаком минус). Сортировка по дате, бегущий остаток.
 * ЧЕРНОВИКИ (statut=0) не включаются — они не входят и в общий остаток Dolibarr (getOutstandingBills).
 */
function build_supplier_statement(DolibarrApi $api, int $socId): array
{
    require_once __DIR__ . '/debt.php';

    $invoices = $api->getSupplierInvoicesForSupplier($socId);
    $rows = [];
    // Оплаты по всем счетам сразу и в валюте счёта (05.09.2026). REST-эндпоинт платежей отдаёт их
    // в долларах, поэтому валютное сальдо по нему не построить — берём из связующей таблицы.
    $paidNative = supplier_paid_native(array_map(fn($i) => (int)($i['id'] ?? 0), is_array($invoices) ? $invoices : []));

    foreach ($invoices as $inv) {
        if ((int)($inv['statut'] ?? $inv['status'] ?? -1) === 0) continue; // черновик — пропускаем

        $type = (int)($inv['type'] ?? 0);
        $isCreditNote = $type === 2;
        $cur = strtoupper(trim((string)($inv['multicurrency_code'] ?? ''))) ?: 'USD';
        // Сумма в валюте документа — то, что реально надо заплатить. Знак у кредит-ноты уже
        // отрицательный в самом Dolibarr (проверено эмпирически) — не инвертируем.
        $total = $cur === 'USD'
            ? (float)($inv['total_ttc'] ?? 0)
            : (float)($inv['multicurrency_total_ttc'] ?? $inv['total_ttc'] ?? 0);

        $rows[] = [
            'date' => (int)($inv['date'] ?? 0),
            'kind_label' => $isCreditNote ? 'Кредит-нота / предоплата' : 'Счёт',
            'ref' => $inv['ref'] ?? '',
            'ref_supplier' => $inv['ref_supplier'] ?? '',
            'currency' => $cur,
            'amount' => $total,
        ];

        $invId = (int)($inv['id'] ?? 0);
        $paid = $paidNative[$invId] ?? 0.0;
        if (abs($paid) > 0.005) {
            $rows[] = [
                'date' => (int)($inv['date'] ?? 0),
                'kind_label' => 'Оплата',
                'ref' => $inv['ref'] ?? '',
                'ref_supplier' => '',
                'currency' => $cur,
                'amount' => -1 * $paid,
            ];
        }
    }

    usort($rows, fn($a, $b) => $a['date'] <=> $b['date']);

    // Бегущее сальдо считается ОТДЕЛЬНО по каждой валюте — складывать евро с долларами нельзя.
    $running = [];
    foreach ($rows as &$r) {
        $c = $r['currency'];
        $running[$c] = round(($running[$c] ?? 0) + $r['amount'], 2);
        $r['balance'] = $running[$c];
    }
    unset($r);

    return $rows;
}

/**
 * Предоплата поставщику — кредит-нота (type=2) без привязки к счёту, ОДНА обобщённая строка (без
 * товара), провалидированная. $usdAmount — уже пересчитанная в доллары сумма (см. вызывающий код —
 * та же логика "сум + курс", что и везде в проекте). Возвращает id созданной кредит-ноты или null.
 *
 * $refSupplier — необязательная пометка, что это за документ (например номер заказа при недопоставке).
 *
 * ⚠️ РЕАЛЬНЫЙ БАГ, найден 03.09.2026 при тестировании фиксации долга поставщика: раньше здесь
 * передавалась ПУСТАЯ строка ref_supplier — Dolibarr на это отвечает `ErrorRefAlreadyExists` (HTTP 500),
 * как только у ЭТОГО ЖЕ поставщика уже есть хоть один документ с пустым ref_supplier (уникальность по
 * паре поставщик+ref_supplier, пустая строка не считается "отсутствием значения"). То есть ПЕРВАЯ
 * предоплата поставщику проходила, а ВТОРАЯ и все последующие — молча падали с непонятной ошибкой.
 * Тест 02.09.2026 этого не поймал, потому что тогда был ровно один такой документ. Теперь ref_supplier
 * всегда непустой и уникальный (метка + время).
 */
function create_supplier_prepayment_document(DolibarrApi $api, int $socId, float $amount, string $comment, string $refSupplier = '', string $currency = 'USD', float $rate = 0.0): ?int
{
    if ($refSupplier === '') $refSupplier = 'PREPAY-' . date('ymd-His');
    // Валюта документа = валюта, в которой мы должны/нам должны (05.09.2026). Раньше метод всегда
    // создавал долларовый документ — для евровой предоплаты или рекламации это было бы неверно.
    // Курс: 0 (по умолчанию) означает «возьми сегодняшний из справочника Dolibarr». Он влияет
    // ТОЛЬКО на долларовый эквивалент документа; сам долг остаётся в своей валюте.
    $currency = strtoupper(trim($currency)) ?: 'USD';
    if ($currency === 'USD') {
        $rate = 1.0;
    } elseif ($rate <= 0) {
        require_once __DIR__ . '/currency.php';
        $rate = dolibarr_currency_rate($currency) ?: 1.0;
    }
    $invId = $api->createSupplierInvoice($socId, $refSupplier, 2, $currency, $rate);
    if (!$invId) return null;
    $lineRes = $api->addGenericSupplierInvoiceLine((int)$invId, $comment !== '' ? $comment : 'Предоплата поставщику', $amount, $currency);
    if ($lineRes === null) return null;
    $val = $api->validateSupplierInvoice((int)$invId);
    if ($val === null) return null;
    return (int)$invId;
}

/**
 * Уже зафиксирована ли недопоставка по этому заказу (C1 финансового аудита 05.09.2026).
 *
 * ⚠️ Найдено живым тестом аудита: кнопка «Зафиксировать как долг поставщика» не проверяла ничего —
 * два обычных клика подряд создавали ДВЕ кредит-ноты и удваивали долг поставщика (сальдо 0 → −40 →
 * −80 на реальном тесте). Гонки для этого не требовалось.
 *
 * Ищем по метке `ref_supplier`, которую сама же кнопка и ставит: «НЕДОПОСТАВКА-<номер заказа>-…».
 * Черновики не считаются — они не входят в сальдо, и повторная фиксация после отката в черновик
 * законна.
 *
 * Возвращает найденный документ или null.
 */
function find_shortfall_document(DolibarrApi $api, int $socId, string $orderRef): ?array
{
    if (trim($orderRef) === '') return null;
    $prefix = 'НЕДОПОСТАВКА-' . $orderRef . '-';
    $invoices = $api->getSupplierInvoicesForSupplier($socId);
    if (!is_array($invoices)) return null;
    foreach ($invoices as $inv) {
        if ((int)($inv['statut'] ?? $inv['status'] ?? -1) === 0) continue;   // черновик — не в сальдо
        $ref = (string)($inv['ref_supplier'] ?? '');
        if ($ref !== '' && str_starts_with($ref, $prefix)) return $inv;
    }
    return null;
}
