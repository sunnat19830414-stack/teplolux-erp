<?php
/**
 * Исправление себестоимости у товаров, где она оказалась заглушкой (10.09.2026).
 *
 * Проверка в SAP показала, откуда взялись равные цене цифры:
 *   CM-WS-90, CM-WS-75 — закупочных документов НЕТ вообще, только ручной ввод остатка 05.01.2025
 *                        по цене, совпавшей с продажной;
 *   B402981, 4092742, D1488901 — приход есть, но оформлен ПО ЦЕНЕ ПРОДАЖИ
 *                        (B402981: приход по 4.15 при закупке 1.83 EUR = 2.13 USD).
 * То есть себестоимости там не было, её подставили ценой продажи.
 *
 * Ставим закупочную цену поставщика в базовой валюте (USD). ⚠️ Она БЕЗ доставки и растаможки,
 * то есть занижена — но занижена честно и заметно меньше, чем «равна цене продажи», при которой
 * наценка выглядит нулевой. Когда пойдут приёмки через NodirTool, себестоимость пересчитается
 * с фрахтом сама.
 *
 * D1488901 не трогаем: у него закупочная цена — заглушка 0,01, брать неоткуда.
 *
 * Без аргументов — пробный прогон. С `-- --apply` — запись со снимком.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

$refs = ['CM-WS-90', 'CM-WS-75', 'B402981', '4092742', 'D1488901'];
$in = "'" . implode("','", array_map([$db, 'real_escape_string'], $refs)) . "'";

$rows = [];
$r = $db->query("SELECT p.rowid, p.ref, p.label, p.price, p.pmp, p.stock,
                        fp.price bp, fp.multicurrency_price mp, fp.multicurrency_code mc, s.nom
                 FROM llx_product p
                 LEFT JOIN llx_product_fournisseur_price fp ON fp.fk_product = p.rowid
                 LEFT JOIN llx_societe s ON s.rowid = fp.fk_soc
                 WHERE p.ref IN ($in)");
while ($x = $r->fetch_assoc()) $rows[] = $x;

$plan = []; $skip = [];
// У товара может быть несколько поставщиков с разными ценами: у B402981 это 2.13 от
// VA ALBERTONI и 12.35 от ALTHEA, причём вторая ВЫШЕ цены продажи. Выбираем поставщика,
// чьё имя встречается в названии товара — товар брендирован, и это надёжный признак.
// Если так выбрать нельзя, товар уходит в пропуск: себестоимость угадывать нельзя.
$byRef = [];
foreach ($rows as $x) {
    $bp = (float)$x['bp']; $mp = (float)$x['mp'];
    if ($mp <= 0.01 || $bp <= 0) { $byRef[$x['ref']]['skip'][] = $x; continue; }
    $byRef[$x['ref']]['ok'][] = $x;
}
$ambiguous = [];
foreach ($byRef as $ref => $g) {
    $ok = $g['ok'] ?? [];
    if (!$ok) { $skip[] = $g['skip'][0]; continue; }
    if (count($ok) === 1) { $plan[] = $ok[0]; continue; }
    $pick = null;
    foreach ($ok as $cand) {
        $sup = mb_strtolower(trim((string)$cand['nom']));
        $lab = mb_strtolower((string)$cand['label']);
        foreach (preg_split('/\s+/u', $sup) as $word) {
            if (mb_strlen($word) >= 4 && mb_strpos($lab, $word) !== false) { $pick = $cand; break 2; }
        }
    }
    if ($pick) $plan[] = $pick; else $ambiguous[] = $ok;
}

printf("%-14s %-30s %8s %8s → %8s  поставщик\n", 'артикул', 'товар', 'цена', 'было', 'станет');
foreach ($plan as $x)
    printf("%-14s %-30s %8.2f %8.2f → %8.2f  %.2f %s у %s\n", $x['ref'], mb_substr($x['label'], 0, 30),
        $x['price'], $x['pmp'], $x['bp'], $x['mp'], $x['mc'], mb_substr((string)$x['nom'], 0, 16));
foreach ($skip as $x)
    printf("%-14s %-30s ПРОПУСК — закупочной цены нет\n", $x['ref'], mb_substr($x['label'], 0, 30));
foreach ($ambiguous as $g)
    printf("%-14s ПРОПУСК — несколько поставщиков, выбрать нельзя: %s\n", $g[0]['ref'],
        implode(' / ', array_map(fn($c) => sprintf('%s %.2f', mb_substr((string)$c['nom'], 0, 14), $c['bp']), $g)));

echo "\nнаценка после исправления:\n";
foreach ($plan as $x)
    printf("  %-14s была 0%%, станет %.0f%%\n", $x['ref'], ($x['price'] / $x['bp'] - 1) * 100);

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

$bak = array_map(fn($x) => ['rowid' => (int)$x['rowid'], 'ref' => $x['ref'], 'pmp' => (float)$x['pmp']], $plan);
$f = 'C:\PHPTMP\ninefix_backup_' . date('Ymd_His') . '.json';
file_put_contents($f, json_encode($bak, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nснимок: $f\n";

$db->begin_transaction();
try {
    $st = $db->prepare("UPDATE llx_product SET pmp = ?, tms = NOW() WHERE rowid = ?");
    $log = $db->prepare("INSERT INTO llx_nt_product_log (fk_product, field, old_value, new_value, who, tool, datec)
                         VALUES (?,?,?,?,?,?,NOW())");
    $n = 0;
    foreach ($plan as $x) {
        $id = (int)$x['rowid']; $v = round((float)$x['bp'], 4);
        $st->bind_param('di', $v, $id); $st->execute();
        $old = number_format((float)$x['pmp'], 2, '.', ''); $new = number_format($v, 2, '.', '');
        $field = 'Себестоимость'; $who = 'закупочная цена поставщика'; $tool = 'импорт';
        $log->bind_param('isssss', $id, $field, $old, $new, $who, $tool);
        $log->execute();
        $n++;
    }
    $st->close(); $log->close();
    $db->commit();
    printf("исправлено: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n"); exit(1);
}

$s = $db->query("SELECT COUNT(*) n FROM llx_product WHERE pmp > 0 AND price > 0 AND pmp >= price - 0.005")->fetch_assoc();
printf("\nосталось товаров с себестоимостью не ниже цены: %s\n", $s['n']);
$q = $db->query("SELECT ref, label, price, pmp FROM llx_product
                 WHERE pmp > 0 AND price > 0 AND pmp >= price - 0.005 ORDER BY pmp/price DESC");
while ($x = $q->fetch_assoc())
    printf("  %-16s %-34s цена %8.2f себест %8.2f\n", $x['ref'], mb_substr($x['label'], 0, 34), $x['price'], $x['pmp']);
