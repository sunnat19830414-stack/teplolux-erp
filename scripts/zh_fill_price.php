<?php
/**
 * 31 товар Жоми, у которых в Dolibarr нет цены продажи, а в базе «Бизнес» есть дилерская.
 * 19 из них лежат на складе (1106 шт) — сейчас их физически нельзя пробить на кассе.
 *
 * Ставим дилерскую в уровень 1 (по определению пользователя это и есть цена продажи),
 * уровни 2 и 3 считаем от неё: +5% и +20%.
 *
 * ⚠️ Закупочные цены из этой базы НЕ берём: у 70% позиций они равны дилерской, а у остальных
 * дают наценку 3% — как закупочные они негодны (пользователь предупредил об этом по базе Турк,
 * здесь то же самое).
 *
 * Без аргументов — пробный прогон. С `-- --apply` — запись со снимком.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

const MARKUP = [2 => 0.05, 3 => 0.20];

$data = json_decode(file_get_contents('C:\PHPTMP\zhomi_plan.json'), true);
$plan = $data['noprice'] ?? [];
if (!$plan) { fwrite(STDERR, "нечего заливать\n"); exit(1); }

printf("товаров без цены, к заполнению: %d\n", count($plan));
$stock = array_filter($plan, fn($p) => $p['stock_now'] > 0);
printf("из них на складе: %d (%s шт)\n\n", count($stock),
    number_format(array_sum(array_column($stock, 'stock_now')), 0, '.', ' '));
printf("%-16s %-38s %9s %9s %9s\n", 'артикул', 'товар', 'дилер', 'опт', 'розн');
foreach (array_slice($plan, 0, 10) as $p)
    printf("%-16s %-38s %9.2f %9.2f %9.2f\n", $p['ref'], mb_substr($p['label'], 0, 38),
        $p['p1'], round($p['p1'] * 1.05, 2), round($p['p1'] * 1.20, 2));

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

$f = 'C:\PHPTMP\zhprice_backup_' . date('Ymd_His') . '.json';
file_put_contents($f, json_encode(array_map(fn($p) => ['rowid' => $p['dol_id'], 'ref' => $p['ref'],
    'price' => $p['price']], $plan), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nснимок: $f\n";

$db->begin_transaction();
try {
    $upd = $db->prepare("UPDATE llx_product SET price = ?, price_ttc = ?, tms = NOW() WHERE rowid = ?");
    $del = $db->prepare("DELETE FROM llx_product_price WHERE fk_product = ? AND price_level IN (1,2,3)");
    $ins = $db->prepare("INSERT INTO llx_product_price
        (entity, tms, fk_product, date_price, price_level, price, price_ttc, price_min, price_min_ttc,
         price_base_type, tva_tx, recuperableonly, localtax1_tx, localtax2_tx, fk_user_author, tosell)
        VALUES (1, NOW(), ?, NOW(), ?, ?, ?, 0, 0, 'HT', 0, 0, 0, 0, 1, 1)");
    $log = $db->prepare("INSERT INTO llx_nt_product_log (fk_product, field, old_value, new_value, who, tool, datec)
                         VALUES (?,?,?,?,?,?,NOW())");
    $n = 0;
    foreach ($plan as $p) {
        $id = (int)$p['dol_id']; $base = round((float)$p['p1'], 2);
        $upd->bind_param('ddi', $base, $base, $id); $upd->execute();
        $del->bind_param('i', $id); $del->execute();
        foreach ([1 => 0.0, 2 => MARKUP[2], 3 => MARKUP[3]] as $lvl => $m) {
            $v = round($base * (1 + $m), 2);
            $ins->bind_param('iidd', $id, $lvl, $v, $v); $ins->execute();
        }
        $field = 'Цена продажи'; $old = '0.00'; $new = number_format($base, 2, '.', '');
        $who = 'база «Бизнес» Жоми'; $tool = 'импорт';
        $log->bind_param('isssss', $id, $field, $old, $new, $who, $tool);
        $log->execute();
        $n++;
    }
    $upd->close(); $del->close(); $ins->close(); $log->close();
    $db->commit();
    printf("заполнено: %d\n", $n);
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n"); exit(1);
}

$s = $db->query("SELECT COUNT(*) n FROM llx_product WHERE COALESCE(price,0) <= 0")->fetch_assoc();
$w = $db->query("SELECT COUNT(*) n FROM llx_product WHERE COALESCE(price,0) <= 0 AND COALESCE(stock,0) > 0")->fetch_assoc();
printf("\nтоваров без цены осталось: %s, из них на складе: %s\n", $s['n'], $w['n']);
