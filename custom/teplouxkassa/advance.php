<?php
/**
 * Приём аванса/предоплаты от клиента — деньги получены, но товар ещё не отпущен ни на копейку.
 * Технически — кредит-нота (как возврат в return.php), НЕ привязанная к конкретному счёту: она сразу
 * уменьшает долг клиента (может увести его в "минус" = у нас в долгу перед ним), а реальные деньги
 * кладём напрямую на кассу/сумовый счёт (см. includes/payment_split.php::postAdvanceMoney()) — не
 * через addPaymentDistributed(), т.к. "оплата" кредит-ноты в Dolibarr означает возврат денег клиенту,
 * а не приём от него.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_split.php';

if (!array_key_exists('advance_client', $_SESSION)) $_SESSION['advance_client'] = null;

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — сбрасывает
// выбранного клиента, чтобы не "застревать" на нём.
reset_selection_unless_preserved('advance_client');

$message = '';
$messageType = '';
$showReceiptLink = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!select_client_for_direction($api, $cfg, 'advance_client', $clientId, $_POST['client_name'] ?? '')) {
            $message = 'Клиент не найден или относится к другому направлению.';
            $messageType = 'err';
        }
    } elseif ($action === 'clear_client') {
        $_SESSION['advance_client'] = null;
    } elseif ($action === 'receive_advance') {
        $paySplitResult = resolvePaySplit($cfg, $_POST);
        $paySplit = $paySplitResult['amounts'];
        $payDetail = $paySplitResult['detail'];
        $total = array_sum($paySplit);

        if (empty($_SESSION['advance_client']['id'])) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif ($paySplitResult['errors']) {
            $message = implode(' ', $paySplitResult['errors']);
            $messageType = 'err';
        } elseif (empty($paySplit)) {
            $message = 'Укажите сумму хотя бы по одному способу оплаты.';
            $messageType = 'err';
        } else {
            $socId = (int)$_SESSION['advance_client']['id'];
            $noteId = $api->createCreditNote($socId);
            if (!$noteId) {
                $message = 'Ошибка создания документа аванса: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $lineRes = $api->addGenericInvoiceLine($noteId, 'Аванс / предоплата', $total, 0);
                if ($lineRes === null) {
                    $message = 'Ошибка добавления суммы: ' . $api->lastError;
                    $messageType = 'err';
                } else {
                    $val = $api->validateInvoice($noteId);
                    if ($val === null) {
                        $message = 'Ошибка проведения документа: ' . $api->lastError;
                        $messageType = 'err';
                    } else {
                        $moneyErrors = postAdvanceMoney($api, $cfg, $payDetail, 'Аванс от клиента, касса ' . $cfg['direction_label']);
                        $paidLabels = [];
                        foreach ($paySplit as $key => $amt) {
                            $paidLabels[] = paySplitLabel($cfg['payment_accounts'][$key]['label'], $payDetail[$key]);
                        }
                        $message = "Готово! Аванс #$noteId принят: " . implode(' + ', $paidLabels) . ".";
                        if ($moneyErrors) {
                            $message .= "\nВНИМАНИЕ: часть денег не удалось записать на счёт: " . implode('; ', $moneyErrors);
                        }
                        $messageType = $moneyErrors ? 'err' : 'ok';

                        $receiptItems = [];
                        foreach ($paySplit as $key => $amt) {
                            $receiptItems[] = ['ref' => 'Аванс', 'method' => $cfg['payment_accounts'][$key]['label'], 'amount' => $amt, 'uzs' => $payDetail[$key]['uzs'], 'rate' => $payDetail[$key]['rate']];
                        }
                        $_SESSION['last_receipt'] = [
                            'client_name' => $_SESSION['advance_client']['name'] ?? '',
                            'items' => $receiptItems,
                            'total' => $total,
                            'date' => time(),
                        ];
                        $showReceiptLink = true;
                        $_SESSION['advance_client'] = null;
                        // Документ уже реальный — редирект (POST → GET), чтобы F5 не повторил
                        // отправку формы и не создал второй аванс.
                        flash_set($message, $messageType, ['show_receipt_link' => true]);
                        header('Location: advance.php');
                        exit;
                    }
                }
            }
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
    $showReceiptLink = !empty($flash['extra']['show_receipt_link']);
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Аванс / предоплата</h1>
<p class="muted">Клиент даёт деньги вперёд, без конкретной покупки прямо сейчас — его долг сразу уменьшится (уйдёт "в плюс", если раньше долгов не было).</p>
<?php if ($_SESSION['advance_client']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_client">
    <button type="submit" class="secondary">← Сменить клиента</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>
<?php if ($showReceiptLink): ?>
  <p><a class="btn secondary" href="payment_receipt_excel.php">🧾 Распечатать квитанцию о приёме денег (Excel)</a></p>
<?php endif; ?>

<div class="card">
  <h2>Клиент</h2>
  <?php if ($_SESSION['advance_client']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['advance_client']['name']) ?></strong>
        <div><a href="client_form.php?ctx=advance&id=<?= (int)$_SESSION['advance_client']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_client">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
  <?php else: ?>
    <input type="text" id="clientSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать имя...">
    <div id="clientResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="client_form.php?ctx=advance" class="btn secondary small">+ Новый клиент</a></p>
  <?php endif; ?>
</div>

<?php if ($_SESSION['advance_client']): ?>
<div class="card">
  <h2>Сколько приняли</h2>
  <p class="muted">Можно разбить сразу на несколько способов (например часть наличными, часть картой).</p>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="receive_advance">
    <div class="row">
      <?php foreach ($cfg['payment_accounts'] as $key => $acc): ?>
        <?php if (($acc['currency'] ?? 'USD') === 'UZS'): ?>
          <div class="pay-uzs-group">
            <label><?= htmlspecialchars($acc['label']) ?> — сумма в сумах</label>
            <input type="number" step="1" min="0" class="pay-uzs-input" data-key="<?= $key ?>" name="pay_uzs[<?= $key ?>]" placeholder="0">
            <label>Курс (сум за 1$)</label>
            <input type="number" step="0.01" min="0" class="pay-rate-input" data-key="<?= $key ?>" name="pay_rate[<?= $key ?>]" placeholder="напр. 12700">
            <div class="muted">≈ <span class="pay-uzs-preview" data-key="<?= $key ?>">0.00</span> $</div>
          </div>
        <?php else: ?>
          <div>
            <label><?= htmlspecialchars($acc['label']) ?></label>
            <input type="number" step="0.01" min="0" name="pay[<?= $key ?>]" placeholder="0.00">
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <button type="submit">Принять аванс</button>
  </form>
</div>
<?php endif; ?>

<script>
window.onClientPick = function (c) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_client">' +
    '<input type="hidden" name="client_id" value="' + c.id + '">' +
    '<input type="hidden" name="client_name" value="' + c.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
};

document.querySelectorAll('.pay-uzs-input').forEach(uzsInput => {
  const key = uzsInput.dataset.key;
  const rateInput = document.querySelector('.pay-rate-input[data-key="' + key + '"]');
  const preview = document.querySelector('.pay-uzs-preview[data-key="' + key + '"]');
  if (!rateInput || !preview) return;
  function update() {
    const uzs = parseFloat(uzsInput.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;
    preview.textContent = (rate > 0 ? uzs / rate : 0).toFixed(2);
  }
  uzsInput.addEventListener('input', update);
  rateInput.addEventListener('input', update);
});
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
