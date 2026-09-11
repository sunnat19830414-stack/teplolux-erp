<?php
/**
 * Очистка истории движений склада (11.09.2026, «надо и в Dolibarr очистить движение товаров»).
 * Остатки (llx_product_stock) НЕ меняются — по решению пользователя они остаются до новых данных.
 * Вместо истории — одна строка «Начальный остаток» на каждую пару товар+склад, ровно на текущее
 * количество: история в Dolibarr снова сходится с остатком. Пишем напрямую в llx_stock_mouvement —
 * REST/классы Dolibarr при записи движения ещё раз изменили бы сам остаток.
 * Копия до: C:\DolibarrBackup\dolibarr_20260911_185218.sql
 */
$apply = in_array('--apply', $argv, true);
$db = new mysqli('localhost', 'root', 'fhjmKl1nINU3uxcgP5DFi4Tr', 'dolibarr'); $db->set_charset('utf8mb4');
$before = ['moves' => (int)$db->query("SELECT COUNT(*) FROM llx_stock_mouvement")->fetch_row()[0],
           'qty' => (float)$db->query("SELECT SUM(reel) FROM llx_product_stock")->fetch_row()[0],
           'rows' => (int)$db->query("SELECT COUNT(*) FROM llx_product_stock WHERE reel <> 0")->fetch_row()[0]];
echo "до: движений {$before['moves']}, остаток {$before['qty']} шт в {$before['rows']} строках\n";
$db->begin_transaction();
try {
    if ($db->query("SHOW TABLES LIKE 'llx_stock_mouvement_extrafields'")->num_rows) $db->query("DELETE FROM llx_stock_mouvement_extrafields");
    $db->query("DELETE FROM llx_stock_mouvement"); $del = $db->affected_rows;
    $db->query("INSERT INTO llx_stock_mouvement (datem, fk_product, fk_entrepot, value, price, type_mouvement, fk_user_author, label, inventorycode, tms)
                SELECT NOW(), s.fk_product, s.fk_entrepot, s.reel, COALESCE(p.pmp, 0), 0, 1,
                       'Начальный остаток (история очищена 11.09.2026)', 'NACH-20260911', NOW()
                  FROM llx_product_stock s JOIN llx_product p ON p.rowid = s.fk_product
                 WHERE s.reel <> 0");
    $ins = $db->affected_rows;
    $after = (float)$db->query("SELECT SUM(reel) FROM llx_product_stock")->fetch_row()[0];
    $mism = (int)$db->query("SELECT COUNT(*) FROM llx_product_stock s WHERE ABS(s.reel - COALESCE((SELECT SUM(m.value) FROM llx_stock_mouvement m
                             WHERE m.fk_product = s.fk_product AND m.fk_entrepot = s.fk_entrepot), 0)) > 0.0001")->fetch_row()[0];
    echo "удалено движений $del, записано начальных остатков $ins; остаток $after шт (было {$before['qty']}); расхождений история/остаток: $mism\n";
    if (abs($after - $before['qty']) > 0.0001 || $mism || $ins !== $before['rows']) throw new RuntimeException('проверка не сошлась');
    if ($apply) { $db->commit(); echo "записано\n"; } else { $db->rollback(); echo "пробный прогон — откат\n"; }
} catch (Throwable $e) { $db->rollback(); exit("ОШИБКА, ничего не изменено: " . $e->getMessage() . "\n"); }
