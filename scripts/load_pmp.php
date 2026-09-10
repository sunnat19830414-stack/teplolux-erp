<?php
/**
 * Себестоимость (pmp) из прошлогодней базы SAP TEPLOLUX_PROD (10.09.2026, по просьбе пользователя:
 * «цену и остатки оттуда брать не надо, себестоимость — можно»).
 *
 * ЧТО БЫЛО: pmp проставлен формулой «цена × 0.8» у 1017 товаров — это заглушка, а не факт.
 * Реальные данные показывают медиану 0.75 при разбросе 0.58…0.85, то есть формула систематически
 * врёт в обе стороны.
 *
 * ⚠️ НЕ ТРОГАЕМ 60 товаров из `llx_supplier_landed_result` — у них себестоимость посчитана
 * по реальной партии от 07.09.2026 с учётом фрахта. Она новее исторической и точнее: историческая
 * не знает про доставку. Затирать её было бы шагом назад.
 *
 * `pmp` через REST не задаётся — только прямым SQL (правило из справочника).
 *
 * Без аргументов — пробный прогон. С `-- --apply` — запись со снимком.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

$plan = json_decode(file_get_contents('C:\PHPTMP\sap_cost_plan.json'), true);
if (!$plan) { fwrite(STDERR, "нет плана\n"); exit(1); }

// себестоимость, посчитанная по факту приёмки — она главнее исторической
$keep = [];
$r = $db->query("SELECT DISTINCT fk_product FROM llx_supplier_landed_result");
while ($x = $r->fetch_assoc()) $keep[(int)$x['fk_product']] = true;

$write = []; $skipped = []; $bad = [];
foreach ($plan as $p) {
    $id = (int)$p['rowid'];
    $cost = (float)$p['cost'];
    if (isset($keep[$id])) { $skipped[] = $p; continue; }
    if ($cost <= 0) continue;
    // защита от бессмыслицы: себестоимость выше цены больше чем в полтора раза
    if ($p['price'] > 0 && $cost / $p['price'] > 1.5) { $bad[] = $p; continue; }
    $write[] = $p;
}

printf("сопоставлено с себестоимостью из SAP: %d\n", count($plan));
printf("  к записи                          : %d\n", count($write));
printf("  пропущено (есть себестоимость по факту приёмки): %d\n", count($skipped));
printf("  отбито (себестоимость выше цены в 1.5 раза)    : %d\n", count($bad));
foreach ($bad as $p) printf("      %s: цена %.2f, себест %.2f\n", $p['ref'], $p['price'], $p['cost']);

$was0 = count(array_filter($write, fn($p) => $p['pmp_now'] <= 0));
printf("\nиз них у %d себестоимости не было вовсе\n", $was0);
echo "\nпримеры изменений:\n";
foreach (array_slice($write, 0, 8) as $p)
    printf("  %-16s цена %8.2f | было %8.2f → станет %8.2f\n",
        $p['ref'], $p['price'], $p['pmp_now'], $p['cost']);

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

$bak = array_map(fn($p) => ['rowid' => $p['rowid'], 'ref' => $p['ref'], 'pmp' => $p['pmp_now']], $write);
$f = 'C:\PHPTMP\pmp_backup_' . date('Ymd_His') . '.json';
file_put_contents($f, json_encode($bak, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nснимок: $f\n";

$db->begin_transaction();
try {
    $st = $db->prepare("UPDATE llx_product SET pmp = ?, tms = NOW() WHERE rowid = ?");
    $n = 0;
    foreach ($write as $p) {
        $c = (float)$p['cost']; $id = (int)$p['rowid'];
        $st->bind_param('di', $c, $id);
        $st->execute(); $n++;
    }
    $st->close();
    $db->commit();
    printf("записано: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n");
    exit(1);
}

$s = $db->query("SELECT COUNT(*) n, SUM(COALESCE(pmp,0) > 0) with_pmp,
                        ROUND(AVG(CASE WHEN pmp > 0 AND price > 0 THEN pmp/price END), 3) k
                 FROM llx_product")->fetch_assoc();
printf("\nтоваров всего %s, с себестоимостью %s, среднее отношение себест/цена %s\n",
    $s['n'], $s['with_pmp'], $s['k']);
