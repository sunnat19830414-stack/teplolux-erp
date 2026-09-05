<?php
/**
 * Моя касса: остаток, история движений, свои расходы и передача денег дальше.
 * У Умида — касса шефа; у Суннатиллы — своя, и передавать он может шефу.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boss_cash.php';
require_once __DIR__ . '/includes/stock_lookup.php';

$me = $_SESSION['user'];
$acc = $me['cash_account'] ?? null;
if (!$acc) {
    http_response_code(403);
    die('У вас не настроена касса.');
}
$accId = (int)$acc['id'];

// Куда можно передать: Суннатилла — шефу; шеф — на счета компании.
$targets = [];
if ($accId !== (int)$cfg['boss_cash_account']['id']) {
    $targets['boss'] = ['id' => (int)$cfg['boss_cash_account']['id'], 'label' => $cfg['boss_cash_account']['label']];
} else {
    $targets['usd'] = ['id' => $cfg['currency_accounts']['USD'], 'label' => 'Долларовый счёт компании'];
    $targets['eur'] = ['id' => $cfg['currency_accounts']['EUR'], 'label' => 'Счёт компании в евро'];
    $targets['rub'] = ['id' => $cfg['currency_accounts']['RUB'], 'label' => 'Рублёвый счёт компании'];
    $targets['uzs'] = ['id' => (int)$cfg['uzs_account_id'], 'label' => 'Сумовый счёт компании'];
}
// Валюта каждого счёта — из его же карточки в Dolibarr. Нужна, чтобы не записать доллары сумами.
$myCur = account_currency($accId);
foreach ($targets as $k => $t) {
    $targets[$k]['currency'] = account_currency((int)$t['id']);
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'expense') {
        $r = boss_record_expense(
            $api, $accId,
            (int)($_POST['category_id'] ?? 0),
            round((float)str_replace(',', '.', $_POST['amount'] ?? '0'), 2),
            trim($_POST['comment'] ?? ''),
            $me['login']
        );
        flash_set($r['ok'] ? (($r['warning'] ?? '') . 'Расход записан, деньги списаны с кассы.') : $r['error'],
                  $r['ok'] ? (!empty($r['warning']) ? 'warn' : 'ok') : 'err');
        header('Location: cash.php');
        exit;
    } elseif ($action === 'transfer') {
        $key = $_POST['target'] ?? '';
        $t = $targets[$key] ?? null;
        if (!$t) {
            flash_set('Выберите, кому передаёте деньги.', 'err');
        } else {
            $amt = round((float)str_replace(',', '.', $_POST['amount'] ?? '0'), 2);
            $rate = (float)str_replace(',', '.', $_POST['rate'] ?? '0');
            $r = boss_transfer($api, $accId, (int)$t['id'], $t['label'], $amt,
                $me['name'], trim($_POST['comment'] ?? ''), $myCur, $t['currency'], $rate);
            if ($r['ok']) {
                $txt = 'Передано ' . number_format($amt, 2, '.', ' ') . ' ' . $myCur . ' — ' . $t['label'];
                if (empty($r['same_currency'])) {
                    $txt .= ' (зачислено ' . number_format($r['received'], 2, '.', ' ') . ' ' . $t['currency'] . ')';
                }
                flash_set($txt . '.', 'ok');
            } else {
                flash_set($r['error'], 'err');
            }
        }
        header('Location: cash.php');
        exit;
    }
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$balance = $api->getAccountBalance($accId);
$lines = array_reverse($api->getBankLines($accId));   // свежие сверху
$categories = boss_expense_categories();

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Моя касса</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2><?= htmlspecialchars($acc['label']) ?></h2>
  <div style="font-size:30px; font-weight:700"><?= $balance === null ? '—' : money((float)$balance) ?></div>
</div>

<div class="grid-2col">
<div>

<div class="card">
  <h2>Записать расход</h2>
  <?php if (empty($categories)): ?>
    <p class="muted">Виды расходов ещё не заведены — их создаёт Абдурашид в разделе «Хозрасходы».</p>
  <?php else: ?>
    <form method="post" onsubmit="return appConfirmSubmit(this, 'Записать расход и списать деньги с кассы?');">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="expense">
      <label>Вид расхода</label>
      <select name="category_id" required>
        <option value="">— выберите —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['rowid'] ?>"><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Сумма, $</label>
      <input type="number" name="amount" step="0.01" min="0.01" required>
      <label>На что <span class="muted">(необязательно)</span></label>
      <input type="text" name="comment" placeholder="коротко, чтобы потом вспомнить">
      <button type="submit">Записать расход</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Передать деньги</h2>
  <form method="post" onsubmit="return appConfirmSubmit(this, 'Передать деньги? Операция сразу изменит оба остатка.');">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="transfer">
    <label>Кому</label>
    <select name="target" id="trTarget" required>
      <?php foreach ($targets as $k => $t): ?>
        <option value="<?= htmlspecialchars($k) ?>" data-currency="<?= htmlspecialchars($t['currency']) ?>">
          <?= htmlspecialchars($t['label']) ?> (<?= htmlspecialchars($t['currency']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <label>Сумма, <?= htmlspecialchars($myCur) ?> <span class="muted">— столько уйдёт с вашего счёта</span></label>
    <input type="number" name="amount" id="trAmount" step="0.01" min="0.01" required>
    <div id="trRateBox" style="display:none">
      <label>Курс: 1 <?= htmlspecialchars($myCur) ?> = <span id="trRateCur"></span></label>
      <input type="number" name="rate" id="trRate" step="0.0001" min="0.0001">
      <p class="muted" id="trHint" style="margin:-4px 0 10px"></p>
    </div>
    <label>Комментарий <span class="muted">(необязательно)</span></label>
    <input type="text" name="comment">
    <button type="submit">Передать</button>
  </form>
</div>

</div>
<div>

<div class="card">
  <h2>Движение денег</h2>
  <?php if (empty($lines)): ?>
    <p class="muted">Движений пока не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Дата</th><th>Описание</th><th class="num">Сумма</th></tr>
      <?php foreach (array_slice($lines, 0, 80) as $l): ?>
        <?php $amt = (float)($l['amount'] ?? 0); ?>
        <tr>
          <td><?= !empty($l['date']) ? date('d.m.Y', (int)$l['date']) : (!empty($l['dateo']) ? date('d.m.Y', (int)$l['dateo']) : '') ?></td>
          <td><?= htmlspecialchars($l['label'] ?? '') ?></td>
          <td class="num" style="color:<?= $amt >= 0 ? 'var(--ok)' : 'var(--danger)' ?>">
            <?= ($amt >= 0 ? '+' : '') . number_format($amt, 2, '.', ' ') ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($lines) > 80): ?>
      <p class="muted">Показаны последние 80 из <?= count($lines) ?>.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

</div>
</div>

<script>
// Курс нужен только когда валюты счетов разные. Сразу показываем, сколько получит адресат —
// чтобы «100 долларов» не превратились молча в «100 сум».
(function () {
  const sel = document.getElementById('trTarget');
  const amt = document.getElementById('trAmount');
  const box = document.getElementById('trRateBox');
  const rate = document.getElementById('trRate');
  const rateCur = document.getElementById('trRateCur');
  const hint = document.getElementById('trHint');
  if (!sel || !amt) return;
  const myCur = <?= json_encode($myCur) ?>;

  function targetCur() {
    const o = sel.options[sel.selectedIndex];
    return o ? (o.dataset.currency || 'USD') : 'USD';
  }
  function sync() {
    const t = targetCur();
    if (t === myCur) { box.style.display = 'none'; rate.required = false; rate.value = ''; hint.textContent = ''; return; }
    box.style.display = '';
    rate.required = true;
    rateCur.textContent = t;
    const a = parseFloat(amt.value) || 0;
    const r = parseFloat(rate.value) || 0;
    hint.textContent = (a > 0 && r > 0)
      ? ('получатель получит ' + (a * r).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ' + t)
      : '';
  }
  sel.addEventListener('change', sync);
  amt.addEventListener('input', sync);
  rate.addEventListener('input', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
