<?php
/**
 * Сбор истории клиента (покупки/возвраты/авансы по документам + оплаты), с необязательными
 * фильтрами (дата, тип документа, товар, категория, склад) — общая логика для экрана "Отчёты",
 * его печатной версии и Excel-выгрузки, чтобы не считать одно и то же трижды в разных местах.
 */
require_once __DIR__ . '/dolibarr_direct.php';

/** Код способа оплаты (c_paiement.code) -> человекочитаемая подпись. */
function payment_code_label(string $code): string
{
    $map = ['LIQ' => 'Наличные', 'CB' => 'Карта', 'VIR' => 'Перевод/QR', 'CHQ' => 'Чек'];
    return $map[$code] ?? $code;
}

/** Значения фильтров по умолчанию ("без фильтра" по каждому полю) — используется и здесь, и в reports.php. */
function default_report_filters(): array
{
    return [
        'date_from' => '', 'date_to' => '',
        'types' => ['sale', 'return', 'advance'],
        'product_id' => 0, 'product_label' => '',
        'category_id' => 0, 'warehouse_id' => 0,
    ];
}

/**
 * Разбирает $_POST (сохранение из формы фильтров) ИЛИ $_GET (переход по ссылке на Excel/печать) в
 * единый формат фильтров — одна и та же форма данных с обеих сторон, поэтому один парсер на оба случая.
 */
function report_filters_from_request(array $src): array
{
    $types = $src['types'] ?? null;
    if (!is_array($types)) $types = $types !== null ? [$types] : [];
    $types = array_values(array_intersect($types, ['sale', 'return', 'advance']));
    if (empty($types)) $types = ['sale', 'return', 'advance']; // ничего не отмечено — считаем "без фильтра"

    $dateOk = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);

    return [
        'date_from' => $dateOk($src['date_from'] ?? '') ? $src['date_from'] : '',
        'date_to' => $dateOk($src['date_to'] ?? '') ? $src['date_to'] : '',
        'types' => $types,
        'product_id' => (int)($src['product_id'] ?? 0),
        'product_label' => (string)($src['product_label'] ?? ''),
        'category_id' => (int)($src['category_id'] ?? 0),
        'warehouse_id' => (int)($src['warehouse_id'] ?? 0),
    ];
}

/** Строка query-параметров для ссылок на report_excel.php / report_print.php с текущими фильтрами. */
function report_filters_to_query(array $filters, int $clientId): string
{
    $parts = ['client_id' => $clientId];
    if ($filters['date_from'] !== '') $parts['date_from'] = $filters['date_from'];
    if ($filters['date_to'] !== '') $parts['date_to'] = $filters['date_to'];
    if ($filters['product_id'] > 0) $parts['product_id'] = $filters['product_id'];
    if ($filters['category_id'] > 0) $parts['category_id'] = $filters['category_id'];
    if ($filters['warehouse_id'] > 0) $parts['warehouse_id'] = $filters['warehouse_id'];
    $query = http_build_query($parts);
    foreach ($filters['types'] as $t) {
        $query .= '&types[]=' . urlencode($t);
    }
    return $query;
}

/**
 * @param array $filters см. default_report_filters() — любое поле можно не передавать, дополнится
 *   значением по умолчанию.
 * @return array{documents: array, payments: array, summary: array, by_product: array}
 *   documents: [['date_raw','date','doc_type' ('sale'|'return'|'advance'),'type_label','doc_ref',
 *                'total','lines' => [['product','article','qty','total','fk_product'], ...]], ...] —
 *              сгруппировано по документу, самые новые документы первыми.
 *   payments: [['date_raw','date','doc_ref','amount','method'], ...] — только по счетам-продажам.
 *   summary: ['purchased'=>float,'returned'=>float,'paid'=>float,'debt'=>?float] — purchased/returned
 *            посчитаны ТОЛЬКО по отфильтрованным строкам; debt — текущий долг клиента "на сегодня",
 *            не зависит от фильтра по дате (это разные вещи, показывать рядом отдельно).
 *   by_product: агрегация по товару (сколько куплено/возвращено) — только строки с реальным товаром,
 *            авансы в неё не попадают (там нет товара).
 */
