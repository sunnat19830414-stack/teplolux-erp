<?php
/**
 * Выдача денег клиенту (физический возврат наличными/картой/QR/переводом) — зеркало advance.php, но
 * в обратную сторону: закрывает существующую "переплату" (незакрытую кредит-ноту от возврата/аванса)
 * штатным Dolibarr-механизмом setInvoicePaid() (закрывает документ БЕЗ создания платежа — проверено
 * эмпирически 02.09.2026, корректно убирает вклад кредит-ноты из общего баланса клиента), а реальные
 * деньги списываются напрямую с кассы/сумового счёта направления (см. includes/payment_split.php::
 * postPayoutMoney()) — независимо от закрытия документа, тем же принципом, что и у аванса.
 *
 * Если у клиента нет незакрытой кредит-ноты, но деньги всё равно нужно выдать — можно оформить
 * "другую сумму": заводится НОВАЯ кредит-нота с обязательным комментарием и сразу закрывается тем же
 * способом, чтобы не оставлять в Dolibarr незакрытый долг "в другую сторону".
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_split.php';

/**
 * Незакрытые кредит-ноты (переплаты) клиента — та же выборка, что и для отображения таблицы ниже,
 * вынесена в функцию, чтобы её же можно было заново вызвать ПРЯМО ПЕРЕД списанием денег (BUG-K4,
 * внешний отчёт 02.09.2026, см. ниже у payout_mode==='custom').
 */
function payout_get_open_credit_notes(DolibarrApi $api, int $socId): array
{
    $result = [];
    $summaries = $api->getInvoicesForClient($socId);
    if (is_array($summaries)) {
        foreach ($summaries as $s) {
            $inv = $api->getInvoice((int)$s['id']);
            if (!is_array($inv)) continue;
            if ((int)($inv['type'] ?? 0) === 2 && (string)($inv['paye'] ?? '1') === '0' && (string)($inv['statut'] ?? '') === '1') {
                $result[] = [
                    'id' => (int)$inv['id'],
                    'ref' => $inv['ref'] ?? '',
                    'amount' => abs((float)($inv['total_ttc'] ?? 0)),
                    'date' => !empty($inv['date']) ? date('d.m.Y', (int)$inv['date']) : '',
                ];
            }
        }
    }
    return $result;
}

if (!array_key_exists('payout_client', $_SESSION)) $_SESSION['payout_client'] = null;

reset_selection_unless_preserved('payout_client');

