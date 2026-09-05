<?php
/**
 * Расчёты для отчётов руководства. Вся арифметика здесь, страницы только показывают — чтобы экран и
 * выгрузка в Excel считали одно и то же (тот же принцип, что у отчётов в кассе и закупках).
 *
 * Направления: Суннатилла видит только Турк. Ограничение приходит списком префиксов ('J'/'T') —
 * там, где источник данных его поддерживает, фильтруем по нему; где нет (общие счета компании,
 * зарплата) — это честно помечено в самом отчёте, а не молча смешано.
 */
require_once __DIR__ . '/stock_lookup.php';

function reports_db(): mysqli
{
    return stock_lookup_db();
}

/** Начало и конец месяца/периода из GET, с разумными значениями по умолчанию. */
function report_period(): array
{
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-t');
    if ($to < $from) [$from, $to] = [$to, $from];
    return [$from, $to];
}

// ---------------------------------------------------------------- продажи и долги клиентов

/**
 * Продажи за период по направлениям. Кредит-ноты (возвраты, авансы, выдачи денег) у Dolibarr имеют
 * ОТРИЦАТЕЛЬНЫЙ total_ttc — знак используем как есть, ничего не инвертируя (проверено ранее в
 * TeplouxKassa). Возвраты считаем отдельной строкой, чтобы «продали» не выглядело заниженным.
 */
function report_sales(DolibarrApi $api, array $directions, string $from, string $to): array
{
    $rows = $api->getClientInvoicesBetween($directions, $from, $to);
    $sold = 0.0; $returned = 0.0; $onCredit = 0.0;
    $invoiceCount = 0; $returnCount = 0;
    $byClient = [];

    foreach ($rows as $inv) {
        $type = (int)($inv['type'] ?? 0);
        $total = (float)($inv['total_ttc'] ?? 0);
        $socId = (int)($inv['socid'] ?? 0);
        if ($type === 2) {
            $returned += abs($total);
            $returnCount++;
        } else {
            $sold += $total;
            $invoiceCount++;
            $onCredit += (float)($inv['remaintopay'] ?? 0);
        }
        $byClient[$socId] = ($byClient[$socId] ?? 0) + ($type === 2 ? -abs($total) : $total);
    }
    arsort($byClient);

    return [
        'sold' => round($sold, 2),
        'returned' => round($returned, 2),
        'net' => round($sold - $returned, 2),
        'on_credit' => round($onCredit, 2),
        'invoice_count' => $invoiceCount,
        'return_count' => $returnCount,
        'by_client' => $byClient,
    ];
}

/** Кто сколько должен сейчас (не за период — это текущее состояние). */
function report_client_debts(DolibarrApi $api, array $directions): array
{
    $rows = $api->getUnpaidClientInvoices($directions);
    $bySoc = [];
    foreach ($rows as $inv) {
        $remaining = (float)($inv['remaintopay'] ?? 0);
        if (abs($remaining) < 0.01) continue;
        $socId = (int)($inv['socid'] ?? 0);
        $bySoc[$socId] = ($bySoc[$socId] ?? 0) + $remaining;
    }
    // Кредит-ноты дают минус и гасят долг того же клиента — оставляем только реальных должников.
    $bySoc = array_filter($bySoc, fn($v) => $v > 0.01);
    arsort($bySoc);
    return $bySoc;
}

// ---------------------------------------------------------------- деньги

/**
 * Движение денег по всем счетам компании за период: пришло, ушло, разница, и то же по каждому счёту.
 * Источник — банковские проводки: это единственное место, где видно и продажи, и расходы, и зарплату
 * одновременно. Переводы между своими счетами считаются отдельно и в «пришло/ушло» НЕ попадают —
 * иначе передача кассы выглядела бы как доход и расход одновременно.
 */
