<?php
/**
 * Доходы за период — выгрузка в Excel (04.09.2026). Зеркало household_excel.php, тот же порядок
 * разделов, что на экране (сначала итоги по источникам, потом все поступления).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';
require_once __DIR__ . '/includes/xls_helper.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-t');

$report = income_report($from, $to);

xls_send_headers('Income_' . $from . '_' . $to . '.xls', 'Доходы_' . $from . '_' . $to . '.xls');
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

 <Worksheet ss:Name="Доходы">
  <Table>
   <Column ss:Width="95"/>
   <Column ss:Width="200"/>
   <Column ss:Width="95"/>
   <Column ss:Width="170"/>
   <Column ss:Width="240"/>
   <Column ss:Width="110"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — прочие доходы', 5) ?></Row>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Период: ' . date('d.m.Y', strtotime($from)) . ' — ' . date('d.m.Y', strtotime($to)), 5) ?></Row>
   <Row><?= xls_cell_str('Cell', 'Деньги, поступившие не от продажи товара: солнечные батареи, аренда, услуги и работы и т.п.', 5) ?></Row>
   <Row/>

   <Row><?= xls_cell_str('Label', 'Всего за период, $') ?><?= xls_cell_num('MoneyBold', number_format($report['total'], 2, '.', '')) ?></Row>
   <Row/>

   <Row ss:Height="18"><?= xls_cell_str('SubTitle', 'По источникам', 5) ?></Row>
   <Row>
    <?= xls_cell_str('Label', 'Источник') ?><?= xls_cell_str('Label', 'Поступлений') ?>
    <?= xls_cell_str('Label', 'Сумма, $') ?><?= xls_cell_str('Label', 'Доля, %') ?>
   </Row>
   <?php if (empty($report['by_source'])): ?>
   <Row><?= xls_cell_str('Cell', 'За этот период доходов не было.', 5) ?></Row>
   <?php endif; ?>
   <?php foreach ($report['by_source'] as $s): ?>
   <?php $share = $report['total'] > 0 ? ((float)$s['total'] / $report['total'] * 100) : 0; ?>
   <Row>
    <?= xls_cell_str('Cell', $s['name']) ?>
    <?= xls_cell_num('CellCenter', (int)$s['cnt']) ?>
    <?= xls_cell_num('MoneyBold', number_format((float)$s['total'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format($share, 1, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <Row/>

   <Row ss:Height="18"><?= xls_cell_str('SubTitle', 'Все поступления за период', 5) ?></Row>
   <Row>
    <?= xls_cell_str('Label', 'Дата') ?><?= xls_cell_str('Label', 'Источник') ?><?= xls_cell_str('Label', 'Сумма, $') ?>
    <?= xls_cell_str('Label', 'В валюте счёта') ?><?= xls_cell_str('Label', 'Комментарий') ?><?= xls_cell_str('Label', 'Кто') ?>
   </Row>
   <?php foreach ($report['rows'] as $i): ?>
   <Row>
    <?= xls_cell_str('Cell', date('d.m.Y', strtotime($i['income_date']))) ?>
    <?= xls_cell_str('Cell', $i['source_name']) ?>
    <?= xls_cell_num('Money', number_format((float)$i['amount_usd'], 2, '.', '')) ?>
    <?php
      $nativeText = '';
      if (!empty($i['native_currency']) && $i['native_currency'] !== 'USD') {
          $nativeText = number_format((float)$i['native_amount'], 0, '.', ' ') . ' ' . $i['native_currency']
              . ' по курсу ' . rtrim(rtrim(number_format((float)$i['rate'], 2, '.', ''), '0'), '.');
      }
    ?>
    <?= xls_cell_str('Cell', $nativeText) ?>
    <?= xls_cell_str('Cell', $i['comment'] ?? '') ?>
    <?= xls_cell_str('Cell', $i['who'] ?? '') ?>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
