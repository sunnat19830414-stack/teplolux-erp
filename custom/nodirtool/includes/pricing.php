<?php
/**
 * Цены после нового прихода (11.09.2026, решение пользователя: пункты 1 и 3, вариант «б», порог 10%).
 *
 * ОДИНАКОВАЯ копия лежит в TeplouxKassa, NodirTool и BossTool — правки вносить во все три
 * (та же договорённость, что для sellable_stock.php и bank_transfer.php).
 *
 * 1. Очередь «Новый приход — проверьте цены» (llx_nt_price_review). Касса после приёмки заносит
 *    туда принятые товары заказа; Умид в BossTool смотрит себестоимость и наценку, ставит цену и
 *    отмечает «проверено». Цифры себестоимости НЕ хранятся в очереди — считаются в момент показа из
 *    llx_product.pmp и llx_supplier_landed_result: логистику часто вносят уже после приёмки.
 *
 * 2. Вариант «б»: пока приход не проверен, касса продаёт по старой цене — КРОМЕ товаров, у которых
 *    цена продажи стала не выше себестоимости. Их касса не продаёт, пока Умид не поставит цену или
 *    не отметит приход проверенным (значит, продавать так — его осознанное решение).
 *    Если расходы по поставке внесли уже после проверки и товар ушёл ниже себестоимости, запись
 *    открывается снова (pricing_reopen_below_cost, вызывается из пересчёта себестоимости).
 *
 * 3. Оптовая (+5%) и розничная (+20%) — от дилерской (уровень 1 = llx_product.price), см.
 *    setup_price_levels.php 10.09.2026. Цену менять ТОЛЬКО через pricing_levels_payload():
 *    PUT products/{id} с 'multiprices' => [1 => дилерская, 2 => +5%, 3 => +20%] — Dolibarr сам пишет
 *    все три уровня в историю и карточку.
 *
 *    ⚠️ ОШИБКА DOLIBARR 24 (найдена 11.09.2026): при включённых уровнях PUT products/{id} с одним
 *    'price' пишет новую цену в историю, а в карточку (llx_product.price, её читает касса) — ПРЕДЫДУЩУЮ:
 *    цикл по уровням в api_products.class.php сравнивает multiprices_min с multiprices и перезаписывает
 *    уровень 1 старым значением. Цена «применялась» только со второго сохранения. Проверено на насосе
 *    DAB: 160 → «205» дало карточку 160, следующее «207» — 205. С 'multiprices' всё верно сразу.
 *    pricing_sync_levels() — только для только что созданного товара (там REST уровни не заводит).
 */

const PRICING_LEVEL_MARKUP = [2 => 0.05, 3 => 0.20];
const PRICING_LOW_MARKUP = 0.10;   // порог «мало наценки», решение пользователя 11.09.2026

