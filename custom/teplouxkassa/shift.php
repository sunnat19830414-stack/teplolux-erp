<?php
/**
 * Сменный отчёт кассира — "сколько я сегодня продал/принял/выдал/вернул". Самый частый ежедневный
 * запрос кассира (см. отчёт ревью, P0#4) — раньше такого не было вообще, только "История клиента" по
 * одному клиенту. Читает GET-параметр date (по умолчанию сегодня), никакого состояния в сессии не
 * держит — можно смотреть любой прошлый день, просто поменяв дату.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/shift_report.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$report = build_shift_report($api, $cfg, $date);
$isToday = $date === date('Y-m-d');

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Сменный отчёт кассира</h1>

<div class="card">
  <form method="get" class="row" style="align-items:end">
    <div>
      <label>Дата</label>
      <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div style="flex:0"><button type="submit">Показать</button></div>
    <div style="flex:0"><a class="btn secondary" href="shift.php">Сегодня</a></div>
  </form>
</div>

<div class="card">
  <h2>Товар (по дате оформления документа)</h2>
  <div class="row">
    <div><div class="muted">Продано</div><div style="font-size:22px; font-weight:700"><?= number_format($report['sold'], 2) ?> $</div><div class="muted"><?= $report['sale_count'] ?> продаж(и)</div></div>
    <div><div class="muted">— из них в долг</div><div style="font-size:22px; font-weight:700; color:var(--danger)"><?= number_format($report['on_credit'], 2) ?> $</div></div>
    <div><div class="muted">Возвращено</div><div style="font-size:22px; font-weight:700"><?= number_format($report['returned'], 2) ?> $</div><div class="muted"><?= $report['return_count'] ?> возврат(ов)</div></div>
  </div>

  <?php // K-2 (внешняя приёмка, 03.09.2026): возвраты без привязки к счёту — отдельной строкой с
        // причинами. Это единственный способ уменьшить долг клиента без документа-основания, поэтому
        // за день они должны быть видны отдельно, а не растворяться в общей сумме "Возвращено". ?>
  <?php if (!empty($report['free_returns'])): ?>
    <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border)">
      <div class="row" style="align-items:baseline">
        <div><strong>Возвраты без счёта</strong>
          <div class="muted">входят в «Возвращено» выше; показаны отдельно, потому что оформлены без исходной продажи</div>
        </div>
        <div style="flex:0; font-size:20px; font-weight:700; white-space:nowrap"><?= number_format($report['free_returns_total'], 2) ?> $</div>
      </div>
      <table style="margin-top:10px">
        <tr><th>Документ</th><th>Сумма</th><th>Причина</th></tr>
        <?php foreach ($report['free_returns'] as $fr): ?>
          <tr>
            <td><?= htmlspecialchars($fr['ref']) ?></td>
            <td><?= number_format($fr['amount'], 2) ?> $</td>
            <td class="muted"><?= htmlspecialchars($fr['reason']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Реальное движение денег (по банковским проводкам за день)</h2>
  <p class="muted">Не то же самое, что "продано" — сюда попадает оплата долга за прошлые дни, а продажа "в долг" сегодня денег не даёт вообще.</p>
  <table>
    <tr><th></th><th>Наличные</th><th>Карта / QR / перевод</th><th>Итого</th></tr>
    <tr>
      <td>Принято</td>
      <td class="ok"><?= number_format($report['money_in']['cash'], 2) ?> $</td>
      <td class="ok"><?= number_format($report['money_in']['electronic'], 2) ?> $</td>
      <td class="ok"><strong><?= number_format($report['money_in']['cash'] + $report['money_in']['electronic'], 2) ?> $</strong></td>
    </tr>
    <tr>
      <td>Выдано</td>
      <td class="err"><?= number_format($report['money_out']['cash'], 2) ?> $</td>
      <td class="err"><?= number_format($report['money_out']['electronic'], 2) ?> $</td>
      <td class="err"><strong><?= number_format($report['money_out']['cash'] + $report['money_out']['electronic'], 2) ?> $</strong></td>
    </tr>
  </table>
  <?php // K-4: без слова "Dolibarr" — суть та же, словами кассира. ?>
  <p class="muted">Карта, QR и перевод показаны вместе одной суммой: все три идут на один и тот же электронный счёт направления, и разделить их по отдельности нельзя.</p>
  <?php if ($report['advance_count'] > 0): ?>
    <p class="muted">За день оформлено авансов: <?= $report['advance_count'] ?> (сумма учтена в "Принято" выше).</p>
  <?php endif; ?>
</div>

<?php if ($isToday && $report['cash_balance_now'] !== null): ?>
<div class="card">
  <h2>Наличная касса сейчас</h2>
  <div style="font-size:26px; font-weight:700;"><?= number_format($report['cash_balance_now'], 2) ?> $</div>
  <p class="muted" style="margin-top:8px"><a href="debt.php">Передать кассу — раздел «Касса / Долги» →</a></p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Выгрузка</h2>
  <p class="stage-row">
    <a class="btn secondary" href="shift_excel.php?date=<?= htmlspecialchars($date) ?>">📄 Скачать Excel</a>
    <a class="btn secondary" href="shift_print.php?date=<?= htmlspecialchars($date) ?>" target="_blank">🖨️ Печать</a>
  </p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
