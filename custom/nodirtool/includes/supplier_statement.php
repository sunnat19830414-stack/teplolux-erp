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
    $invoices = $api->getSupplierInvoicesForSupplier($socId);
    $rows = [];
    foreach ($invoices as $inv) {
        if ((int)($inv['statut'] ?? $inv['status'] ?? -1) === 0) continue; // черновик — пропускаем

        $type = (int)($inv['type'] ?? 0);
        $isCreditNote = $type === 2;
        $totalTtc = (float)($inv['total_ttc'] ?? 0);
        $rows[] = [
            'date' => (int)($inv['date'] ?? 0),
            'kind_label' => $isCreditNote ? 'Кредит-нота / предоплата' : 'Счёт',
            'ref' => $inv['ref'] ?? '',
            'ref_supplier' => $inv['ref_supplier'] ?? '',
            'amount' => $totalTtc,
        ];

        $invId = (int)($inv['id'] ?? 0);
        foreach ($api->getSupplierInvoicePayments($invId) as $p) {
            $rows[] = [
                'date' => !empty($p['date']) ? strtotime($p['date']) : (int)($inv['date'] ?? 0),
                'kind_label' => 'Оплата',
                'ref' => $inv['ref'] ?? '',
                'ref_supplier' => '',
                'amount' => -1 * (float)($p['amount'] ?? 0),
            ];
        }
    }

    usort($rows, fn($a, $b) => $a['date'] <=> $b['date']);

    $running = 0.0;
    foreach ($rows as &$r) {
        $running += $r['amount'];
        $r['balance'] = round($running, 2);
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
function create_supplier_prepayment_document(DolibarrApi $api, int $socId, float $usdAmount, string $comment, string $refSupplier = ''): ?int
{
    if ($refSupplier === '') $refSupplier = 'PREPAY-' . date('ymd-His');
    $invId = $api->createSupplierInvoice($socId, $refSupplier, 2);
    if (!$invId) return null;
    $lineRes = $api->addGenericSupplierInvoiceLine((int)$invId, $comment !== '' ? $comment : 'Предоплата поставщику', $usdAmount);
    if ($lineRes === null) return null;
    $val = $api->validateSupplierInvoice((int)$invId);
    if ($val === null) return null;
    return (int)$invId;
}
