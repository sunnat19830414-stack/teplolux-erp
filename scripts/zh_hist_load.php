<?php
/**
 * Недостающая история продаж Жоми: 2024-01 .. 2024-11 из базы «Бизнес» (10.09.2026).
 *
 * В Dolibarr история Жоми начинается с 2024-12, база «Бизнес» покрывает с 2024-01.
 * Заливаем ТОЛЬКО период до начала имеющейся истории — пересечения нет, удалять ничего не нужно.
 * Источник помечаем `BUS_ZHOMI`, чтобы в отчётах было видно, откуда цифры.
 *
 * На общем периоде источники сходятся (2025-01..2025-11 отношение 0.91..0.96), значит на стыке
 * не будет ступеньки. Месяцы, где они РАСХОДЯТСЯ, сюда не попадают и вынесены пользователю
 * отдельно — там вопрос не в стыковке, а в полноте уже залитых данных.
 *
 * Без аргументов — пробный прогон. С `-- --apply` — запись.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

$plan = json_decode(file_get_contents('C:\PHPTMP\zh_hist_plan.json'), true);
if (!$plan) { fwrite(STDERR, "нет плана\n"); exit(1); }

$periods = array_unique(array_column($plan, 1));
sort($periods);
printf("строк к заливке: %d\n", count($plan));
printf("товаров: %d\n", count(array_unique(array_column($plan, 0))));
printf("период: %s .. %s\n", reset($periods), end($periods));
printf("суммарно штук: %s\n", number_format(array_sum(array_column($plan, 2)), 0, '.', ' '));

$exists = $db->query("SELECT COUNT(*) n FROM llx_nt_sales_history
                      WHERE direction = 'J' AND period < '2024-12'")->fetch_assoc()['n'];
printf("\nуже есть строк Жоми за период до 2024-12: %s %s\n", $exists,
    $exists > 0 ? '⚠ пересечение!' : '(пересечения нет)');

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }
if ($exists > 0) { fwrite(STDERR, "есть пересечение — остановка\n"); exit(1); }

$db->begin_transaction();
try {
    $st = $db->prepare("INSERT INTO llx_nt_sales_history
        (fk_product, direction, period, qty_sold, qty_returned, source, datec)
        VALUES (?, 'J', ?, ?, 0, 'BUS_ZHOMI', NOW())
        ON DUPLICATE KEY UPDATE qty_sold = VALUES(qty_sold), datec = NOW()");
    $n = 0;
    foreach ($plan as [$pid, $period, $qty]) {
        $pid = (int)$pid; $qty = (float)$qty;
        $st->bind_param('isd', $pid, $period, $qty);
        $st->execute(); $n++;
    }
    $st->close();
    $db->commit();
    printf("записано: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n"); exit(1);
}

echo "\nистория продаж по источникам:\n";
$r = $db->query("SELECT direction, source, MIN(period) a, MAX(period) b, COUNT(*) n,
                        COUNT(DISTINCT fk_product) p
                 FROM llx_nt_sales_history GROUP BY direction, source ORDER BY direction, a");
while ($x = $r->fetch_assoc())
    printf("  %s %-14s %s..%s строк %5s товаров %s\n",
        $x['direction'], $x['source'], $x['a'], $x['b'], $x['n'], $x['p']);
