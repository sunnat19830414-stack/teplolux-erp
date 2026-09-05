<?php
/** Закупки за период, что сейчас в пути и кому мы должны. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/period_form.php';

[$from, $to] = report_period();
$p = report_purchases($api, $from, $to);
$debts = report_supplier_debts($api);

$names = $api->getThirdpartiesByIds(array_merge(
    array_slice(array_keys($p['by_supplier']), 0, 15),
    array_slice(array_keys($debts), 0, 20),
    array_map(fn($t) => $t['socid'], $p['transit_rows'])
));
$totalDebt = array_sum($debts);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Закупки и поставщики</h1>
<?php if (user_direction() !== null): ?>
  <p class="warn">Заказы поставщикам не разделены по направлениям — здесь показана закупка по всей компании.</p>
<?php endif; ?>
<?php render_period_form('report_purchases.php', $from, $to); ?>

<div class="card">
  <div class="kpi-grid">
    <div class="kpi"><div class="k">Заказано за период</div><div class="v"><?= money($p['ordered']) ?></div>
      <div class="muted">заказов: <?= (int)$p['order_count'] ?></div></div>
    <div class="kpi"><div class="k">Сейчас в пути</div><div class="v"><?= money($p['in_transit']) ?></div>
      <div class="muted">заказов: <?= (int)$p['in_transit_count'] ?></div></div>
    <div class="kpi"><div class="k">Должны поставщикам</div><div class="v neg"><?= money($totalDebt) ?></div>
      <div class="muted">по неоплаченным счетам</div></div>
  </div>
</div>

<div class="card">
  <h2>В пути</h2>
  <?php if (empty($p['transit_rows'])): ?>
    <p class="muted">Сейчас ничего не едет.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Поставщик</th><th class="num">Сумма</th><th>Ожидается</th></tr>
      <?php foreach ($p['transit_rows'] as $t): ?>
        <?php
          $late = $t['delivery'] > 0 && $t['delivery'] < strtotime('today');
          $soon = $t['delivery'] > 0 && !$late && $t['delivery'] <= strtotime('+7 days');
        ?>
        <tr>
          <td><?= htmlspecialchars($t['ref']) ?></td>
          <td><?= htmlspecialchars($names[$t['socid']]['name'] ?? ('#' . $t['socid'])) ?></td>
          <td class="num">
            <?php if ($t['currency'] !== 'USD' && $t['total_native'] > 0): ?>
              <?= number_format($t['total_native'], 2, '.', ' ') ?> <?= htmlspecialchars($t['currency']) ?>
              <div class="muted">≈ <?= number_format($t['total'], 2, '.', ' ') ?> $</div>
            <?php else: ?>
              <?= number_format($t['total'], 2, '.', ' ') ?> $
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$t['delivery']): ?>
              <span class="muted">дата не указана</span>
            <?php elseif ($late): ?>
              <span class="badge badge-debt">просрочено с <?= date('d.m.Y', $t['delivery']) ?></span>
            <?php elseif ($soon): ?>
              <span class="badge badge-warn"><?= date('d.m.Y', $t['delivery']) ?></span>
            <?php else: ?>
              <?= date('d.m.Y', $t['delivery']) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="grid-2col">
<div>
<div class="card">
  <h2>У кого закупались за период</h2>
  <?php if (empty($p['by_supplier'])): ?>
    <p class="muted">За эти дни заказов не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Поставщик</th><th class="num">Сумма, $</th></tr>
      <?php foreach (array_slice($p['by_supplier'], 0, 15, true) as $socId => $sum): ?>
        <tr><td><?= htmlspecialchars($names[$socId]['name'] ?? ('#' . $socId)) ?></td>
            <td class="num"><?= number_format($sum, 2, '.', ' ') ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</div>
<div>
<div class="card">
  <h2>Кому должны</h2>
  <?php if (empty($debts)): ?>
    <p class="ok">Неоплаченных счетов нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Поставщик</th><th class="num">Долг, $</th></tr>
      <?php foreach (array_slice($debts, 0, 20, true) as $socId => $sum): ?>
        <tr><td><?= htmlspecialchars($names[$socId]['name'] ?? ('#' . $socId)) ?></td>
            <td class="num" style="color:var(--danger)"><?= number_format($sum, 2, '.', ' ') ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
