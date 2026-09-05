<?php
require_once __DIR__ . '/includes/auth.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert') {
    $uzsAmount = (float)($_POST['uzs_amount'] ?? 0);
    $rate = (float)($_POST['rate'] ?? 0);
    $targetCurrency = $_POST['target_currency'] ?? '';
    $targetAccId = $cfg['currency_accounts'][$targetCurrency] ?? null;
    $who = $_SESSION['user']['name'] ?? '';

    if ($uzsAmount <= 0 || $rate <= 0 || !$targetAccId) {
        $message = 'Заполните сумму в сумах, курс и валюту.';
        $messageType = 'err';
    } else {
        $targetAmount = round($uzsAmount / $rate, 2);
        $label = "Конвертация: {$uzsAmount} сум по курсу {$rate} = {$targetAmount} {$targetCurrency} ({$who})";

        // Не блокируем (это внутренний учётный счёт, не настоящий банк), но предупреждаем, если
        // списание уводит сумовый счёт в минус — чтобы не потерять это из виду по невнимательности.
        $balanceBefore = $api->getAccountBalance((int)$cfg['uzs_account_id']);
        $overdraftWarning = '';
        if ($balanceBefore !== null && $uzsAmount > $balanceBefore + 0.01) {
            $overdraftWarning = 'ВНИМАНИЕ: на сумовом счету было ' . number_format($balanceBefore, 0, '.', ' ') .
                ' сум, после этой конвертации счёт уйдёт в минус. ';
        }

        $out = $api->addBankLine((int)$cfg['uzs_account_id'], $label, -1 * $uzsAmount, 'VIR');
        if ($out === null) {
            $message = 'Ошибка списания с сумового счёта: ' . $api->lastError;
            $messageType = 'err';
        } else {
            $in = $api->addBankLine((int)$targetAccId, $label, $targetAmount, 'VIR');
            if ($in === null) {
                $message = "ВНИМАНИЕ: списано {$uzsAmount} сум с сумового счёта, но НЕ зачислено на {$targetCurrency}-MAIN: " . $api->lastError . ". Поправьте вручную.";
                $messageType = 'err';
            } else {
                $message = $overdraftWarning . "Готово: {$uzsAmount} сум → {$targetAmount} {$targetCurrency} по курсу {$rate}.";
                $messageType = $overdraftWarning ? 'warn' : 'ok';
                // Деньги уже реально переведены — редирект (POST → GET), чтобы F5 не отправил эту же
                // форму повторно и не провёл конвертацию ещё раз.
                flash_set($message, $messageType);
                header('Location: convert.php');
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

$uzsBalance = $api->getAccountBalance((int)$cfg['uzs_account_id']);
$currencyBalances = [];
foreach ($cfg['currency_accounts'] as $code => $accId) {
    $currencyBalances[$code] = $api->getAccountBalance((int)$accId);
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Конвертация валют</h1>
<p class="muted">Перевод с единого сумового счёта на валютный — по курсу, который действует прямо сейчас (вводится каждый раз, не берётся из настроек).</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <h2>Остатки</h2>
  <div class="row">
    <div><div class="muted">Сумовый счёт (UZS)</div><div style="font-size:20px; font-weight:700"><?= $uzsBalance !== null ? number_format($uzsBalance, 0, '.', ' ') . ' сум' : '?' ?></div></div>
    <?php foreach ($currencyBalances as $code => $bal): ?>
      <div><div class="muted"><?= htmlspecialchars($code) ?>-MAIN</div><div style="font-size:20px; font-weight:700"><?= $bal !== null ? number_format($bal, 2) . ' ' . htmlspecialchars($code) : '?' ?></div></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Конвертировать</h2>
  <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="convert">
    <div>
      <label>Сумма в сумах</label>
      <input type="number" step="1" min="1" name="uzs_amount" required>
    </div>
    <div>
      <label>Курс (сум за 1 ед. валюты)</label>
      <input type="number" step="0.01" min="0.01" name="rate" required>
    </div>
    <div>
      <label>В какую валюту</label>
      <select name="target_currency">
        <?php foreach ($cfg['currency_accounts'] as $code => $accId): ?><option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0"><button type="submit">Конвертировать</button></div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
