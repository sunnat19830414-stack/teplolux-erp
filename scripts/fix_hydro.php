<?php
/**
 * Себестоимость гидроблока DE DIETRICH `7730248` — по ПОСЛЕДНЕЙ партии, а не по среднему.
 *
 * В SAP себестоимость 118.47 — это скользящее среднее по двум приходам:
 *   13.05.2025  4 шт по 128.03 USD (115.23 EUR) + доп.расходы 152.99
 *   19.12.2025  4 шт по  55.16 USD ( 47.44 EUR) + доп.расходы  62.02   ← цена упала вдвое
 * Среднее смешивает дорогую старую партию с дешёвой новой и потому выглядит выше цены продажи.
 *
 * Берём последнюю партию: 55.1628 + 62.02/4 = 70.67 за штуку — с доставкой, как и положено.
 * Остаток в Dolibarr нулевой, поэтому оценка запаса не затрагивается: цифра нужна только
 * для расчёта наценки, и по последней закупке она честнее среднего.
 *
 * ⚠️ Насос `60182217H` НЕ трогаем: там себестоимость 178.69 верна и включает доставку
 * (приход 135.78 + доп.расходы 45.92 на штуку). Он действительно продаётся ниже себестоимости
 * при цене 160 — это вопрос к цене, а не к данным.
 */
$apply = in_array('--apply', $argv, true);
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();

$LAST_BATCH_UNIT = 55.1628;          // цена прихода 19.12.2025, USD за штуку
$LAST_BATCH_FREIGHT = 62.02 / 4;     // доп.расходы той же партии на штуку
$NEW = round($LAST_BATCH_UNIT + $LAST_BATCH_FREIGHT, 4);

$p = $db->query("SELECT rowid, ref, label, price, pmp, stock FROM llx_product WHERE ref = '7730248'")->fetch_assoc();
if (!$p) { fwrite(STDERR, "товар не найден\n"); exit(1); }
printf("%s — %s\n", $p['ref'], mb_substr($p['label'], 0, 50));
printf("  цена продажи   %8.2f\n", $p['price']);
printf("  себестоимость  %8.2f  (среднее по двум партиям)\n", $p['pmp']);
printf("  станет         %8.2f  = %.4f приход + %.4f доставка\n", $NEW, $LAST_BATCH_UNIT, $LAST_BATCH_FREIGHT);
printf("  наценка        была %.0f%%, станет %.0f%%\n",
    ($p['price'] / $p['pmp'] - 1) * 100, ($p['price'] / $NEW - 1) * 100);
printf("  остаток        %s\n", number_format((float)$p['stock'], 0));

if (!$apply) { echo "\n(пробный прогон)\n"; exit; }

file_put_contents('C:\PHPTMP\hydro_backup_' . date('Ymd_His') . '.json',
    json_encode(['rowid' => (int)$p['rowid'], 'ref' => $p['ref'], 'pmp' => (float)$p['pmp']],
        JSON_UNESCAPED_UNICODE));

$db->begin_transaction();
try {
    $id = (int)$p['rowid'];
    $st = $db->prepare("UPDATE llx_product SET pmp = ?, tms = NOW() WHERE rowid = ?");
    $st->bind_param('di', $NEW, $id); $st->execute(); $st->close();
    $log = $db->prepare("INSERT INTO llx_nt_product_log (fk_product, field, old_value, new_value, who, tool, datec)
                         VALUES (?,?,?,?,?,?,NOW())");
    $old = number_format((float)$p['pmp'], 2, '.', ''); $new = number_format($NEW, 2, '.', '');
    $field = 'Себестоимость'; $who = 'последняя партия SAP 19.12.2025'; $tool = 'импорт';
    $log->bind_param('isssss', $id, $field, $old, $new, $who, $tool);
    $log->execute(); $log->close();
    $db->commit();
    echo "\nисправлено\n";
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, "ОТКАТ: " . $e->getMessage() . "\n"); exit(1);
}

$q = $db->query("SELECT ref, label, price, pmp FROM llx_product
                 WHERE pmp > 0 AND price > 0 AND pmp >= price - 0.005 ORDER BY pmp/price DESC");
echo "\nосталось товаров с себестоимостью не ниже цены:\n";
while ($x = $q->fetch_assoc())
    printf("  %-16s %-34s цена %8.2f себест %8.2f\n", $x['ref'], mb_substr($x['label'], 0, 34), $x['price'], $x['pmp']);
