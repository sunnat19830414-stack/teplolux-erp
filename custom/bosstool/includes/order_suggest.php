<?php
/**
 * Рекомендации к заказу по поставщику (04.09.2026, по образцу, который показал пользователь —
 * раздел «Закупки» в SR Lux).
 *
 * Смысл: шеф выбирает поставщика и сразу видит ВСЮ его номенклатуру одной таблицей — склад, сколько
 * уже едет, сколько продали за год, на сколько месяцев хватит запаса и готовую рекомендацию, сколько
 * заказать. Количества вписываются прямо в строках. Это заменяет поиск товаров по одному: закупка
 * идёт поставщик за поставщиком, а не товар за товаром.
 *
 * Рекомендация = запас на N месяцев − склад − то, что уже в пути (N по умолчанию 3, как в образце).
 *
 * ⚠️ Средний расход считается за ТОТ ПЕРИОД, за который в системе реально есть продажи, а не всегда
 * за 12 месяцев. Пользователь решил историю продаж из SAP/Bus.gdb не переносить — она копится сама
 * с момента запуска. Если бы мы делили накопленное на 12 всегда, то через два месяца работы товар,
 * проданный 60 раз за эти два месяца, дал бы «5 в месяц» вместо настоящих 30 — рекомендация
 * оказалась бы занижена в шесть раз, и это молча, без единой ошибки на экране. Поэтому делим на
 * фактическую глубину данных (но не больше 12 месяцев) и подписываем её в интерфейсе.
 */
require_once __DIR__ . '/stock_lookup.php';

/**
 * Меньше этого срока истории считать расход бессмысленно: за две недели один крупный отгруз даёт
 * «расход 2000 в месяц» и рекомендацию «заказать 5000». Пока истории меньше — колонки расхода,
 * запаса и рекомендации остаются пустыми, а на экране висит объяснение почему. Склад и «в пути»
 * при этом показываются как обычно, ими и надо пользоваться первое время.
 */
const SUGGEST_MIN_HISTORY_MONTHS = 1.0;

/**
 * Сколько месяцев истории продаж реально есть в системе (от самого раннего проведённого счёта),
 * но не больше 12. Минимум 0.5, чтобы в первые дни работы не делить на почти ноль.
 */
function sales_history_months(): float
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $db = stock_lookup_db();
    $row = $db->query("SELECT MIN(datef) mn FROM llx_facture WHERE fk_statut > 0")->fetch_assoc();
    if (empty($row['mn'])) return $cached = 0.5;

    $days = (time() - strtotime((string)$row['mn'])) / 86400;
    $months = $days / 30.44;
    if ($months < 0.5) $months = 0.5;
    if ($months > 12) $months = 12.0;
    return $cached = round($months, 2);
}

/**
 * @param int   $supplierId  поставщик, чью номенклатуру показываем
 * @param array $directions  разрешённые направления ('J'/'T') — у Суннатиллы только своё
 * @param float $months      на сколько месяцев хотим запас
 * @return array{window_months: float, rows: array<int, array<string, mixed>>}
 */
