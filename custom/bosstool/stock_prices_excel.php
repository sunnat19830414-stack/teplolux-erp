<?php
/**
 * «Склад: цены» — выгрузка в Excel. Тот же SpreadsheetML, что и у остальных выгрузок проекта
 * (includes/xls_helper.php), без сторонних библиотек. Колонки и порядок — как на экране, чтобы
 * файл и страница не расходились.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stock_lookup.php';
require_once __DIR__ . '/includes/xls_helper.php';

$dirs = visible_directions($cfg);
$filters = [
    'ref'        => trim($_GET['ref'] ?? ''),
    'label'      => trim($_GET['label'] ?? ''),
    'supplier'   => (int)($_GET['supplier'] ?? 0),
    'stock_from' => trim($_GET['stock_from'] ?? ''),
    'stock_to'   => trim($_GET['stock_to'] ?? ''),
    'only_stock' => !empty($_GET['only_stock']),
];

$rows = stock_price_rows($dirs, $filters, 100000);
$stockValue = 0.0;
foreach ($rows as $r) $stockValue += $r['cost'] * $r['stock'];

xls_send_headers('Stock_prices_' . date('Y-m-d') . '.xls', 'Склад_цены_' . date('Y-m-d') . '.xls');
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

 <Worksheet ss:Name="Склад — цены">
  <Table>
   <Column ss:Width="110"/><Column ss:Width="300"/><Column ss:Width="160"/>
   <Column ss:Width="80"/><Column ss:Width="60"/><Column ss:Width="90"/>
   <Column ss:Width="95"/><Column ss:Width="95"/><Column ss:Width="70"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — склад: цены и себестоимость', 8) ?></Row>
   <Row><?= xls_cell_str('SubTitle', 'На ' . date('d.m.Y H:i')
        . (count($dirs) === 1 ? ' · направление ' . ($cfg['directions'][$dirs[0]] ?? '') : ''), 8) ?></Row>
   <Row/>
   <Row>
    <?= xls_cell_str('Label', 'Товаров') ?><?= xls_cell_num('MoneyBold', count($rows)) ?>
   </Row>
   <Row>
    <?= xls_cell_str('Label', 'Стоимость остатков, $') ?><?= xls_cell_num('MoneyBold', number_format($stockValue, 2, '.', '')) ?>
   </Row>
   <Row/>

   <Row>
    <?= xls_cell_str('Header', 'Артикул') ?><?= xls_cell_str('Header', 'Наименование') ?>
    <?= xls_cell_str('Header', 'Поставщик') ?><?= xls_cell_str('Header', 'Заводская') ?>
    <?= xls_cell_str('Header', 'Валюта') ?><?= xls_cell_str('Header', 'Заводская, $') ?>
    <?= xls_cell_str('Header', 'Себестоимость, $') ?><?= xls_cell_str('Header', 'Цена продажи, $') ?>
    <?= xls_cell_str('Header', 'Остаток') ?>
   </Row>

   <?php foreach ($rows as $r): ?>
   <Row>
    <?= xls_cell_str('Cell', $r['ref']) ?>
    <?= xls_cell_str('Cell', $r['label']) ?>
    <?= xls_cell_str('Cell', $r['supplier']) ?>
    <?= $r['factory_native'] > 0
          ? xls_cell_num('Money', number_format($r['factory_native'], 4, '.', ''))
          : xls_cell_str('Cell', '') ?>
    <?= xls_cell_str('CellCenter', $r['factory_cur']) ?>
    <?= $r['factory_usd'] > 0
          ? xls_cell_num('Money', number_format($r['factory_usd'], 4, '.', ''))
          : xls_cell_str('Cell', '') ?>
    <?= $r['cost'] > 0 ? xls_cell_num('Money', number_format($r['cost'], 4, '.', '')) : xls_cell_str('Cell', '') ?>
    <?= $r['sale'] > 0 ? xls_cell_num('Money', number_format($r['sale'], 4, '.', '')) : xls_cell_str('Cell', '') ?>
    <?= xls_cell_num('CellCenter', rtrim(rtrim(number_format($r['stock'], 3, '.', ''), '0'), '.') ?: '0') ?>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
