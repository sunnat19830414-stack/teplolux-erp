<?php
/**
 * Закрытие пяти провалов в истории продаж Жоми данными из базы «Бизнес» (10.09.2026).
 *
 * Месяцы 2024-12, 2026-05, 2026-06, 2026-07, 2026-09: в Dolibarr было 0..36 штук против
 * реальных 3 481..17 411. Это 45 тыс. штук продаж, невидимых для аналитики закупок, причём
 * самых свежих — а их отчёт «что пора закупать» взвешивает сильнее всего.
 *
 * Прежние крохи за эти месяцы удаляются: держать 3 штуки из SAP рядом с 13 114 из «Бизнеса»
 * значит задвоить. Источник новых строк — `BUS_ZHOMI`.
 *
 * ⚠️ 2026-08 не трогаем: там в Dolibarr БОЛЬШЕ (37 135 из TL_PROD против 25 294), смешивать нельзя.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

$GAPS = ['2024-12', '2026-05', '2026-06', '2026-07', '2026-09'];
$plan = json_decode(file_get_contents('C:\PHPTMP\zh_gap_plan.json'), true);
$in = "'" . implode("','", $GAPS) . "'";

$old = $db->query("SELECT period, source, COUNT(*) n, COALESCE(SUM(qty_sold),0) q
                   FROM llx_nt_sales_history WHERE direction='J' AND period IN ($in)
                   GROUP BY period, source ORDER BY period")->fetch_all(MYSQLI_ASSOC);
echo "что сейчас лежит за эти месяцы:\n";
foreach ($old as $x) printf("  %s %-14s строк %s, %s шт\n", $x['period'], $x['source'], $x['n'],
    number_format((float)$x['q'], 0, '.', ' '));
if (!$old) echo "  ничего\n";

printf("\nк заливке: %d строк, %s шт\n", count($plan),
    number_format(array_sum(array_column($plan, 2)), 0, '.', ' '));

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

$db->begin_transaction();
try {
    $db->query("DELETE FROM llx_nt_sales_history WHERE direction='J' AND period IN ($in)");
    $st = $db->prepare("INSERT INTO llx_nt_sales_history
        (fk_product, direction, period, qty_sold, qty_returned, source, datec)
        VALUES (?, 'J', ?, ?, 0, 'BUS_ZHOMI', NOW())");
    $n = 0;
    foreach ($plan as [$pid, $period, $qty]) {
        $pid = (int)$pid; $qty = (float)$qty;
        $st->bind_param('isd', $pid, $period, $qty);
        $st->execute(); $n++;
    }
    $st->close();
    $db->commit();
    printf("\nзаписано: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n"); exit(1);
}

echo "\nистория Жоми по месяцам после заливки:\n";
$r = $db->query("SELECT period, source, COALESCE(SUM(qty_sold),0) q FROM llx_nt_sales_history
                 WHERE direction='J' GROUP BY period, source ORDER BY period");
while ($x = $r->fetch_assoc())
    printf("  %s %-14s %10s шт\n", $x['period'], $x['source'], number_format((float)$x['q'], 0, '.', ' '));
