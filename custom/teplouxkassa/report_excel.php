<?php
/**
 * Выгрузка истории клиента (покупки/возвраты + оплаты) в Excel — ОДИН лист, разделы друг под другом
 * (специально не разносим по отдельным вкладкам листа — легко не заметить вторую вкладку и решить,
 * что данных там нет; на экране reports.php оба раздела тоже идут один под другим на одной странице).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/client_history.php';

$socid = (int)($_GET['client_id'] ?? 0);
if (!$socid) {
    http_response_code(400);
    die('Не указан клиент.');
}

// Проверка направления — так же, как в invoice_excel.php: клиент должен быть НАШЕГО направления.
$soc = $api->getThirdparty($socid);
$codeClient = is_array($soc) ? ($soc['code_client'] ?? '') : '';
if (stripos($codeClient, $cfg['ref_prefix']) !== 0) {
    http_response_code(403);
    die('Этот клиент относится к другому направлению.');
}
$clientName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';

$filters = report_filters_from_request($_GET);
$history = buildClientHistory($api, $socid, $filters);
$safeName = preg_replace('/[^A-Za-z0-9_-]/', '', $clientName) ?: 'client';

xls_send_headers(
    'History_' . $safeName . '.xls',
    'История_' . $clientName . '.xls'
);
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

 <Worksheet ss:Name="История клиента">
  <Table>
   <Column ss:Width="220"/>
   <Column ss:Width="120"/>
   <Column ss:Width="90"/>
   <Column ss:Width="90"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — ' . $cfg['direction_label'], 3) ?></Row>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Клиент: ' . $clientName, 3) ?></Row>
   <Row/>
   <Row>
    <?= xls_cell_str('Label', 'Куплено (по фильтру):') ?><?= xls_cell_num('Cell', number_format($history['summary']['purchased'], 2, '.', '')) ?>
    <?= xls_cell_str('Label', 'Возвращено (по фильтру):') ?><?= xls_cell_num('Cell', number_format($history['summary']['returned'], 2, '.', '')) ?>
   </Row>
   <Row>
    <?= xls_cell_str('Label', 'Оплачено (по фильтру):') ?><?= xls_cell_num('Cell', number_format($history['summary']['paid'], 2, '.', '')) ?>
    <?= xls_cell_str('Label', 'Долг на сегодня:') ?><?= xls_cell_num('Cell', number_format($history['summary']['debt'] ?? 0, 2, '.', '')) ?>
   </Row>
   <Row/>

   <Row><?= xls_cell_str('SubTitle', 'Сводная таблица по товарам', 5) ?></Row>
   <Row/>
   <?php if (empty($history['by_product'])): ?>
   <Row><?= xls_cell_str('Cell', 'Нет данных по товарам за этот фильтр.', 5) ?></Row>
   <?php else: ?>
   <Row>
    <?= xls_cell_str('Label', 'Товар') ?><?= xls_cell_str('Label', 'Артикул') ?><?= xls_cell_str('Label', 'Куплено, шт') ?><?= xls_cell_str('Label', 'Куплено, $') ?><?= xls_cell_str('Label', 'Возвращено, шт') ?><?= xls_cell_str('Label', 'Возвращено, $') ?>
   </Row>
   <?php foreach ($history['by_product'] as $row): ?>
   <Row>
    <?= xls_cell_str('Cell', $row['product']) ?>
    <?= xls_cell_str('Cell', $row['article']) ?>
    <?= xls_cell_num('CellCenter', $row['qty_sale'] > 0 ? rtrim(rtrim(number_format($row['qty_sale'], 3, '.', ''), '0'), '.') : '0') ?>
    <?= xls_cell_num('Money', number_format($row['total_sale'], 2, '.', '')) ?>
    <?= xls_cell_num('CellCenter', $row['qty_return'] > 0 ? rtrim(rtrim(number_format($row['qty_return'], 3, '.', ''), '0'), '.') : '0') ?>
    <?= xls_cell_num('Money', number_format($row['total_return'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <?php endif; ?>
   <Row/>
   <Row/>

   <Row><?= xls_cell_str('SubTitle', 'Документы (покупки, возвраты, авансы)', 3) ?></Row>
   <Row/>
   <Row>
    <?= xls_cell_str('Label', 'Товар') ?><?= xls_cell_str('Label', 'Артикул') ?><?= xls_cell_str('Label', 'Кол-во') ?><?= xls_cell_str('Label', 'Сумма, $') ?>
   </Row>
   <?php if (empty($history['documents'])): ?>
   <Row><?= xls_cell_str('Cell', 'Ничего не найдено по этому фильтру.', 3) ?></Row>
   <?php endif; ?>
   <?php foreach ($history['documents'] as $doc): ?>
   <Row>
    <?= xls_cell_str('Header', $doc['type_label'] . ' ' . $doc['doc_ref'] . ' от ' . $doc['date'] . ' — итого ' . number_format($doc['total'], 2, '.', '') . ' $', 3) ?>
   </Row>
   <?php foreach ($doc['lines'] as $line): ?>
   <Row>
    <?= xls_cell_str('Cell', $line['product']) ?>
    <?= xls_cell_str('Cell', $line['article']) ?>
    <?= xls_cell_num('CellCenter', rtrim(rtrim(number_format($line['qty'], 3, '.', ''), '0'), '.')) ?>
    <?= xls_cell_num('Money', number_format($line['total'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <?php endforeach; ?>
   <Row/>
   <Row/>

   <Row><?= xls_cell_str('SubTitle', 'Оплаты', 3) ?></Row>
   <Row/>
   <Row>
    <?= xls_cell_str('Label', 'Дата') ?><?= xls_cell_str('Label', 'Счёт') ?><?= xls_cell_str('Label', 'Способ оплаты') ?><?= xls_cell_str('Label', 'Сумма, $') ?>
   </Row>
   <?php if (empty($history['payments'])): ?>
   <Row><?= xls_cell_str('Cell', 'Оплат не было.', 3) ?></Row>
   <?php endif; ?>
   <?php foreach ($history['payments'] as $row): ?>
   <Row>
    <?= xls_cell_str('Cell', $row['date']) ?>
    <?= xls_cell_str('Cell', $row['doc_ref']) ?>
    <?= xls_cell_str('Cell', $row['method']) ?>
    <?= xls_cell_num('Money', number_format($row['amount'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <?php if (!empty($history['payments'])): ?>
   <Row>
    <?= xls_cell_str('TotalLabel', 'Оплачено всего:', 2) ?>
    <?= xls_cell_num('MoneyBold', number_format($history['summary']['paid'], 2, '.', '')) ?>
   </Row>
   <?php endif; ?>
  </Table>
 </Worksheet>
</Workbook>
