<?php
/**
 * Печатная версия отчёта по клиенту — для выдачи/отправки самому клиенту (в отличие от report_excel.php,
 * который скорее "рабочая" выгрузка данных). Обычная HTML-страница с CSS для печати, без сторонних
 * библиотек — сохранить в PDF через "Печать -> Сохранить как PDF" в браузере.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client_history.php';

$socid = (int)($_GET['client_id'] ?? 0);
if (!$socid) {
    http_response_code(400);
    die('Не указан клиент.');
}

// Проверка направления — так же, как в report_excel.php/invoice_excel.php.
$soc = $api->getThirdparty($socid);
$codeClient = is_array($soc) ? ($soc['code_client'] ?? '') : '';
if (stripos($codeClient, $cfg['ref_prefix']) !== 0) {
    http_response_code(403);
    die('Этот клиент относится к другому направлению.');
}
$clientName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';
$clientPhone = is_array($soc) ? ($soc['phone'] ?? '') : '';

$filters = report_filters_from_request($_GET);
$history = buildClientHistory($api, $socid, $filters);

$typeLabelsAll = ['sale' => 'Продажа', 'return' => 'Возврат', 'advance' => 'Аванс'];
$activeTypeLabels = array_map(fn($t) => $typeLabelsAll[$t], $filters['types']);
$periodLabel = ($filters['date_from'] || $filters['date_to'])
    ? ('с ' . ($filters['date_from'] ? date('d.m.Y', strtotime($filters['date_from'])) : '…') . ' по ' . ($filters['date_to'] ? date('d.m.Y', strtotime($filters['date_to'])) : '…'))
    : 'за всё время';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>История клиента — <?= htmlspecialchars($clientName) ?></title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1f2430; max-width: 900px; margin: 24px auto; padding: 0 16px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 22px 0 8px; }
  .muted { color: #6b7280; font-size: 13px; }
  .head-row { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1f2430; padding-bottom: 10px; margin-bottom: 16px; }
  .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 8px; }
  .summary-box { border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px 12px; }
  .summary-box .label { font-size: 12px; color: #6b7280; }
  .summary-box .value { font-size: 18px; font-weight: 700; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 13.5px; margin-bottom: 4px; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e2e5ea; }
  th { color: #6b7280; font-weight: 600; font-size: 11.5px; text-transform: uppercase; }
  .doc-header { background: #f4f5f7; font-weight: 600; }
  .num { text-align: right; }
  .no-print { margin: 18px 0; }
  .sign-row { display: flex; justify-content: space-between; margin-top: 60px; font-size: 14px; }
  @media print {
    .no-print { display: none; }
    body { margin: 0; max-width: none; }
  }
</style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()">🖨️ Печать / Сохранить в PDF</button>
</div>

<div class="head-row">
  <div>
    <h1>Теплолюкс — <?= htmlspecialchars($cfg['direction_label']) ?></h1>
    <div class="muted">История клиента, <?= htmlspecialchars($periodLabel) ?></div>
  </div>
  <div class="muted"><?= date('d.m.Y') ?></div>
</div>

<p>
  <strong>Клиент:</strong> <?= htmlspecialchars($clientName) ?><?= $clientPhone ? ' · ' . htmlspecialchars($clientPhone) : '' ?><br>
  <strong>Типы документов в отчёте:</strong> <?= htmlspecialchars(implode(', ', $activeTypeLabels)) ?>
</p>

<div class="summary-grid">
  <div class="summary-box"><div class="label">Куплено</div><div class="value"><?= number_format($history['summary']['purchased'], 2) ?> $</div></div>
  <div class="summary-box"><div class="label">Возвращено</div><div class="value"><?= number_format($history['summary']['returned'], 2) ?> $</div></div>
  <div class="summary-box"><div class="label">Оплачено</div><div class="value"><?= number_format($history['summary']['paid'], 2) ?> $</div></div>
  <div class="summary-box"><div class="label">Долг на сегодня</div><div class="value"><?= $history['summary']['debt'] !== null ? number_format($history['summary']['debt'], 2) . ' $' : '?' ?></div></div>
</div>

<?php if (!empty($history['by_product'])): ?>
<h2>Сводная таблица по товарам</h2>
<table>
  <tr><th>Товар</th><th>Артикул</th><th class="num">Куплено</th><th class="num">Возвращено</th></tr>
  <?php foreach ($history['by_product'] as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['product']) ?></td>
      <td><?= htmlspecialchars($row['article']) ?></td>
      <td class="num"><?= $row['qty_sale'] > 0 ? rtrim(rtrim(number_format($row['qty_sale'], 3, '.', ''), '0'), '.') . ' шт / ' . number_format($row['total_sale'], 2) . ' $' : '—' ?></td>
      <td class="num"><?= $row['qty_return'] > 0 ? rtrim(rtrim(number_format($row['qty_return'], 3, '.', ''), '0'), '.') . ' шт / ' . number_format($row['total_return'], 2) . ' $' : '—' ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Документы</h2>
<?php if (empty($history['documents'])): ?>
  <p class="muted">Ничего не найдено по этому фильтру.</p>
<?php else: ?>
<table>
  <tr><th>Дата</th><th>Тип</th><th>№ документа</th><th>Товар</th><th>Артикул</th><th class="num">Кол-во</th><th class="num">Сумма</th></tr>
  <?php foreach ($history['documents'] as $doc): ?>
    <?php if (empty($doc['lines'])): ?>
      <tr class="doc-header">
        <td><?= htmlspecialchars($doc['date']) ?></td>
        <td><?= htmlspecialchars($doc['type_label']) ?></td>
        <td><?= htmlspecialchars($doc['doc_ref']) ?></td>
        <td colspan="3">—</td>
        <td class="num"><?= number_format($doc['total'], 2) ?> $</td>
      </tr>
    <?php else: ?>
      <?php foreach ($doc['lines'] as $i => $line): ?>
        <tr class="<?= $i === 0 ? 'doc-header' : '' ?>">
          <td><?= $i === 0 ? htmlspecialchars($doc['date']) : '' ?></td>
          <td><?= $i === 0 ? htmlspecialchars($doc['type_label']) : '' ?></td>
          <td><?= $i === 0 ? htmlspecialchars($doc['doc_ref']) : '' ?></td>
          <td><?= htmlspecialchars($line['product']) ?></td>
          <td><?= htmlspecialchars($line['article']) ?></td>
          <td class="num"><?= rtrim(rtrim(number_format($line['qty'], 3, '.', ''), '0'), '.') ?></td>
          <td class="num"><?= number_format($line['total'], 2) ?> $</td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Оплаты</h2>
<?php if (empty($history['payments'])): ?>
  <p class="muted">Оплат не было (или тип "Продажа" не выбран в фильтре).</p>
<?php else: ?>
<table>
  <tr><th>Дата</th><th>Счёт</th><th>Способ оплаты</th><th class="num">Сумма</th></tr>
  <?php foreach ($history['payments'] as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['date']) ?></td>
      <td><?= htmlspecialchars($row['doc_ref']) ?></td>
      <td><?= htmlspecialchars($row['method']) ?></td>
      <td class="num"><?= number_format($row['amount'], 2) ?> $</td>
    </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<div class="sign-row">
  <div>Выдал: _______________________</div>
  <div>Получил: _______________________</div>
</div>

</body>
</html>
