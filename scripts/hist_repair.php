<?php
/**
 * Замена истории Жоми (BUS_ZHOMI) очищенной от перемещений между складами.
 *
 * Ошибка была моя: я взял весь расход (`DIRECT = 0`) за продажи, а туда входят и переброски
 * на другой склад. В загруженном периоде это 1254 штуки из 130 726 — один процент, но
 * в отчёте «что пора закупать» переброска выглядела бы спросом.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

$clean = json_decode(file_get_contents('C:\PHPTMP\zh_hist_clean.json'), true);
$was = $db->query("SELECT COUNT(*) n, COALESCE(SUM(qty_sold),0) q FROM llx_nt_sales_history
                   WHERE source = 'BUS_ZHOMI'")->fetch_assoc();
printf("сейчас в базе: %s строк, %s шт\n", $was['n'], number_format((float)$was['q'], 0, '.', ' '));
printf("станет:        %d строк, %s шт\n", count($clean),
    number_format(array_sum(array_column($clean, 2)), 0, '.', ' '));
printf("разница:       %s шт — это перемещения между складами\n",
    number_format((float)$was['q'] - array_sum(array_column($clean, 2)), 0, '.', ' '));

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

$db->begin_transaction();
try {
    $db->query("DELETE FROM llx_nt_sales_history WHERE source = 'BUS_ZHOMI'");
    $st = $db->prepare("INSERT INTO llx_nt_sales_history
        (fk_product, direction, period, qty_sold, qty_returned, source, datec)
        VALUES (?, 'J', ?, ?, 0, 'BUS_ZHOMI', NOW())");
    $n = 0;
    foreach ($clean as [$pid, $period, $qty]) {
        $pid = (int)$pid; $qty = (float)$qty;
        $st->bind_param('isd', $pid, $period, $qty);
        $st->execute(); $n++;
    }
    $st->close();
    $db->commit();
    printf("\nперезаписано: %d строк\n", $n);
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n"); exit(1);
}
