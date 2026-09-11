<?php
/**
 * Перевозчики (топ-5 пункт 3, 02.09.2026) — настоящие контрагенты Dolibarr (is_carrier=1), долг/оплата
 * считаются через includes/logistics.php (своя таблица, не supplierinvoices — расход "Фрахт" на
 * заказ/партию начисляет долг, здесь его гасят, полностью или частями, любым из счетов проекта).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logistics.php';
require_once __DIR__ . '/includes/debt.php';   // долг перевозчику по валютам (05.09.2026)
require_once __DIR__ . '/includes/shipments.php'; // рейсы этого перевозчика (B6, 05.09.2026)

if (!array_key_exists('selected_carrier', $_SESSION)) $_SESSION['selected_carrier'] = null;

// Прямая ссылка с главной ("Сводка") — ?carrier_id=X сразу открывает карточку этого перевозчика.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['carrier_id'])) {
    $jumpId = (int)$_GET['carrier_id'];
    $jumpSoc = $api->getThirdparty($jumpId);
    if (is_array($jumpSoc)) {
        $_SESSION['selected_carrier'] = ['id' => $jumpId, 'name' => $jumpSoc['name'] ?? $jumpSoc['nom'] ?? ''];
        $_SESSION['_preserve_once']['selected_carrier'] = true;
    }
}

reset_selection_unless_preserved('selected_carrier');

$message = '';
$messageType = '';

// Те же счета списания, что и в "Оплата поставщикам" — включая личную кассу текущего закупщика.
$moneyAccounts = [];
$myCashAcc = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCashAcc) {
    $moneyAccounts['mycash'] = ['id' => $myCashAcc['id'], 'label' => 'Моя касса (' . $myCashAcc['label'] . ')', 'currency' => 'USD'];
}
$moneyAccounts['uzs'] = ['id' => $cfg['uzs_account_id'], 'label' => 'Сумовый счёт (UZS-MAIN)', 'currency' => 'UZS'];
foreach ($cfg['currency_accounts'] as $curCode => $accId) {
    $moneyAccounts[strtolower($curCode)] = ['id' => $accId, 'label' => $curCode . '-MAIN', 'currency' => $curCode];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'select_carrier') {
        $_SESSION['selected_carrier'] = ['id' => (int)($_POST['carrier_id'] ?? 0), 'name' => $_POST['carrier_name'] ?? ''];
    } elseif ($action === 'clear_carrier') {
        $_SESSION['selected_carrier'] = null;
    } elseif ($action === 'pay_carrier') {
        $carrierId = (int)($_POST['carrier_id'] ?? 0);
        $accKey = $_POST['account'] ?? '';
        $acc = $moneyAccounts[$accKey] ?? null;
        $amount = (float)($_POST['amount'] ?? 0);
        $rate = $acc && $acc['currency'] !== 'USD' ? (float)($_POST['rate'] ?? 0) : null;
        $comment = trim($_POST['comment'] ?? '');
        $who = $_SESSION['user']['name'] ?? '';

        if (!$carrierId || !$acc) {
            $message = 'Выберите счёт списания.';
            $messageType = 'err';
        } else {
            // К какому рейсу относится оплата — необязательно (можно платить «в общий долг»),
            // но если указан, в акте сверки будет видно, за какой инвойс заплатили (B6, 05.09.2026).
            $shipmentId = (int)($_POST['shipment_id'] ?? 0) ?: null;
            $r = logistics_record_carrier_payment($carrierId, $amount, $acc['currency'], $rate, (int)$acc['id'], $who, $comment, $shipmentId);
            if (!($r['ok'] ?? false)) {
                $message = $r['error'] ?? 'Ошибка оплаты.';
                $messageType = 'err';
            } else {
                $message = ($r['overdraft_warning'] ?? '') . "Оплачено {$amount} " . ($acc['currency'] === 'UZS' ? 'сум' : $acc['currency']) .
                    ($r['usd_amount'] != $amount ? " ({$r['usd_amount']} \$)" : '') . '.';
                $messageType = !empty($r['overdraft_warning']) ? 'warn' : 'ok';
                $_SESSION['selected_carrier'] = ['id' => $carrierId, 'name' => $_SESSION['selected_carrier']['name'] ?? ''];
                $_SESSION['_preserve_once']['selected_carrier'] = true;
                flash_set($message, $messageType);
                header('Location: carriers.php');
                exit;
            }
        }
    } elseif ($action === 'upload_document') {
        $carrierId = (int)($_POST['carrier_id'] ?? 0);
        if (!$carrierId) {
            $message = 'Перевозчик не выбран.';
            $messageType = 'err';
        } elseif (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = 'Выберите файл для загрузки.';
            $messageType = 'err';
        } else {
            $filename = basename($_FILES['document']['name']);
            $content = base64_encode(file_get_contents($_FILES['document']['tmp_name']));
            $res = $api->uploadCarrierDocument($carrierId, $filename, $content);
            if ($res === null) {
                $message = 'Ошибка загрузки файла: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $message = "Файл «{$filename}» загружен.";
                $messageType = 'ok';
                $_SESSION['selected_carrier'] = ['id' => $carrierId, 'name' => $_SESSION['selected_carrier']['name'] ?? ''];
                $_SESSION['_preserve_once']['selected_carrier'] = true;
                flash_set($message, $messageType);
                header('Location: carriers.php');
                exit;
            }
        }
    } elseif ($action === 'delete_document') {
        $carrierId = (int)($_POST['carrier_id'] ?? 0);
        $filename = basename($_POST['filename'] ?? '');
        if (!$carrierId || $filename === '') {
            $message = 'Не удалось определить файл для удаления.';
            $messageType = 'err';
        } else {
            $ok = $api->deleteCarrierDocument($carrierId, $filename);
            $message = $ok ? "Файл «{$filename}» удалён." : ('Ошибка удаления: ' . $api->lastError);
            $messageType = $ok ? 'ok' : 'err';
        }
        $_SESSION['selected_carrier'] = ['id' => $carrierId, 'name' => $_SESSION['selected_carrier']['name'] ?? ''];
        $_SESSION['_preserve_once']['selected_carrier'] = true;
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

// --- Дашборд "кому должны" — только когда перевозчик не выбран ---
$owedCarriers = [];
if (empty($_SESSION['selected_carrier'])) {
    // 05.09.2026: долг — в валюте договорённости, а не в долларовом пересчёте.
    $debtsByCur = carrier_debt_by_currency();
    $ids = array_keys($debtsByCur);
    $names = $ids ? $api->getThirdpartiesByIds($ids) : [];
    foreach ($debtsByCur as $cid => $byCur) {
        if (!$byCur) continue; // рассчитались — не мешаем списку
        $soc = $names[$cid] ?? null;
        $owedCarriers[] = [
            'id' => $cid,
            'name' => is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? "#{$cid}") : "#{$cid}",
            'debt_by_currency' => $byCur,
        ];
    }
    usort($owedCarriers, fn($a, $b) => debt_sort_key($b['debt_by_currency']) <=> debt_sort_key($a['debt_by_currency']));
}

// --- Карточка выбранного перевозчика ---
$detail = null;
$expenses = [];
$payments = [];
$documents = [];
if ($_SESSION['selected_carrier']) {
    $cid = (int)$_SESSION['selected_carrier']['id'];
    $soc = $api->getThirdparty($cid);
    if (is_array($soc)) {
        $expenses = logistics_get_carrier_expenses($cid);
        $payments = logistics_get_carrier_payments($cid);
        $charged = array_sum(array_column($expenses, 'usd_amount'));
        $paid = array_sum(array_column($payments, 'usd_amount'));
        // По валютам — то, что показываем человеку. Долларовые суммы остаются для себестоимости.
        $byCur = logistics_get_carrier_totals_by_currency($cid);
        $detail = [
            'id' => $cid,
            'name' => $soc['name'] ?? $soc['nom'] ?? '',
            'charged' => (float)$charged,
            'paid' => (float)$paid,
            'debt' => round((float)$charged - (float)$paid, 2),
            'array_options' => $soc['array_options'] ?? [],
            'charged_by_currency' => $byCur['charged'],
            'paid_by_currency' => $byCur['paid'],
            'debt_by_currency' => $byCur['debt'],
        ];
        $documents = $api->getCarrierDocuments($cid);
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Перевозчики</h1>
<?php if ($_SESSION['selected_carrier']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_carrier">
    <button type="submit" class="secondary">← Назад к списку</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <h2>Перевозчик</h2>
  <?php if ($_SESSION['selected_carrier']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($detail['name'] ?? $_SESSION['selected_carrier']['name']) ?></strong>
        <div><a href="carrier_form.php?ctx=carriers&id=<?= (int)$_SESSION['selected_carrier']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_carrier">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
  <?php else: ?>
    <input type="text" id="carrierSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать название...">
    <div id="carrierResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="carrier_form.php?ctx=carriers" class="btn secondary small">+ Новый перевозчик</a></p>
  <?php endif; ?>
</div>

<?php if (empty($_SESSION['selected_carrier'])): ?>
<div class="card">
  <h2>Кому должны</h2>
  <p class="muted">Долг появляется, когда расход "Фрахт" (или другой) на заказ/партию внесён с указанием перевозчика — сумма считается долгом, пока не оплачена здесь.</p>
  <?php if (empty($owedCarriers)): ?>
    <p class="muted">Долгов перед перевозчиками нет.</p>
  <?php else: ?>
    <div class="debtor-grid">
      <?php foreach ($owedCarriers as $c): ?>
        <form method="post" class="debtor-block">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="select_carrier">
          <input type="hidden" name="carrier_id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="carrier_name" value="<?= htmlspecialchars($c['name']) ?>">
          <button type="submit" class="debtor-block-btn">
            <span class="debtor-block-name"><?= htmlspecialchars($c['name']) ?></span>
            <?php $cOwe = array_filter($c['debt_by_currency'], fn($v) => $v > 0.01); ?>
            <?php $cOver = array_filter($c['debt_by_currency'], fn($v) => $v < -0.01); ?>
            <?php if ($cOwe): ?>
              <span class="badge badge-debt">Должны: <?= htmlspecialchars(money_by_currency($cOwe)) ?></span>
            <?php else: ?>
              <span class="badge badge-ok">Переплата: <?= htmlspecialchars(money_by_currency(array_map('abs', $cOver))) ?></span>
            <?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($detail): ?>

<div class="card">
  <h2>Баланс</h2>
  <div class="row">
    <div><div class="muted">Начислено</div><div style="font-size:20px; font-weight:700"><?= htmlspecialchars(money_by_currency($detail['charged_by_currency'])) ?></div></div>
    <div><div class="muted">Оплачено</div><div style="font-size:20px; font-weight:700"><?= htmlspecialchars(money_by_currency($detail['paid_by_currency'])) ?></div></div>
    <div>
      <div class="muted"><?= $detail['debt'] > 0 ? 'Долг' : ($detail['debt'] < 0 ? 'Переплата' : 'Баланс') ?></div>
      <div style="font-size:20px; font-weight:700" class="<?= $detail['debt'] > 0.01 ? 'err' : 'ok' ?>"><?= htmlspecialchars(money_by_currency(array_map('abs', $detail['debt_by_currency']))) ?></div>
    </div>
  </div>
</div>

<?php if ($detail['debt'] > 0.01): ?>
<div class="card">
  <h2>Оплатить</h2>
  <form method="post" class="row" style="align-items:end" id="carrierPayForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="pay_carrier">
    <input type="hidden" name="carrier_id" value="<?= $detail['id'] ?>">
    <div>
      <label>Счёт списания</label>
      <select name="account" id="carrierPayAccount" onchange="document.getElementById('carrierPayRateBlock').style.display = this.options[this.selectedIndex].dataset.currency === 'USD' ? 'none' : '';">
        <?php foreach ($moneyAccounts as $key => $acc): ?>
          <option value="<?= $key ?>" data-currency="<?= htmlspecialchars($acc['currency']) ?>"><?= htmlspecialchars($acc['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Сумма (в валюте счёта)</label>
      <input type="number" step="0.01" min="0.01" name="amount" required>
    </div>
    <div id="carrierPayRateBlock" style="display:none">
      <label>Курс (за 1$)</label>
      <input type="number" step="0.0001" min="0.0001" name="rate">
    </div>
    <div>
      <label>За какой рейс (необязательно)</label>
      <?php $openShipments = array_filter(shipments_list((int)$detail['id'], 50),
                                          fn($sh) => $sh['status']['code'] !== 'paid'); ?>
      <select name="shipment_id">
        <option value="">— в общий долг —</option>
        <?php foreach ($openShipments as $sh): ?>
          <option value="<?= (int)$sh['rowid'] ?>">
            <?= htmlspecialchars(trim($sh['route_from'] . ' → ' . $sh['route_to'], ' →')) ?>
            <?= $sh['invoice_number'] ? '· инвойс ' . htmlspecialchars($sh['invoice_number']) : '' ?>
            (<?= htmlspecialchars(money((float)($sh['invoice_amount'] ?? $sh['agreed_amount']), $sh['currency'])) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Комментарий (необязательно)</label><input type="text" name="comment"></div>
    <div style="flex:0"><button type="submit">Оплатить</button></div>
  </form>
  <script>document.getElementById('carrierPayAccount').dispatchEvent(new Event('change'));</script>
</div>
<?php endif; ?>

<?php
  // Договор перевозки: сколько выбрано по лимиту (B6, 05.09.2026). Показывается, только если
  // договор реально заведён — у большинства перевозчиков его может не быть.
  $cOpts = $detail['array_options'] ?? [];
  $contractAmount   = (float)($cOpts['options_contract_amount'] ?? 0);
  $contractCurrency = strtoupper((string)($cOpts['options_contract_currency'] ?? '')) ?: 'USD';
  $contractNumber   = (string)($cOpts['options_contract_number'] ?? '');
  $contractStartTs  = !empty($cOpts['options_contract_start']) ? (int)$cOpts['options_contract_start'] : null;
?>
<?php if ($contractAmount > 0): ?>
  <?php
    $usage = shipments_contract_usage((int)$detail['id'], $contractCurrency, $contractStartTs);
    $left = $contractAmount - $usage['used'];
    $pct = $contractAmount > 0 ? min(100, max(0, $usage['used'] / $contractAmount * 100)) : 0;
  ?>
  <div class="card">
    <h2>Договор перевозки<?= $contractNumber ? ' № ' . htmlspecialchars($contractNumber) : '' ?></h2>
    <p>
      <strong><?= htmlspecialchars(money($usage['used'], $contractCurrency)) ?></strong>
      из <strong><?= htmlspecialchars(money($contractAmount, $contractCurrency)) ?></strong>
      <span class="muted">· рейсов: <?= (int)$usage['count'] ?><?= $contractStartTs ? ' с ' . date('d.m.Y', $contractStartTs) : '' ?></span>
    </p>
    <div class="contract-bar"><div class="contract-bar-fill <?= $left < 0 ? 'over' : '' ?>" style="width: <?= $pct ?>%"></div></div>
    <p class="<?= $left < 0 ? 'err' : 'muted' ?>">
      <?= $left < 0
            ? 'Договор превышен на ' . htmlspecialchars(money(abs($left), $contractCurrency))
            : 'Осталось по договору: ' . htmlspecialchars(money($left, $contractCurrency)) ?>
    </p>
    <?php if (!empty($usage['other'])): ?>
      <p class="warn">Есть рейсы в другой валюте (<?= htmlspecialchars(money_by_currency($usage['other'])) ?>)
        — в лимит договора они не засчитаны, договор в <?= htmlspecialchars(cur_symbol($contractCurrency)) ?>.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2>Рейсы этого перевозчика</h2>
  <?php $carrierShipments = shipments_list((int)$detail['id'], 50); ?>
  <?php if (empty($carrierShipments)): ?>
    <p class="muted">Рейсов ещё не было.
      <a href="shipments.php">Записать рейс →</a></p>
  <?php else: ?>
    <table>
      <tr><th>Маршрут</th><th>Что едет</th><th>Цена</th><th>Инвойс</th><th>Положение</th><th></th></tr>
      <?php foreach ($carrierShipments as $sh): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(trim($sh['route_from'] . ' → ' . $sh['route_to'], ' →')) ?></td>
          <td class="muted"><?= htmlspecialchars(shipment_scope_label($sh, $api)) ?></td>
          <td><?= htmlspecialchars(money((float)$sh['agreed_amount'], $sh['currency'])) ?></td>
          <td class="muted"><?= $sh['invoice_number'] ? htmlspecialchars($sh['invoice_number']) : '—' ?></td>
          <td><span class="badge badge-<?= $sh['status']['cls'] === 'ok' ? 'ok' : ($sh['status']['cls'] === 'warn' ? 'warn' : 'neutral') ?>"><?= htmlspecialchars($sh['status']['label']) ?></span></td>
          <td><a class="btn secondary small" href="shipments.php?id=<?= (int)$sh['rowid'] ?>">Открыть</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Начисленные расходы</h2>
  <?php if (empty($expenses)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <table>
      <tr><th>Где</th><th>Вид</th><th>Сумма</th><th>$</th><th>Когда</th><th>Комментарий</th></tr>
      <?php foreach ($expenses as $e): ?>
        <tr>
          <td class="muted"><?= $e['scope_type'] === 'batch' ? 'Партия #' . $e['scope_id'] : 'Заказ #' . $e['scope_id'] ?></td>
          <td><?= htmlspecialchars(logistics_expense_type_label($e['expense_type'])) ?></td>
          <td><?= htmlspecialchars(money((float)$e['native_amount'], (string)$e['native_currency'])) ?><?= $e['rate'] ? ' (курс ' . number_format((float)$e['rate'], 2) . ')' : '' ?></td>
          <td><?= htmlspecialchars(money((float)$e['usd_amount'], 'USD')) ?></td>
          <td class="muted"><?= htmlspecialchars(substr($e['datec'], 0, 16)) ?></td>
          <td class="muted"><?= htmlspecialchars($e['comment']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Оплаты</h2>
  <?php if (empty($payments)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <table>
      <tr><th>Сумма</th><th>$</th><th>Когда</th><th>Комментарий</th></tr>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= htmlspecialchars(money((float)$p['native_amount'], (string)$p['native_currency'])) ?><?= $p['rate'] ? ' (курс ' . number_format((float)$p['rate'], 2) . ')' : '' ?></td>
          <td><?= htmlspecialchars(money((float)$p['usd_amount'], 'USD')) ?></td>
          <td class="muted"><?= htmlspecialchars(substr($p['datec'], 0, 16)) ?></td>
          <td class="muted"><?= htmlspecialchars($p['comment']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Документы (договор и т.п.)</h2>
  <p class="muted">Без ограничений по типу/размеру файла.</p>
  <?php if (empty($documents)): ?>
    <p class="muted">Пока ничего не загружено.</p>
  <?php else: ?>
    <table>
      <tr><th>Файл</th><th>Размер</th><th>Загружен</th><th></th></tr>
      <?php foreach ($documents as $d): ?>
        <tr>
          <td><a href="carrier_document_download.php?carrier_id=<?= $detail['id'] ?>&filename=<?= urlencode($d['filename'] ?? $d['name'] ?? '') ?>"><?= htmlspecialchars($d['filename'] ?? $d['name'] ?? '') ?></a></td>
          <td class="muted"><?= isset($d['size']) ? number_format($d['size'] / 1024, 0) . ' КБ' : '' ?></td>
          <td class="muted"><?= !empty($d['date']) ? date('d.m.Y H:i', (int)$d['date']) : '' ?></td>
          <td>
            <form method="post" onsubmit="return appConfirmSubmit(this, 'Удалить файл «<?= htmlspecialchars($d['filename'] ?? $d['name'] ?? '', ENT_QUOTES) ?>»?');">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_document">
              <input type="hidden" name="carrier_id" value="<?= $detail['id'] ?>">
              <input type="hidden" name="filename" value="<?= htmlspecialchars($d['filename'] ?? $d['name'] ?? '') ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="margin-top:12px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_document">
    <input type="hidden" name="carrier_id" value="<?= $detail['id'] ?>">
    <input type="file" name="document" required>
    <button type="submit" style="margin-top:8px">Загрузить файл</button>
  </form>
</div>

<?php endif; ?>

<script src="assets/picker.js?v=20260911"></script>
<script>
window.wireCarrierSearch && window.wireCarrierSearch('carrierSearch', 'carrierResults', function (c) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_carrier">' +
    '<input type="hidden" name="carrier_id" value="' + c.id + '">' +
    '<input type="hidden" name="carrier_name" value="' + c.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
