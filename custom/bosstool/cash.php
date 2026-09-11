<?php
/**
 * Моя касса: остаток, история движений, свои расходы и передача денег дальше.
 * У Умида — касса шефа; у Суннатиллы — своя, и передавать он может шефу.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/boss_cash.php';
require_once __DIR__ . '/includes/stock_lookup.php';
require_once __DIR__ . '/includes/owner_moves.php';

$me = $_SESSION['user'];
$acc = $me['cash_account'] ?? null;
if (!$acc) {
    http_response_code(403);
    die('У вас не настроена касса.');
}
$accId = (int)$acc['id'];
// Касса шефа (11.09.2026): пополнение счетов компании из своих денег = вложение собственника,
// деньги компании, забранные себе, = изъятие. См. includes/owner_moves.php.
$isBoss = $accId === (int)$cfg['boss_cash_account']['id'];

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
    } elseif ($action === 'delete_expense') {
        $r = boss_delete_expense($api, (int)($_POST['expense_id'] ?? 0), $me['login']);
        flash_set($r['ok'] ? ('Расход удалён.' . (!empty($r['note']) ? ' ' . $r['note'] : '')) : $r['error'],
                  $r['ok'] ? 'ok' : 'err');
        header('Location: cash.php');
        exit;
    } elseif ($isBoss && $action === 'topup') {
        // Пополнить счёт компании: из кассы (деньги компании у Умида) или из своих (вложение).
        $t = $targets[$_POST['target'] ?? ''] ?? null;
        $source = ($_POST['source'] ?? 'cash') === 'own' ? 'own' : 'cash';
        $amt = round((float)str_replace([' ', ','], ['', '.'], $_POST['amount'] ?? '0'), 2);
        $rate = (float)str_replace([' ', ','], ['', '.'], $_POST['rate'] ?? '0');   // единиц валюты счёта за 1 $
        $comment = trim($_POST['comment'] ?? '');
        if (!$t) {
            flash_set('Выберите, какой счёт пополняете.', 'err');
        } elseif ($amt <= 0) {
            flash_set('Укажите сумму больше нуля.', 'err');
        } elseif ($t['currency'] !== 'USD' && $rate <= 0) {
            flash_set("Укажите курс: сколько {$t['currency']} за 1 \$.", 'err');
        } elseif ($source === 'own') {
            // сумма — в валюте пополняемого счёта: столько на него и придёт
            $r = owner_contribute((int)$t['id'], $t['label'], $t['currency'], $amt, $t['currency'] === 'USD' ? null : $rate, $me['name'], $comment);
            flash_set($r['ok'] ? 'Вложение собственника: +' . money($amt, $t['currency']) . ' на «' . $t['label'] . '». Касса не тронута.' : $r['error'],
                      $r['ok'] ? 'ok' : 'err');
        } else {
            // сумма — в долларах из кассы; чего в кассе не хватает, записывается вложением
            $bal = bank_account_balance_direct($accId);
            $fromCash = round(min($amt, max(0.0, floor($bal * 100) / 100)), 2);
            $rest = round($amt - $fromCash, 2);
            $conv = fn(float $usd) => $t['currency'] === 'USD' ? $usd : round($usd * $rate, 2);
            $parts = []; $err = '';
            if ($fromCash > 0) {
                $r1 = boss_transfer($api, $accId, (int)$t['id'], $t['label'], $fromCash, $me['name'], $comment,
                                    $myCur, $t['currency'], $t['currency'] === 'USD' ? 1.0 : $rate);
                if (empty($r1['ok'])) $err = $r1['error'];
                else $parts[] = 'из кассы ' . money($fromCash, $myCur);
            }
            if (!$err && $rest > 0) {
                $r2 = owner_contribute((int)$t['id'], $t['label'], $t['currency'], $conv($rest), $t['currency'] === 'USD' ? null : $rate,
                                       $me['name'], trim($comment . ' (в кассе не хватило)'));
                if (empty($r2['ok'])) $err = ($parts ? 'Из кассы передано ' . money($fromCash, $myCur) . ', но вложение на остаток не записано: ' : '') . $r2['error'];
                else $parts[] = 'вложение собственника ' . money($rest, 'USD');
            }
            if ($err) flash_set($err, 'err');
            else flash_set('Счёт «' . $t['label'] . '» пополнен на ' . money($conv($amt), $t['currency']) . ': ' . implode(' + ', $parts) . '.'
                           . ($rest > 0 ? ' В кассе было только ' . money(max(0, $bal), $myCur) . ' — недостающее записано как ваше вложение.' : ''), 'ok');
        }
        header('Location: cash.php');
        exit;
    } elseif ($isBoss && $action === 'withdraw') {
        $amt = round((float)str_replace([' ', ','], ['', '.'], $_POST['amount'] ?? '0'), 2);
        $r = owner_withdraw($accId, $myCur, $amt, $me['name'], trim($_POST['comment'] ?? ''));
        flash_set($r['ok'] ? 'Изъятие собственника: ' . money($amt, $myCur) . ' из кассы. Это не расход компании.' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: cash.php');
        exit;
    } elseif ($isBoss && $action === 'delete_owner_move') {
        $r = owner_delete((int)($_POST['move_id'] ?? 0));
        flash_set($r['ok'] ? ($r['kind'] === 'in' ? 'Вложение убрано, деньги сняты со счёта компании.' : 'Изъятие убрано, деньги вернулись в кассу.') : $r['error'],
                  $r['ok'] ? 'ok' : 'err');
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
$myExpenses = boss_my_expenses($me['login']);
$owner = $isBoss ? owner_summary() : null;

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
      <p class="muted" style="margin:0 0 10px">⚠️ Таможня, фрахт, сертификат и расходы декларанта по поставке — <strong>не сюда</strong>. Их вносят в самом заказе поставщику (NodirTool → заказ → «Логистические расходы»), а фрахт по рейсу оплачивают в «Перевозчиках». Иначе деньги уйдут, но расход не попадёт в себестоимость товара, а долг перевозчику не уменьшится.</p>
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

<?php if ($isBoss): ?>
<div class="card">
  <h2>Пополнить счёт компании</h2>
  <form method="post" onsubmit="return appConfirmSubmit(this, 'Пополнить счёт компании?');">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="topup">
    <label>Какой счёт</label>
    <select name="target" id="tuTarget" required>
      <?php foreach ($targets as $k => $t): ?>
        <option value="<?= htmlspecialchars($k) ?>" data-currency="<?= htmlspecialchars($t['currency']) ?>">
          <?= htmlspecialchars($t['label']) ?> (<?= htmlspecialchars($t['currency']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <label>Откуда деньги</label>
    <label style="font-weight:400; display:flex; gap:8px; align-items:flex-start">
      <input type="radio" name="source" value="cash" checked style="width:auto; margin-top:3px">
      <span>Из моей кассы — деньги компании у меня на руках (сейчас <?= $balance === null ? '—' : money((float)$balance) ?>).
        <span class="muted">Если в кассе меньше, недостающее запишется как ваше вложение.</span></span></label>
    <label style="font-weight:400; display:flex; gap:8px; align-items:flex-start">
      <input type="radio" name="source" value="own" style="width:auto; margin-top:3px">
      <span>Мои личные деньги — <strong>вложение собственника</strong>. Касса не меняется.</span></label>
    <label>Сумма, <span id="tuCur">$</span> <span class="muted" id="tuCurHint"></span></label>
    <input type="number" name="amount" id="tuAmount" step="0.01" min="0.01" required>
    <div id="tuRateBox" style="display:none">
      <label>Курс: сколько <span id="tuRateCur"></span> за 1 $</label>
      <input type="number" name="rate" id="tuRate" step="any" min="0">
    </div>
    <p class="muted" id="tuHint" style="margin:-4px 0 10px"></p>
    <label>Комментарий <span class="muted">(необязательно)</span></label>
    <input type="text" name="comment" placeholder="например: на оплату ICMA">
    <button type="submit">Пополнить</button>
  </form>
</div>

<div class="card">
  <h2>Забрать себе</h2>
  <p class="muted" style="margin-top:0">Деньги компании из вашей кассы, которые вы забираете лично, — это
    <strong>изъятие собственника</strong>, а не расход компании: прибыль оно не уменьшает.</p>
  <form method="post" onsubmit="return appConfirmSubmit(this, 'Записать изъятие собственника из кассы?');">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="withdraw">
    <label>Сумма, <?= htmlspecialchars($myCur === 'USD' ? '$' : $myCur) ?></label>
    <input type="number" name="amount" step="0.01" min="0.01" required>
    <label>Комментарий <span class="muted">(необязательно)</span></label>
    <input type="text" name="comment">
    <button type="submit" class="secondary">Забрать себе</button>
  </form>
</div>
<?php else: ?>
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

<?php endif; ?>

</div>
<div>

<?php if ($isBoss): ?>
<div class="card">
  <h2>Вложения и изъятия собственника</h2>
  <?php if (!$owner['moves']): ?>
    <p class="muted">Пока не было. Когда пополните счёт компании из своих денег или заберёте деньги себе, итог появится здесь.</p>
  <?php else: ?>
    <div class="row">
      <div><div class="muted">Вложено</div><div style="font-size:18px; font-weight:700" class="ok"><?= htmlspecialchars(money_by_currency($owner['in'])) ?></div></div>
      <div><div class="muted">Забрано</div><div style="font-size:18px; font-weight:700"><?= htmlspecialchars(money_by_currency($owner['out'])) ?></div></div>
      <div><div class="muted">Итого в долларах</div>
        <div style="font-size:18px; font-weight:700"><?= htmlspecialchars(money(abs($owner['net_usd']))) ?></div>
        <div class="muted" style="font-size:12px"><?= $owner['net_usd'] >= 0 ? 'вложено больше, чем забрано' : 'забрано больше, чем вложено' ?></div></div>
    </div>
    <table style="margin-top:10px">
      <tr><th>Дата</th><th>Что</th><th>Счёт</th><th class="num">Сумма</th><th></th></tr>
      <?php foreach ($owner['moves'] as $mv): ?>
        <tr>
          <td class="muted"><?= date('d.m.Y', strtotime($mv['datec'])) ?></td>
          <td><?= $mv['kind'] === 'in' ? 'Вложение' : 'Изъятие' ?>
            <?php if ($mv['comment']): ?><div class="muted" style="font-size:12px"><?= htmlspecialchars($mv['comment']) ?></div><?php endif; ?></td>
          <td class="muted"><?= htmlspecialchars($mv['account_label'] ?? '') ?></td>
          <td class="num" style="color:<?= $mv['kind'] === 'in' ? 'var(--ok)' : 'var(--danger)' ?>">
            <?= ($mv['kind'] === 'in' ? '+' : '−') . htmlspecialchars(money((float)$mv['amount'], $mv['currency'])) ?>
            <?php if ($mv['currency'] !== 'USD'): ?><div class="muted" style="font-size:12px">≈ <?= htmlspecialchars(money((float)$mv['amount_usd'])) ?></div><?php endif; ?></td>
          <td>
            <form method="post" style="display:inline" onsubmit="return appConfirmSubmit(this, '<?= $mv['kind'] === 'in' ? 'Убрать это вложение? Деньги будут сняты со счёта компании.' : 'Убрать это изъятие? Деньги вернутся в кассу.' ?>');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_owner_move">
              <input type="hidden" name="move_id" value="<?= (int)$mv['rowid'] ?>">
              <button type="submit" class="secondary small" title="Убрать ошибочную запись">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

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

<div class="card">
  <h2>Мои расходы <span class="muted">— за последние 60 дней</span></h2>
  <p class="muted">Здесь можно убрать ошибочную запись — деньги вернутся на счёт, с которого были
    списаны. Чужие расходы удалять нельзя, как и ваши — никому другому.</p>
  <?php if (empty($myExpenses)): ?>
    <p class="muted">Расходов пока не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Дата</th><th>Вид</th><th class="num">Сумма</th><th>Комментарий</th><th></th></tr>
      <?php foreach ($myExpenses as $e): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(date('d.m.Y', strtotime($e['expense_date']))) ?></td>
          <td><?= htmlspecialchars($e['category_name'] ?? '') ?></td>
          <td class="num"><?= number_format((float)$e['amount_usd'], 2, '.', ' ') ?> $</td>
          <td class="muted"><?= htmlspecialchars($e['comment'] ?? '') ?></td>
          <td>
            <form method="post" style="display:inline"
                  onsubmit="return appConfirmSubmit(this, 'Удалить этот расход? Деньги вернутся на счёт, с которого были списаны.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_expense">
              <input type="hidden" name="expense_id" value="<?= (int)$e['rowid'] ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
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

<?php if ($isBoss): ?>
<script>
// Пополнение: из кассы сумма в долларах (столько уйдёт из кассы), из своих — в валюте счёта
// (столько на него придёт). Курс нужен, если счёт не долларовый.
(function () {
  const sel = document.getElementById('tuTarget'), amt = document.getElementById('tuAmount');
  const box = document.getElementById('tuRateBox'), rate = document.getElementById('tuRate');
  const cur = document.getElementById('tuCur'), curHint = document.getElementById('tuCurHint');
  const rateCur = document.getElementById('tuRateCur'), hint = document.getElementById('tuHint');
  if (!sel) return;
  const cash = <?= json_encode($balance === null ? 0 : (float)$balance) ?>;
  const f = v => v.toLocaleString('ru-RU', {maximumFractionDigits: 2});
  function src() { return document.querySelector('input[name="source"]:checked').value; }
  function sync() {
    const t = sel.options[sel.selectedIndex].dataset.currency || 'USD';
    const own = src() === 'own';
    const inCur = own ? t : 'USD';
    cur.textContent = inCur === 'USD' ? '$' : inCur;
    curHint.textContent = own ? '— столько придёт на счёт' : '— столько уйдёт из кассы';
    box.style.display = t === 'USD' ? 'none' : ''; rate.required = t !== 'USD'; rateCur.textContent = t;
    const a = parseFloat(amt.value) || 0, r = parseFloat(rate.value) || 0;
    let h = '';
    if (a > 0) {
      if (own) h = t !== 'USD' && r > 0 ? '≈ ' + f(a / r) + ' $ — запишется как ваше вложение' : 'запишется как ваше вложение';
      else {
        const fromCash = Math.min(a, Math.max(0, cash)), rest = a - fromCash;
        h = t !== 'USD' ? (r > 0 ? 'на счёт придёт ' + f(a * r) + ' ' + t + '. ' : '') : '';
        if (rest > 0.004) h += 'Из кассы ' + f(fromCash) + ' $, ещё ' + f(rest) + ' $ запишется как ваше вложение.';
      }
    }
    hint.textContent = h;
  }
  [sel, amt, rate].forEach(e => e.addEventListener('input', sync));
  sel.addEventListener('change', sync);
  document.querySelectorAll('input[name="source"]').forEach(e => e.addEventListener('change', sync));
  sync();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
