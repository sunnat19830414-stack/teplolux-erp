<?php
/**
 * Привести уровень 1 «Дилерская» к актуальной дилерской цене из Bus.gdb (10.09.2026).
 *
 * Пользователь: «цена в Bus.gdb в категории Дилер. — это актуальная дилерская цена; в Dolibarr мы
 * записывали как продажную именно дилерскую, потому что розничную выставлять не планировали».
 *
 * Проверка подтвердила и объяснила все 130 расхождений:
 *   64 товара Ostendorf — цена в Dolibarr в точности равна РОЗНИЧНОЙ из Bus.gdb (64 из 64),
 *      их цены менялись в 2023 и 2025, то есть до переноса → при миграции взяли не ту колонку;
 *   66 товаров (в основном ICMA) — в Dolibarr цена НИЖЕ, и у 65 из 66 цена в Bus.gdb менялась
 *      в августе 2026, уже после переноса; дилерская и розничная там равны между собой →
 *      путаницы колонок нет, в Dolibarr просто устаревшая цифра.
 * В обоих случаях верна дилерская из Bus.gdb.
 *
 * Уровни 2 и 3 пересчитываются от новой базы: +5% и +20%.
 * Трогаем ТОЛЬКО товары Турк, у которых есть дилерская цена из Bus.gdb. Жоми не касается.
 *
 * Без аргументов — пробный прогон. С `-- --apply` — запись со снимком.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

const MARKUP = [2 => 0.05, 3 => 0.20];

$rows = [];
$r = $db->query("SELECT p.rowid, p.ref, p.label, p.price, p.tva_tx, p.price_base_type, e.dealer_price
                 FROM llx_product p JOIN llx_product_extrafields e ON e.fk_object = p.rowid
                 WHERE COALESCE(e.dealer_price,0) > 0 AND COALESCE(p.price,0) > 0
                   AND ABS(p.price - e.dealer_price) >= 0.005");
while ($x = $r->fetch_assoc()) $rows[] = $x;

$down = array_filter($rows, fn($x) => $x['dealer_price'] < $x['price']);
$up   = array_filter($rows, fn($x) => $x['dealer_price'] > $x['price']);
printf("к исправлению: %d товаров\n", count($rows));
printf("  цена ПОНИЗИТСЯ: %d  (сумма %.2f → %.2f)\n", count($down),
    array_sum(array_column($down, 'price')), array_sum(array_column($down, 'dealer_price')));
printf("  цена ПОВЫСИТСЯ: %d  (сумма %.2f → %.2f)\n", count($up),
    array_sum(array_column($up, 'price')), array_sum(array_column($up, 'dealer_price')));

echo "\nсамые заметные изменения:\n";
usort($rows, fn($a, $b) => abs($b['price'] - $b['dealer_price']) <=> abs($a['price'] - $a['dealer_price']));
foreach (array_slice($rows, 0, 10) as $x)
    printf("  %-14s %-32s %8.2f → %8.2f  (%+.0f%%)\n", $x['ref'], mb_substr($x['label'], 0, 32),
        $x['price'], $x['dealer_price'], ($x['dealer_price'] / $x['price'] - 1) * 100);

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

$bak = array_map(fn($x) => ['rowid' => (int)$x['rowid'], 'ref' => $x['ref'],
                            'price' => (float)$x['price']], $rows);
$f = 'C:\PHPTMP\level1_backup_' . date('Ymd_His') . '.json';
file_put_contents($f, json_encode($bak, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nснимок: $f\n";

$db->begin_transaction();
try {
    $upP = $db->prepare("UPDATE llx_product SET price = ?, price_ttc = ?, tms = NOW() WHERE rowid = ?");
    $insHist = $db->prepare("INSERT INTO llx_product_price
        (entity, tms, fk_product, date_price, price_level, price, price_ttc, price_min, price_min_ttc,
         price_base_type, tva_tx, recuperableonly, localtax1_tx, localtax2_tx, fk_user_author, tosell)
        VALUES (1, NOW(), ?, NOW(), ?, ?, ?, 0, 0, ?, ?, 0, 0, 0, 1, 1)");
    $delLvl = $db->prepare("DELETE FROM llx_product_price WHERE fk_product = ? AND price_level IN (2,3)");
    $log = $db->prepare("INSERT INTO llx_nt_product_log
        (fk_product, field, old_value, new_value, who, tool, datec) VALUES (?,?,?,?,?,?,NOW())");

    $n = 0;
    foreach ($rows as $x) {
        $id = (int)$x['rowid'];
        $new = round((float)$x['dealer_price'], 2);
        $vat = (float)$x['tva_tx'];
        $type = $x['price_base_type'] ?: 'HT';
        $ttc = round($new * (1 + $vat / 100), 2);

        $upP->bind_param('ddi', $new, $ttc, $id);
        $upP->execute();

        // уровень 1 — новая строка в истории цен, чтобы прежнее значение осталось видно
        $lvl = 1;
        $insHist->bind_param('iiddsd', $id, $lvl, $new, $ttc, $type, $vat);
        $insHist->execute();

        // уровни 2 и 3 пересчитываем от новой базы
        $delLvl->bind_param('i', $id); $delLvl->execute();
        foreach (MARKUP as $l => $m) {
            $v = round($new * (1 + $m), 2);
            $vt = round($v * (1 + $vat / 100), 2);
            $insHist->bind_param('iiddsd', $id, $l, $v, $vt, $type, $vat);
            $insHist->execute();
        }

        $old = number_format((float)$x['price'], 2, '.', '');
        $nw  = number_format($new, 2, '.', '');
        $field = 'Цена продажи'; $who = 'Bus.gdb'; $tool = 'импорт';
        $log->bind_param('isssss', $id, $field, $old, $nw, $who, $tool);
        $log->execute();
        $n++;
    }
    $upP->close(); $insHist->close(); $delLvl->close(); $log->close();
    $db->commit();
    printf("\nисправлено товаров: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n");
    exit(1);
}

$s = $db->query("SELECT COUNT(*) n FROM llx_product p JOIN llx_product_extrafields e ON e.fk_object=p.rowid
                 WHERE COALESCE(e.dealer_price,0) > 0 AND ABS(p.price - e.dealer_price) >= 0.005")->fetch_assoc();
printf("осталось расхождений с дилерской из Bus.gdb: %s\n", $s['n']);
