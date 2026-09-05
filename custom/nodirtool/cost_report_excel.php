<?php
/**
 * Выгрузка "Себестоимость по товарам" в Excel — тот же принцип SpreadsheetML, что и у остальных
 * экспортов проекта (includes/xls_helper.php).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/logistics.php';

$rows = logistics_get_landed_report();

$productIds = array_values(array_unique(array_map(fn($r) => (int)$r['fk_product'], $rows)));
$productLabel = [];
$productPmp = []; // BUG-N3 (внешний отчёт, 02.09.2026) — та же колонка, что и на экране cost_report.php
foreach ($productIds as $pid) {
    $p = $api->getProduct($pid);
    $productLabel[$pid] = is_array($p) ? trim(($p['ref'] ?? "#$pid") . ' — ' . ($p['label'] ?? '')) : "#$pid";
    $productPmp[$pid] = is_array($p) ? (float)($p['pmp'] ?? 0) : 0.0;
}
$orderLabel = [];
foreach ($rows as $r) {
    if ($r['scope_type'] === 'order' && !isset($orderLabel[$r['scope_id']])) {
        $o = $api->getSupplierOrder((int)$r['scope_id']);
        $supplierName = '';
        if (is_array($o) && !empty($o['socid'])) {
            $soc = $api->getThirdparty((int)$o['socid']);
            $supplierName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';
        }
        $orderLabel[$r['scope_id']] = (is_array($o) ? ($o['ref'] ?? "#{$r['scope_id']}") : "#{$r['scope_id']}") . ($supplierName ? " ({$supplierName})" : '');
    }
}

xls_send_headers('Cost_report.xls', 'Себестоимость_по_товарам.xls');
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

 <Worksheet ss:Name="Себестоимость">
  <Table>
   <Column ss:Width="220"/>
   <Column ss:Width="180"/>
   <Column ss:Width="75"/>
   <Column ss:Width="95"/>
   <Column ss:Width="90"/>
   <Column ss:Width="260"/>
   <Column ss:Width="105"/>
   <Column ss:Width="130"/>
   <Column ss:Width="110"/>

   <?php // K-4 / N-2 / N-1 (внешняя приёмка, 03.09.2026): те же колонки и те же формулировки, что на
         // экране — без слов "pmp"/"landed"/"Dolibarr", плюс колонка "Принято" при недопоставке. ?>
   <Row ss:Height="22"><?= xls_cell_str('Title', 'Закупки — Теплолюкс', 8) ?></Row>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Себестоимость по товарам', 8) ?></Row>
   <Row><?= xls_cell_str('Cell', 'Смотреть колонку "Себестоимость" — цена товара из этой поставки вместе с её расходами. "Средняя по всем поставкам" усреднена по всему остатку товара на складе и для назначения цены не годится. Если принято меньше, чем заказано, себестоимость занижена: часть расходов приходится на непривезённое.', 8) ?></Row>
   <Row/>

   <Row>
    <?= xls_cell_str('Label', 'Товар') ?><?= xls_cell_str('Label', 'Поставка') ?><?= xls_cell_str('Label', 'Заказано') ?><?= xls_cell_str('Label', 'Принято') ?>
    <?= xls_cell_str('Label', 'Цена поставщика, $/шт') ?><?= xls_cell_str('Label', 'Логистика всей поставки') ?>
    <?= xls_cell_str('Label', 'Себестоимость, $/шт') ?><?= xls_cell_str('Label', 'Средняя по всем поставкам') ?><?= xls_cell_str('Label', 'Посчитано') ?>
   </Row>
   <?php if (empty($rows)): ?>
   <Row><?= xls_cell_str('Cell', 'Пока ни одной поставки с посчитанной себестоимостью нет.', 9) ?></Row>
   <?php endif; ?>
   <?php foreach ($rows as $r): ?>
   <?php
     $pid = (int)$r['fk_product'];
     $scopeLabel = $r['scope_type'] === 'batch'
       ? 'Партия «' . ($r['scope_label'] ?? ('#' . $r['scope_id'])) . '»'
       : 'Заказ ' . ($orderLabel[$r['scope_id']] ?? ('#' . $r['scope_id']));
     $expenseParts = [];
     foreach ($r['expenses'] as $type => $amt) {
         if ($amt > 0.01) $expenseParts[] = (LOGISTICS_EXPENSE_TYPES[$type] ?? $type) . ': ' . number_format($amt, 2, '.', '') . '$';
     }
   ?>
   <Row>
    <?= xls_cell_str('Cell', $productLabel[$pid] ?? "#$pid") ?>
    <?= xls_cell_str('Cell', $scopeLabel) ?>
    <?= xls_cell_num('CellCenter', rtrim(rtrim(number_format((float)$r['qty'], 3, '.', ''), '0'), '.')) ?>
    <?php // N-1: сколько реально принято; при недопоставке помечаем ячейку, чтобы было видно в файле. ?>
    <?= xls_cell_num(!empty($r['is_partial']) ? 'PartialQty' : 'CellCenter', rtrim(rtrim(number_format((float)($r['received_qty'] ?? 0), 3, '.', ''), '0'), '.')) ?>
    <?= xls_cell_num('Money', number_format((float)$r['raw_price_per_unit'], 4, '.', '')) ?>
    <?php // UX-N3 (внешний отчёт, 02.09.2026): каждый вид расхода на своей строке внутри ячейки
          // (CellWrap, includes/xls_helper.php), не одна длинная строка через запятую. ?>
    <?= xls_cell_str('CellWrap', $expenseParts ? implode("\n", $expenseParts) : '') ?>
    <?= xls_cell_num('MoneyBold', number_format((float)$r['landed_cost_per_unit'], 4, '.', '')) ?>
    <?= xls_cell_num('Money', number_format($productPmp[$pid] ?? 0, 4, '.', '')) ?>
    <?= xls_cell_str('Cell', substr($r['computed_at'], 0, 16)) ?>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
