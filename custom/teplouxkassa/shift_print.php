<?php
/**
 * Печатная версия сменного отчёта кассира — см. includes/shift_report.php, тот же принцип, что у
 * report_print.php (обычный HTML + CSS для печати, "Печать -> Сохранить как PDF" в браузере).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/shift_report.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$report = build_shift_report($api, $cfg, $date);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Сменный отчёт — <?= htmlspecialchars($date) ?></title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1f2430; max-width: 700px; margin: 24px auto; padding: 0 16px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 22px 0 8px; }
  .muted { color: #6b7280; font-size: 13px; }
  .head-row { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1f2430; padding-bottom: 10px; margin-bottom: 16px; }
  .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 8px; }
  .summary-box { border: 1px solid #e2e5ea; border-radius: 8px; padding: 10px 12px; }
  .summary-box .label { font-size: 12px; color: #6b7280; }
  .summary-box .value { font-size: 18px; font-weight: 700; margin-top: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 13.5px; margin-bottom: 4px; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e2e5ea; }
  th { color: #6b7280; font-weight: 600; font-size: 11.5px; text-transform: uppercase; }
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
    <div class="muted">Сменный отчёт кассира</div>
  </div>
  <div class="muted"><?= date('d.m.Y', strtotime($date)) ?></div>
</div>

<h2>Товар</h2>
<div class="summary-grid">
  <div class="summary-box"><div class="label">Продано (<?= $report['sale_count'] ?>)</div><div class="value"><?= number_format($report['sold'], 2) ?> $</div></div>
  <div class="summary-box"><div class="label">— в долг</div><div class="value"><?= number_format($report['on_credit'], 2) ?> $</div></div>
  <div class="summary-box"><div class="label">Возвращено (<?= $report['return_count'] ?>)</div><div class="value"><?= number_format($report['returned'], 2) ?> $</div></div>
</div>

<h2>Деньги (реальное движение за день)</h2>
<table>
  <tr><th></th><th class="num">Наличные</th><th class="num">Карта/QR/перевод</th><th class="num">Итого</th></tr>
  <tr>
    <td>Принято</td>
    <td class="num"><?= number_format($report['money_in']['cash'], 2) ?> $</td>
    <td class="num"><?= number_format($report['money_in']['electronic'], 2) ?> $</td>
    <td class="num"><strong><?= number_format($report['money_in']['cash'] + $report['money_in']['electronic'], 2) ?> $</strong></td>
  </tr>
  <tr>
    <td>Выдано</td>
    <td class="num"><?= number_format($report['money_out']['cash'], 2) ?> $</td>
    <td class="num"><?= number_format($report['money_out']['electronic'], 2) ?> $</td>
    <td class="num"><strong><?= number_format($report['money_out']['cash'] + $report['money_out']['electronic'], 2) ?> $</strong></td>
  </tr>
</table>

<?php if ($report['cash_balance_now'] !== null && $date === date('Y-m-d')): ?>
<p><strong>Наличная касса сейчас:</strong> <?= number_format($report['cash_balance_now'], 2) ?> $</p>
<?php endif; ?>

<div class="sign-row">
  <div>Кассир: _______________________</div>
  <div>Принял: _______________________</div>
</div>

</body>
</html>
