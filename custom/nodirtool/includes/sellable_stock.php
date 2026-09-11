<?php
/**
 * «Годный остаток» — остаток БЕЗ складов брака (11.09.2026).
 *
 * Один и тот же файл лежит во всех трёх инструментах, как bank_transfer.php и catalog.php.
 *
 * Зачем: Dolibarr хранит в `llx_product.stock` сумму по ВСЕМ складам, включая «08 Брак Жоми»
 * и «09 Брак Турк». Бракованный товар мы приняли (за него заплачено, по нему рекламация), но
 * продать его нельзя. Если читать `p.stock` напрямую, брак окажется «в наличии», а отчёт
 * «Что пора закупать» недозакажет ровно на количество брака.
 *
 * Список складов брака — в `llx_const.NT_DEFECT_WAREHOUSES`, один на все инструменты.
 *
 * ⚠️ Себестоимость (pmp, landed cost) считается по ВСЕМ складам, включая брак: за эти единицы
 * заплачено, и из средней цены их выбрасывать неверно. Этот файл — только про «что можно продать».
 */

/** id складов брака; пустой массив, если константа не задана. */
function nt_defect_warehouse_ids(mysqli $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $r = $db->query("SELECT value FROM llx_const WHERE name = 'NT_DEFECT_WAREHOUSES' LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        if ($row) {
            foreach (explode(',', (string)$row['value']) as $id) {
                $id = (int)trim($id);
                if ($id > 0) $cache[] = $id;
            }
        }
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * SQL-выражение годного остатка товара с псевдонимом таблицы $alias (например 'p').
 * Подставляется вместо `p.stock` в SELECT, WHERE и ORDER BY.
 */
function nt_sellable_stock_sql(mysqli $db, string $alias = 'p'): string
{
    $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);
    $ids = nt_defect_warehouse_ids($db);
    $not = $ids ? ' AND ps.fk_entrepot NOT IN (' . implode(',', array_map('intval', $ids)) . ')' : '';
    return "COALESCE((SELECT SUM(ps.reel) FROM llx_product_stock ps
                      WHERE ps.fk_product = {$alias}.rowid{$not}), 0)";
}

/**
 * Годный остаток из ответа REST (`includestockdata=1`): `stock_reel` минус склады брака.
 * REST отдаёт сумму по всем складам и разбивку в `stock_warehouse`.
 */
function nt_sellable_from_rest(mysqli $db, array $product): float
{
    $total = (float)($product['stock_reel'] ?? 0);
    $by = $product['stock_warehouse'] ?? [];
    foreach (nt_defect_warehouse_ids($db) as $wid) {
        $row = $by[$wid] ?? ($by[(string)$wid] ?? null);
        if (is_array($row)) $total -= (float)($row['real'] ?? 0);
        elseif (is_object($row)) $total -= (float)($row->real ?? 0);
    }
    return $total;
}
