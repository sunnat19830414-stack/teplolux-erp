<?php
/**
 * «Что пора закупать» — отчёт, который просил пользователь: аналитика продаж и, на её основании,
 * когда и сколько чего надо покупать.
 *
 * Считаем честно и просто: сколько товара продали за выбранный период → средний расход в день → на
 * сколько дней хватит того, что есть на складе и уже едет → сколько докупить, чтобы хватило на
 * заданный горизонт. Это оценка по прошлому спросу, а не предсказание — так и подписано на экране,
 * чтобы к цифрам относились как к подсказке, а не как к обязательству.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/period_form.php';

[$from, $to] = report_period();
$horizon = (int)($_GET['horizon'] ?? 60);
if ($horizon < 7) $horizon = 7;
if ($horizon > 365) $horizon = 365;

$onlyUrgent = !empty($_GET['urgent']);
$d = report_demand(visible_directions($cfg), $from, $to, $horizon);

$rows = $d['rows'];
if ($onlyUrgent) {
    $rows = array_values(array_filter($rows, fn($r) => $r['days_left'] !== null && $r['days_left'] <= $horizon));
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Что пора закупать</h1>
<?php render_period_form('report_demand.php', $from, $to, ['horizon' => $horizon] + ($onlyUrgent ? ['urgent' => 1] : [])); ?>

<div class="card">
  <form method="get" class="row" style="align-items:end">
    <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
    <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
    <div style="max-width:230px">
      <label>На сколько дней хотим запас</label>
      <input type="number" name="horizon" value="<?= $horizon ?>" min="7" max="365" step="1">
    </div>
    <div style="max-width:230px; align-self:center">
      <label style="display:inline"><input type="checkbox" name="urgent" value="1" style="width:auto; margin:0 6px 0 0" <?= $onlyUrgent ? 'checked' : '' ?>>
        только то, что кончится раньше</label>
    </div>
    <div style="flex:0"><button type="submit">Пересчитать</button></div>
  </form>
  <p class="muted">Расход считается по продажам за выбранный период (<?= (int)$d['days'] ?> дн.).
  Чем длиннее период, тем спокойнее цифра: за неделю случайная крупная отгрузка сильно её задерёт.</p>
</div>

<div class="card">
  <h2>Товары — сначала те, что кончатся раньше</h2>
  <?php if (empty($rows)): ?>
    <p class="muted">За выбранный период продаж не было — считать не из чего. Возьмите период подлиннее.</p>
  <?php else: ?>
    <table>
      <tr>
        <th>Товар</th>
        <th class="num">Продано за период</th>
        <th class="num">В день</th>
        <th class="num">На складе</th>
        <th class="num">Едет</th>
        <th class="num">Хватит на</th>
        <th class="num">Докупить</th>
      </tr>
      <?php foreach (array_slice($rows, 0, 200) as $r): ?>
        <?php
          $dl = $r['days_left'];
          $urgentRow = $dl !== null && $dl <= 14;
          $soonRow = $dl !== null && !$urgentRow && $dl <= $horizon;
        ?>
        <tr<?= $urgentRow ? ' style="background:#fff7ed"' : '' ?>>
          <td>
            <?= htmlspecialchars($r['label']) ?>
            <div class="muted"><?= htmlspecialchars($r['ref']) ?><?php
              if ($r['direction'] !== '' && count(visible_directions($cfg)) > 1)
                  echo ' · ' . htmlspecialchars($cfg['directions'][$r['direction']] ?? $r['direction']); ?></div>
          </td>
          <td class="num"><?= number_format($r['sold'], 0, '.', ' ') ?></td>
          <td class="num muted"><?= number_format($r['per_day'], 2) ?></td>
          <td class="num"><?= number_format($r['stock'], 0, '.', ' ') ?></td>
          <td class="num muted"><?= $r['incoming'] > 0 ? number_format($r['incoming'], 0, '.', ' ') : '—' ?></td>
          <td class="num">
            <?php if ($dl === null): ?>—
            <?php elseif ($urgentRow): ?><span class="badge badge-debt"><?= number_format($dl, 0) ?> дн.</span>
            <?php elseif ($soonRow): ?><span class="badge badge-warn"><?= number_format($dl, 0) ?> дн.</span>
            <?php else: ?><?= number_format($dl, 0) ?> дн.
            <?php endif; ?>
          </td>
          <td class="num"><strong><?= $r['need'] > 0 ? number_format($r['need'], 0, '.', ' ') : '—' ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($rows) > 200): ?>
      <p class="muted">Показаны первые 200 из <?= count($rows) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Как читать</h2>
  <p class="muted">
    <strong>«В день»</strong> — сколько штук в среднем уходило за день выбранного периода.<br>
    <strong>«Хватит на»</strong> — на сколько дней хватит склада вместе с тем, что уже едет, если
    продавать так же.<br>
    <strong>«Докупить»</strong> — сколько нужно, чтобы хватило на <?= $horizon ?> дн.<br>
    Товары, которых за период не продали ни одного, в список не попадают: по ним считать нечего.
    Сроки поставки здесь не учитываются — если товар идёт месяц, заказывать надо раньше, чем он
    кончится.
  </p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