function pricing_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function pricing_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    pricing_db()->query("CREATE TABLE IF NOT EXISTS llx_nt_price_review (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_order INT NOT NULL,
        fk_product INT NOT NULL,
        pmp_before DECIMAL(24,8) DEFAULT NULL,   -- средняя себестоимость до приёмки (если нет базовой точки логистики)
        status VARCHAR(8) NOT NULL DEFAULT 'open',  -- open | done
        datec DATETIME NOT NULL,
        reopened DATETIME DEFAULT NULL,
        done_at DATETIME DEFAULT NULL,
        done_by VARCHAR(100) DEFAULT NULL,
        UNIQUE KEY uniq_order_product (fk_order, fk_product),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Средняя себестоимость товаров сейчас — снимок перед приёмкой. */
function pricing_pmp_snapshot(array $productIds): array
{
    $ids = implode(',', array_unique(array_filter(array_map('intval', $productIds))));
    if ($ids === '') return [];
    $out = [];
    $r = pricing_db()->query("SELECT rowid, pmp FROM llx_product WHERE rowid IN ($ids)");
    while ($x = $r->fetch_assoc()) $out[(int)$x['rowid']] = (float)$x['pmp'];
    return $out;
}

/**
 * После приёмки: занести принятые товары заказа в очередь. Уже проверенную позицию открываем снова,
 * только если цена теперь не выше себестоимости. Возвращает число открытых позиций по заказу.
 */
function pricing_register_receipt(int $orderId, array $pmpBefore): int
{
    pricing_ensure_table();
    $db = pricing_db();
    $now = date('Y-m-d H:i:s');
    $ins = $db->prepare("INSERT IGNORE INTO llx_nt_price_review (fk_order, fk_product, pmp_before, status, datec) VALUES (?, ?, ?, 'open', ?)");
    foreach ($pmpBefore as $pid => $pmp) {
        $pid = (int)$pid; $pmp = (float)$pmp;
        $ins->bind_param('iids', $orderId, $pid, $pmp, $now);
        $ins->execute();
    }
    $ins->close();
    pricing_reopen_below_cost(array_keys($pmpBefore), [$orderId]);
    return (int)$db->query("SELECT COUNT(*) n FROM llx_nt_price_review WHERE fk_order = " . (int)$orderId . " AND status = 'open'")->fetch_assoc()['n'];
}

/** Проверенные позиции, где цена стала не выше себестоимости (расходы внесли позже), — снова в очередь. */
function pricing_reopen_below_cost(array $productIds, ?array $orderIds = null): void
{
    pricing_ensure_table();
    $ids = implode(',', array_unique(array_filter(array_map('intval', $productIds))));
    if ($ids === '') return;
    $where = $orderIds ? ' AND r.fk_order IN (' . implode(',', array_map('intval', $orderIds)) . ')' : '';
    pricing_db()->query("UPDATE llx_nt_price_review r JOIN llx_product p ON p.rowid = r.fk_product
                         SET r.status = 'open', r.reopened = NOW(), r.done_at = NULL, r.done_by = NULL
                         WHERE r.status = 'done' AND r.fk_product IN ($ids) $where AND p.pmp > 0 AND p.price <= p.pmp");
}

/**
 * Вариант «б»: какие из товаров касса продавать не должна. [fk_product => ref]
 * Условие: позиция в непроверенном приходе И цена продажи не выше средней себестоимости.
 */
function pricing_blocked(array $productIds): array
{
    pricing_ensure_table();
    $ids = implode(',', array_unique(array_filter(array_map('intval', $productIds))));
    if ($ids === '') return [];
    $out = [];
    $r = pricing_db()->query("SELECT DISTINCT p.rowid, p.ref FROM llx_nt_price_review r JOIN llx_product p ON p.rowid = r.fk_product
                              WHERE r.status = 'open' AND r.fk_product IN ($ids) AND p.pmp > 0 AND p.price <= p.pmp");
    while ($x = $r->fetch_assoc()) $out[(int)$x['rowid']] = (string)$x['ref'];
    return $out;
}

/** Сообщение кассиру — без цифр себестоимости: касса их не видит. */
function pricing_blocked_message(array $blocked): string
{
    return 'Цена на ' . implode(', ', array_values($blocked)) . ' пересматривается руководством после нового прихода — '
         . 'продажа временно закрыта. Позвоните Умиду, чтобы он поставил цену.';
}

/** Тело PUT products/{id} для смены дилерской цены — сразу все три уровня (см. шапку, ошибка Dolibarr). */
function pricing_levels_payload(float $dealer): array
{
    $m = ['1' => $dealer];
    foreach (PRICING_LEVEL_MARKUP as $lvl => $mk) $m[(string)$lvl] = round($dealer * (1 + $mk), 2);
    return ['multiprices' => $m];
}

/**
 * Оптовая и розничная от дилерской: дописать уровни 2 и 3 в историю цен, если они отличаются от
 * последних записанных. $userId — служебный пользователь Dolibarr инструмента.
 */
function pricing_sync_levels(int $productId, int $userId): void
{
    $db = pricing_db();
    $p = $db->query("SELECT price, tva_tx, price_base_type FROM llx_product WHERE rowid = " . (int)$productId)->fetch_assoc();
    if (!$p || (float)$p['price'] <= 0) return;
    $base = (float)$p['price']; $vat = (float)$p['tva_tx']; $type = $p['price_base_type'] ?: 'HT';
    $ins = $db->prepare("INSERT INTO llx_product_price
        (entity, tms, fk_product, date_price, price_level, price, price_ttc, price_min, price_min_ttc,
         price_base_type, tva_tx, recuperableonly, localtax1_tx, localtax2_tx, fk_user_author, tosell)
        VALUES (1, NOW(), ?, NOW(), ?, ?, ?, 0, 0, ?, ?, 0, 0, 0, ?, 1)");
    foreach (PRICING_LEVEL_MARKUP as $lvl => $mk) {
        $val = round($base * (1 + $mk), 2);
        $last = $db->query("SELECT price FROM llx_product_price WHERE fk_product = " . (int)$productId . " AND price_level = $lvl
                            ORDER BY date_price DESC, rowid DESC LIMIT 1")->fetch_assoc();
        if ($last && abs((float)$last['price'] - $val) < 0.005) continue;
        $ttc = round($val * (1 + $vat / 100), 2);
        $ins->bind_param('iiddsdi', $productId, $lvl, $val, $ttc, $type, $vat, $userId);
        $ins->execute();
    }
    $ins->close();
}

/** Цены уровней 2 и 3 (последние записанные). [pid => [2 => .., 3 => ..]] */
function pricing_levels(array $productIds): array
{
    $ids = implode(',', array_unique(array_filter(array_map('intval', $productIds))));
    if ($ids === '') return [];
    $out = [];
    $r = pricing_db()->query("SELECT pp.fk_product, pp.price_level, pp.price FROM llx_product_price pp
        JOIN (SELECT fk_product, price_level, MAX(rowid) m FROM llx_product_price
              WHERE fk_product IN ($ids) AND price_level IN (2,3) GROUP BY fk_product, price_level) t ON t.m = pp.rowid");
    while ($x = $r->fetch_assoc()) $out[(int)$x['fk_product']][(int)$x['price_level']] = (float)$x['price'];
    return $out;
}