function report_money(DolibarrApi $api, array $cfg, string $from, string $to, array $directions): array
{
    // ⚠️ Валюта (04.09.2026): счета разновалютные — сумовый держит сумы, EUR-MAIN евро. Складывать их
    // в одно число нельзя, получится бессмыслица. Считаем итоги ОТДЕЛЬНО ПО КАЖДОЙ ВАЛЮТЕ.
    $accounts = $cfg['all_cash_accounts'];
    $byCurrency = [];      // 'USD' => ['in'=>..,'out'=>..,'moved'=>..,'balance'=>..]
    $byAccount = [];

    foreach ($accounts as $accId => $meta) {
        // Ограниченный пользователь (Суннатилла) видит счета своего направления и общие компании.
        if (count($directions) === 1 && $meta['direction'] !== null && $meta['direction'] !== $directions[0]) {
            continue;
        }
        $lines = $api->getBankLinesBetween((int)$accId, $from, $to);
        $accIn = 0.0; $accOut = 0.0; $accMoved = 0.0;
        foreach ($lines as $l) {
            $amt = (float)($l['amount'] ?? 0);
            $label = (string)($l['label'] ?? '');
            $isTransfer = (mb_stripos($label, 'Передача') !== false) || (mb_stripos($label, 'Получено от') !== false)
                || (mb_stripos($label, 'Конвертация') !== false);
            if ($isTransfer) { $accMoved += abs($amt); continue; }
            if ($amt >= 0) $accIn += $amt; else $accOut += abs($amt);
        }
        $balance = $api->getAccountBalance((int)$accId);
        $cur = account_currency((int)$accId);
        $byAccount[$accId] = [
            'label' => $meta['label'], 'currency' => $cur,
            'in' => round($accIn, 2), 'out' => round($accOut, 2),
            'moved' => round($accMoved, 2), 'balance' => $balance === null ? null : round((float)$balance, 2),
        ];

        if (!isset($byCurrency[$cur])) $byCurrency[$cur] = ['in' => 0.0, 'out' => 0.0, 'moved' => 0.0, 'balance' => 0.0];
        $byCurrency[$cur]['in'] += $accIn;
        $byCurrency[$cur]['out'] += $accOut;
        $byCurrency[$cur]['moved'] += $accMoved;
        $byCurrency[$cur]['balance'] += (float)($balance ?? 0);
    }

    foreach ($byCurrency as $cur => $v) {
        $byCurrency[$cur] = [
            'in' => round($v['in'], 2), 'out' => round($v['out'], 2),
            'diff' => round($v['in'] - $v['out'], 2),
            'moved' => round($v['moved'], 2), 'balance' => round($v['balance'], 2),
        ];
    }
    // Доллары первыми — это основная валюта компании.
    uksort($byCurrency, fn($a, $b) => ($a === 'USD' ? -1 : ($b === 'USD' ? 1 : strcmp($a, $b))));

    return ['by_currency' => $byCurrency, 'by_account' => $byAccount];
}

// ---------------------------------------------------------------- закупки

function report_purchases(DolibarrApi $api, string $from, string $to): array
{
    $fromTs = strtotime($from . ' 00:00:00');
    $toTs = strtotime($to . ' 23:59:59');

    $ordered = 0.0; $orderCount = 0; $bySupplier = [];
    $inTransit = 0.0; $inTransitCount = 0; $transitRows = [];

    foreach (['validated', 'approved', 'running', 'received_start', 'received_end'] as $st) {
        foreach ($api->getSupplierOrdersByStatus($st) as $o) {
            $date = (int)($o['date_commande'] ?? 0);
            $total = (float)($o['total_ttc'] ?? 0);
            $socId = (int)($o['socid'] ?? 0);
            if ($date >= $fromTs && $date <= $toTs) {
                $ordered += $total;
                $orderCount++;
                $bySupplier[$socId] = ($bySupplier[$socId] ?? 0) + $total;
            }
            if (in_array($st, ['running', 'received_start'], true)) {
                $inTransit += $total;
                $inTransitCount++;
                $transitRows[] = [
                    'id' => (int)$o['id'], 'ref' => (string)($o['ref'] ?? ''), 'socid' => $socId,
                    'total' => $total, 'delivery' => (int)($o['delivery_date'] ?? 0),
                    'currency' => strtoupper((string)($o['multicurrency_code'] ?? '')) ?: 'USD',
                    'total_native' => (float)($o['multicurrency_total_ttc'] ?? 0),
                ];
            }
        }
    }
    arsort($bySupplier);

    return [
        'ordered' => round($ordered, 2), 'order_count' => $orderCount, 'by_supplier' => $bySupplier,
        'in_transit' => round($inTransit, 2), 'in_transit_count' => $inTransitCount, 'transit_rows' => $transitRows,
    ];
}

