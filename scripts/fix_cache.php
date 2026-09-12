<?php
/**
 * Пересчёт кэша общего остатка llx_product.stock по складам (11.09.2026).
 * У 13 товаров кэш расходился со складами (например B402981: кэш 12, по складам 1). Правда —
 * в llx_product_stock: касса считает по складам и продаёт по ним, а Dolibarr пересчитывает кэш
 * только при движении. BossTool и мой вчерашний отчёт читали кэш и потому ошибались.
 */
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();
$sub = "COALESCE((SELECT SUM(ps.reel) FROM llx_product_stock ps WHERE ps.fk_product=p.rowid),0)";
$bad = $db->query("SELECT p.rowid, p.ref, p.stock, $sub s FROM llx_product p
                   HAVING ABS(COALESCE(p.stock,0) - s) > 0.001")->fetch_all(MYSQLI_ASSOC);
file_put_contents('C:\PHPTMP\stockcache_backup_' . date('Ymd_His') . '.json', json_encode($bad, JSON_UNESCAPED_UNICODE));
$db->query("UPDATE llx_product p SET p.stock = $sub WHERE ABS(COALESCE(p.stock,0) - $sub) > 0.001");
printf("исправлено: %d (снимок сохранён)\n", $db->affected_rows);
$left = $db->query("SELECT COUNT(*) n FROM llx_product p WHERE ABS(COALESCE(p.stock,0) - $sub) > 0.001")->fetch_assoc()['n'];
echo "расхождений осталось: $left\n";
