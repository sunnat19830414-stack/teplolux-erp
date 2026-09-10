<?php
/**
 * Долг контрагентам — в ВАЛЮТЕ, в которой мы должны заплатить (05.09.2026, прямое требование
 * пользователя: «долг показываешь на своих валютах — то есть то, что мы должны оплатить»).
 *
 * Раньше долг поставщику брался из `getSupplierOutstanding()` (штатный `Societe::getOutstandingBills`)
 * и приходил ВСЕГДА в базовой валюте компании, то есть в долларах, — счёт в евро показывался
 * пересчитанным. Это вводит в заблуждение: заплатить надо евро, а курс к моменту оплаты уедет.
 *
 * Теперь считаем сами, по самим счетам, и группируем по валюте счёта. Заодно уходит N+1: раньше
 * `payments.php` и `index.php` дёргали `getSupplierInvoicePayments()` на КАЖДЫЙ счёт.
 *
 * ⚠️ Почему прямой SQL, а не REST: `GET /supplierinvoices/{id}/payments` внутри вызывает
 * `getListOfPayments()` БЕЗ параметра multicurrency, поэтому отдаёт суммы платежей в долларах,
 * а не в валюте счёта — посчитать по нему остаток в евро нельзя. В связующей таблице
 * `llx_paiementfourn_facturefourn` есть и `multicurrency_amount`, и код валюты.
 *
 * Себестоимость товара при этом по-прежнему в долларах — это базовая валюта учёта, иначе не
 * сложить фрахт с ценой товара. Долларовый эквивалент здесь считается только для сортировки
 * списков «кому должны больше всего» и на экран не выводится.
 */

require_once __DIR__ . '/money.php';

function debt_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/**
 * Долг по поставщикам, разложенный по валютам.
 *
 * Возвращает [socId => ['EUR' => 1200.00, 'USD' => 300.00], ...] — только ненулевые остатки.
 * Кредит-ноты (предоплаты, фиксация недопоставки) имеют отрицательную сумму и корректно уменьшают
 * долг, вплоть до минуса — это «мы в переплате», ровно как и раньше показывал Dolibarr.
 *
 * $socId — если указан, считаем только по одному поставщику.
 */
function supplier_debt_by_currency(?int $socId = null): array
{
    $db = debt_db();
    $where = "f.fk_statut = 1 AND f.paye = 0 AND f.entity = 1";
    $params = [];
    $types = '';
    if ($socId !== null) { $where .= " AND f.fk_soc = ?"; $params[] = $socId; $types .= 'i'; }

    $sql = "
        SELECT f.fk_soc,
               UPPER(COALESCE(NULLIF(f.multicurrency_code, ''), 'USD')) AS cur,
               SUM(f.multicurrency_total_ttc - COALESCE(pay.paid, 0))   AS remaining
          FROM llx_facture_fourn f
          LEFT JOIN (
                SELECT fk_facturefourn, SUM(multicurrency_amount) AS paid
                  FROM llx_paiementfourn_facturefourn
                 GROUP BY fk_facturefourn
          ) pay ON pay.fk_facturefourn = f.rowid
         WHERE $where
         GROUP BY f.fk_soc, cur
    ";
    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($r = $res->fetch_assoc()) {
        $sum = (float)$r['remaining'];
        if (abs($sum) < 0.005) continue;
        $out[(int)$r['fk_soc']][$r['cur']] = round($sum, 2);
    }
    $stmt->close();
    return $out;
}

/**
 * Неоплаченные счета поставщику с остатком В ВАЛЮТЕ СЧЁТА — то, что показывается построчно
 * в карточке поставщика и в выписке. Одним запросом вместо счёта-за-счётом.
 */
function supplier_unpaid_invoices(?int $socId = null): array
{
    $db = debt_db();
    $where = "f.fk_statut = 1 AND f.paye = 0 AND f.entity = 1";
    $params = []; $types = '';
    if ($socId !== null) { $where .= " AND f.fk_soc = ?"; $params[] = $socId; $types .= 'i'; }

    $sql = "
        SELECT f.rowid, f.ref, f.ref_supplier, f.fk_soc, f.datef, f.type, f.date_lim_reglement,
               UPPER(COALESCE(NULLIF(f.multicurrency_code, ''), 'USD')) AS cur,
               f.multicurrency_total_ttc AS total_native,
               f.total_ttc               AS total_usd,
               COALESCE(pay.paid, 0)     AS paid_native
          FROM llx_facture_fourn f
          LEFT JOIN (
                SELECT fk_facturefourn, SUM(multicurrency_amount) AS paid
                  FROM llx_paiementfourn_facturefourn
                 GROUP BY fk_facturefourn
          ) pay ON pay.fk_facturefourn = f.rowid
         WHERE $where
         ORDER BY f.datef, f.rowid
    ";
    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($r = $res->fetch_assoc()) {
        $r['remaining_native'] = round((float)$r['total_native'] - (float)$r['paid_native'], 2);
        $out[] = $r;
    }
    $stmt->close();
    return $out;
}