$message = '';
$messageType = '';
$showReceiptLink = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!select_client_for_direction($api, $cfg, 'payout_client', $clientId, $_POST['client_name'] ?? '')) {
            $message = 'Клиент не найден или относится к другому направлению.';
            $messageType = 'err';
        }
    } elseif ($action === 'clear_client') {
        $_SESSION['payout_client'] = null;
    } elseif ($action === 'payout') {
        $socId = (int)($_SESSION['payout_client']['id'] ?? 0);
        $mode = $_POST['payout_mode'] ?? 'existing'; // 'existing' (закрыть кредит-ноту) или 'custom' (другая сумма)
        $creditNoteId = (int)($_POST['credit_note_id'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        $paySplitResult = resolvePaySplit($cfg, $_POST);
        $paySplit = $paySplitResult['amounts'];
        $payDetail = $paySplitResult['detail'];
        $total = array_sum($paySplit);

        if (empty($socId)) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif ($paySplitResult['errors']) {
            $message = implode(' ', $paySplitResult['errors']);
            $messageType = 'err';
        } elseif (empty($paySplit)) {
            $message = 'Укажите сумму хотя бы по одному способу выдачи.';
            $messageType = 'err';
        } elseif ($mode === 'custom' && $comment === '') {
            $message = 'Укажите причину выдачи — это обязательно, если она не привязана к конкретной переплате.';
            $messageType = 'err';
        } elseif ($mode === 'custom' && payout_get_open_credit_notes($api, $socId)) {
            // BUG-K4 (внешний отчёт, 02.09.2026): "другая сумма" заводит НОВУЮ, не связанную с
            // остальными кредит-ноту и сразу её закрывает — а уже СУЩЕСТВУЮЩИЕ незакрытые кредит-ноты
            // клиента (реальная переплата, задокументированная раньше) при этом не трогаются вообще.
            // Раньше это позволяло выдать деньги через "другую сумму", а потом ЕЩЁ РАЗ выдать те же
            // деньги, закрыв старые кредит-ноты по кнопке "Выбрать" — двойная выдача одной и той же
            // переплаты. Теперь, пока у клиента есть хоть одна незакрытая кредит-нота, "другая сумма"
            // заблокирована — сначала закрыть их через "Выбрать" в таблице выше, только когда реальных
            // задокументированных переплат не осталось, "другая сумма" снова доступна.
            $message = 'У клиента уже есть незакрытая переплата (кредит-нота/аванс) — сначала закройте её через кнопку «Выбрать» в таблице выше, «Другая сумма» пока заблокирована, чтобы не выдать одну и ту же переплату дважды.';
            $messageType = 'err';
        } elseif ($mode === 'existing' && !$creditNoteId) {
            $message = 'Выберите переплату, которую закрываете.';
            $messageType = 'err';
        } else {
            $refForReceipt = 'Выдача денег';
            $ok = true;

            if ($mode === 'existing') {
                // Перепроверяем документ ЗАНОВО на сервере — не доверяем сумме, посчитанной при
                // отрисовке формы (могла устареть, если кто-то уже закрыл эту переплату параллельно).
                $fresh = $api->getInvoice($creditNoteId);
                $freshOk = is_array($fresh)
                    && (int)($fresh['socid'] ?? 0) === $socId
                    && (int)($fresh['type'] ?? 0) === 2
                    && (string)($fresh['paye'] ?? '1') === '0';
                if (!$freshOk) {
                    $message = 'Эта переплата уже закрыта или не найдена — обновите страницу.';
                    $messageType = 'err';
                    $ok = false;
                } else {
                    $expected = abs((float)($fresh['total_ttc'] ?? 0));
                    if (abs($total - $expected) > 0.01) {
                        $message = "Сумма выдачи ({$total} \$) не совпадает с суммой переплаты (" . number_format($expected, 2) . " \$) — исправьте и повторите.";
                        $messageType = 'err';
                        $ok = false;
                    } else {
                        $refForReceipt = $fresh['ref'] ?? "#$creditNoteId";
                    }
                }
            }

            if ($ok) {
                $noteIdToClose = $creditNoteId;
                if ($mode === 'custom') {
                    $noteIdToClose = $api->createCreditNote($socId);
                    if (!$noteIdToClose) {
                        $message = 'Ошибка создания документа: ' . $api->lastError;
                        $messageType = 'err';
                        $ok = false;
                    } else {
                        $lineRes = $api->addGenericInvoiceLine($noteIdToClose, 'Выдача денег: ' . $comment, $total, 0);
                        if ($lineRes === null) {
                            $message = 'Ошибка добавления суммы: ' . $api->lastError;
                            $messageType = 'err';
                            $ok = false;
                        } else {
                            $val = $api->validateInvoice($noteIdToClose);
                            if ($val === null) {
                                $message = 'Ошибка проведения документа: ' . $api->lastError;
                                $messageType = 'err';
                                $ok = false;
                            } else {
                                $refForReceipt = 'Выдача денег: ' . $comment;
                            }
                        }
                    }
                }

                if ($ok) {
                    $closeOk = $api->setInvoicePaid($noteIdToClose);
                    if (!$closeOk) {
                        $message = 'Не удалось закрыть документ (' . $api->lastError . ') — деньги НЕ списаны, повторите.';
                        $messageType = 'err';
                    } else {
                        $moneyErrors = postPayoutMoney($api, $cfg, $payDetail, 'Выдача денег клиенту, касса ' . $cfg['direction_label'] . ($comment !== '' ? ' — ' . $comment : ''));
                        $paidLabels = [];
                        foreach ($paySplit as $key => $amt) {
                            $paidLabels[] = paySplitLabel($cfg['payment_accounts'][$key]['label'], $payDetail[$key]);
                        }
                        $message = "Готово! Выдано клиенту: " . implode(' + ', $paidLabels) . ".";
                        if ($moneyErrors) {
                            $message .= "\nВНИМАНИЕ: часть суммы не удалось списать со счёта: " . implode('; ', $moneyErrors) . " — переплата клиента уже закрыта, поправьте остаток кассы вручную.";
                        }
                        $messageType = $moneyErrors ? 'err' : 'ok';

                        $receiptItems = [];
                        foreach ($paySplit as $key => $amt) {
                            $receiptItems[] = ['ref' => $refForReceipt, 'method' => $cfg['payment_accounts'][$key]['label'], 'amount' => $amt, 'uzs' => $payDetail[$key]['uzs'], 'rate' => $payDetail[$key]['rate']];
                        }
                        $_SESSION['last_receipt'] = [
                            'type' => 'out',
                            'client_name' => $_SESSION['payout_client']['name'] ?? '',
                            'items' => $receiptItems,
                            'total' => $total,
                            'date' => time(),
                        ];
                        $showReceiptLink = true;
                        $_SESSION['payout_client'] = null;
                        // Документ уже реальный (и деньги уже списаны) — редирект (POST → GET), чтобы
                        // F5 не повторил отправку формы. Особенно важно для режима "другая сумма" —
                        // там, в отличие от "закрыть существующую переплату", повторная отправка не
                        // отбилась бы автоматически (создала бы ещё одну кредит-ноту и списала деньги
                        // повторно).
                        flash_set($message, $messageType, ['show_receipt_link' => true]);
                        header('Location: payout.php');
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

// Незакрытые кредит-ноты (переплаты) выбранного клиента — те же признаки, что везде в приложении
// использует настоящая кредит-нота (type=2), ещё не закрытая (paye=0, statut=1 — здесь этот документ
// либо только что провалидирован, либо ждёт закрытия; черновики/отменённые в этом приложении никогда
// не остаются, поэтому достаточно этих двух условий).
$openCreditNotes = [];
$clientBalance = null;
if ($_SESSION['payout_client']) {
    $socId = (int)$_SESSION['payout_client']['id'];
    $openCreditNotes = payout_get_open_credit_notes($api, $socId);
    $out = $api->getOutstandingInvoices($socId);
    if (is_array($out) && isset($out['opened'])) $clientBalance = (float)$out['opened'];
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Выдача денег клиенту</h1>
<p class="muted">Физический возврат наличными/картой/QR/переводом — закрывает существующую переплату клиента или, если её нет, оформляет новую (с обязательной причиной) и сразу закрывает.</p>
<?php if ($_SESSION['payout_client']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_client">
    <button type="submit" class="secondary">← Сменить клиента</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>
<?php if ($showReceiptLink): ?>
  <p><a class="btn secondary" href="payment_receipt_excel.php">🧾 Распечатать квитанцию о выдаче денег (Excel)</a></p>
<?php endif; ?>

<div class="card">
  <h2>Клиент</h2>
  <?php if ($_SESSION['payout_client']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['payout_client']['name']) ?></strong>
        <?php if ($clientBalance !== null): ?>
          <div><span class="badge <?= $clientBalance > 0.01 ? 'badge-debt' : 'badge-ok' ?>">
            <?php if ($clientBalance > 0.01): ?>Долг: <?= number_format($clientBalance, 2) ?> $
            <?php elseif ($clientBalance < -0.01): ?>Аванс/переплата: <?= number_format(abs($clientBalance), 2) ?> $
            <?php else: ?>Долгов нет
            <?php endif; ?>
          </span></div>
        <?php endif; ?>
        <div><a href="client_form.php?ctx=payout&id=<?= (int)$_SESSION['payout_client']['id'] ?>" class="muted">✏️ Редактировать</a></div>
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
  <?php endif; ?>
</div>

<?php if ($_SESSION['payout_client']): ?>

<?php if (!empty($openCreditNotes)): ?>
<div class="card">
  <h2>Закрыть существующую переплату</h2>
  <table>
    <tr><th>Документ</th><th>Дата</th><th>Сумма</th><th></th></tr>
    <?php foreach ($openCreditNotes as $cn): ?>
      <tr>
        <td><?= htmlspecialchars($cn['ref']) ?></td>
        <td class="muted"><?= htmlspecialchars($cn['date']) ?></td>
        <td><?= number_format($cn['amount'], 2) ?> $</td>
        <td><button type="button" class="small" onclick="selectCreditNote(<?= (int)$cn['id'] ?>, <?= (float)$cn['amount'] ?>, '<?= htmlspecialchars($cn['ref'], ENT_QUOTES) ?>')">Выбрать</button></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2 id="payoutFormTitle">Сколько и как выдать</h2>
  <?php if (!empty($openCreditNotes)): ?>
    <?php // K-4 (внешняя приёмка, 03.09.2026): из текста для кассира убрана ссылка на "BUG-K4 в отчёте" —
          // Жамшид и MuhammadAli не знают ни про какой отчёт. Причина объяснена теми же словами, но по делу. ?>
    <p class="muted" id="payoutModeHint">У клиента есть незакрытая переплата — нажмите «Выбрать» в таблице выше. Пока она не закрыта, «Другая сумма» недоступна: иначе одну и ту же переплату можно выдать дважды.</p>
  <?php else: ?>
    <p class="muted" id="payoutModeHint">Незакрытых переплат нет — заполните «Другую сумму» ниже и укажите причину.</p>
  <?php endif; ?>
  <?php // UX-K4 (02.09.2026): заодно исправлен пре-существующий баг — onsubmit стоял на <button>, а не
        // на <form>, где-то и не мог работать (браузер игнорирует onsubmit не-формы), подтверждение
        // фактически никогда не срабатывало. Перенесено на форму вместе с заменой confirm() на модалку.
        // K-7 (внешний QA-аудит, раунд 2, 03.09.2026) — текст подтверждения теперь собирается в JS
        // (см. payoutForm.onsubmit ниже), не статичной строкой здесь, чтобы показать точные цифры
        // долга при выдаче клиенту, который всё ещё должен фирме БОЛЬШЕ, чем эта выдача. ?>
  <form method="post" id="payoutForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="payout">
    <input type="hidden" name="payout_mode" id="f_mode" value="custom">
    <input type="hidden" name="credit_note_id" id="f_credit_note_id" value="0">
    <div id="customReasonBlock">
      <label>Причина выдачи (обязательно, если не выбрана переплата выше)</label>
      <input type="text" name="comment" id="f_comment" placeholder="например: вернули деньги за бракованный товар, вопрос был решён без официального возврата">
    </div>
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
            <input type="number" step="0.01" min="0" class="pay-amount-out" name="pay[<?= $key ?>]" placeholder="0.00">
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <button type="submit">Выдать деньги</button>
  </form>
</div>
<?php endif; ?>

<script>
function selectCreditNote(id, amount, ref) {
  document.getElementById('f_mode').value = 'existing';
  document.getElementById('f_credit_note_id').value = id;
  document.getElementById('customReasonBlock').style.display = 'none';
  document.getElementById('payoutFormTitle').textContent = 'Закрываем: ' + ref + ' (' + amount.toFixed(2) + ' $)';
  document.getElementById('payoutModeHint').textContent = 'Сумма выдачи должна совпасть с суммой переплаты — введите её ниже по способам оплаты.';
  const first = document.querySelector('.pay-amount-out');
  if (first && !first.value) first.value = amount.toFixed(2);
}

// K-7 (внешний QA-аудит, раунд 2, 03.09.2026): раньше можно было выдать деньги клиенту, у которого
// чистый баланс всё равно остаётся долгом (переплат по мелким кредит-нотам меньше, чем общий долг) —
// без единого предупреждения. Решение (согласовано с пользователем): не блокировать (может быть законная
// причина — например возврат за брак, даже если в целом клиент должник), но явно спросить с точными
// цифрами "было/будет", если после этой выдачи клиент останется (или впервые станет) должен.
const clientNetBalance = <?= json_encode($clientBalance) ?>; // null, если баланс не удалось получить

function payoutAmountAboutToPay() {
  let sum = 0;
  document.querySelectorAll('.pay-amount-out').forEach(inp => { sum += parseFloat(inp.value) || 0; });
  document.querySelectorAll('.pay-uzs-group').forEach(group => {
    const uzs = parseFloat(group.querySelector('.pay-uzs-input').value) || 0;
    const rate = parseFloat(group.querySelector('.pay-rate-input').value) || 0;
    sum += rate > 0 ? uzs / rate : 0;
  });
  return sum;
}

document.getElementById('payoutForm').addEventListener('submit', function (e) {
  const form = this;
  // Та же двухпроходная схема, что и в appConfirmSubmit() (assets/confirm-modal.js) — не пользуемся
  // ей напрямую здесь, т.к. нужен ДИНАМИЧЕСКИЙ текст подтверждения (точные цифры долга), а не
  // статичная строка.
  if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; } // второй, настоящий проход — пропускаем
  e.preventDefault();

  const amount = payoutAmountAboutToPay();
  let message = 'Выдать деньги клиенту?';
  if (clientNetBalance !== null && amount > 0.001) {
    // Выдача увеличивает баланс клиента точно так же, как на сервере (getOutstandingInvoices → opened):
    // была переплата (баланс < 0) — она уменьшается; был/появляется долг — он растёт.
    const after = clientNetBalance + amount;
    if (after > 0.01) {
      message = 'У клиента ' + (clientNetBalance > 0.01 ? ('долг ' + clientNetBalance.toFixed(2) + ' $') : 'долгов нет') +
        '. Выдача ' + amount.toFixed(2) + ' $ ' + (clientNetBalance > 0.01 ? 'увеличит его до ' : 'создаст долг ') +
        after.toFixed(2) + ' $. Точно выдать?';
    }
  }
  window.appConfirm(message).then(function (ok) {
    if (ok) { form.dataset.confirmed = '1'; form.requestSubmit(); }
  });
});

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
