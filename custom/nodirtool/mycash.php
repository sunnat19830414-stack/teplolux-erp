<?php
/**
 * Личная касса закупщика — топ-5 пункт 2 (02.09.2026). Остаток + история движений своего кассового
 * счёта (NODIR-CASH/ABDUR-CASH) + квитанция-подтверждение получения от склада. Сами деньги здесь не
 * заводятся вручную (кроме оплаты поставщику ниже) — приход идёт из TeplouxKassa ("Передать кассу"),
 * расход — из payments.php ("Оплата поставщикам", способ "Моя касса"). См. includes/mycash.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mycash.php';

$myAcc = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $myAcc) {
    $action = $_POST['action'] ?? '';
    if ($action === 'confirm_receipt') {
        $lineId = (int)($_POST['line_id'] ?? 0);
        if ($lineId) {
            mycash_confirm_line($lineId, (int)$myAcc['id'], $_SESSION['user']['login']);
            $message = 'Подтверждение сохранено.';
            $messageType = 'ok';
            flash_set($message, $messageType);
            header('Location: mycash.php');
            exit;
        }
    } elseif ($action === 'handover_to_boss') {
        // Передача остатка шефу (04.09.2026): деньги стекаются к Умиду — закупщик собирает, тратит,
        // остаток отдаёт. Две проводки, как у "Передать кассу" в TeplouxKassa: списание со своей кассы
        // + зачисление на кассу шефа. Если зачисление не прошло — списание НЕ откатываем задним числом,
        // но явно предупреждаем, чтобы деньги не потерялись молча.
        $bossAcc = $cfg['boss_cash_account'] ?? null;
        $amount = (float)($_POST['amount'] ?? 0);
        $currentBalance = $api->getAccountBalance((int)$myAcc['id']);
        $who = $_SESSION['user']['name'] ?? '';
        // Обе кассы сейчас долларовые, поэтому передача идёт числом «как есть». Если когда-нибудь
        // одну из них заведут в другой валюте, молча переложить сумму нельзя — лучше честно
        // отказать, чем записать доллары сумами (04.09.2026, по следам ошибки в оплате поставщику).
        require_once __DIR__ . '/includes/currency.php';
        $curFrom = account_currency((int)$myAcc['id']);
        $curTo = $bossAcc ? account_currency((int)$bossAcc['id']) : $curFrom;

        if ($bossAcc && $curFrom !== $curTo) {
            $message = "Ваша касса в {$curFrom}, а касса получателя в {$curTo} — передача между разными "
                     . 'валютами здесь не предусмотрена. Сообщите Суннату.';
            $messageType = 'err';
        } elseif (!$bossAcc) {
            $message = 'Касса шефа не настроена — обратитесь к администратору.';
            $messageType = 'err';
        } elseif ($amount <= 0.001) {
            $message = 'Укажите сумму передачи.';
            $messageType = 'err';
        } elseif ($currentBalance !== null && $amount > $currentBalance + 0.001) {
            $message = 'В кассе только ' . number_format((float)$currentBalance, 2) . ' $ — передать больше нельзя.';
            $messageType = 'err';
        } else {
            $label = 'Передача шефу (Умид) — от: ' . $who;
            $outRes = $api->addBankLine((int)$myAcc['id'], $label, -1 * $amount, 'LIQ');
            if ($outRes === null) {
                $message = 'Не удалось списать с вашей кассы: ' . $api->lastError . ' — передача не записана.';
                $messageType = 'err';
            } else {
                $inRes = $api->addBankLine((int)$bossAcc['id'], 'Принято от: ' . $who, $amount, 'LIQ');
                $msg = 'Передано шефу: ' . number_format($amount, 2) . ' $.';
                if ($inRes === null) {
                    $msg .= ' ВНИМАНИЕ: с вашей кассы списано, но на кассу шефа НЕ зачислено ('
                        . $api->lastError . ') — сообщите Суннату, поправим вручную.';
                }
                flash_set($msg, $inRes === null ? 'err' : 'ok');
                header('Location: mycash.php');
                exit;
            }
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

$balance = null;
$lines = [];
$ackMap = [];
if ($myAcc) {
    $balance = $api->getAccountBalance((int)$myAcc['id']);
    $lines = $api->getBankLines((int)$myAcc['id']);
    // Свежие сверху — API отдаёт по возрастанию rowid.
    $lines = array_reverse($lines);
    $ackMap = mycash_get_ack_map((int)$myAcc['id']);
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Моя касса</h1>
<?php if (!$myAcc): ?>
  <p class="err">Для вашего логина не настроен личный кассовый счёт — обратитесь к администратору.</p>
<?php else: ?>
<p class="muted">
  Наличные, которые вам передал Жамшид/MuhammadAli через "Передать кассу" в мини-кассе, зачисляются сюда
  сразу и по-настоящему. Оплата поставщику наличными из этой суммы — на странице
  «<a href="payments.php">Оплата поставщикам</a>» (способ списания «Моя касса»).
</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <h2 style="margin:0"><?= htmlspecialchars($myAcc['label']) ?></h2>
      <div style="font-size:28px; font-weight:700"><?= $balance !== null ? number_format($balance, 2) . ' $' : '?' ?></div>
    </div>
    <?php // Передача остатка шефу (04.09.2026) — деньги у вас копятся, а потом уходят Умиду. ?>
    <?php if (!empty($cfg['boss_cash_account']) && $balance !== null && $balance > 0.01): ?>
      <form method="post" style="flex:0; display:flex; gap:8px; align-items:end"
            onsubmit="return appConfirmSubmit(this, 'Передать деньги шефу? Сумма спишется с вашей кассы и зачислится на кассу Умида.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="handover_to_boss">
        <div>
          <label>Передать шефу, $</label>
          <input type="number" step="0.01" min="0.01" max="<?= number_format((float)$balance, 2, '.', '') ?>"
                 name="amount" value="<?= number_format((float)$balance, 2, '.', '') ?>" style="margin:0; min-width:130px">
        </div>
        <button type="submit">Передать шефу</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>История движений</h2>
  <?php if (empty($lines)): ?>
    <p class="muted">Пока пусто — движений по этому счёту ещё не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Дата</th><th>Сумма</th><th>Описание</th><th></th></tr>
      <?php foreach ($lines as $l):
        $lineId = (int)($l['id'] ?? 0);
        $amount = (float)($l['amount'] ?? 0);
        $ack = $ackMap[$lineId] ?? null;
      ?>
        <tr>
          <td><?= $l['dateo'] ? date('d.m.Y', (int)$l['dateo']) : '' ?></td>
          <td class="<?= $amount >= 0 ? 'ok' : 'err' ?>"><?= ($amount >= 0 ? '+' : '') . number_format($amount, 2) ?> $</td>
          <td><?= htmlspecialchars($l['label'] ?? '') ?></td>
          <td>
            <?php if ($amount > 0): ?>
              <?php if ($ack): ?>
                <span class="badge badge-ok">✓ Подтвердил: <?= htmlspecialchars($cfg['users'][$ack['by']]['display_name'] ?? $ack['by']) ?>, <?= date('d.m.Y H:i', strtotime($ack['at'])) ?></span>
              <?php else: ?>
                <form method="post">
  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="confirm_receipt">
                  <input type="hidden" name="line_id" value="<?= $lineId ?>">
                  <button type="submit" class="small secondary">Подтверждаю получение</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
