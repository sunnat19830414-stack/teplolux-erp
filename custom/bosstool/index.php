<?php
/**
 * Сводка руководства: главные цифры за текущий месяц и то, что требует внимания.
 * Задача экрана — чтобы шеф с одного взгляда понимал, где деньги и что застряло, а за подробностями
 * шёл в конкретный отчёт по ссылке.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/requests.php';

$from = date('Y-m-01');
$to = date('Y-m-t');
$dirs = visible_directions($cfg);

$sales = report_sales($api, $dirs, $from, $to);
$debts = report_client_debts($api, $dirs);
$money = report_money($api, $cfg, $from, $to, $dirs);
$purch = report_purchases($api, $from, $to);
$supDebts = report_supplier_debts($api);

$myRequests = request_list($dirs, ['draft', 'sent', 'taken']);
$lateTransit = array_values(array_filter($purch['transit_rows'],
    fn($t) => $t['delivery'] > 0 && $t['delivery'] < strtotime('today')));

$socIds = array_merge(array_slice(array_keys($debts), 0, 5), array_map(fn($t) => $t['socid'], $lateTransit));
$names = $api->getThirdpartiesByIds($socIds);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Сводка за <?= htmlspecialchars(month_name_ru((int)date('n'))) ?></h1>
<p class="muted">С <?= date('d.m.Y', strtotime($from)) ?> по сегодня<?php
  if (count($dirs) === 1) echo ' · направление ' . htmlspecialchars($cfg['directions'][$dirs[0]]); ?></p>

<div class="card">
  <div class="kpi-grid">
    <div class="kpi"><div class="k">Продано за месяц</div><div class="v"><?= money($sales['net']) ?></div></div>
    <div class="kpi"><div class="k">Должны нам</div><div class="v neg"><?= money(array_sum($debts)) ?></div></div>
    <div class="kpi"><div class="k">Должны мы</div><div class="v neg"><?= money(array_sum($supDebts)) ?></div></div>
    <div class="kpi"><div class="k">Деньги: пришло − ушло</div>
      <?php // Только доллары — основная валюта компании. Смешивать их с сумами и евро в одной
            // цифре нельзя; полную картину по каждой валюте показывает отчёт «Пришло / ушло». ?>
      <?php $usdDiff = $money['by_currency']['USD']['diff'] ?? 0; ?>
      <div class="v <?= $usdDiff >= 0 ? 'pos' : 'neg' ?>"><?= money($usdDiff) ?></div>
      <div class="muted">только долларовые счета</div></div>
    <div class="kpi"><div class="k">Закуплено за месяц</div><div class="v"><?= money($purch['ordered']) ?></div></div>
    <div class="kpi"><div class="k">В пути</div><div class="v"><?= money($purch['in_transit']) ?></div>
      <div class="muted">заказов: <?= (int)$purch['in_transit_count'] ?></div></div>
  </div>
</div>

<div class="grid-2col">
<div>

<div class="card">
  <h2>Мои заявки на закупку</h2>
  <?php if (empty($myRequests)): ?>
    <p class="muted">Открытых заявок нет.</p>
    <p><a class="btn small" href="requests.php">Составить заявку</a></p>
  <?php else: ?>
    <table>
      <tr><th>Заявка</th><th>Поставщик</th><th>Статус</th></tr>
      <?php foreach (array_slice($myRequests, 0, 8) as $r): ?>
        <tr>
          <td><a href="request_view.php?id=<?= (int)$r['rowid'] ?>">№<?= (int)$r['rowid'] ?></a>
            <?= $r['label'] ? '<div class="muted">' . htmlspecialchars($r['label']) . '</div>' : '' ?></td>
          <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
          <td><span class="badge <?= request_status_badge($r['status']) ?>"><?= htmlspecialchars(request_status_label($r['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p style="margin-top:10px"><a class="btn secondary small" href="requests.php">Все заявки</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Просроченные поставки</h2>
  <?php if (empty($lateTransit)): ?>
    <p class="muted">Просрочек нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Поставщик</th><th>Ждём с</th></tr>
      <?php foreach (array_slice($lateTransit, 0, 8) as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['ref']) ?></td>
          <td><?= htmlspecialchars($names[$t['socid']]['name'] ?? ('#' . $t['socid'])) ?></td>
          <td><span class="badge badge-debt"><?= date('d.m.Y', $t['delivery']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p style="margin-top:10px"><a class="btn secondary small" href="report_purchases.php">Все закупки</a></p>
  <?php endif; ?>
</div>

</div>
<div>

<div class="card">
  <h2>Крупнейшие должники</h2>
  <?php if (empty($debts)): ?>
    <p class="ok">Долгов нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Клиент</th><th class="num">Долг</th></tr>
      <?php foreach (array_slice($debts, 0, 5, true) as $socId => $sum): ?>
        <tr><td><?= htmlspecialchars($names[$socId]['name'] ?? ('#' . $socId)) ?></td>
            <td class="num" style="color:var(--danger)"><?= number_format($sum, 2, '.', ' ') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p style="margin-top:10px"><a class="btn secondary small" href="report_sales.php">Продажи и долги</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Деньги на счетах сейчас</h2>
  <table>
    <?php foreach ($money['by_account'] as $a): ?>
      <?php if ($a['balance'] === null || abs($a['balance']) < 0.01) continue; ?>
      <tr>
        <td><?= htmlspecialchars($a['label']) ?></td>
        <td class="num"><strong><?= number_format($a['balance'], 2, '.', ' ') ?></strong>
          <span class="muted"><?= htmlspecialchars($a['currency'] === 'USD' ? '$' : $a['currency']) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted" style="margin-top:8px">Каждый счёт показан в своей валюте — они не складываются.</p>
  <p><a class="btn secondary small" href="report_money.php">Пришло / ушло</a></p>
</div>

</div>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
