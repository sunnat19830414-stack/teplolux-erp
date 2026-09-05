<?php
/**
 * Квитанция о приёме денег — печатается сразу после оплаты в разделе "Касса/Долги" (и через
 * "Принять оплату" FIFO, и через точечную кнопку "Оплатить" на конкретном счёте). Данные берём из
 * сессии, куда их кладёт debt.php сразу после успешного платежа — не привязываемся к отдельному
 * "id квитанции" в Dolibarr (такого документа там нет, это чисто наша печатная форма).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';

$receipt = $_SESSION['last_receipt'] ?? null;
if (!is_array($receipt)) {
    http_response_code(400);
    die('Квитанция ещё не сформирована — сначала примите оплату в разделе "Касса / Долги".');
}

$dateStr = date('d.m.Y H:i', (int)($receipt['date'] ?? time()));
$safeDate = date('YmdHis', (int)($receipt['date'] ?? time()));
// 'out' — квитанция о ВЫДАЧЕ денег клиенту (payout.php), 'in' (по умолчанию, для обратной совместимости
// со старыми местами, где ключ 'type' в сессии никогда не выставлялся) — обычный приём оплаты.
$isOut = ($receipt['type'] ?? 'in') === 'out';

xls_send_headers('Receipt_' . $safeDate . '.xls', 'Квитанция_' . $safeDate . '.xls');
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
 <Worksheet ss:Name="Квитанция">
  <Table>
   <Column ss:Width="150"/>
   <Column ss:Width="150"/>
   <Column ss:Width="120"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — ' . $cfg['direction_label'], 2) ?></Row>
   <Row/>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', $isOut ? 'Квитанция о выдаче денег клиенту' : 'Квитанция о приёме оплаты', 2) ?></Row>
   <Row/>
   <Row><?= xls_cell_str('Label', 'Дата:') ?><?= xls_cell_str('Plain', $dateStr, 1) ?></Row>
   <Row><?= xls_cell_str('Label', $isOut ? 'Выдано:' : 'Принято от:') ?><?= xls_cell_str('Plain', $receipt['client_name'], 1) ?></Row>
   <Row/>
   <Row><?= xls_cell_str('Header', $isOut ? 'Основание' : 'Счёт', 0) ?><?= xls_cell_str('Header', 'Способ оплаты', 0) ?><?= xls_cell_str('Header', 'Сумма, $', 0) ?></Row>
   <?php foreach ($receipt['items'] as $item): ?>
   <?php $methodText = $item['method'] . (!empty($item['uzs']) ? ' (' . number_format($item['uzs'], 0, '.', ' ') . ' сум по курсу ' . rtrim(rtrim(number_format($item['rate'], 2, '.', ''), '0'), '.') . ')' : ''); ?>
   <Row>
    <?= xls_cell_str('Cell', $item['ref']) ?>
    <?= xls_cell_str('Cell', $methodText) ?>
    <?= xls_cell_num('Money', number_format($item['amount'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <Row/>
   <Row><?= xls_cell_str('TotalLabel', $isOut ? 'Итого выдано:' : 'Итого принято:', 1) ?><?= xls_cell_num('MoneyBold', number_format($receipt['total'], 2, '.', '')) ?></Row>
   <Row/>
   <Row/>
   <?php if ($isOut): ?>
   <Row><?= xls_cell_str('Plain', 'Выдал: _______________________', 2) ?></Row>
   <Row><?= xls_cell_str('Plain', 'Получил: _______________________', 2) ?></Row>
   <?php else: ?>
   <Row><?= xls_cell_str('Plain', 'Принял: _______________________', 2) ?></Row>
   <?php endif; ?>
  </Table>
 </Worksheet>
</Workbook>
