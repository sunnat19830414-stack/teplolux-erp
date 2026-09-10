<?php
/**
 * Три уровня цен в Dolibarr (решение пользователя 10.09.2026):
 *
 *   уровень 1 — Дилерская  = то, что сейчас лежит в карточке как цена продажи (база)
 *   уровень 2 — Оптовая    = дилерская + 5%
 *   уровень 3 — Розничная  = дилерская + 20%
 *
 * ПОЧЕМУ УРОВЕНЬ 1 ИМЕННО ДИЛЕРСКАЯ, А НЕ РОЗНИЧНАЯ: `llx_product.price` — это всегда уровень 1,
 * и именно его читают все три наших инструмента (`$p['price']` из REST). Если положить в первый
 * уровень розничную, касса начнёт продавать дороже с той же секунды. Дилерская в первом уровне
 * означает, что СЕГОДНЯШНЕЕ поведение не меняется вообще: цена в кассе остаётся ровно та же.
 *
 * Всем контрагентам ставим `price_level = 1` по той же причине: пока никому уровень не назначен
 * вручную, все продолжают получать текущую цену. Оптовую и розничную можно раздавать клиентам
 * по одному, когда понадобится.
 *
 * ⚠️ Доп.поле `dealer_price` (залито из Bus.gdb) — ДРУГАЯ величина: там дилерская цена по данным
 * «Бизнеса», и у 64 товаров Ostendorf она на 20% ниже нынешней цены Dolibarr. Здесь мы её не
 * используем: пользователь определил дилерскую как «то, что сейчас в Dolibarr».
 *
 * Без аргументов — пробный прогон. С `-- --apply` — запись со снимком.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

const MARKUP = [1 => 0.00, 2 => 0.05, 3 => 0.20];
const LABELS = [1 => 'Дилерская', 2 => 'Оптовая', 3 => 'Розничная'];

$rows = [];
$r = $db->query("SELECT rowid, ref, price, price_ttc, tva_tx, price_base_type, price_min
                 FROM llx_product WHERE COALESCE(price,0) > 0");
while ($x = $r->fetch_assoc()) $rows[] = $x;

printf("товаров с ценой: %d\n", count($rows));
printf("уровни: 1 «%s» = как есть · 2 «%s» +%d%% · 3 «%s» +%d%%\n\n",
    LABELS[1], LABELS[2], MARKUP[2] * 100, LABELS[3], MARKUP[3] * 100);
echo "примеры пересчёта:\n";
foreach (array_slice($rows, 0, 6) as $p) {
    printf("  %-16s %8.2f → опт %8.2f → розн %8.2f\n", $p['ref'], $p['price'],
        round($p['price'] * 1.05, 2), round($p['price'] * 1.20, 2));
}

$existing = $db->query("SELECT price_level, COUNT(*) n FROM llx_product_price GROUP BY price_level");
echo "\nсейчас в истории цен:\n";
while ($x = $existing->fetch_assoc()) printf("  уровень %s: %s строк\n", $x['price_level'], $x['n']);

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

// снимок: настройки и уровни контрагентов — то, что меняем необратимо на вид
$bak = ['const' => [], 'societe_levels' => [], 'ts' => date('c')];
$r = $db->query("SELECT name, value FROM llx_const WHERE name LIKE 'PRODUIT_MULTIPRICES%'");
while ($x = $r->fetch_assoc()) $bak['const'][] = $x;
$r = $db->query("SELECT COALESCE(price_level,0) l, COUNT(*) n FROM llx_societe GROUP BY l");
while ($x = $r->fetch_assoc()) $bak['societe_levels'][] = $x;
$f = 'C:\PHPTMP\pricelevels_backup_' . date('Ymd_His') . '.json';
file_put_contents($f, json_encode($bak, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nснимок: $f\n";

$db->begin_transaction();
try {
    $setConst = function (string $name, string $value) use ($db) {
        $n = $db->real_escape_string($name); $v = $db->real_escape_string($value);
        $has = $db->query("SELECT rowid FROM llx_const WHERE name='$n' AND entity=1")->fetch_assoc();
        if ($has) $db->query("UPDATE llx_const SET value='$v', tms=NOW() WHERE name='$n' AND entity=1");
        else      $db->query("INSERT INTO llx_const (name,entity,value,type,visible,note,tms)
                              VALUES ('$n',1,'$v','chaine',0,'Три уровня цен, 10.09.2026',NOW())");
    };
    $setConst('PRODUIT_MULTIPRICES', '1');
    $setConst('PRODUIT_MULTIPRICES_LIMIT', '3');
    foreach (LABELS as $lvl => $lbl) $setConst('PRODUIT_MULTIPRICES_LABEL' . $lvl, $lbl);

    // уровни 2 и 3: чистим прежние (если запускается повторно) и пишем заново
    $db->query("DELETE FROM llx_product_price WHERE price_level IN (2,3)");
    $ins = $db->prepare("INSERT INTO llx_product_price
        (entity, tms, fk_product, date_price, price_level, price, price_ttc, price_min, price_min_ttc,
         price_base_type, tva_tx, recuperableonly, localtax1_tx, localtax2_tx, fk_user_author, tosell)
        VALUES (1, NOW(), ?, NOW(), ?, ?, ?, 0, 0, ?, ?, 0, 0, 0, 1, 1)");
    $n = 0;
    foreach ($rows as $p) {
        $base = (float)$p['price'];
        $vat  = (float)$p['tva_tx'];
        $type = $p['price_base_type'] ?: 'HT';
        foreach ([2, 3] as $lvl) {
            $val = round($base * (1 + MARKUP[$lvl]), 2);
            $ttc = round($val * (1 + $vat / 100), 2);
            $pid = (int)$p['rowid'];
            $ins->bind_param('iiddsd', $pid, $lvl, $val, $ttc, $type, $vat);
            $ins->execute();
            $n++;
        }
    }
    $ins->close();

    // все контрагенты — на первый уровень: сегодняшняя цена не меняется ни для кого
    $db->query("UPDATE llx_societe SET price_level = 1 WHERE COALESCE(price_level,0) = 0");

    $db->commit();
    printf("\nсоздано строк цен уровней 2 и 3: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n");
    exit(1);
}

$s = $db->query("SELECT price_level l, COUNT(*) n, ROUND(AVG(price),2) avg FROM llx_product_price GROUP BY l ORDER BY l");
echo "\nстало:\n";
while ($x = $s->fetch_assoc()) printf("  уровень %s (%s): %s строк, средняя %s\n",
    $x['l'], LABELS[$x['l']] ?? '?', $x['n'], $x['avg']);
$c = $db->query("SELECT COUNT(*) n FROM llx_societe WHERE price_level = 1")->fetch_assoc();
printf("контрагентов на первом уровне: %s\n", $c['n']);
