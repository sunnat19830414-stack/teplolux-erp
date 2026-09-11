<?php
/**
 * Очистка базы от тестовых операций (11.09.2026, решение пользователя).
 *   - документы и деньги: удалить ВСЁ (продажи, оплаты, заказы поставщикам, приёмки, счета и оплаты
 *     поставщиков, рейсы, расходы, рекламации, заявки, передачи, проводки) — счета и кассы в ноль;
 *   - остатки склада: НЕ трогать (пользователь даст новые данные);
 *   - себестоимость: вернуть к ночной копии 11.09 03:30 (загрузка из SAP + правки 10.09, без приходов 11.09);
 *   - 16 тестовых контрагентов: удалить;
 *   - закупочные цены ALTHEA, поставленные тестами: откатить по журналу.
 * Резервная копия перед очисткой: C:\DolibarrBackup\dolibarr_20260911_182743.sql
 * Запуск: php purge_test_data.php [--apply]
 */
$apply = in_array('--apply', $argv, true);
$db = new mysqli('localhost', 'root', 'fhjmKl1nINU3uxcgP5DFi4Tr', 'dolibarr'); $db->set_charset('utf8mb4');
$has = fn($t) => $db->query("SHOW TABLES LIKE '$t'")->num_rows > 0;
$testSoc = '1,493,495,496,497,498,502,503,507,508,509,510,511,512,513,514';
$chk = $db->query("SELECT COUNT(*) n FROM llx_societe WHERE rowid IN ($testSoc) AND (nom LIKE '%ТЕСТ%' OR nom LIKE '%не использовать%')")->fetch_assoc()['n'];
if ((int)$chk !== 16) exit("список тестовых контрагентов не сходится ($chk из 16) — остановлено\n");

$steps = [
  // продажи
  "DELETE FROM llx_paiement_facture",
  "DELETE FROM llx_paiement",
  "DELETE FROM llx_facturedet_extrafields",
  "DELETE FROM llx_facturedet",
  "DELETE FROM llx_facture_extrafields",
  "DELETE FROM llx_facture",
  "DELETE FROM llx_societe_remise_except",
  // закупки
  "DELETE FROM llx_paiementfourn_facturefourn",
  "DELETE FROM llx_paiementfourn",
  "DELETE FROM llx_facture_fourn_det_extrafields",
  "DELETE FROM llx_facture_fourn_det",
  "DELETE FROM llx_facture_fourn_extrafields",
  "DELETE FROM llx_facture_fourn",
  "DELETE FROM llx_receptiondet_batch_extrafields",
  "DELETE FROM llx_receptiondet_batch",
  "DELETE FROM llx_reception_extrafields",
  "DELETE FROM llx_reception",
  "DELETE FROM llx_commande_fournisseurdet_extrafields",
  "DELETE FROM llx_commande_fournisseurdet",
  "DELETE FROM llx_commande_fournisseur_extrafields",
  "DELETE FROM llx_commande_fournisseur_log",
  "DELETE FROM llx_commande_fournisseur",
  // деньги
  "DELETE FROM llx_bank_url",
  "DELETE FROM llx_bank_class",
  "DELETE FROM llx_bank",
  // свои таблицы операций
  "DELETE FROM llx_boss_request_line", "DELETE FROM llx_boss_request",
  "DELETE FROM llx_brand_discount_log", "DELETE FROM llx_carrier_payment", "DELETE FROM llx_draft_order",
  "DELETE FROM llx_nodirtool_cash_ack", "DELETE FROM llx_nt_claim", "DELETE FROM llx_nt_price_review",
  "DELETE FROM llx_nt_shipment", "DELETE FROM llx_supplier_landed_baseline", "DELETE FROM llx_supplier_landed_result",
  "DELETE FROM llx_supplier_logistics_expense", "DELETE FROM llx_supplier_shipment_batch_order", "DELETE FROM llx_supplier_shipment_batch",
  "DELETE FROM llx_nt_cash_handover", "DELETE FROM llx_nt_owner_move", "DELETE FROM llx_nt_payroll_entry",
  "DELETE FROM llx_nt_household_expense", "DELETE FROM llx_nt_income", "DELETE FROM llx_nt_mail_log",
  // связи и события по удалённым документам
  "DELETE FROM llx_element_element WHERE sourcetype IN ('facture','invoice_supplier','order_supplier','commande_fournisseur','reception','facture_fourn') OR targettype IN ('facture','invoice_supplier','order_supplier','commande_fournisseur','reception','facture_fourn')",
  "DELETE r FROM llx_actioncomm_resources r JOIN llx_actioncomm a ON a.id = r.fk_actioncomm WHERE a.elementtype IN ('invoice','invoice_supplier','order_supplier','reception') OR a.fk_soc IN ($testSoc)",
  "DELETE FROM llx_actioncomm_extrafields WHERE fk_object IN (SELECT id FROM llx_actioncomm WHERE elementtype IN ('invoice','invoice_supplier','order_supplier','reception') OR fk_soc IN ($testSoc))",
  "DELETE FROM llx_actioncomm WHERE elementtype IN ('invoice','invoice_supplier','order_supplier','reception') OR fk_soc IN ($testSoc)",
  "DELETE FROM llx_ecm_files WHERE filepath LIKE 'facture/%' OR filepath LIKE 'fournisseur/%' OR filepath LIKE 'reception/%'",
  // тестовые контрагенты
  "DELETE FROM llx_societe_extrafields WHERE fk_object IN ($testSoc)",
  "DELETE FROM llx_societe_commerciaux WHERE fk_soc IN ($testSoc)",
  "DELETE FROM llx_societe_rib WHERE fk_soc IN ($testSoc)",
  "DELETE FROM llx_societe_account WHERE fk_soc IN ($testSoc)",
  "DELETE FROM llx_societe_prices WHERE fk_soc IN ($testSoc)",
  "DELETE FROM llx_societe_remise WHERE fk_soc IN ($testSoc)",
  "DELETE FROM llx_product_customer_price WHERE fk_soc IN ($testSoc)",
  "DELETE FROM llx_ecm_files WHERE src_object_type = 'societe' AND src_object_id IN ($testSoc)",
  "DELETE FROM llx_societe WHERE rowid IN ($testSoc)",
  // себестоимость — к ночной копии 11.09 03:30
  "UPDATE llx_product p JOIN dolibarr_snap0330.llx_product s ON s.rowid = p.rowid SET p.pmp = s.pmp, p.cost_price = s.cost_price
     WHERE ABS(p.pmp - s.pmp) > 0.00005 OR ABS(COALESCE(p.cost_price,0) - COALESCE(s.cost_price,0)) > 0.00005",
];

