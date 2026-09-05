<?php
/**
 * Деньги: пришло / ушло / разница за период, по всем счетам компании.
 * Источник — банковские проводки: единственное место, где одновременно видны продажи, закупки,
 * зарплата и хозрасходы. Переводы между своими счетами вынесены отдельно и в «пришло/ушло» не
 * входят — иначе передача кассы выглядела бы одновременно доходом и расходом.
 *
 * ⚠️ Итоги считаются ОТДЕЛЬНО ПО КАЖДОЙ ВАЛЮТЕ (04.09.2026): счета разновалютные, и складывать сумы
 * с евро в одно число бессмысленно. Евро показываем евро, сумы — сумами.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/reports.php';
require_once __DIR__ . '/includes/period_form.php';

[$from, $to] = report_period();
$dirs = visible_directions($cfg);
$m = report_money($api, $cfg, $from, $to, $dirs);

/** Подпись валюты: доллары показываем знаком, остальные — кодом. */
function cur_label(string $c): string { return $c === 'USD' ? '$' : $c; }

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Деньги: пришло и ушло</h1>
<?php render_period_form('report_money.php', $from, $to); ?>

<?php foreach ($m['by_currency'] as $cur => $v): ?>
  <div class="card">
    <h2>В <?= $cur === 'USD' ? 'долларах' : ($cur === 'EUR' ? 'евро' : ($cur === 'UZS' ? 'сумах' : ($cur === 'RUB' ? 'рублях' : htmlspecialchars($cur)))) ?></h2>
    <div class="kpi-grid">
      <div class="kpi"><div class="k">Пришло</div>
        <div class="v pos"><?= number_format($v['in'], 2, '.', ' ') ?> <?= htmlspecialchars(cur_label($cur)) ?></div></div>
      <div class="kpi"><div class="k">Ушло</div>
        <div class="v neg"><?= number_format($v['out'], 2, '.', ' ') ?> <?= htmlspecialchars(cur_label($cur)) ?></div></div>
      <div class="kpi"><div class="k">Разница</div>
        <div class="v <?= $v['diff'] >= 0 ? 'pos' : 'neg' ?>"><?= number_format($v['diff'], 2, '.', ' ') ?> <?= htmlspecialchars(cur_label($cur)) ?></div></div>
      <div class="kpi"><div class="k">Остаток сейчас</div>
        <div class="v"><?= number_format($v['balance'], 2, '.', ' ') ?> <?= htmlspecialchars(cur_label($cur)) ?></div></div>
      <div class="kpi"><div class="k">Переложено между счетами</div>
        <div class="v"><?= number_format($v['moved'], 2, '.', ' ') ?> <?= htmlspecialchars(cur_label($cur)) ?></div>
        <div class="muted">не доход и не расход</div></div>
    </div>
  </div>
<?php endforeach; ?>

<div class="card">
  <h2>По счетам</h2>
  <p class="muted">Каждый счёт — в своей валюте. «Остаток» — текущий, на сегодня, а не на конец периода.</p>
  <table>
    <tr><th>Счёт</th><th>Валюта</th><th class="num">Пришло</th><th class="num">Ушло</th><th class="num">Разница</th><th class="num">Переложено</th><th class="num">Остаток сейчас</th></tr>
    <?php foreach ($m['by_account'] as $a): ?>
      <?php $d = $a['in'] - $a['out']; ?>
      <tr>
        <td><?= htmlspecialchars($a['label']) ?></td>
        <td><?= htmlspecialchars($a['currency']) ?></td>
        <td class="num"><?= $a['in'] ? number_format($a['in'], 2, '.', ' ') : '—' ?></td>
        <td class="num"><?= $a['out'] ? number_format($a['out'], 2, '.', ' ') : '—' ?></td>
        <td class="num" style="color:<?= $d >= 0 ? 'var(--ok)' : 'var(--danger)' ?>"><?= number_format($d, 2, '.', ' ') ?></td>
        <td class="num muted"><?= $a['moved'] ? number_format($a['moved'], 2, '.', ' ') : '—' ?></td>
        <td class="num"><strong><?= $a['balance'] === null ? '—' : number_format($a['balance'], 2, '.', ' ') ?></strong></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if (count($dirs) === 1): ?>
    <p class="muted" style="margin-top:10px">Показаны счета вашего направления и общие счета компании.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Как это считается</h2>
  <p class="muted">Суммы берутся из проводок по счетам за выбранные дни: всё, что зачислено — «пришло»,
  всё, что списано — «ушло». Валюты не смешиваются: сумовый счёт считается в сумах, евровый в евро.
  Продажи в долг сюда не попадают: денег по ним ещё не было, их видно в отчёте «Продажи и долги».</p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
