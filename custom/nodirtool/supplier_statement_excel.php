<?php
/**
 * Выгрузка выписки по поставщику в Excel (топ-5 пункт 4, 02.09.2026) — тот же принцип SpreadsheetML,
 * что и у TeplouxKassa (includes/xls_helper.php, портирован без изменений).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/supplier_statement.php';
require_once __DIR__ . '/includes/debt.php';   // сальдо по валютам (H3, 05.09.2026)

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

// H3 (финансовый аудит 05.09.2026) — МОЯ ЖЕ недоделка того же дня: экран и строки выписки я
// перевёл на валюту документа, а шапку этого файла оставил на `getSupplierOutstanding()`, который
// отдаёт ТОЛЬКО долларовый пересчёт. Получалось, что в одном файле шапка показывает 1395.35 (доллары),
// а строки под подписью «Сумма, $» — 1200 (на самом деле евро). Считаем шапку тем же способом,
// что и экран, и подписываем валюту у каждой суммы, а не в заголовке колонки.
$balanceByCur = supplier_debt_by_currency($socId)[$socId] ?? [];
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
   <?php
     $owe  = array_filter($balanceByCur, fn($v) => $v > 0.01);
     $over = array_filter($balanceByCur, fn($v) => $v < -0.01);
   ?>
   <?php if ($owe): ?>
   <Row>
    <?= xls_cell_str('Label', 'Мы должны поставщику:') ?>
    <?= xls_cell_str('MoneyBold', money_by_currency($owe)) ?>
   </Row>
   <?php endif; ?>
   <?php if ($over): ?>
   <Row>
    <?= xls_cell_str('Label', 'Предоплата/переплата:') ?>
    <?= xls_cell_str('MoneyBold', money_by_currency(array_map('abs', $over))) ?>
   </Row>
   <?php endif; ?>
   <?php if (!$owe && !$over): ?>
   <Row>
    <?= xls_cell_str('Label', 'Сальдо:') ?>
    <?= xls_cell_str('MoneyBold', money(0.0)) ?>
   </Row>
   <?php endif; ?>
   <Row/>

   <Row>
    <?= xls_cell_str('Label', 'Дата') ?><?= xls_cell_str('Label', 'Документ') ?><?= xls_cell_str('Label', '№') ?><?= xls_cell_str('Label', 'Сумма') ?><?= xls_cell_str('Label', 'Сальдо') ?>
   </Row>
   <?php if (empty($rows)): ?>
   <Row><?= xls_cell_str('Cell', 'Пока нет ни одного счёта/оплаты.', 5) ?></Row>
   <?php endif; ?>
   <?php foreach ($rows as $r): ?>
   <Row>
    <?= xls_cell_str('Cell', $r['date'] ? date('d.m.Y', $r['date']) : '') ?>
    <?= xls_cell_str('Cell', $r['kind_label']) ?>
    <?= xls_cell_str('Cell', $r['ref'] . ($r['ref_supplier'] ? ' (' . $r['ref_supplier'] . ')' : '')) ?>
    <?= xls_cell_str('Cell', money($r['amount'], $r['currency'])) ?>
    <?= xls_cell_str('Cell', money($r['balance'], $r['currency'])) ?>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