echo $apply ? "=== ОЧИСТКА ===\n" : "=== пробный прогон (ничего не меняется) ===\n";
// Связи Dolibarr (кредит-нота → исходный счёт и т.п.) мешают удалять таблицу целиком по порядку.
// Удаляем операции ЦЕЛИКОМ, поэтому проверку связей выключаем на время, а после — сами проверяем,
// что на удалённых контрагентов больше никто не ссылается.
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$db->begin_transaction();
try {
    foreach ($steps as $sql) {
        if (preg_match('/(?:FROM|UPDATE)\s+(?:\w+\s+)?(llx_\w+)/', $sql, $m) && !$has($m[1])) continue;
        $db->query($sql);
        $name = preg_replace('/\s+/', ' ', mb_substr($sql, 0, 90));
        if ($db->affected_rows) printf("  %6d  %s\n", $db->affected_rows, $name);
    }
    // ALTHEA: закупочные цены, поставленные тестами, — к состоянию до первого изменения в журнале
    $alth = (int)$db->query("SELECT rowid FROM llx_societe WHERE nom = 'ALTHEA'")->fetch_assoc()['rowid'];
    $r = $db->query("SELECT h.fk_product, h.old_price FROM llx_supplier_price_history h
                     JOIN (SELECT fk_product, MIN(rowid) m FROM llx_supplier_price_history WHERE fk_supplier = $alth GROUP BY fk_product) f ON f.m = h.rowid");
    while ($x = $r->fetch_assoc()) {
        $pid = (int)$x['fk_product'];
        if ($x['old_price'] === null) {
            $db->query("DELETE FROM llx_product_fournisseur_price WHERE fk_soc = $alth AND fk_product = $pid");
            printf("  ALTHEA #%d: тестовая закупочная цена удалена (%d)\n", $pid, $db->affected_rows);
        } else {
            $v = (float)$x['old_price'];
            $db->query("UPDATE llx_product_fournisseur_price SET price = $v, unitprice = $v, multicurrency_price = $v, multicurrency_unitprice = $v WHERE fk_soc = $alth AND fk_product = $pid");
            printf("  ALTHEA #%d: закупочная цена возвращена к %s (%d)\n", $pid, $v, $db->affected_rows);
        }
    }
    // контроль: ссылки на удалённых контрагентов из любых таблиц
    $refs = $db->query("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = 'dolibarr' AND REFERENCED_TABLE_NAME = 'llx_societe'")->fetch_all(MYSQLI_ASSOC);
    foreach ([['llx_socpeople', 'fk_soc'], ['llx_product_fournisseur_price', 'fk_soc'], ['llx_stock_mouvement', 'fk_soc']] as [$t, $c]) $refs[] = ['t' => $t, 'c' => $c];
    $orph = [];
    foreach ($refs as $x) {
        if (!$db->query("SHOW COLUMNS FROM `{$x['t']}` LIKE '{$x['c']}'")->num_rows) continue;
        $n = (int)$db->query("SELECT COUNT(*) FROM `{$x['t']}` WHERE `{$x['c']}` IN ($testSoc)")->fetch_row()[0];
        if ($n) $orph[] = "{$x['t']}.{$x['c']}: $n";
    }
    if ($orph) throw new RuntimeException('на удалённых контрагентов ещё ссылаются: ' . implode(', ', $orph));
    echo "  контроль ссылок на удалённых контрагентов: чисто (" . count($refs) . " связей проверено)\n";
    if ($apply) { $db->commit(); echo "база: изменения записаны\n"; }
    else { $db->rollback(); echo "база: откат (пробный прогон)\n"; }
} catch (Throwable $e) {
    $db->rollback();
    $db->query("SET FOREIGN_KEY_CHECKS = 1");
    exit("ОШИБКА, ничего не изменено: " . $e->getMessage() . "\n");
}
$db->query("SET FOREIGN_KEY_CHECKS = 1");

if ($apply) {
    // файлы удалённых документов
    $n = 0; $fail = [];
    foreach (['facture', 'fournisseur/commande', 'fournisseur/facture', 'reception'] as $d) {
        $path = 'C:/Dolibarr/documents/' . $d;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) { @rmdir($f->getPathname()); continue; }
            if (@unlink($f->getPathname())) $n++; else $fail[] = $f->getPathname();
        }
    }
    foreach (explode(',', $testSoc) as $sid) {
        $p = "C:/Dolibarr/documents/societe/$sid";
        if (is_dir($p)) { foreach (glob("$p/*") as $f) { if (@unlink($f)) $n++; else $fail[] = $f; } @rmdir($p); }
    }
    echo "файлы: удалено $n" . ($fail ? ', не удалось ' . count($fail) . ' (права IIS): ' . implode('; ', array_slice($fail, 0, 5)) : '') . "\n";
}
