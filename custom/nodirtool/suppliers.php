<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/debt.php';   // сальдо по валютам (05.09.2026)
require_once __DIR__ . '/includes/supplier_statement.php';
require_once __DIR__ . '/includes/currency.php';

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
        $amount = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['contract_amount'] ?? 0));
        $start = trim($_POST['contract_start'] ?? '');
        // «Уже выполнено до учёта в программе» (11.09.2026): работаем с поставщиком давно, а заказы
        // здесь ведутся с сентября 2026 — без этой цифры контракт выглядел начатым с нуля.
        $done = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['contract_done_amount'] ?? 0));
        $doneDate = trim($_POST['contract_done_date'] ?? '');
        if ($done > 0 && $doneDate === '') $doneDate = date('Y-m-d');
        if ($amount < 0 || $done < 0) {
            $message = 'Суммы не могут быть отрицательными.'; $messageType = 'err';
        } elseif ($done > 0 && $start !== '' && $doneDate < $start) {
            $message = 'Дата «выполнено на» раньше начала контракта — проверьте даты.'; $messageType = 'err';
        } else {
            $ok = $api->updateThirdpartyExtrafields($id, [
                'contract_amount' => $amount,
                'contract_start' => $start !== '' ? strtotime($start) : null,
                'contract_done_amount' => $done > 0 ? $done : null,
                'contract_done_date' => $done > 0 ? strtotime($doneDate) : null,
            ]);
            if ($ok === null) {
                $message = 'Ошибка сохранения: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $_SESSION['_preserve_once']['selected_supplier'] = true;
                flash_set('Данные по контракту сохранены.', 'ok');
                header('Location: suppliers.php#contract');
                exit;
            }
        }
    } elseif ($action === 'save_contact' || $action === 'set_contact_status') {
        // Сотрудники поставщика (11.09.2026) — штатные контакты Dolibarr, видны и в самом Dolibarr.
        // Ушедшего не удаляем, а отмечаем «больше не работает»: в старой переписке и заказах он остаётся.
        $sid = (int)($_POST['supplier_id'] ?? 0);
        $cid = (int)($_POST['contact_id'] ?? 0);
        $err = '';
        if ($cid) {
            $c = $api->getContact($cid);
            if (!is_array($c) || (int)($c['socid'] ?? $c['fk_soc'] ?? 0) !== $sid) $err = 'Сотрудник не найден у этого поставщика.';
        }
        if (!$err && $action === 'set_contact_status') {
            $on = !empty($_POST['active']) ? 1 : 0;
            $r = $api->updateContact($cid, ['status' => $on, 'statut' => $on]);
            if ($r === null) $err = 'Ошибка: ' . $api->lastError;
            else $okMsg = $on ? 'Сотрудник снова в списке.' : 'Отмечен «больше не работает» — в списке ушедших.';
        } elseif (!$err) {
            $data = [
                'lastname'     => trim((string)($_POST['lastname'] ?? '')),
                'firstname'    => trim((string)($_POST['firstname'] ?? '')),
                'poste'        => trim((string)($_POST['poste'] ?? '')),
                'email'        => trim((string)($_POST['email'] ?? '')),
                'phone_pro'    => trim((string)($_POST['phone_pro'] ?? '')),
                'phone_mobile' => trim((string)($_POST['phone_mobile'] ?? '')),
                'note_private' => trim((string)($_POST['note_private'] ?? '')),
            ];
            if ($data['lastname'] === '' && $data['firstname'] !== '') { $data['lastname'] = $data['firstname']; $data['firstname'] = ''; }
            if ($data['lastname'] === '') $err = 'Укажите имя сотрудника.';
            elseif ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $err = 'Почта записана с ошибкой: ' . $data['email'];
            else {
                if ($cid) $r = $api->updateContact($cid, $data);
                else $r = $api->createContact($data + ['socid' => $sid, 'status' => 1, 'statut' => 1]);
                if ($r === null) $err = 'Ошибка сохранения: ' . $api->lastError;
                else $okMsg = $cid ? 'Данные сотрудника сохранены.' : 'Сотрудник добавлен.';
            }
        }
        if ($err) { $message = $err; $messageType = 'err'; }
        else {
            $_SESSION['_preserve_once']['selected_supplier'] = true;
            flash_set($okMsg, 'ok');
            header('Location: suppliers.php#staff');
            exit;
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
        // Валюта контракта — родное поле Dolibarr (форма поставщика пишет туда), доп.поле — запасное.
        $contractCur = strtoupper((string)($soc['multicurrency_code'] ?? ''))
                       ?: (strtoupper((string)($opts['options_contract_currency'] ?? '')) ?: 'USD');
        $doneAmount = (float)($opts['options_contract_done_amount'] ?? 0);
        $doneTs = !empty($opts['options_contract_done_date']) ? (int)$opts['options_contract_done_date'] : null;
        $contractRate = $contractCur === 'USD' ? 1.0 : dolibarr_currency_rate($contractCur);

        // 11.09.2026: выполнение считается В ВАЛЮТЕ КОНТРАКТА. Раньше суммировались доллары и
        // сравнивались с суммой контракта как есть — у CALEFFI контракт 100 000 € сравнивался с
        // долларами. Заказ в валюте контракта берётся как есть, в другой — через доллары по курсу Dolibarr.
        $spent = 0.0;          // заказы в программе, в валюте контракта
        $spentByCur = [];
        $orderCount = 0;
        $contractOrders = [];
        $currencies = [];
        $converted = false;
        if ($contractAmount > 0 && $contractStartTs) {
            $orders = $api->getSupplierOrdersForSupplier($id);
            if (is_array($orders)) {
                foreach ($orders as $o) {
                    $statut = (int)($o['statut'] ?? 0);
                    // Дата заказа: у утверждённого, но ещё не отправленного её нет — берём дату
                    // утверждения/проведения/создания, иначе такие заказы в контракт не попадали.
                    $date = (int)($o['date_commande'] ?: ($o['date_approve'] ?: ($o['date_valid'] ?: ($o['date_creation'] ?? 0))));
                    if ($statut < 2 || $statut > 5 || $date < $contractStartTs) continue;
                    // что было до «выполнено на» — уже входит в введённую вручную сумму
                    if ($doneTs && date('Y-m-d', $date) <= date('Y-m-d', $doneTs)) continue;
                    $currency = strtoupper((string)($o['multicurrency_code'] ?: 'USD'));
                    $totalNative = $currency === 'USD'
                        ? (float)($o['total_ttc'] ?? 0)
                        : (float)($o['multicurrency_total_ttc'] ?? $o['total_ttc'] ?? 0);
                    if ($currency === $contractCur) $inContract = $totalNative;
                    else { $inContract = $contractRate ? (float)($o['total_ttc'] ?? 0) * $contractRate : 0.0; $converted = true; }
                    $spent += $inContract;
                    $spentByCur[$currency] = ($spentByCur[$currency] ?? 0) + $totalNative;
                    $orderCount++;
                    $currencies[$currency] = true;
                    $contractOrders[] = [
                        'ref' => $o['ref'] ?? '',
                        'date' => $date ? date('d.m.Y', $date) : '',
                        'total_ttc' => $totalNative,
                        'currency' => $currency,
                    ];
                }
            }
        }
        $fulfilled = $doneAmount + $spent;

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
            'spent_by_currency' => $spentByCur,
            'done_amount' => $doneAmount,
            'done_date' => $doneTs ? date('Y-m-d', $doneTs) : '',
            'fulfilled' => $fulfilled,
            'converted' => $converted,
            // Валюта контракта. Форма поставщика сохраняет её в родное поле Dolibarr
            // (multicurrency_code), поэтому берём сначала его, а доп.поле — как запасной вариант.
            // Раньше читалось только доп.поле, и карточка контракта всегда показывала доллары,
            // какую бы валюту ни выбрали (та же ошибка, что нашёл Абдурашид у перевозчиков).
            'contract_currency' => $contractCur,
            'remaining' => $contractAmount - $fulfilled,
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
            'contacts' => $api->getThirdpartyContacts($id),
            'currency' => (string)($soc['multicurrency_code'] ?? ''),
        ];

        $supplierDocuments = $api->getPartyDocuments($id);

        // Выписка / сальдо (топ-5 пункт 4, 02.09.2026). 05.09.2026: сальдо считаем сами и ПО
        // ВАЛЮТАМ — штатный getOutstandingBills() отдаёт только долларовый пересчёт, а платить
        // поставщику надо в валюте его счёта.
        $balanceByCur = supplier_debt_by_currency($id)[$id] ?? [];
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
            <?php if ($detail['contact_person'] === '' && $detail['email'] === '' && !$detail['contacts']): ?>
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

<?php if ($detail):
    $staffActive = array_values(array_filter($detail['contacts'], fn($c) => (int)($c['status'] ?? $c['statut'] ?? 1) === 1));
    $staffGone = array_values(array_filter($detail['contacts'], fn($c) => (int)($c['status'] ?? $c['statut'] ?? 1) !== 1));
    $staffForm = function (?array $c) use ($detail) { ob_start(); ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_contact">
        <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
        <input type="hidden" name="contact_id" value="<?= (int)($c['id'] ?? 0) ?>">
        <div class="row">
          <div><label>Имя</label><input type="text" name="firstname" value="<?= htmlspecialchars($c['firstname'] ?? '') ?>"></div>
          <div><label>Фамилия</label><input type="text" name="lastname" value="<?= htmlspecialchars($c['lastname'] ?? '') ?>"></div>
          <div><label>Должность</label><input type="text" name="poste" value="<?= htmlspecialchars($c['poste'] ?? '') ?>" placeholder="менеджер, бухгалтер, логист…"></div>
        </div>
        <div class="row">
          <div><label>Почта</label><input type="email" name="email" value="<?= htmlspecialchars($c['email'] ?? '') ?>"></div>
          <div><label>Телефон</label><input type="text" name="phone_pro" value="<?= htmlspecialchars($c['phone_pro'] ?? '') ?>"></div>
          <div><label>Мобильный / WhatsApp</label><input type="text" name="phone_mobile" value="<?= htmlspecialchars($c['phone_mobile'] ?? '') ?>"></div>
        </div>
        <div><label>Заметка</label><input type="text" name="note_private" value="<?= htmlspecialchars($c['note_private'] ?? '') ?>" placeholder="за что отвечает, на каком языке писать…"></div>
        <button type="submit"><?= $c ? 'Сохранить' : 'Добавить сотрудника' ?></button>
      </form>
    <?php return ob_get_clean(); };
?>
<div class="card" id="staff">
  <h2>Сотрудники поставщика (<?= count($staffActive) ?>)</h2>
  <?php if (!$staffActive): ?>
    <p class="muted">Пока никого. Добавьте менеджера, бухгалтера, логиста — кому писать по заказам, оплате и отгрузке.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Кто</th><th>Должность</th><th>Почта</th><th>Телефон</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($staffActive as $c):
            $cn = trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? ''));
            $ph = array_filter([$c['phone_pro'] ?? '', $c['phone_mobile'] ?? '']); ?>
        <tr>
          <td><strong><?= htmlspecialchars($cn) ?></strong>
            <?php if (!empty($c['note_private'])): ?><div class="muted" style="font-size:12px"><?= htmlspecialchars($c['note_private']) ?></div><?php endif; ?></td>
          <td><?= htmlspecialchars($c['poste'] ?? '') ?></td>
          <td><?php if (!empty($c['email'])): ?><a href="mailto:<?= htmlspecialchars($c['email']) ?>"><?= htmlspecialchars($c['email']) ?></a><?php endif; ?></td>
          <td style="white-space:nowrap"><?= htmlspecialchars(implode(', ', $ph)) ?></td>
          <td style="white-space:nowrap">
            <details style="display:inline-block"><summary class="muted" style="cursor:pointer">✏️</summary>
              <div style="min-width:560px; margin-top:8px"><?= $staffForm($c) ?></div></details>
            <form method="post" style="display:inline" onsubmit="return appConfirmSubmit(this, 'Отметить, что <?= htmlspecialchars(addslashes($cn), ENT_QUOTES) ?> больше не работает у поставщика? Он уйдёт в список ушедших, данные сохранятся.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_contact_status">
              <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
              <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
              <input type="hidden" name="active" value="0">
              <button type="submit" class="secondary small" title="Больше не работает">🚪</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <details style="margin-top:12px"<?= $staffActive ? '' : ' open' ?>>
    <summary style="cursor:pointer; font-weight:600">+ Добавить сотрудника</summary>
    <div style="margin-top:10px"><?= $staffForm(null) ?></div>
  </details>
  <?php if ($staffGone): ?>
    <details style="margin-top:8px">
      <summary class="muted" style="cursor:pointer">Больше не работают (<?= count($staffGone) ?>)</summary>
      <table style="margin-top:6px">
        <?php foreach ($staffGone as $c): ?>
          <tr class="muted">
            <td><?= htmlspecialchars(trim(($c['firstname'] ?? '') . ' ' . ($c['lastname'] ?? ''))) ?></td>
            <td><?= htmlspecialchars($c['poste'] ?? '') ?></td>
            <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_contact_status">
                <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
                <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="active" value="1">
                <button type="submit" class="secondary small">Вернуть в список</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

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

