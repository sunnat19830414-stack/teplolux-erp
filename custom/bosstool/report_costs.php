<?php
/** Зарплата, хозрасходы и прочие доходы за период — то, что ведут Нодир и Абдурашид. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/period_form.php';

[$from, $to] = report_period();
$c = report_costs($from, $to);
$spent = $c['salary'] + $c['salary_tax'] + $c['household'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Зарплата и расходы</h1>
<?php if (user_direction() !== null): ?>
  <p class="warn">Зарплата и хозрасходы ведутся по компании целиком, без разделения на направления.</p>
<?php endif; ?>
<?php render_period_form('report_costs.php', $from, $to); ?>

<div class="card">
  <div class="kpi-grid">
    <div class="kpi"><div class="k">Зарплата выдана</div><div class="v"><?= money($c['salary']) ?></div></div>
    <div class="kpi"><div class="k">Налог сверху</div><div class="v"><?= money($c['salary_tax']) ?></div>
      <div class="muted">при выплате на карту</div></div>
    <div class="kpi"><div class="k">Хозрасходы</div><div class="v"><?= money($c['household']) ?></div></div>
    <div class="kpi"><div class="k">Прочие доходы</div><div class="v pos"><?= money($c['income']) ?></div></div>
    <div class="kpi"><div class="k">Итого потрачено</div><div class="v neg"><?= money($spent) ?></div>
      <div class="muted">зарплата + налог + хозрасходы</div></div>
  </div>
  <?php if ($c['advances'] > 0): ?>
    <p class="muted" style="margin-top:12px">Кроме этого выдано авансов сотрудникам на
      <?= money($c['advances']) ?> — они вычитаются из будущей зарплаты, поэтому в «потрачено» отдельно
      не складываются.</p>
  <?php endif; ?>
</div>

<div class="grid-2col">
<div>
<div class="card">
  <h2>Хозрасходы по видам</h2>
  <?php if (empty($c['household_by_category'])): ?>
    <p class="muted">За этот период хозрасходов не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Вид</th><th class="num">Сумма, $</th><th class="num">Доля</th></tr>
      <?php foreach ($c['household_by_category'] as $r): ?>
        <?php $share = $c['household'] > 0 ? $r['sum'] / $c['household'] * 100 : 0; ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?>
            <div class="bar"><i style="width:<?= round($share) ?>%"></i></div></td>
          <td class="num"><?= number_format($r['sum'], 2, '.', ' ') ?></td>
          <td class="num muted"><?= number_format($share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</div>
<div>
<div class="card">
  <h2>Доходы по источникам</h2>
  <?php if (empty($c['income_by_source'])): ?>
    <p class="muted">За этот период прочих доходов не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Источник</th><th class="num">Сумма, $</th><th class="num">Доля</th></tr>
      <?php foreach ($c['income_by_source'] as $r): ?>
        <?php $share = $c['income'] > 0 ? $r['sum'] / $c['income'] * 100 : 0; ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?>
            <div class="bar"><i style="width:<?= round($share) ?>%"></i></div></td>
          <td class="num"><?= number_format($r['sum'], 2, '.', ' ') ?></td>
          <td class="num muted"><?= number_format($share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
</div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