function buildClientHistory(DolibarrApi $api, int $socid, array $filters = []): array
{
    $filters = array_merge(default_report_filters(), $filters);
    $dateFromTs = $filters['date_from'] !== '' ? strtotime($filters['date_from'] . ' 00:00:00') : null;
    $dateToTs = $filters['date_to'] !== '' ? strtotime($filters['date_to'] . ' 23:59:59') : null;
    $lineFilterActive = $filters['product_id'] > 0 || $filters['category_id'] > 0 || $filters['warehouse_id'] > 0;
    $typeLabels = ['sale' => 'Продажа', 'return' => 'Возврат', 'advance' => 'Аванс'];

    $documents = [];
    $payments = [];
    $byProduct = [];
    $summary = ['purchased' => 0.0, 'returned' => 0.0, 'paid' => 0.0, 'debt' => null];

    // getInvoicesForClient() уже приезжает СО СТРОКАМИ каждого счёта (см. докблок метода) — раньше
    // здесь был ещё один getInvoice() на каждый счёт, полностью лишний (см. отчёт ревью P0#5).
    $invoiceSummaries = $api->getInvoicesForClient($socid);
    if (is_array($invoiceSummaries)) {
        foreach ($invoiceSummaries as $invSummary) {
            $invoiceId = (int)$invSummary['id'];
            $inv = $invSummary;

            $dateRaw = (int)($inv['date'] ?? 0);
            if ($dateFromTs !== null && $dateRaw < $dateFromTs) continue;
            if ($dateToTs !== null && $dateRaw > $dateToTs) continue;

            $isCredit = ((int)($inv['type'] ?? 0)) === 2;
            $rawLines = $inv['lines'] ?? [];
            // Аванс технически тоже кредит-нота (см. advance.php), но с ОДНОЙ обобщённой строкой без
            // товара — отличаем его от настоящего возврата товара именно по этому признаку.
            // mb_stripos, НЕ stripos — обычный stripos сравнивает регистр побайтово и не распознаёт
            // кириллицу в UTF-8 (проверено эмпирически: stripos('Аванс', 'аванс') === false).
            $isAdvance = $isCredit && count($rawLines) === 1
                && empty($rawLines[0]['fk_product'])
                && mb_stripos((string)($rawLines[0]['label'] ?? $rawLines[0]['desc'] ?? ''), 'аванс') !== false;
            $docType = $isAdvance ? 'advance' : ($isCredit ? 'return' : 'sale');

            if (!in_array($docType, $filters['types'], true)) continue;

            // Склад, с которого физически списан/на который зачислен каждый товар — не хранится в
            // самом счёте (см. dolibarr_direct.php), запрашиваем только если фильтр по складу активен.
            $lineWarehouses = $filters['warehouse_id'] > 0 ? get_invoice_line_warehouses($invoiceId) : [];

            $lines = [];
            $docTotal = 0.0;
            foreach ($rawLines as $line) {
                $fkProduct = (int)($line['fk_product'] ?? 0);

                if ($lineFilterActive) {
                    if ($filters['product_id'] > 0 && $fkProduct !== $filters['product_id']) continue;
                    if ($filters['category_id'] > 0 && !in_array($filters['category_id'], get_product_category_ids($fkProduct), true)) continue;
                    if ($filters['warehouse_id'] > 0 && !in_array($filters['warehouse_id'], $lineWarehouses[$fkProduct] ?? [], true)) continue;
                }

                $total = (float)($line['total_ttc'] ?? 0);
                $qty = (float)($line['qty'] ?? 0);
                $article = $line['product_ref'] ?? '';
                $product = $line['product_label'] ?? $line['desc'] ?? '';

                $lines[] = ['product' => $product, 'article' => $article, 'qty' => $qty, 'total' => $total, 'fk_product' => $fkProduct];
                $docTotal += $total;

                if ($docType === 'sale') {
                    $summary['purchased'] += $total;
                } elseif ($docType === 'return') {
                    $summary['returned'] += abs($total);
                }

                if ($docType !== 'advance' && $fkProduct > 0) {
                    $key = $article !== '' ? $article : ('lbl:' . $product);
                    if (!isset($byProduct[$key])) {
                        $byProduct[$key] = ['product' => $product, 'article' => $article, 'qty_sale' => 0.0, 'total_sale' => 0.0, 'qty_return' => 0.0, 'total_return' => 0.0];
                    }
                    if ($docType === 'sale') {
                        $byProduct[$key]['qty_sale'] += $qty;
                        $byProduct[$key]['total_sale'] += $total;
                    } else {
                        $byProduct[$key]['qty_return'] += abs($qty);
                        $byProduct[$key]['total_return'] += abs($total);
                    }
                }
            }

            // Если активен фильтр по товару/категории/складу и в документе не осталось ни одной
            // подходящей строки — сам документ в отчёте не нужен (нечего показывать).
            if ($lineFilterActive && empty($lines)) continue;

            $documents[] = [
                'date_raw' => $dateRaw,
                'date' => $dateRaw ? date('d.m.Y', $dateRaw) : '',
                'doc_type' => $docType,
                'type_label' => $typeLabels[$docType],
                'doc_ref' => $inv['ref'] ?? '',
                // При активном фильтре по товару/категории/складу сумма документа — это сумма ТОЛЬКО
                // отфильтрованных строк (а не полная сумма счёта), иначе цифры не совпадали бы визуально.
                'total' => $lineFilterActive ? $docTotal : (float)($inv['total_ttc'] ?? 0),
                'lines' => $lines,
            ];

            // Оплаты бывают только у настоящих продаж (возвраты/авансы в этом приложении не "оплачиваются"
            // через addPayment — см. advance.php/return.php) — если тип "Продажа" не выбран в фильтре,
            // мы сюда для него и не попадём (см. проверку типа документа выше).
            if ($docType === 'sale') {
                $pays = $api->getInvoicePayments($invoiceId);
                if (is_array($pays)) {
                    foreach ($pays as $p) {
                        $payDate = !empty($p['date']) ? strtotime($p['date']) : $dateRaw;
                        if ($dateFromTs !== null && $payDate < $dateFromTs) continue;
                        if ($dateToTs !== null && $payDate > $dateToTs) continue;
                        $amount = (float)($p['amount'] ?? 0);
                        $payments[] = [
                            'date_raw' => $payDate,
                            'date' => $payDate ? date('d.m.Y', $payDate) : '',
                            'doc_ref' => $inv['ref'] ?? '',
                            'amount' => $amount,
                            'method' => payment_code_label((string)($p['type'] ?? '')),
                        ];
                        $summary['paid'] += $amount;
                    }
                }
            }
        }
    }

    usort($documents, fn($a, $b) => $b['date_raw'] <=> $a['date_raw']);
    usort($payments, fn($a, $b) => $b['date_raw'] <=> $a['date_raw']);
    $byProductList = array_values($byProduct);
    usort($byProductList, fn($a, $b) => ($b['total_sale'] + $b['total_return']) <=> ($a['total_sale'] + $a['total_return']));

    $out = $api->getOutstandingInvoices($socid);
    if (is_array($out)) {
        $summary['debt'] = (float)($out['opened'] ?? 0);
    }

    return ['documents' => $documents, 'payments' => $payments, 'summary' => $summary, 'by_product' => $byProductList];
}
