<?php
/**
 * «Цены нового прихода» (11.09.2026, решение пользователя — пункт 1).
 *
 * После приёмки товара касса заносит принятые позиции в очередь (includes/pricing.php). Здесь
 * руководство видит по каждому приходу: сколько стоил товар раньше, сколько стоит новая партия с
 * логистикой, средняя себестоимость, текущие цены и наценку; ставит новую дилерскую цену (оптовая
 * +5% и розничная +20% пересчитываются сами) и отмечает приход проверенным.
 *
 * Пока приход не проверен, касса продаёт по старой цене — кроме товаров, у которых цена стала не выше
 * себестоимости (вариант «б»): они подсвечены красным «касса не продаёт».
 *
 * Наценку подсвечиваем от БОЛЬШЕЙ из двух себестоимостей (новая партия / средняя): средняя разбавлена
 * старым дешёвым остатком, и когда он распродастся, наценка станет той, что к новой партии.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/sellable_stock.php';

$dirs = visible_directions($cfg);
$db = pricing_db();
pricing_ensure_table();
$who = (string)($_SESSION['user']['name'] ?? '');

// строки очереди, видимые этому пользователю (направление — по первой букве kod_sap)
function np_rows(mysqli $db, array $dirs, ?int $orderId = null): array
{
    $stockSql = nt_sellable_stock_sql($db, 'p');
    $w = $orderId ? ' AND r.fk_order = ' . (int)$orderId : '';
    $sql = "SELECT r.rowid rid, r.fk_order, r.fk_product, r.pmp_before, r.datec, r.reopened,
                   c.ref order_ref, s.nom supplier, p.ref, p.label, p.price, p.pmp, pe.kod_sap, $stockSql stock,
                   (SELECT d.subprice FROM llx_commande_fournisseurdet d WHERE d.fk_commande = r.fk_order AND d.fk_product = r.fk_product LIMIT 1) raw_price
              FROM llx_nt_price_review r
              JOIN llx_product p ON p.rowid = r.fk_product
              LEFT JOIN llx_product_extrafields pe ON pe.fk_object = p.rowid
              JOIN llx_commande_fournisseur c ON c.rowid = r.fk_order
              JOIN llx_societe s ON s.rowid = c.fk_soc
             WHERE r.status = 'open' $w
             ORDER BY r.fk_order, p.ref";
    $rows = [];
    $r = $db->query($sql);
    while ($x = $r->fetch_assoc()) {
        if (!in_array(strtoupper(substr((string)$x['kod_sap'], 0, 1)), $dirs, true)) continue;
        $rows[] = $x;
    }
    if (!$rows) return [];

    // Поставка для расчёта себестоимости — партия, если заказ в ней, иначе сам заказ (как в logistics.php)
    $orders = array_unique(array_map(fn($x) => (int)$x['fk_order'], $rows));
    $scope = [];
    foreach ($orders as $oid) {
        $b = $db->query("SELECT fk_batch FROM llx_supplier_shipment_batch_order WHERE fk_order = $oid LIMIT 1")->fetch_assoc();
        $scope[$oid] = $b ? ['batch', (int)$b['fk_batch']] : ['order', $oid];
    }
    foreach ($rows as &$x) {
        [$st, $sid] = $scope[(int)$x['fk_order']];
        $pid = (int)$x['fk_product'];
        $lr = $db->query("SELECT landed_cost_per_unit FROM llx_supplier_landed_result WHERE scope_type='$st' AND scope_id=$sid AND fk_product=$pid")->fetch_assoc();
        $bl = $db->query("SELECT prior_qty, prior_cost FROM llx_supplier_landed_baseline WHERE scope_type='$st' AND scope_id=$sid AND fk_product=$pid")->fetch_assoc();
        $x['has_logistics'] = (int)$db->query("SELECT COUNT(*) n FROM llx_supplier_logistics_expense WHERE scope_type='$st' AND scope_id=$sid")->fetch_assoc()['n'] > 0;
        $x['batch_cost'] = $lr ? (float)$lr['landed_cost_per_unit'] : (float)$x['raw_price'];
        // «было» — себестоимость остатка до этой поставки: базовая точка логистики точнее снимка
        // перед приёмкой (если расходы внесли раньше приёмки, снимок уже с ними перемешан)
        $x['cost_before'] = $bl ? ((float)$bl['prior_qty'] > 0 ? (float)$bl['prior_cost'] : null)
                                : ((float)$x['pmp_before'] > 0 ? (float)$x['pmp_before'] : null);
        $x['price'] = (float)$x['price']; $x['pmp'] = (float)$x['pmp'];
    }
    unset($x);
    return $rows;
}

$message = ''; $messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $rows = np_rows($db, $dirs, $orderId);
    $changed = 0; $below = []; $errors = [];
    $log = $db->prepare("INSERT INTO llx_nt_product_log (fk_product, field, old_value, new_value, who, tool, datec) VALUES (?, 'Цена продажи', ?, ?, ?, 'boss', NOW())");
    foreach ($rows as $x) {
        $pid = (int)$x['fk_product'];
        $raw = trim((string)($_POST['price'][$pid] ?? ''));
        if ($raw === '') continue;
        $new = round((float)str_replace([' ', ','], ['', '.'], $raw), 2);
        if ($new <= 0) { $errors[] = $x['ref'] . ': цена должна быть больше нуля'; continue; }
        if (abs($new - $x['price']) >= 0.005) {
            if ($api->saveSalePrice($pid, $new)) {   // дилерская + оптовая и розничная (пункт 3)
                $o = number_format($x['price'], 2, '.', ''); $n = number_format($new, 2, '.', '');
                $log->bind_param('isss', $pid, $o, $n, $who); $log->execute();
                $changed++;
            } else {
                $errors[] = $x['ref'] . ': ' . $api->lastError;
                continue;
            }
        }
        if ($x['pmp'] > 0 && $new <= $x['pmp']) $below[] = $x['ref'];
    }
    $log->close();
    if ($errors) {
        $message = 'Не всё сохранено — приход НЕ отмечен проверенным: ' . implode('; ', $errors); $messageType = 'err';
    } else {
        $ids = implode(',', array_map(fn($x) => (int)$x['rid'], $rows));
        if ($ids !== '') {
            $st = $db->prepare("UPDATE llx_nt_price_review SET status='done', done_at=NOW(), done_by=? WHERE rowid IN ($ids)");
            $st->bind_param('s', $who); $st->execute(); $st->close();
        }
        flash_set('Приход ' . ($rows[0]['order_ref'] ?? '') . ' проверен. Цен изменено: ' . $changed . '.'
            . ($below ? ' Ниже себестоимости по вашему решению: ' . implode(', ', $below) . ' — касса их теперь продаёт.' : ''), $below ? 'warn' : 'ok');
        header('Location: new_prices.php');
        exit;
    }
}
$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$rows = np_rows($db, $dirs);
$groups = [];
foreach ($rows as $x) $groups[(int)$x['fk_order']][] = $x;
$levels = pricing_levels(array_map(fn($x) => (int)$x['fk_product'], $rows));
$pct = fn(float $price, ?float $cost) => ($cost && $cost > 0) ? ($price / $cost - 1) * 100 : null;

require __DIR__ . '/includes/layout_top.php';
?>
<style>
  table.np td, table.np th { padding: 7px 6px; font-size: 13.5px; }
  table.np input.np-price { width: 92px; margin: 0; padding: 6px 8px; }
  tr.np-red td { background: #fee2e2; }
  tr.np-yellow td { background: #fef3c7; }
  .np-tag { font-size: 11.5px; font-weight: 600; }
  .np-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>

<h1>Цены нового прихода</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<p class="muted">Здесь появляется товар, принятый на склад. Проверьте наценку, при необходимости поставьте
  новую <strong>дилерскую</strong> цену — оптовая (+5%) и розничная (+20%) пересчитаются сами — и отметьте
  приход проверенным. Пока приход не проверен, касса продаёт по старой цене, кроме
  <span style="background:#fee2e2; padding:0 4px">красных</span> строк: у них цена не выше себестоимости,
  касса их не продаёт. <span style="background:#fef3c7; padding:0 4px">Жёлтые</span> — наценка меньше
  <?= (int)(PRICING_LOW_MARKUP * 100) ?>%.</p>

<?php if (!$groups): ?>
  <div class="card"><p class="muted" style="margin:0">Непроверенных приходов нет.</p></div>
<?php endif; ?>

<?php foreach ($groups as $oid => $items):
      $g = $items[0]; $noLog = !$g['has_logistics']; ?>
<div class="card">
  <h2><?= htmlspecialchars($g['supplier']) ?> · заказ <?= htmlspecialchars($g['order_ref']) ?>
    <span class="muted" style="font-weight:400; font-size:14px">· принят <?= date('d.m.Y', strtotime($g['datec'])) ?> · позиций <?= count($items) ?></span></h2>
  <?php if ($noLog): ?>
    <p class="warn">Расходы по этой поставке (фрахт, таможня…) ещё не внесены — себестоимость новой партии
      пока <strong>без логистики</strong>. Лучше дождаться, пока закупщики их внесут.</p>
  <?php endif; ?>
  <form method="post" class="np-form" data-order="<?= (int)$oid ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_order">
    <input type="hidden" name="order_id" value="<?= (int)$oid ?>">
    <div class="row" style="align-items:end; margin-bottom:8px">
      <div style="flex:0 0 auto"><label>Подставить наценку, %</label><input type="number" step="0.1" class="np-mk" style="width:100px; margin:0" placeholder="20"></div>
      <div style="flex:0 0 auto"><label>от</label>
        <select class="np-base" style="margin:0"><option value="batch">новой партии</option><option value="pmp">средней себестоимости</option></select></div>
      <div style="flex:0 0 auto"><button type="button" class="secondary np-apply">Подставить всем</button></div>
    </div>
    <div style="overflow-x:auto">
    <table class="np">
      <thead><tr>
        <th>Артикул</th><th>Наименование</th><th class="np-num">Остаток</th>
        <th class="np-num">Себест.<br>была</th><th class="np-num">Себест.<br>новой партии</th><th class="np-num">Себест.<br>средняя</th>
        <th class="np-num">Дилерская</th><th class="np-num">Оптовая<br>+5%</th><th class="np-num">Розничная<br>+20%</th>
        <th class="np-num">Наценка<br>к партии</th><th class="np-num">Наценка<br>к средней</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $x):
            $pid = (int)$x['fk_product'];
            $lv = $levels[$pid] ?? []; ?>
        <tr data-pmp="<?= $x['pmp'] ?>" data-batch="<?= $x['batch_cost'] ?>" data-price="<?= $x['price'] ?>">
          <td style="white-space:nowrap"><a href="product_card.php?id=<?= $pid ?>"><?= htmlspecialchars($x['ref']) ?></a></td>
          <td><?= htmlspecialchars(mb_strimwidth((string)$x['label'], 0, 60, '…')) ?></td>
          <td class="np-num"><?= rtrim(rtrim(number_format((float)$x['stock'], 3, '.', ' '), '0'), '.') ?></td>
          <td class="np-num muted"><?= $x['cost_before'] !== null ? number_format($x['cost_before'], 2, '.', ' ') : '—' ?></td>
          <td class="np-num"><?= number_format($x['batch_cost'], 2, '.', ' ') ?></td>
          <td class="np-num"><?= number_format($x['pmp'], 2, '.', ' ') ?></td>
          <td class="np-num"><input type="number" step="0.01" min="0.01" class="np-price" name="price[<?= $pid ?>]" value="<?= number_format($x['price'], 2, '.', '') ?>"></td>
          <td class="np-num np-l2"><?= isset($lv[2]) ? number_format($lv[2], 2, '.', ' ') : '—' ?></td>
          <td class="np-num np-l3"><?= isset($lv[3]) ? number_format($lv[3], 2, '.', ' ') : '—' ?></td>
          <td class="np-num np-mb"></td><td class="np-num np-mp"></td>
          <td class="np-tag"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <button type="submit" style="margin-top:10px">Сохранить цены и отметить приход проверенным</button>
  </form>
</div>
<?php endforeach; ?>

<script>
(function () {
  const LOW = <?= json_encode(PRICING_LOW_MARKUP * 100) ?>;
  const f = v => v.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function syncRow(tr) {
    const p = parseFloat(tr.querySelector('.np-price').value) || 0;
    const pmp = parseFloat(tr.dataset.pmp) || 0, batch = parseFloat(tr.dataset.batch) || 0;
    const orig = parseFloat(tr.dataset.price) || 0;
    const changed = Math.abs(p - orig) >= 0.005;
    // оптовая и розничная — как их пересчитает сервер
    if (changed && p > 0) { tr.querySelector('.np-l2').textContent = f(Math.round(p * 105) / 100); tr.querySelector('.np-l3').textContent = f(Math.round(p * 120) / 100); }
    const mk = c => c > 0 && p > 0 ? (p / c - 1) * 100 : null;
    const mb = mk(batch), mp = mk(pmp);
    tr.querySelector('.np-mb').textContent = mb === null ? '—' : mb.toFixed(1) + '%';
    tr.querySelector('.np-mp').textContent = mp === null ? '—' : mp.toFixed(1) + '%';
    const worst = Math.max(pmp, batch);
    const red = pmp > 0 && p <= pmp, low = !red && worst > 0 && (p / worst - 1) * 100 < LOW;
    tr.classList.toggle('np-red', red); tr.classList.toggle('np-yellow', low);
    tr.querySelector('.np-tag').textContent = red ? 'касса не продаёт' : (low ? 'мало наценки' : (changed ? 'новая цена' : ''));
  }
  document.querySelectorAll('.np-form').forEach(form => {
    form.querySelectorAll('tbody tr').forEach(syncRow);
    form.addEventListener('input', e => { if (e.target.matches('.np-price')) syncRow(e.target.closest('tr')); });
    form.querySelector('.np-apply').addEventListener('click', () => {
      const mk = parseFloat(form.querySelector('.np-mk').value);
      if (!(mk > -100)) return;
      const base = form.querySelector('.np-base').value;
      form.querySelectorAll('tbody tr').forEach(tr => {
        const c = parseFloat(base === 'batch' ? tr.dataset.batch : tr.dataset.pmp) || 0;
        if (c > 0) { tr.querySelector('.np-price').value = (Math.round(c * (1 + mk / 100) * 100) / 100).toFixed(2); syncRow(tr); }
      });
    });
    form.addEventListener('submit', e => {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      const red = form.querySelectorAll('tr.np-red').length;
      appConfirm(red ? 'Позиций с ценой не выше себестоимости: ' + red + '. После отметки касса начнёт продавать их по этой цене. Сохранить и отметить приход проверенным?'
                     : 'Сохранить цены и отметить приход проверенным?').then(ok => {
        if (ok) { form.dataset.confirmed = '1'; form.requestSubmit(); }
      });
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
