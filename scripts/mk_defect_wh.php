<?php
/**
 * Склады для брака (решение пользователя 11.09.2026, по просьбе Жамшида).
 * Бракованный товар принимается, но не на продажу: мы за него заплатили, по нему идёт рекламация.
 * Список складов-брака пишем в llx_const NT_DEFECT_WAREHOUSES — единый источник для всех трёх
 * инструментов: все места, где считается «годный остаток», исключают эти склады.
 */
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();
$want = ['08 Брак Жоми' => 'J', '09 Брак Турк' => 'T'];
$ids = [];
foreach ($want as $ref => $dir) {
    $r = $db->query("SELECT rowid FROM llx_entrepot WHERE ref = '" . $db->real_escape_string($ref) . "'")->fetch_assoc();
    if ($r) { $ids[$dir] = (int)$r['rowid']; echo "уже есть: $ref id={$r['rowid']}\n"; continue; }
    $desc = 'Брак при приёмке: товар принят, но НЕ для продажи. По каждой позиции заводится рекламация поставщику. Создан 11.09.2026.';
    $st = $db->prepare("INSERT INTO llx_entrepot (ref, datec, entity, description, lieu, town, fk_pays, statut, fk_user_author)
                        VALUES (?, NOW(), 1, ?, 'Брак', 'Tashkent', 230, 1, 1)");
    $st->bind_param('ss', $ref, $desc); $st->execute();
    $ids[$dir] = (int)$db->insert_id; $st->close();
    echo "создан: $ref id={$ids[$dir]}\n";
}
$v = $ids['J'] . ',' . $ids['T'];
$db->query("DELETE FROM llx_const WHERE name='NT_DEFECT_WAREHOUSES'");
$db->query("INSERT INTO llx_const (name,entity,value,type,visible,note,tms) VALUES
            ('NT_DEFECT_WAREHOUSES',1,'$v','chaine',0,'Склады брака: исключаются из годного остатка во всех инструментах',NOW())");
echo "NT_DEFECT_WAREHOUSES = $v\n";
file_put_contents('C:\PHPTMP\defect_wh.json', json_encode($ids));
