<?php
/**
 * Выгрузка выписки по поставщику в Excel (топ-5 пункт 4, 02.09.2026) — тот же принцип SpreadsheetML,
 * что и у TeplouxKassa (includes/xls_helper.php, портирован без изменений).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/supplier_statement.php';

$socId = (int)($_GET['supplier_id'] ?? 0);
if (!$socId) {
    http_response_code(400);
    die('Не указан поставщик.');
}

$soc = $api->getThirdparty($socId);
if (!is_array($soc)) {
    http_response_code(404);
    die('Поставщик не найден.');
}
$supplierName = $soc['name'] ?? $soc['nom'] ?? '';

$outstanding = $api->getSupplierOutstanding($socId);
$rows = build_supplier_statement($api, $socId);
$safeName = preg_replace('/[^A-Za-z0-9_-]/', '', $supplierName) ?: 'supplier';

xls_send_headers('Statement_' . $safeName . '.xls', 'Выписка_' . $supplierName . '.xls');
?>
<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
<?= xls_common_styles() ?>
 </Styles>

 <Worksheet ss:Name="Выписка">
  <Table>
   <Column ss:Width="90"/>
   <Column ss:Width="180"/>
   <Column ss:Width="140"/>
   <Column ss:Width="90"/>
   <Column ss:Width="90"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Закупки — Теплолюкс', 4) ?></Row>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Выписка по поставщику: ' . $supplierName, 4) ?></Row>
   <Row/>
   <Row>
    <?= xls_cell_str('Label', $outstanding['opened'] > 0 ? 'Мы должны поставщику:' : 'Предоплата/переплата:') ?>
    <?= xls_cell_num('MoneyBold', number_format(abs($outstanding['opened']), 2, '.', '')) ?>
   </Row>
   <Row/>

   <Row>
    <?= xls_cell_str('Label', 'Дата') ?><?= xls_cell_str('Label', 'Документ') ?><?= xls_cell_str('Label', '№') ?><?= xls_cell_str('Label', 'Сумма, $') ?><?= xls_cell_str('Label', 'Сальдо, $') ?>
   </Row>
   <?php if (empty($rows)): ?>
   <Row><?= xls_cell_str('Cell', 'Пока нет ни одного счёта/оплаты.', 5) ?></Row>
   <?php endif; ?>
   <?php foreach ($rows as $r): ?>
   <Row>
    <?= xls_cell_str('Cell', $r['date'] ? date('d.m.Y', $r['date']) : '') ?>
    <?= xls_cell_str('Cell', $r['kind_label']) ?>
    <?= xls_cell_str('Cell', $r['ref'] . ($r['ref_supplier'] ? ' (' . $r['ref_supplier'] . ')' : '')) ?>
    <?= xls_cell_num('Money', number_format($r['amount'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format($r['balance'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