/**
 * Сколько оплачено по каждому счёту В ЕГО ВАЛЮТЕ — одним запросом на список счетов.
 *
 * Заменяет `getSupplierInvoicePayments()` в цикле (N+1) и, главное, даёт сумму именно в валюте
 * счёта: REST-эндпоинт платежей отдаёт их в долларах, по нему остаток в евро не посчитать.
 *
 * Возвращает [invoiceId => оплачено_в_валюте_счёта].
 */
function supplier_paid_native(array $invoiceIds): array
{
    $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
    if (!$ids) return [];
    $db = debt_db();
    $in = implode(',', $ids);   // только целые после intval — подстановка безопасна
    $res = $db->query("
        SELECT fk_facturefourn AS id, SUM(multicurrency_amount) AS paid
          FROM llx_paiementfourn_facturefourn
         WHERE fk_facturefourn IN ($in)
         GROUP BY fk_facturefourn
    ");
    $out = [];
    if ($res) while ($r = $res->fetch_assoc()) $out[(int)$r['id']] = round((float)$r['paid'], 2);
    return $out;
}

/**
 * Долг перевозчику по валютам: начислено (расходы, привязанные к перевозчику) минус оплачено.
 *
 * В отличие от поставщиков, здесь свои таблицы, и в них с самого начала лежит и сумма в валюте
 * (`native_amount`/`native_currency`), и долларовый эквивалент. Раньше долг считался ТОЛЬКО по
 * `usd_amount` — теперь по родной валюте, а usd остаётся для себестоимости.
 *
 * $carrierId — если указан, только по одному перевозчику.
 */
function carrier_debt_by_currency(?int $carrierId = null): array
{
    $db = debt_db();
    $out = [];

    foreach ([['llx_supplier_logistics_expense', 1], ['llx_carrier_payment', -1]] as [$table, $sign]) {
        $check = $db->query("SHOW TABLES LIKE '$table'");
        if (!$check || !$check->num_rows) continue;

        $where = "fk_carrier IS NOT NULL AND fk_carrier > 0";
        $params = []; $types = '';
        if ($carrierId !== null) { $where .= " AND fk_carrier = ?"; $params[] = $carrierId; $types .= 'i'; }

        $stmt = $db->prepare("
            SELECT fk_carrier,
                   UPPER(COALESCE(NULLIF(native_currency, ''), 'USD')) AS cur,
                   SUM(native_amount) AS total
              FROM $table
             WHERE $where
             GROUP BY fk_carrier, cur
        ");
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $cid = (int)$r['fk_carrier'];
            $cur = $r['cur'];
            $out[$cid][$cur] = round(($out[$cid][$cur] ?? 0) + $sign * (float)$r['total'], 2);
        }
        $stmt->close();
    }

    // убираем нулевые остатки, чтобы не показывать «0.00 €» у закрытых перевозчиков
    foreach ($out as $cid => $byCur) {
        foreach ($byCur as $cur => $sum) {
            if (abs($sum) < 0.005) unset($out[$cid][$cur]);
        }
        if (!$out[$cid]) unset($out[$cid]);
    }
    return $out;
}

/**
 * Курсы валют из справочника Dolibarr — сколько единиц валюты за 1 доллар. Нужны ТОЛЬКО чтобы
 * отсортировать список «кому должны больше всего», когда валюты разные. На экран не выводятся.
 */
function debt_rates(): array
{
    static $rates = null;
    if ($rates !== null) return $rates;
    $rates = ['USD' => 1.0];
    $db = debt_db();
    // Курсы хранятся историей (Dolibarr дописывает новую строку каждый день), поэтому берём
    // САМУЮ СВЕЖУЮ по каждой валюте — сортировка по возрастанию, последняя запись перетирает.
    $res = $db->query("
        SELECT c.code, r.rate
          FROM llx_multicurrency c
          JOIN llx_multicurrency_rate r ON r.fk_multicurrency = c.rowid
         WHERE c.entity = 1
         ORDER BY r.date_sync, r.rowid
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rate = (float)$r['rate'];
            if ($rate > 0) $rates[strtoupper($r['code'])] = $rate;
        }
    }
    return $rates;
}

/** Долларовый эквивалент долга — только для сортировки списков. */
function debt_sort_key(array $byCurrency): float
{
    return money_usd_equivalent($byCurrency, debt_rates());
}
