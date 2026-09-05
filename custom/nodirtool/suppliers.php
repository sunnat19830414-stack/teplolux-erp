<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supplier_statement.php';

// Те же счета списания, что и в "Оплата поставщикам"/"Перевозчики" — включая личную кассу закупщика.
$moneyAccounts = [];
$myCashAcc = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCashAcc) {
    $moneyAccounts['mycash'] = ['id' => $myCashAcc['id'], 'label' => 'Моя касса (' . $myCashAcc['label'] . ')', 'currency' => 'USD'];
}
$moneyAccounts['uzs'] = ['id' => $cfg['uzs_account_id'], 'label' => 'Сумовый счёт (UZS-MAIN)', 'currency' => 'UZS'];
foreach ($cfg['currency_accounts'] as $curCode => $accId) {
    $moneyAccounts[strtolower($curCode)] = ['id' => $accId, 'label' => $curCode . '-MAIN', 'currency' => $curCode];
}

if (!array_key_exists('selected_supplier', $_SESSION)) $_SESSION['selected_supplier'] = null;

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — всегда сбрасывает
// выбранного поставщика, чтобы не "застревать" на нём.
reset_selection_unless_preserved('selected_supplier');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'select_supplier') {
        $_SESSION['selected_supplier'] = ['id' => (int)($_POST['supplier_id'] ?? 0), 'name' => $_POST['supplier_name'] ?? ''];
    } elseif ($action === 'clear_supplier') {
        $_SESSION['selected_supplier'] = null;
    } elseif ($action === 'save_contract') {
        $id = (int)($_POST['supplier_id'] ?? 0);
        $amount = (float)($_POST['contract_amount'] ?? 0);
        $start = trim($_POST['contract_start'] ?? '');
        $ok = $api->updateThirdpartyExtrafields($id, [
            'contract_amount' => $amount,
            'contract_start' => $start !== '' ? strtotime($start) : null,
        ]);
        if ($ok === null) {
            $message = 'Ошибка сохранения: ' . $api->lastError;
            $messageType = 'err';
        } else {
            $message = 'Данные по контракту сохранены.';
            $messageType = 'ok';
        }
    } elseif ($action === 'upload_document' || $action === 'delete_document') {
        // Документы поставщика — контракт, допсоглашения, спецификации (пункт B3 отчёта
        // «Пробелы NodirTool», 04.09.2026). Хранилище то же штатное Dolibarr, что уже используется
        // для перевозчиков (modulepart='societe' по числовому id) — файлы видны и в самом Dolibarr.
        $socId = (int)($_POST['supplier_id'] ?? 0);
        if (!$socId) {
            $message = 'Поставщик не выбран.';
            $messageType = 'err';
        } elseif ($action === 'upload_document') {
            if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $message = 'Выберите файл для загрузки.';
                $messageType = 'err';
            } else {
                $filename = basename($_FILES['document']['name']);
                $content = base64_encode(file_get_contents($_FILES['document']['tmp_name']));
                $res = $api->uploadPartyDocument($socId, $filename, $content);
                $message = $res === null ? ('Ошибка загрузки файла: ' . $api->lastError) : "Файл «{$filename}» загружен.";
                $messageType = $res === null ? 'err' : 'ok';
            }
        } else {
            $filename = basename($_POST['filename'] ?? '');
            if ($filename === '') {
                $message = 'Не удалось определить файл для удаления.';
                $messageType = 'err';
            } else {
                $ok = $api->deletePartyDocument($socId, $filename);
                $message = $ok ? "Файл «{$filename}» удалён." : ('Ошибка удаления: ' . $api->lastError);
                $messageType = $ok ? 'ok' : 'err';
            }
        }
        $_SESSION['selected_supplier'] = ['id' => $socId, 'name' => $_SESSION['selected_supplier']['name'] ?? ''];
        $_SESSION['_preserve_once']['selected_supplier'] = true;
        flash_set($message, $messageType);
        header('Location: suppliers.php');
        exit;
    } elseif ($action === 'send_prepayment') {
        // Предоплата поставщику (топ-5 пункт 4, 02.09.2026) — см. includes/supplier_statement.php:
        // кредит-нота (validate, без settopaid) + отдельная реальная проводка списания денег.
        $socId = (int)($_POST['supplier_id'] ?? 0);
        $accKey = $_POST['account'] ?? '';
        $acc = $moneyAccounts[$accKey] ?? null;
        $amount = (float)($_POST['amount'] ?? 0);
        $rate = $acc && $acc['currency'] !== 'USD' ? (float)($_POST['rate'] ?? 0) : null;
        $comment = trim($_POST['comment'] ?? '');
        $who = $_SESSION['user']['name'] ?? '';

        if (!$socId || !$acc || $amount <= 0) {
            $message = 'Выберите счёт списания и укажите сумму.';
            $messageType = 'err';
        } elseif ($acc['currency'] !== 'USD' && (!$rate || $rate <= 0)) {
            $message = 'Укажите курс для пересчёта в доллары.';
            $messageType = 'err';
        } else {
            $usdAmount = $acc['currency'] === 'USD' ? round($amount, 2) : round($amount / $rate, 2);
            $noteId = create_supplier_prepayment_document($api, $socId, $usdAmount, $comment);
            if (!$noteId) {
                $message = 'Ошибка создания предоплаты: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $overdraftWarning = '';
                $balanceBefore = $api->getAccountBalance((int)$acc['id']);
                if ($balanceBefore !== null && $amount > $balanceBefore + 0.01) {
                    $overdraftWarning = 'ВНИМАНИЕ: на счету было ' . number_format($balanceBefore, 2) . ' — после этой предоплаты счёт уйдёт в минус. ';
                }
                $label = "Предоплата поставщику #$socId ($who)" . ($comment !== '' ? " — $comment" : '');
                $bankRes = $api->addBankLine((int)$acc['id'], $label, -1 * $amount, 'VIR');
                if ($bankRes === null) {
                    $message = "Документ предоплаты создан (кредит-нота #$noteId), но деньги НЕ списаны: " . $api->lastError . '. Поправьте вручную.';
                    $messageType = 'err';
                } else {
                    $message = $overdraftWarning . "Предоплата {$amount} " . ($acc['currency'] === 'UZS' ? 'сум' : $acc['currency']) .
                        ($usdAmount != $amount ? " ({$usdAmount} \$)" : '') . " отправлена поставщику.";
                    $messageType = $overdraftWarning ? 'warn' : 'ok';
                    $_SESSION['selected_supplier'] = ['id' => $socId, 'name' => $_SESSION['selected_supplier']['name'] ?? ''];
                    $_SESSION['_preserve_once']['selected_supplier'] = true;
                    flash_set($message, $messageType);
                    header('Location: suppliers.php');
                    exit;
                }
            }
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

$detail = null;
$outstanding = null;
$statementRows = [];
$supplierDocuments = [];
if ($_SESSION['selected_supplier']) {
    $id = (int)$_SESSION['selected_supplier']['id'];
    $soc = $api->getThirdparty($id);
    if (is_array($soc)) {
        $opts = $soc['array_options'] ?? [];
        $contractAmount = (float)($opts['options_contract_amount'] ?? 0);
        $contractStartTs = !empty($opts['options_contract_start']) ? (int)$opts['options_contract_start'] : null;

        $spent = 0.0;
        $orderCount = 0;
        $contractOrders = [];
        $currencies = [];
        if ($contractAmount > 0 && $contractStartTs) {
            $orders = $api->getSupplierOrdersForSupplier($id);
            if (is_array($orders)) {
                foreach ($orders as $o) {
                    $statut = (int)($o['statut'] ?? 0);
                    $date = (int)($o['date_commande'] ?? 0);
                    // считаем заказы, дошедшие хотя бы до "утверждён" (2+), с даты начала контракта
                    if ($statut >= 2 && $statut <= 5 && $date >= $contractStartTs) {
                        $currency = $o['multicurrency_code'] ?: 'USD';
                        $spent += (float)($o['total_ttc'] ?? 0);
                        $orderCount++;
                        $currencies[$currency] = true;
                        $contractOrders[] = [
                            'ref' => $o['ref'] ?? '',
                            'date' => $date ? date('d.m.Y', $date) : '',
                            'total_ttc' => (float)($o['total_ttc'] ?? 0),
                            'currency' => $currency,
                        ];
                    }
                }
            }
        }

        $detail = [
            'id' => $id,
            'name' => $soc['name'] ?? $soc['nom'] ?? '',
            // Условия оплаты (03.09.2026, по списку от Нодира/Абдурашида) — 'prepay' (платим ДО отгрузки)
            // или 'postpay' (товар сначала, оплата потом). Влияет на то, по какому количеству оформляется
            // счёт поставщику, см. payments.php::create_invoice_from_order.
            'payment_terms' => $opts['options_payment_terms'] ?? '',
            'contract_amount' => $contractAmount,
            'contract_start' => $contractStartTs ? date('Y-m-d', $contractStartTs) : '',
            'spent' => $spent,
            'remaining' => $contractAmount - $spent,
            'order_count' => $orderCount,
            'orders' => $contractOrders,
            // Сумма контракта считается в предположении USD — если заказы были в разных валютах, сумма
            // "как есть" (без конвертации) может вводить в заблуждение. Показываем валюту каждого
            // заказа отдельно (см. отчёт аудита), не пытаясь молча конвертировать за пользователя.
            'mixed_currency' => count($currencies) > 1,
            // Контакты (пункт B3 отчёта, 04.09.2026) — раньше их негде было ни хранить, ни увидеть.
            'email' => (string)($soc['email'] ?? ''),
            'phone' => (string)($soc['phone'] ?? ''),
            'contact_person' => (string)($opts['options_contact_person'] ?? ''),
            'country' => (string)($soc['country'] ?? ''),
            'currency' => (string)($soc['multicurrency_code'] ?? ''),
        ];

        $supplierDocuments = $api->getPartyDocuments($id);

        // Выписка / сальдо (топ-5 пункт 4, 02.09.2026) — штатный агрегат Dolibarr + своя хронология.
        $outstanding = $api->getSupplierOutstanding($id);
        $statementRows = build_supplier_statement($api, $id);
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Поставщики / контракты</h1>
<?php if ($_SESSION['selected_supplier']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_supplier">
    <button type="submit" class="secondary">← Сменить поставщика</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Поставщик</h2>
  <?php if ($_SESSION['selected_supplier']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['selected_supplier']['name']) ?></strong>
        <?php if ($detail && $detail['payment_terms']): ?>
          <div><span class="badge <?= $detail['payment_terms'] === 'prepay' ? 'badge-warn' : 'badge-ok' ?>">
            <?= $detail['payment_terms'] === 'prepay' ? '💳 Предоплата 100% (платим до отгрузки)' : '📦 Постоплата (товар сначала, оплата потом)' ?>
          </span></div>
        <?php endif; ?>
        <?php if ($detail): ?>
          <div class="muted" style="margin-top:6px; line-height:1.6">
            <?php if ($detail['contact_person'] !== ''): ?>👤 <?= htmlspecialchars($detail['contact_person']) ?><br><?php endif; ?>
            <?php if ($detail['email'] !== ''): ?>✉️ <a href="mailto:<?= htmlspecialchars($detail['email']) ?>"><?= htmlspecialchars($detail['email']) ?></a><br><?php endif; ?>
            <?php if ($detail['phone'] !== ''): ?>☎️ <?= htmlspecialchars($detail['phone']) ?><br><?php endif; ?>
            <?php if ($detail['country'] !== ''): ?>🌍 <?= htmlspecialchars($detail['country']) ?><?php endif; ?>
            <?php if ($detail['currency'] !== '' && $detail['currency'] !== 'USD'): ?>
              · договор в <?= htmlspecialchars($detail['currency']) ?>
            <?php endif; ?>
            <?php if ($detail['contact_person'] === '' && $detail['email'] === ''): ?>
              <span style="color:var(--warn)">Почта и контактное лицо не заполнены — добавьте, чтобы не искать в переписке.</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div><a href="supplier_form.php?ctx=suppliers&id=<?= (int)$_SESSION['selected_supplier']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_supplier">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
  <?php else: ?>
    <input type="text" id="supplierSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать название...">
    <div id="supplierResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="supplier_form.php?ctx=suppliers" class="btn secondary small">+ Новый поставщик</a></p>
  <?php endif; ?>
</div>

<?php if ($detail): ?>
<div class="card">
  <h2>Документы поставщика</h2>
  <p class="muted">Контракт, допсоглашения, подписанные спецификации, сертификаты. Файлы лежат в самом
  Dolibarr — их видно и там, если открыть карточку этого поставщика.</p>
  <?php if (empty($supplierDocuments)): ?>
    <p class="muted">Пока ничего не загружено.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Файл</th><th>Размер</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($supplierDocuments as $d): ?>
        <?php $fname = $d['filename'] ?? $d['name'] ?? ''; ?>
        <tr>
          <td><a href="party_document_download.php?party_id=<?= $detail['id'] ?>&filename=<?= urlencode($fname) ?>"><?= htmlspecialchars($fname) ?></a></td>
          <td class="muted"><?= isset($d['size']) ? number_format((int)$d['size'] / 1024, 0, '.', ' ') . ' КБ' : '' ?></td>
          <td>
            <form method="post" onsubmit="return appConfirmSubmit(event, 'Удалить файл «<?= htmlspecialchars(addslashes($fname)) ?>»?')">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_document">
              <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
              <input type="hidden" name="filename" value="<?= htmlspecialchars($fname) ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="row" style="align-items:end; margin-top:12px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_document">
    <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
    <div><label>Добавить файл</label><input type="file" name="document" required></div>
    <div style="flex:0"><button type="submit">Загрузить</button></div>
  </form>
</div>

<div class="card">
  <h2>Годовой контракт</h2>
  <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_contract">
    <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
    <div>
      <label>Сумма контракта, $</label>
      <input type="number" step="0.01" min="0" name="contract_amount" value="<?= $detail['contract_amount'] ?: '' ?>">
    </div>
    <div>
      <label>Начало периода</label>
      <input type="date" name="contract_start" value="<?= htmlspecialchars($detail['contract_start']) ?>">
    </div>
    <div style="flex:0"><button type="submit">Сохранить</button></div>
  </form>

  <?php if ($detail['contract_amount'] > 0 && $detail['contract_start']): ?>
    <p>Закуплено с <?= date('d.m.Y', strtotime($detail['contract_start'])) ?>:
      <strong><?= number_format($detail['spent'], 2) ?> $</strong> из <strong><?= number_format($detail['contract_amount'], 2) ?> $</strong>
      (<?= $detail['order_count'] ?> заказ(ов))</p>
    <div class="contract-bar">
      <div class="contract-bar-fill <?= $detail['remaining'] < 0 ? 'over' : '' ?>" style="width: <?= min(100, max(0, $detail['contract_amount'] > 0 ? ($detail['spent'] / $detail['contract_amount'] * 100) : 0)) ?>%"></div>
    </div>
    <p class="<?= $detail['remaining'] < 0 ? 'err' : 'ok' ?>">
      <?= $detail['remaining'] < 0 ? 'Контракт превышен на ' . number_format(abs($detail['remaining']), 2) . ' $' : 'Осталось по контракту: ' . number_format($detail['remaining'], 2) . ' $' ?>
    </p>
    <?php if ($detail['mixed_currency']): ?>
      <p class="warn">Внимание: заказы в этот период оформлены в РАЗНЫХ валютах — сумма выше сложена "как есть", без конвертации. Смотрите валюту каждого заказа в списке ниже.</p>
    <?php endif; ?>
    <?php if (!empty($detail['orders'])): ?>
      <table style="margin-top:10px">
        <tr><th>Заказ</th><th>Дата</th><th>Сумма</th></tr>
        <?php foreach ($detail['orders'] as $o): ?>
          <tr>
            <td><?= htmlspecialchars($o['ref']) ?></td>
            <td><?= htmlspecialchars($o['date']) ?></td>
            <td><?= number_format($o['total_ttc'], 2) ?> <?= htmlspecialchars($o['currency']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">Заполните сумму и дату начала, чтобы видеть остаток по контракту.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Выписка / сальдо</h2>
  <div class="row" style="align-items:center; margin-bottom:10px">
    <div>
      <div class="muted"><?= $outstanding['opened'] > 0 ? 'Мы должны поставщику' : ($outstanding['opened'] < 0 ? 'Предоплата / переплата' : 'Сальдо') ?></div>
      <div style="font-size:22px; font-weight:700" class="<?= $outstanding['opened'] > 0.01 ? 'err' : 'ok' ?>"><?= number_format(abs($outstanding['opened']), 2) ?> $</div>
    </div>
    <div style="flex:0"><a class="btn secondary small" href="supplier_statement_excel.php?supplier_id=<?= $detail['id'] ?>">📄 Скачать выписку (Excel)</a></div>
  </div>
  <?php if (empty($statementRows)): ?>
    <p class="muted">Пока нет ни одного счёта/оплаты по этому поставщику.</p>
  <?php else: ?>
    <table>
      <tr><th>Дата</th><th>Документ</th><th>№</th><th>Сумма</th><th>Сальдо</th></tr>
      <?php foreach ($statementRows as $r): ?>
        <tr>
          <td class="muted"><?= $r['date'] ? date('d.m.Y', $r['date']) : '' ?></td>
          <td><?= htmlspecialchars($r['kind_label']) ?></td>
          <td class="muted"><?= htmlspecialchars($r['ref']) ?><?= $r['ref_supplier'] ? ' (' . htmlspecialchars($r['ref_supplier']) . ')' : '' ?></td>
          <td class="<?= $r['amount'] < 0 ? 'ok' : '' ?>"><?= ($r['amount'] >= 0 ? '+' : '') . number_format($r['amount'], 2) ?> $</td>
          <td><?= number_format($r['balance'], 2) ?> $</td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Предоплата поставщику</h2>
  <p class="muted">Платёж ДО того, как пришёл счёт — уменьшает сальдо выше, применится автоматически, когда появится реальный счёт.</p>
  <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_prepayment">
    <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
    <div>
      <label>Счёт списания</label>
      <select name="account" id="prepayAccount" onchange="document.getElementById('prepayRateBlock').style.display = this.options[this.selectedIndex].dataset.currency === 'USD' ? 'none' : '';">
        <?php foreach ($moneyAccounts as $key => $acc): ?>
          <option value="<?= $key ?>" data-currency="<?= htmlspecialchars($acc['currency']) ?>"><?= htmlspecialchars($acc['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Сумма (в валюте счёта)</label>
      <input type="number" step="0.01" min="0.01" name="amount" required>
    </div>
    <div id="prepayRateBlock" style="display:none">
      <label>Курс (за 1$)</label>
      <input type="number" step="0.0001" min="0.0001" name="rate">
    </div>
    <div><label>Комментарий (необязательно)</label><input type="text" name="comment"></div>
    <div style="flex:0"><button type="submit">Отправить предоплату</button></div>
  </form>
  <script>document.getElementById('prepayAccount').dispatchEvent(new Event('change'));</script>
</div>
<?php endif; ?>

<script src="assets/picker.js"></script>
<script>
window.wireSupplierSearch('supplierSearch', 'supplierResults', function (s) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_supplier">' +
    '<input type="hidden" name="supplier_id" value="' + s.id + '">' +
    '<input type="hidden" name="supplier_name" value="' + s.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
