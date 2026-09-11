<?php
/**
 * «Наценки: опт и розница» (11.09.2026, просьба пользователя: «у босса должна быть возможность
 * менять наценки на оптовую и розничную цену»).
 *
 * Оптовая и розничная считаются от дилерской (уровень 1 = цена кассы). Наценки хранятся в llx_const
 * (NT_PRICE_MARKUP_LEVEL2/3, проценты) и применяются при каждой смене дилерской цены во всех трёх
 * инструментах (includes/pricing.php). После смены наценок оптовую и розничную у ВСЕХ товаров можно
 * сразу пересчитать — иначе старые цены уровней остались бы по прежним процентам.
 *
 * Только руководитель (пользователь без направления) — Суннатилла сюда не попадает.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pricing.php';

if (user_direction() !== null) {
    http_response_code(403);
    die('Наценки меняет только руководитель.');
}

$message = ''; $messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $p2 = (float)str_replace([' ', ','], ['', '.'], $_POST['mk2'] ?? '');
    $p3 = (float)str_replace([' ', ','], ['', '.'], $_POST['mk3'] ?? '');
    $old = pricing_markups();
    $r = pricing_set_markups($p2, $p3, (string)($_SESSION['user']['name'] ?? ''));
    if (!$r['ok']) {
        $message = $r['error']; $messageType = 'err';
    } else {
        $txt = 'Наценки сохранены: оптовая ' . $p2 . '%, розничная ' . $p3 . '%.';
        if (!empty($_POST['recalc'])) {
            try {
                $n = pricing_recalc_all_levels(5);
                $txt .= ' Оптовая и розничная пересчитаны у всех товаров (записано цен: ' . $n . ').';
            } catch (Throwable $e) {
                $txt .= ' Пересчитать цены товаров НЕ удалось: ' . $e->getMessage() . ' — наценки применятся при следующей смене цены.';
            }
        } else {
            $txt .= ' Цены товаров не пересчитывались — новые наценки применятся при следующей смене дилерской цены.';
        }
        flash_set($txt, 'ok');
        header('Location: price_settings.php');
        exit;
    }
}
$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$mk = pricing_markups();
$note = pricing_markups_note();
$db = pricing_db();
// сколько товаров сейчас не по действующим наценкам (например, если сохранили без пересчёта)
$off = (int)$db->query("SELECT COUNT(*) n FROM llx_product p WHERE p.price > 0 AND (
    ABS(ROUND(p.price * " . (1 + $mk[2]) . ", 2) - COALESCE((SELECT pp.price FROM llx_product_price pp WHERE pp.fk_product = p.rowid AND pp.price_level = 2 ORDER BY pp.date_price DESC, pp.rowid DESC LIMIT 1), -1)) > 0.011
 OR ABS(ROUND(p.price * " . (1 + $mk[3]) . ", 2) - COALESCE((SELECT pp.price FROM llx_product_price pp WHERE pp.fk_product = p.rowid AND pp.price_level = 3 ORDER BY pp.date_price DESC, pp.rowid DESC LIMIT 1), -1)) > 0.011)")->fetch_assoc()['n'];
$pctTxt = fn(float $v) => rtrim(rtrim(number_format($v * 100, 2, '.', ''), '0'), '.');

require __DIR__ . '/includes/layout_top.php';
?>
<h1>Наценки: опт и розница</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:640px">
  <p class="muted" style="margin-top:0">Касса продаёт по <strong>дилерской</strong> цене. Оптовая и розничная
    считаются от неё по этим наценкам — каждый раз, когда меняется дилерская цена (в «Ценах нового прихода»,
    «Склад: цены» и в каталоге).</p>
  <form method="post" id="mkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="row">
      <div><label>Оптовая, % к дилерской</label>
        <input type="number" name="mk2" id="mk2" step="0.1" min="0" max="500" value="<?= $pctTxt($mk[2]) ?>" required></div>
      <div><label>Розничная, % к дилерской</label>
        <input type="number" name="mk3" id="mk3" step="0.1" min="0" max="500" value="<?= $pctTxt($mk[3]) ?>" required></div>
    </div>
    <p class="muted" id="mkExample" style="margin-top:-4px"></p>
    <label style="font-weight:400; display:flex; gap:8px; align-items:flex-start">
      <input type="checkbox" name="recalc" value="1" checked style="width:auto; margin-top:3px">
      <span>Сразу пересчитать оптовую и розничную у всех товаров. <span class="muted">Дилерская цена и цена
        в кассе не меняются.</span></span></label>
    <button type="submit">Сохранить</button>
  </form>
  <?php if ($note): ?><p class="muted" style="margin-bottom:0">Последнее изменение: <?= htmlspecialchars($note) ?>.</p><?php endif; ?>
  <?php if ($off): ?><p class="warn" style="margin-bottom:0">У <?= $off ?> товаров оптовая или розничная не совпадает с
    действующими наценками — сохраните с галочкой «пересчитать», чтобы привести в порядок.</p><?php endif; ?>
</div>

<script>
(function () {
  const a = document.getElementById('mk2'), b = document.getElementById('mk3'), ex = document.getElementById('mkExample');
  const f = v => v.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function sync() {
    const x = parseFloat(a.value) || 0, y = parseFloat(b.value) || 0;
    ex.textContent = 'Пример: дилерская 100,00 → оптовая ' + f(100 * (1 + x / 100)) + ', розничная ' + f(100 * (1 + y / 100)) +
      (x > y ? ' — оптовая не может быть больше розничной' : '');
  }
  a.addEventListener('input', sync); b.addEventListener('input', sync); sync();
  document.getElementById('mkForm').addEventListener('submit', function (e) {
    if (this.dataset.confirmed === '1') return;
    e.preventDefault();
    const form = this;
    appConfirm('Сохранить наценки: оптовая ' + a.value + '%, розничная ' + b.value + '%?' +
      (form.recalc.checked ? ' Оптовая и розничная будут пересчитаны у всех товаров.' : '')).then(ok => {
      if (ok) { form.dataset.confirmed = '1'; form.requestSubmit(); }
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