function supplier_order_suggestions(int $supplierId, array $directions, float $months = 3.0): array
{
    $window = sales_history_months();
    if ($supplierId <= 0) return ['window_months' => $window, 'currency' => 'USD', 'rows' => []];
    $db = stock_lookup_db();

    // --- номенклатура поставщика + остаток ---
    $where = ['p.tobuy = 1'];
    $where[] = 'EXISTS (SELECT 1 FROM llx_product_fournisseur_price f
                        WHERE f.fk_product = p.rowid AND f.fk_soc = ' . (int)$supplierId . ')';
    if (count($directions) === 1) {
        $where[] = "e.kod_sap LIKE '" . $db->real_escape_string($directions[0]) . "%'";
    }
    $res = $db->query(
        "SELECT p.rowid AS id, p.ref, p.label, p.stock, p.customcode, e.kod_sap
         FROM llx_product p
         LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
         WHERE " . implode(' AND ', $where) . "
         ORDER BY p.ref"
    );
    $rows = [];
    $ids = [];
    while ($r = $res->fetch_assoc()) { $rows[(int)$r['id']] = $r; $ids[] = (int)$r['id']; }
    if (empty($ids)) return ['window_months' => $window, 'currency' => supplier_contract_currency($supplierId), 'rows' => []];
    $list = implode(',', $ids);

    // --- что уже едет, с номерами заказов (как в образце — под цифрой видно, каким заказом) ---
    $incoming = [];
    $res = $db->query(
        "SELECT d.fk_product, c.ref,
                SUM(d.qty) AS ordered,
                COALESCE((SELECT SUM(b.qty) FROM llx_receptiondet_batch b
                          WHERE b.fk_element = c.rowid AND b.fk_product = d.fk_product), 0) AS received
         FROM llx_commande_fournisseurdet d
         JOIN llx_commande_fournisseur c ON c.rowid = d.fk_commande
         WHERE c.fk_statut IN (1,2,3,4) AND d.fk_product IN ($list)
         GROUP BY c.rowid, d.fk_product"
    );
    while ($r = $res->fetch_assoc()) {
        $left = (float)$r['ordered'] - (float)$r['received'];
        if ($left <= 0.0001) continue;
        $pid = (int)$r['fk_product'];
        if (!isset($incoming[$pid])) $incoming[$pid] = ['qty' => 0.0, 'refs' => []];
        $incoming[$pid]['qty'] += $left;
        $incoming[$pid]['refs'][] = (string)$r['ref'];
    }

    // --- продажи за последние 365 дней (кредит-ноты вычитаются) ---
    $sold = [];
    $res = $db->query(
        "SELECT d.fk_product, SUM(CASE WHEN f.type = 2 THEN -d.qty ELSE d.qty END) AS qty
         FROM llx_facturedet d
         JOIN llx_facture f ON f.rowid = d.fk_facture
         WHERE f.fk_statut > 0 AND d.fk_product IN ($list)
           AND f.datef >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
         GROUP BY d.fk_product"
    );
    while ($r = $res->fetch_assoc()) $sold[(int)$r['fk_product']] = (float)$r['qty'];

    // --- заводские (закупочные) цены этого поставщика: по просьбе пользователя показываем их
    // руководству прямо в таблице, чтобы сразу было видно, во сколько обойдётся заявка ---
    $prices = get_purchase_prices_bulk($ids, $supplierId);
    $contractCurrency = supplier_contract_currency($supplierId);

    $out = [];
    foreach ($rows as $pid => $r) {
        $stock = (float)$r['stock'];
        $onWay = $incoming[$pid]['qty'] ?? 0.0;
        $soldTotal = max(0.0, $sold[$pid] ?? 0.0);
        // Делим на РЕАЛЬНУЮ глубину данных, а не всегда на 12 — см. пояснение в шапке файла.
        // Если истории совсем мало, расход не считаем вовсе: экстраполяция с двух недель врёт в разы.
        $enoughHistory = $window >= SUGGEST_MIN_HISTORY_MONTHS;
        $perMonth = $enoughHistory ? $soldTotal / $window : 0.0;
        $available = $stock + $onWay;

        // На сколько месяцев хватит. Если не продавалось ни разу — считать не из чего, показываем «нет данных».
        $monthsLeft = $perMonth > 0 ? $available / $perMonth : null;
        $suggest = $perMonth > 0 ? max(0.0, round($perMonth * $months - $available)) : 0.0;

        // Светофор — как в образце: красный меньше месяца, жёлтый 1-2, зелёный больше двух.
        $priceInfo = $prices[$pid] ?? null;

        if ($monthsLeft === null)      $level = 'none';
        elseif ($monthsLeft < 1)       $level = 'red';
        elseif ($monthsLeft < 2)       $level = 'yellow';
        else                           $level = 'green';

        $out[] = [
            'id' => $pid,
            'ref' => (string)$r['ref'],
            'label' => (string)$r['label'],
            'stock' => $stock,
            'incoming' => $onWay,
            'incoming_refs' => array_unique($incoming[$pid]['refs'] ?? []),
            'sold_total' => $soldTotal,      // за доступный период, не обязательно за год
            'per_month' => $perMonth,
            'months_left' => $monthsLeft,
            'suggest' => $suggest,
            'level' => $level,
            // Цена показывается в валюте ДОГОВОРА поставщика — ровно та цифра, что в его прайсе.
            // null означает «цены в справочнике нет», а не «ноль».
            'price' => $priceInfo === null ? null : ($contractCurrency === 'USD'
                ? (float)$priceInfo['price']
                : (float)$priceInfo['native']),
        ];
    }

    // Сначала то, что горит: меньше всего месяцев запаса. Товары без продаж — в конец.
    usort($out, function ($a, $b) {
        $x = $a['months_left'] ?? 1e9;
        $y = $b['months_left'] ?? 1e9;
        if ($x === $y) return strcmp($a['ref'], $b['ref']);
        return $x <=> $y;
    });
    return ['window_months' => $window, 'currency' => $contractCurrency, 'rows' => $out];
}