<div class="card" id="contract">
  <h2>Годовой контракт</h2>
  <?php $cc = $detail['contract_currency']; $ccl = currency_label($cc); ?>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_contract">
    <input type="hidden" name="supplier_id" value="<?= $detail['id'] ?>">
    <div class="row" style="align-items:end">
      <div>
        <label>Сумма контракта, <?= htmlspecialchars($ccl) ?></label>
        <input type="number" step="0.01" min="0" name="contract_amount" value="<?= $detail['contract_amount'] ?: '' ?>">
      </div>
      <div>
        <label>Начало периода</label>
        <input type="date" name="contract_start" value="<?= htmlspecialchars($detail['contract_start']) ?>">
      </div>
    </div>
    <div class="row" style="align-items:end">
      <div>
        <label>Уже выполнено до учёта в программе, <?= htmlspecialchars($ccl) ?></label>
        <input type="number" step="0.01" min="0" name="contract_done_amount" value="<?= $detail['done_amount'] ?: '' ?>" placeholder="0">
      </div>
      <div>
        <label>На дату</label>
        <input type="date" name="contract_done_date" value="<?= htmlspecialchars($detail['done_date']) ?>">
      </div>
    </div>
    <p class="muted" style="margin-top:-4px">Сколько закупили по этому контракту раньше — по сверке с поставщиком,
      1С или SAP. Заказы из программы <strong>после этой даты</strong> прибавятся сами; всё, что было до неё,
      должно входить в эту сумму. Если вели всё здесь с начала контракта — оставьте пустым.</p>
    <button type="submit">Сохранить</button>
  </form>

  <?php if ($detail['contract_amount'] > 0 && $detail['contract_start']):
        $pct = $detail['contract_amount'] > 0 ? $detail['fulfilled'] / $detail['contract_amount'] * 100 : 0; ?>
    <p style="margin-top:14px">Выполнено: <strong><?= htmlspecialchars(money($detail['fulfilled'], $cc)) ?></strong>
      из <strong><?= htmlspecialchars(money($detail['contract_amount'], $cc)) ?></strong>
      (<?= number_format($pct, 1) ?>%)</p>
    <?php if ($detail['done_amount'] > 0): ?>
      <p class="muted" style="margin-top:-6px">
        до учёта в программе (на <?= date('d.m.Y', strtotime($detail['done_date'])) ?>): <?= htmlspecialchars(money($detail['done_amount'], $cc)) ?>
        + заказы в программе после этой даты: <?= htmlspecialchars(money($detail['spent'], $cc)) ?> (<?= $detail['order_count'] ?>)</p>
    <?php else: ?>
      <p class="muted" style="margin-top:-6px">заказы в программе с <?= date('d.m.Y', strtotime($detail['contract_start'])) ?>: <?= $detail['order_count'] ?></p>
    <?php endif; ?>
    <div class="contract-bar">
      <div class="contract-bar-fill <?= $detail['remaining'] < 0 ? 'over' : '' ?>" style="width: <?= min(100, max(0, $pct)) ?>%"></div>
    </div>
    <p class="<?= $detail['remaining'] < 0 ? 'err' : 'ok' ?>">
      <?= $detail['remaining'] < 0
            ? 'Контракт превышен на ' . htmlspecialchars(money(abs($detail['remaining']), $cc))
            : 'Осталось по контракту: ' . htmlspecialchars(money($detail['remaining'], $cc)) ?>
    </p>
    <?php if ($detail['converted']): ?>
      <p class="warn">Часть заказов оформлена не в <?= htmlspecialchars($cc) ?> — они пересчитаны в <?= htmlspecialchars($cc) ?> по курсу Dolibarr, это приблизительно.</p>
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
    <p class="muted">Заполните сумму и дату начала, чтобы видеть выполнение и остаток по контракту.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Выписка / сальдо</h2>
  <div class="row" style="align-items:center; margin-bottom:10px">
    <div>
      <?php
        // Долг может быть сразу в нескольких валютах — показываем строками, не складываем.
        $owe  = array_filter($balanceByCur, fn($v) => $v > 0.01);
        $over = array_filter($balanceByCur, fn($v) => $v < -0.01);
      ?>
      <?php if ($owe): ?>
        <div class="muted">Мы должны поставщику</div>
        <div style="font-size:22px; font-weight:700" class="err"><?= htmlspecialchars(money_by_currency($owe)) ?></div>
      <?php endif; ?>
      <?php if ($over): ?>
        <div class="muted"<?= $owe ? ' style="margin-top:6px"' : '' ?>>Предоплата / переплата</div>
        <div style="font-size:22px; font-weight:700" class="ok"><?= htmlspecialchars(money_by_currency(array_map('abs', $over))) ?></div>
      <?php endif; ?>
      <?php if (!$owe && !$over): ?>
        <div class="muted">Сальдо</div>
        <div style="font-size:22px; font-weight:700" class="ok"><?= htmlspecialchars(money(0.0)) ?></div>
      <?php endif; ?>
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
          <td class="<?= $r['amount'] < 0 ? 'ok' : '' ?>"><?= ($r['amount'] >= 0 ? '+' : '') . htmlspecialchars(money($r['amount'], $r['currency'])) ?></td>
          <td><?= htmlspecialchars(money($r['balance'], $r['currency'])) ?></td>
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

<script src="assets/picker.js?v=20260911"></script>
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
