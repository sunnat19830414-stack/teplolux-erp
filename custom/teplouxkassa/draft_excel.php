<?php
/**
 * Выгрузка черновика продажи в Excel — доступна в любой момент, независимо от статуса (открыт/
 * переведён в продажу/отменён), не требует закрытия документа.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/draft_orders.php';

$draftId = (int)($_GET['id'] ?? 0);
if (!$draftId) {
    http_response_code(400);
    die('Не указан номер черновика.');
}

// get_draft() уже проверяет направление (возвращает null, если черновик чужого направления) —
// та же защита, что и у invoice_excel.php/report_excel.php.
$draft = get_draft($draftId, $_SESSION['direction']);
if (!$draft) {
    http_response_code(404);
    die('Черновик не найден или относится к другому направлению.');
}

$vatMult = 1 + $cfg['vat_rate'] / 100;
$statusLabel = ['open' => 'ОТКРЫТ (не оформлен)', 'converted' => 'ПЕРЕВЕДЁН В ПРОДАЖУ #' . (int)$draft['fk_invoice'], 'cancelled' => 'ОТМЕНЁН'][$draft['status']] ?? $draft['status'];
$dateStr = date('d.m.Y H:i', strtotime($draft['datec']));
$safeRef = 'DRAFT' . $draftId;

$total = 0;
foreach ($draft['items'] as $item) {
    $total += ($item['price'] ?? 0) * ($item['qty'] ?? 0) * $vatMult;
}

xls_send_headers('Draft_' . $safeRef . '.xls', 'Черновик_' . $safeRef . '.xls');
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
 <Worksheet ss:Name="Черновик">
  <Table>
   <Column ss:Width="30"/>
   <Column ss:Width="260"/>
   <Column ss:Width="110"/>
   <Column ss:Width="60"/>
   <Column ss:Width="80"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — ' . $cfg['direction_label'], 4) ?></Row>
   <Row/>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Черновик заказа № ' . $draftId . ' от ' . $dateStr, 4) ?></Row>
   <Row><?= xls_cell_str('PaidNo', 'Это ЧЕРНОВИК, не продажа — товар со склада не списан, долг клиента не изменён.', 4) ?></Row>
   <Row/>
   <Row><?= xls_cell_str('Label', 'Клиент:') ?><?= xls_cell_str('Plain', $draft['client_name'], 3) ?></Row>
   <?php if (!empty($draft['label'])): ?>
   <Row><?= xls_cell_str('Label', 'Пометка:') ?><?= xls_cell_str('Plain', $draft['label'], 3) ?></Row>
   <?php endif; ?>
   <Row><?= xls_cell_str('Label', 'Статус:') ?><?= xls_cell_str($draft['status'] === 'open' ? 'Plain' : ($draft['status'] === 'converted' ? 'PaidYes' : 'PaidNo'), $statusLabel, 3) ?></Row>
   <Row/>
   <Row>
    <?= xls_cell_str('Header', '№') ?><?= xls_cell_str('Header', 'Наименование') ?><?= xls_cell_str('Header', 'Артикул') ?><?= xls_cell_str('Header', 'Кол-во') ?><?= xls_cell_str('Header', 'Сумма, $') ?>
   </Row>
   <?php foreach ($draft['items'] as $i => $item):
       $lineTotal = ($item['price'] ?? 0) * ($item['qty'] ?? 0) * $vatMult;
   ?>
   <Row>
    <?= xls_cell_num('CellCenter', $i + 1) ?>
    <?= xls_cell_str('Cell', $item['label'] ?? '') ?>
    <?= xls_cell_str('Cell', $item['ref'] ?? '') ?>
    <?= xls_cell_num('CellCenter', rtrim(rtrim(number_format((float)($item['qty'] ?? 0), 3, '.', ''), '0'), '.')) ?>
    <?= xls_cell_num('Money', number_format($lineTotal, 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <Row>
    <?= xls_cell_str('TotalLabel', 'Итого:', 3) ?>
    <?= xls_cell_num('MoneyBold', number_format($total, 2, '.', '')) ?>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