/** Долги поставщикам: по неоплаченным счетам, сгруппированные по контрагенту. */
function report_supplier_debts(DolibarrApi $api): array
{
    $bySoc = [];
    foreach ($api->getSupplierInvoices('unpaid') as $inv) {
        if ((int)($inv['paye'] ?? 0) === 1) continue;
        if ((int)($inv['statut'] ?? 0) === 0) continue;      // черновики не долг
        $socId = (int)($inv['socid'] ?? 0);
        $bySoc[$socId] = ($bySoc[$socId] ?? 0) + (float)($inv['total_ttc'] ?? 0);
    }
    arsort($bySoc);
    return $bySoc;
}

// ---------------------------------------------------------------- зарплата, хозрасходы, доходы

/** Суммы из собственных таблиц закупщиков (зарплата/расходы/доходы) за период. */
function report_costs(string $from, string $to): array
{
    $db = reports_db();
    $out = ['salary' => 0.0, 'salary_tax' => 0.0, 'advances' => 0.0,
            'household' => 0.0, 'household_by_category' => [],
            'income' => 0.0, 'income_by_source' => []];

    $has = fn(string $t) => (bool)$db->query("SHOW TABLES LIKE '" . $db->real_escape_string($t) . "'")->num_rows;

    if ($has('llx_nt_payroll_entry')) {
        // В таблице зарплаты нет отдельной колонки с датой операции — есть `period` (за какой месяц
        // начислено) и `datec` (когда реально записали). Для отчёта «сколько денег ушло за период»
        // правильна именно `datec`: выплата за сентябрь, сделанная в октябре, это октябрьские деньги.
        $stmt = $db->prepare("SELECT entry_type, SUM(ABS(amount_usd)) s, SUM(COALESCE(tax_usd,0)) t
                              FROM llx_nt_payroll_entry
                              WHERE DATE(datec) BETWEEN ? AND ? GROUP BY entry_type");
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            if ($r['entry_type'] === 'payout') { $out['salary'] += (float)$r['s']; $out['salary_tax'] += (float)$r['t']; }
            elseif ($r['entry_type'] === 'advance') { $out['advances'] += (float)$r['s']; }
        }
        $stmt->close();
    }

    if ($has('llx_nt_household_expense')) {
        $stmt = $db->prepare("SELECT c.name, SUM(e.amount_usd) s FROM llx_nt_household_expense e
                              LEFT JOIN llx_nt_expense_category c ON c.rowid = e.fk_category
                              WHERE e.expense_date BETWEEN ? AND ? GROUP BY e.fk_category ORDER BY s DESC");
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out['household'] += (float)$r['s'];
            $out['household_by_category'][] = ['name' => $r['name'] ?: 'без вида', 'sum' => round((float)$r['s'], 2)];
        }
        $stmt->close();
    }

    if ($has('llx_nt_income')) {
        $stmt = $db->prepare("SELECT s.name, SUM(i.amount_usd) s FROM llx_nt_income i
                              LEFT JOIN llx_nt_income_source s ON s.rowid = i.fk_source
                              WHERE i.income_date BETWEEN ? AND ? GROUP BY i.fk_source ORDER BY s DESC");
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $out['income'] += (float)$r['s'];
            $out['income_by_source'][] = ['name' => $r['name'] ?: 'без источника', 'sum' => round((float)$r['s'], 2)];
        }
        $stmt->close();
    }

    foreach (['salary', 'salary_tax', 'advances', 'household', 'income'] as $k) $out[$k] = round($out[$k], 2);
    return $out;
}

