<?php
/**
 * Выгрузка сменного отчёта кассира в Excel — см. includes/shift_report.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/shift_report.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$report = build_shift_report($api, $cfg, $date);

xls_send_headers('Shift_' . $date . '.xls', 'Смена_' . $date . '.xls');
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
 <Worksheet ss:Name="Смена">
  <Table>
   <Column ss:Width="220"/>
   <Column ss:Width="120"/>
   <Column ss:Width="120"/>
   <Column ss:Width="120"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — ' . $cfg['direction_label'], 3) ?></Row>
   <Row/>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Сменный отчёт кассира за ' . date('d.m.Y', strtotime($date)), 3) ?></Row>
   <Row/>
   <Row><?= xls_cell_str('Header', 'Товар', 0) ?><?= xls_cell_str('Header', 'Сумма, $', 0) ?><?= xls_cell_str('Header', '', 1) ?></Row>
   <Row><?= xls_cell_str('Cell', 'Продано (' . $report['sale_count'] . ' продаж)') ?><?= xls_cell_num('Money', number_format($report['sold'], 2, '.', '')) ?><?= xls_cell_str('Cell', '') ?></Row>
   <Row><?= xls_cell_str('Cell', '— из них в долг') ?><?= xls_cell_num('Money', number_format($report['on_credit'], 2, '.', '')) ?><?= xls_cell_str('Cell', '') ?></Row>
   <Row><?= xls_cell_str('Cell', 'Возвращено (' . $report['return_count'] . ' возврат.)') ?><?= xls_cell_num('Money', number_format($report['returned'], 2, '.', '')) ?><?= xls_cell_str('Cell', '') ?></Row>
   <Row/>
   <Row><?= xls_cell_str('Header', 'Деньги (реальное движение за день)') ?><?= xls_cell_str('Header', 'Наличные') ?><?= xls_cell_str('Header', 'Карта/QR/перевод') ?></Row>
   <Row>
    <?= xls_cell_str('Cell', 'Принято') ?>
    <?= xls_cell_num('Money', number_format($report['money_in']['cash'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format($report['money_in']['electronic'], 2, '.', '')) ?>
   </Row>
   <Row>
    <?= xls_cell_str('Cell', 'Выдано') ?>
    <?= xls_cell_num('Money', number_format($report['money_out']['cash'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format($report['money_out']['electronic'], 2, '.', '')) ?>
   </Row>
   <Row/>
   <?php if ($report['cash_balance_now'] !== null && $date === date('Y-m-d')): ?>
   <Row><?= xls_cell_str('TotalLabel', 'Наличная касса сейчас:', 1) ?><?= xls_cell_num('MoneyBold', number_format($report['cash_balance_now'], 2, '.', '')) ?></Row>
   <?php endif; ?>
  </Table>
 </Worksheet>
</Workbook>
