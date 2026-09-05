<?php
/** Продажи за период и текущие долги клиентов. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/period_form.php';

[$from, $to] = report_period();
$dirs = visible_directions($cfg);

$s = report_sales($api, $dirs, $from, $to);
$debts = report_client_debts($api, $dirs);

// Имена клиентов — одним запросом на всех, а не по одному (иначе сотни обращений к API).
$topClients = array_slice($s['by_client'], 0, 15, true);
$names = $api->getThirdpartiesByIds(array_merge(array_keys($topClients), array_slice(array_keys($debts), 0, 20)));
$totalDebt = array_sum($debts);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Продажи и долги клиентов</h1>
<?php render_period_form('report_sales.php', $from, $to); ?>

<div class="card">
  <div class="kpi-grid">
    <div class="kpi"><div class="k">Продано за период</div><div class="v"><?= money($s['sold']) ?></div>
      <div class="muted">документов: <?= (int)$s['invoice_count'] ?></div></div>
    <div class="kpi"><div class="k">Возвраты и авансы</div><div class="v"><?= money($s['returned']) ?></div>
      <div class="muted">документов: <?= (int)$s['return_count'] ?></div></div>
    <div class="kpi"><div class="k">Чистая продажа</div><div class="v pos"><?= money($s['net']) ?></div></div>
    <div class="kpi"><div class="k">Из проданного не оплачено</div><div class="v neg"><?= money($s['on_credit']) ?></div>
      <div class="muted">отпущено в долг</div></div>
  </div>
</div>

<div class="grid-2col">
<div>
<div class="card">
  <h2>Кто больше купил за период</h2>
  <?php if (empty($topClients)): ?>
    <p class="muted">За эти дни продаж не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Клиент</th><th class="num">Сумма</th></tr>
      <?php foreach ($topClients as $socId => $sum): ?>
        <tr>
          <td><?= htmlspecialchars($names[$socId]['name'] ?? ('#' . $socId)) ?></td>
          <td class="num"><?= number_format($sum, 2, '.', ' ') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</div>
<div>
<div class="card">
  <h2>Должны нам сейчас — <?= money($totalDebt) ?></h2>
  <p class="muted">Текущее состояние, не за период.</p>
  <?php if (empty($debts)): ?>
    <p class="ok">Долгов нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Клиент</th><th class="num">Долг</th></tr>
      <?php foreach (array_slice($debts, 0, 20, true) as $socId => $sum): ?>
        <tr>
          <td><?= htmlspecialchars($names[$socId]['name'] ?? ('#' . $socId)) ?></td>
          <td class="num" style="color:var(--danger)"><?= number_format($sum, 2, '.', ' ') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($debts) > 20): ?>
      <p class="muted">Показаны 20 крупнейших из <?= count($debts) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
</div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
