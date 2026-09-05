<?php
/**
 * Выгрузка накладной (счёта Dolibarr) в Excel — чтобы сотрудник мог распечатать и отдать/отправить
 * клиенту.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/client_history.php'; // payment_code_label()

$invoiceId = (int)($_GET['id'] ?? 0);
if (!$invoiceId) {
    http_response_code(400);
    die('Не указан номер счёта.');
}

$inv = $api->getInvoice($invoiceId);
if (!is_array($inv)) {
    http_response_code(404);
    die('Счёт не найден: ' . htmlspecialchars($api->lastError));
}

// Проверка направления: счёт должен принадлежать клиенту НАШЕГО направления — иначе сотрудник
// одного направления мог бы выгрузить накладную клиента другого, просто подобрав id счёта в URL.
$soc = $api->getThirdparty((int)($inv['socid'] ?? 0));
$codeClient = is_array($soc) ? ($soc['code_client'] ?? '') : '';
if (stripos($codeClient, $cfg['ref_prefix']) !== 0) {
    http_response_code(403);
    die('Этот счёт относится к другому направлению.');
}

$clientName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';
$clientAddress = trim((is_array($soc) ? ($soc['address'] ?? '') : '') . ' ' . (is_array($soc) ? ($soc['town'] ?? '') : ''));
$clientPhone = is_array($soc) ? ($soc['phone'] ?? $soc['phone_mobile'] ?? '') : '';
$isCredit = ((int)($inv['type'] ?? 0)) === 2;
$docTitle = $isCredit ? 'Возврат' : 'Накладная';
$dateStr = !empty($inv['date']) ? date('d.m.Y', (int)$inv['date']) : '';
$safeRef = preg_replace('/[^A-Za-z0-9_-]/', '', $inv['ref']);

$totalTtc = (float)($inv['total_ttc'] ?? 0);
$remainToPay = (float)($inv['remaintopay'] ?? $totalTtc);
$isPaid = $remainToPay <= 0.01;
$payments = $api->getInvoicePayments($invoiceId);
if (!is_array($payments)) $payments = [];

xls_send_headers(
    ($isCredit ? 'Return' : 'Invoice') . '_' . $safeRef . '.xls',
    $docTitle . '_' . $safeRef . '.xls'
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
 <Worksheet ss:Name="<?= xls_esc($docTitle) ?>">
  <Table>
   <Column ss:Width="30"/>
   <Column ss:Width="260"/>
   <Column ss:Width="110"/>
   <Column ss:Width="60"/>
   <Column ss:Width="80"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — ' . $cfg['direction_label'], 4) ?></Row>
   <Row/>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', $docTitle . ' № ' . $inv['ref'] . ' от ' . $dateStr, 4) ?></Row>
   <Row/>
   <Row><?= xls_cell_str('Label', 'Клиент:') ?><?= xls_cell_str('Plain', $clientName, 3) ?></Row>
   <?php if (trim($clientAddress)): ?>
   <Row><?= xls_cell_str('Label', 'Адрес:') ?><?= xls_cell_str('Plain', trim($clientAddress), 3) ?></Row>
   <?php endif; ?>
   <?php if ($clientPhone): ?>
   <Row><?= xls_cell_str('Label', 'Телефон:') ?><?= xls_cell_str('Plain', $clientPhone, 3) ?></Row>
   <?php endif; ?>
   <Row/>
   <Row>
    <?= xls_cell_str('Label', 'Статус оплаты:') ?>
    <?php if ($isPaid): ?>
      <?= xls_cell_str('PaidYes', 'ОПЛАЧЕНО', 3) ?>
    <?php elseif ($remainToPay < $totalTtc - 0.01): ?>
      <?= xls_cell_str('PaidNo', 'ОПЛАЧЕНО ЧАСТИЧНО — остаток ' . number_format($remainToPay, 2, '.', '') . ' $', 3) ?>
    <?php else: ?>
      <?= xls_cell_str('PaidNo', 'НЕ ОПЛАЧЕНО', 3) ?>
    <?php endif; ?>
   </Row>
   <?php foreach ($payments as $p): ?>
   <Row>
    <?= xls_cell_str('Label', 'Оплата:') ?>
    <?= xls_cell_str('Plain', payment_code_label((string)($p['type'] ?? '')) . ' — ' . number_format((float)($p['amount'] ?? 0), 2, '.', '') . ' $' . (!empty($p['date']) ? (' (' . date('d.m.Y', strtotime($p['date'])) . ')') : ''), 3) ?>
   </Row>
   <?php endforeach; ?>
   <Row/>
   <Row>
    <?= xls_cell_str('Header', '№') ?><?= xls_cell_str('Header', 'Наименование') ?><?= xls_cell_str('Header', 'Артикул') ?><?= xls_cell_str('Header', 'Кол-во') ?><?= xls_cell_str('Header', 'Сумма, $') ?>
   </Row>
   <?php foreach (($inv['lines'] ?? []) as $i => $line): ?>
   <Row>
    <?= xls_cell_num('CellCenter', $i + 1) ?>
    <?= xls_cell_str('Cell', $line['product_label'] ?? $line['desc'] ?? '') ?>
    <?= xls_cell_str('Cell', $line['product_ref'] ?? '') ?>
    <?= xls_cell_num('CellCenter', rtrim(rtrim(number_format((float)$line['qty'], 3, '.', ''), '0'), '.')) ?>
    <?= xls_cell_num('Money', number_format((float)$line['total_ttc'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <Row>
    <?= xls_cell_str('TotalLabel', 'Итого:', 3) ?>
    <?= xls_cell_num('MoneyBold', number_format((float)$inv['total_ttc'], 2, '.', '')) ?>
   </Row>
   <Row/>
   <Row/>
   <Row>
    <?= xls_cell_str('Plain', 'Отпустил: _______________________', 1) ?>
    <?= xls_cell_str('Plain', '') ?>
    <?= xls_cell_str('Plain', 'Получил: _______________________', 1) ?>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
