<?php
/**
 * История закупочных цен поставщика (см. CLAUDE.md/отчёт аудита, "перезапись закупочной цены").
 * savePurchasePrice() в dolibarr_api.php перезаписывает "официальную" цену товара у поставщика
 * КАЖДЫЙ раз, когда её вписывают при оформлении/правке строки заказа — по решению пользователя это
 * поведение не меняем (не спрашиваем подтверждения), но теперь ведём журнал старое→новое значение,
 * чтобы можно было посмотреть, что было раньше, если цена окажется испорченной опечаткой.
 *
 * Простой отдельный mysqli-коннект (тот же паттерн, что в includes/logistics.php) — не через
 * master.inc.php, эта запись не требует классов Dolibarr.
 */

function price_history_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function price_history_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    $db = price_history_db();
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_price_history (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_product INT NOT NULL,
        fk_supplier INT NOT NULL,
        old_price DECIMAL(18,4) DEFAULT NULL,
        new_price DECIMAL(18,4) NOT NULL,
        changed_by VARCHAR(100) DEFAULT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_product_supplier (fk_product, fk_supplier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Сохранить цену от поставщика через DolibarrApi (как раньше) + записать старое/новое значение в
 * журнал, если цена реально поменялась (или не была задана раньше). Возвращает то же bool, что и
 * $api->savePurchasePrice() — вызывающему коду ничего менять не нужно, кроме имени функции.
 */
function save_purchase_price_with_history(DolibarrApi $api, int $productId, int $supplierId, float $newPrice, string $who = '', string $currency = '', float $rate = 0.0): bool
{
    $info = $api->getPurchasePriceInfoForSupplier($productId, $supplierId);
    // 04.09.2026 (B2): цена может прийти в валюте поставщика — журнал ведём в ТОЙ ЖЕ валюте, что и
    // новая цена, иначе «было 4.30, стало 3.69» читалось бы как падение, хотя это просто $ против €.
    $inNative = $currency !== '' && $currency !== 'USD' && $rate > 0;
    $oldPrice = $info === null ? null : ($inNative && $info['currency'] === $currency ? $info['native'] : $info['price']);
    $ok = $api->savePurchasePrice($productId, $supplierId, $newPrice, $currency, $rate);
    if ($ok && ($oldPrice === null || abs($oldPrice - $newPrice) > 0.0001)) {
        price_history_ensure_table();
        $db = price_history_db();
        $db->query("INSERT INTO llx_supplier_price_history (fk_product, fk_supplier, old_price, new_price, changed_by, datec)
            VALUES (" . (int)$productId . ", " . (int)$supplierId . ", " .
            ($oldPrice === null ? 'NULL' : (float)$oldPrice) . ", " . (float)$newPrice . ", '" .
            $db->real_escape_string($who) . "', '" . date('Y-m-d H:i:s') . "')");
    }
    return $ok;
}

/** История цен товара у конкретного поставщика (или у всех поставщиков, если $supplierId не задан). */
function get_price_history(int $productId, ?int $supplierId = null): array
{
    price_history_ensure_table();
    $db = price_history_db();
    $sql = "SELECT * FROM llx_supplier_price_history WHERE fk_product=" . (int)$productId;
    if ($supplierId !== null) $sql .= " AND fk_supplier=" . (int)$supplierId;
    $sql .= " ORDER BY rowid DESC LIMIT 50";
    $res = $db->query($sql);
    $out = [];
    if ($res) while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}