// ---------------------------------------------------------------- что пора закупать

/**
 * Отчёт «когда и сколько чего надо покупать» — собственная формулировка пользователя.
 *
 * Считаем по фактическим продажам за выбранный период: средний расход в день → на сколько дней
 * хватит текущего остатка (с учётом того, что уже едет) → рекомендуемое количество, чтобы хватило на
 * заданный горизонт. Это оценка по прошлому спросу, а не предсказание — так и подписано на экране.
 *
 * Товары, которые за период ни разу не продавались, в отчёт не попадают: по ним считать не из чего.
 */
function report_demand(array $directions, string $from, string $to, int $horizonDays = 60): array
{
    $db = reports_db();
    $days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);

    $dirCond = '';
    if (count($directions) === 1) {
        $dirCond = " AND pe.kod_sap LIKE '" . $db->real_escape_string($directions[0]) . "%'";
    }

    // Продано за период: строки клиентских счетов (type=0 — продажи; кредит-ноты вычитаем).
    $sql = "SELECT d.fk_product,
                   SUM(CASE WHEN f.type = 2 THEN -d.qty ELSE d.qty END) AS sold,
                   p.ref, p.label, p.stock AS stock, pe.kod_sap
            FROM llx_facturedet d
            JOIN llx_facture f ON f.rowid = d.fk_facture
            JOIN llx_product p ON p.rowid = d.fk_product
            LEFT JOIN llx_product_extrafields pe ON pe.fk_object = p.rowid
            WHERE f.datef BETWEEN '" . $db->real_escape_string($from) . "' AND '" . $db->real_escape_string($to) . "'
              AND f.fk_statut > 0 AND d.fk_product > 0" . $dirCond . "
            GROUP BY d.fk_product
            HAVING sold > 0
            ORDER BY sold DESC
            LIMIT 400";
    $res = $db->query($sql);

    $rows = [];
    $ids = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; $ids[] = (int)$r['fk_product']; }
    $incoming = get_incoming_qty_bulk($ids);

    $out = [];
    foreach ($rows as $r) {
        $pid = (int)$r['fk_product'];
        $sold = (float)$r['sold'];
        $perDay = $sold / $days;
        $stock = (float)$r['stock'];
        $onWay = (float)($incoming[$pid] ?? 0);
        $available = $stock + $onWay;
        $daysLeft = $perDay > 0 ? $available / $perDay : null;
        $needed = max(0.0, $perDay * $horizonDays - $available);
        $out[] = [
            'id' => $pid,
            'ref' => (string)$r['ref'],
            'label' => (string)$r['label'],
            'direction' => $r['kod_sap'] ? strtoupper(substr((string)$r['kod_sap'], 0, 1)) : '',
            'sold' => round($sold, 2),
            'per_day' => round($perDay, 3),
            'stock' => round($stock, 2),
            'incoming' => round($onWay, 2),
            'days_left' => $daysLeft === null ? null : round($daysLeft, 1),
            'need' => round($needed, 0),
        ];
    }

    // Сначала то, что кончится раньше всего — это и есть ответ на «что пора закупать».
    usort($out, function ($a, $b) {
        $x = $a['days_left'] ?? 1e9;
        $y = $b['days_left'] ?? 1e9;
        return $x <=> $y;
    });

    return ['days' => $days, 'horizon' => $horizonDays, 'rows' => $out];
}
