<?php
/**
 * Детали одного заказа поставщику + полноценное редактирование (см. CLAUDE.md, 29.08.2026 —
 * "NodirTool: полное редактирование заказа поставщику"):
 * - Статусы "Проведён/Утверждён/Отправлен поставщику" (1/2/3) — кнопка "Изменить заказ" сначала
 *   переоткрывает его до черновика (Dolibarr это умеет штатно, кнопка "Изменить"/"Отменить
 *   утверждение" в родном интерфейсе делает то же самое — CommandeFournisseur::setReopen()), после
 *   чего доступно ниже.
 * - Черновик (statut=0) — можно менять количество/цену любой существующей строки, удалять её,
 *   добавлять новые. После правок заказ ОСТАЁТСЯ черновиком — обратно проводите/утверждаете/
 *   отправляете вручную теми же кнопками, что и в списке заказов (осознанно не автоматизировано).
 * - "Частично/полностью получен" (4/5) и отменённые/отклонённые — редактирование недоступно: там уже
 *   реальные складские движения либо сделка не актуальна, трогать позиции опасно или бессмысленно.
 * - В любом статусе — можно поправить заметку (note_public), это Dolibarr разрешает всегда.
 *
 * Правка/удаление СУЩЕСТВУЮЩЕЙ строки и переоткрытие статуса — через includes/dolibarr_direct.php
 * (прямой доступ к PHP-классам Dolibarr, этого нет в REST API). Добавление новой строки — как и
 * раньше, через обычный REST (там это есть).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/price_history.php';
require_once __DIR__ . '/includes/order_receipts.php';
require_once __DIR__ . '/includes/currency.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    header('Location: orders.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_line') {
        $order = $api->getSupplierOrder($id);
        if (!is_array($order) || (int)($order['statut'] ?? -1) !== 0) {
            $message = 'Добавлять позиции можно только в черновик.';
            $messageType = 'err';
        } else {
            $productId = (int)($_POST['product_id'] ?? 0);
            $label = $_POST['label'] ?? '';
            $price = (float)($_POST['price'] ?? 0);
            $qty = (float)($_POST['qty'] ?? 1);
            if ($qty <= 0) $qty = 1;
            // Валюта берётся у САМОГО заказа (B2): он мог быть оформлен в евро — тогда и цена
            // добавляемой позиции понимается в евро, доллары Dolibarr посчитает по курсу заказа.
            $ordCur = strtoupper(trim((string)($order['multicurrency_code'] ?? ''))) ?: 'USD';
            $ordRate = (float)($order['multicurrency_tx'] ?? 1) ?: 1.0;
            $r = $api->addSupplierOrderLine($id, $productId, $label, $qty, $price, 0, $ordCur);
            if ($r === null) {
                $message = 'Ошибка добавления позиции: ' . $api->lastError;
                $messageType = 'err';
            } else {
                save_purchase_price_with_history($api, $productId, (int)$order['socid'], $price, $_SESSION['user']['name'] ?? '', $ordCur, $ordRate);
                $message = 'Позиция добавлена.';
                $messageType = 'ok';
                // Позиция уже реально добавлена в заказ — редирект (POST → GET), чтобы F5 не отправил
                // эту же форму повторно и не добавил её ещё раз.
                flash_set($message, $messageType);
                header('Location: order_view.php?id=' . $id);
                exit;
            }
        }
    } elseif ($action === 'reopen_order') {
        require_once __DIR__ . '/includes/dolibarr_direct.php';
        $reason = trim($_POST['reopen_reason'] ?? '');
        if ($reason === '') {
            $message = 'Укажите причину переоткрытия — она сохранится в заметке заказа.';
            $messageType = 'err';
        } else {
            $r = dolibarr_reopen_to_draft($id, $reason);
            $message = $r['ok'] ? 'Заказ переоткрыт для правки — теперь можно менять/удалять позиции.' : $r['error'];
            $messageType = $r['ok'] ? 'ok' : 'err';
        }
    } elseif ($action === 'update_line') {
        require_once __DIR__ . '/includes/dolibarr_direct.php';
        $lineId = (int)($_POST['line_id'] ?? 0);
        $qty = (float)($_POST['qty'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $desc = $_POST['desc'] ?? '';
        if ($qty <= 0) {
            $message = 'Количество должно быть больше нуля.';
            $messageType = 'err';
        } else {
            $r = dolibarr_update_line($id, $lineId, $desc, $qty, $price);
            $message = $r['ok'] ? 'Позиция изменена.' : $r['error'];
            $messageType = $r['ok'] ? 'ok' : 'err';
            if ($r['ok']) {
                $order = $api->getSupplierOrder($id);
                if (is_array($order)) {
                    $ordCur = strtoupper(trim((string)($order['multicurrency_code'] ?? ''))) ?: 'USD';
                    $ordRate = (float)($order['multicurrency_tx'] ?? 1) ?: 1.0;
                    save_purchase_price_with_history($api, (int)($_POST['product_id'] ?? 0), (int)$order['socid'], $price, $_SESSION['user']['name'] ?? '', $ordCur, $ordRate);
                }
            }
        }
    } elseif ($action === 'delete_line') {
        require_once __DIR__ . '/includes/dolibarr_direct.php';
        $lineId = (int)($_POST['line_id'] ?? 0);
        $r = dolibarr_delete_line($id, $lineId);
        $message = $r['ok'] ? 'Позиция удалена.' : $r['error'];
        $messageType = $r['ok'] ? 'ok' : 'err';
    } elseif ($action === 'record_shortfall_debt') {
        // Недопоставка при ПРЕДОПЛАТЕ (03.09.2026, новая функция по итогам разговора с Нодиром):
        // заплатили за N штук, приехало меньше — разница физически остаётся нашей предоплатой у
        // поставщика ("поставщик нам должен"). Кнопка РУЧНАЯ (пользователь: "жму сам, когда нужно"),
        // ничего не создаётся автоматически. Долг ведём В ДЕНЬГАХ, без привязки к конкретному товару —
        // поставщик может закрыть его и другим товаром (уточнено пользователем), поэтому документ —
        // обобщённая кредит-нота (тот же механизм, что "Предоплата поставщику", проверенный 02.09.2026).
        require_once __DIR__ . '/includes/supplier_statement.php';
        require_once __DIR__ . '/includes/order_receipts.php';
        $orderNow = $api->getSupplierOrder($id);
        $receivedByLine = [];
        foreach (get_order_receipts($id) as $rc) {
            $receivedByLine[$rc['line_id']] = ($receivedByLine[$rc['line_id']] ?? 0) + $rc['qty'];
        }
        $shortfallUsd = 0.0;
        $shortfallDesc = [];
        foreach ((array)($orderNow['lines'] ?? []) as $l) {
            $orderedQty = (float)($l['qty'] ?? 0);
            $receivedQty = (float)($receivedByLine[(int)($l['id'] ?? 0)] ?? 0);
            $missing = $orderedQty - $receivedQty;
            if ($missing <= 0.0001) continue;
            $shortfallUsd += $missing * (float)($l['subprice'] ?? 0);
            $shortfallDesc[] = ($l['product_label'] ?? $l['desc'] ?? '?') . ' ×' . rtrim(rtrim(number_format($missing, 3, '.', ''), '0'), '.');
        }
        if ($shortfallUsd <= 0.01) {
            $message = 'Недопоставки по этому заказу нет — фиксировать нечего.';
            $messageType = 'err';
        } else {
            $comment = 'Недопоставка по заказу ' . ($orderNow['ref'] ?? "#$id") . ': ' . implode(', ', $shortfallDesc)
                . ' (оплачено, но не привезено) — ' . ($_SESSION['user']['name'] ?? '');
            // ref_supplier — метка "недопоставка по такому-то заказу" (обязательно непустая и
            // уникальная, см. пояснение в create_supplier_prepayment_document()).
            $refForDoc = 'НЕДОПОСТАВКА-' . ($orderNow['ref'] ?? "#$id") . '-' . date('ymd-His');
            $docId = create_supplier_prepayment_document($api, (int)$orderNow['socid'], round($shortfallUsd, 2), $comment, $refForDoc);
            if (!$docId) {
                $message = 'Не удалось создать документ долга поставщика: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $message = 'Зафиксировано: поставщик должен ' . number_format($shortfallUsd, 2) . ' $ (документ #' . $docId
                    . '). Видно в сальдо поставщика («Поставщики / контракты» → Выписка). Деньги при этом НЕ двигались — '
                    . 'это фиксация уже сделанной предоплаты за непривезённое.';
                $messageType = 'ok';
                flash_set($message, $messageType);
                header('Location: order_view.php?id=' . $id);
                exit;
            }
        }
    } elseif ($action === 'add_expense') {
        require_once __DIR__ . '/includes/logistics.php';
        $expenseType = $_POST['expense_type'] ?? '';
        $mode = $_POST['amount_mode'] ?? 'usd';
        $comment = trim($_POST['comment'] ?? '');
        $who = $_SESSION['user']['name'] ?? '';
        // Перевозчик (необязательно, топ-5 пункт 3) — если выбран, деньги не списываются сразу, это
        // становится долгом перевозчику (гасится отдельно в разделе "Перевозчики").
        $carrierId = (int)($_POST['carrier_id'] ?? 0) ?: null;

        if ($mode === 'usd') {
            $amount = (float)($_POST['usd_amount'] ?? 0);
            $r = logistics_record_expense('order', $id, $expenseType, $amount, 'USD', null, (int)$cfg['currency_accounts']['USD'], $who, $comment, $carrierId);
        } else {
            $uzsAmount = (float)($_POST['uzs_amount'] ?? 0);
            $rate = (float)($_POST['rate'] ?? 0);
            $r = logistics_record_expense('order', $id, $expenseType, $uzsAmount, 'UZS', $rate, (int)$cfg['uzs_account_id'], $who, $comment, $carrierId);
        }

        if (!($r['ok'] ?? false)) {
            $message = $r['error'] ?? 'Ошибка сохранения расхода.';
            $messageType = 'err';
        } else {
            $n = count($r['affected_products'] ?? []);
            $message = ($r['overdraft_warning'] ?? '') . "Расход внесён" . (isset($r['usd_amount']) ? " ({$r['usd_amount']} \$)" : '') . ". Себестоимость пересчитана для {$n} товаров." . (!empty($r['note']) ? ' ' . $r['note'] : '');
            $messageType = !empty($r['overdraft_warning']) ? 'warn' : 'ok';
            // Расход уже реально записан (и деньги списаны) — редирект (POST → GET), чтобы F5 не
            // отправил эту же форму повторно и не списал сумму ещё раз.
            flash_set($message, $messageType);
            header('Location: order_view.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'delete_expense') {
        require_once __DIR__ . '/includes/logistics.php';
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $r = logistics_delete_expense($expenseId);
        $message = $r['ok'] ? ('Расход удалён. ' . ($r['note'] ?? '')) : ($r['error'] ?? 'Ошибка удаления.');
        $messageType = $r['ok'] ? 'ok' : 'err';
        flash_set($message, $messageType);
        header('Location: order_view.php?id=' . $id);
        exit;
    } elseif ($action === 'reset_recompute') {
        require_once __DIR__ . '/includes/logistics.php';
        $r = logistics_reset_and_recompute('order', $id);
        $n = count($r['affected_products'] ?? []);
        $message = "Пересчитано заново для {$n} товаров. " . ($r['note'] ?? '');
        $messageType = strpos($r['note'] ?? '', 'ВНИМАНИЕ') !== false ? 'warn' : 'ok';
        flash_set($message, $messageType);
        header('Location: order_view.php?id=' . $id);
        exit;
    } elseif ($action === 'send_to_supplier') {
        // Письмо поставщику со списком позиций и спецификацией во вложении (05.09.2026).
        require_once __DIR__ . '/includes/mailer.php';
        $ord = $api->getSupplierOrder($id);
        $to = trim($_POST['to'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $who = $_SESSION['user']['name'] ?? '';

        if (!is_array($ord)) {
            $message = 'Заказ не найден.';
            $messageType = 'err';
        } elseif ($to === '') {
            $message = 'Укажите адрес поставщика — письмо отправлять некуда.';
            $messageType = 'err';
        } elseif (empty($ord['lines'])) {
            $message = 'В заказе нет позиций — отправлять нечего.';
            $messageType = 'err';
        } else {
            $soc = $api->getThirdparty((int)$ord['socid']);
            $socName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';
            $ordCur = strtoupper(trim((string)($ord['multicurrency_code'] ?? ''))) ?: 'USD';

            $body = mail_order_body($ord, (array)$ord['lines'], $socName, $ordCur, $who);
            $extra = trim($_POST['note'] ?? '');
            if ($extra !== '') {
                $body = '<div style="font-family:Arial,sans-serif;font-size:14px;white-space:pre-line">'
                      . htmlspecialchars($extra) . '</div><br>' . $body;
            }

            // Спецификация генерируется во временный файл тем же кодом, что и при скачивании —
            // чтобы поставщик получил ровно тот документ, который закупщик видит у себя.
            $files = [];
            $attachNames = [];
            if (!empty($_POST['attach_spec'])) {
                require_once __DIR__ . '/includes/spec_render.php';
                $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spec_' . $id . '_' . time() . '.xls';
                $specData = spec_render_to_string($id);
                if ($specData !== null) {
                    file_put_contents($tmp, $specData);
                    $niceName = 'Спецификация ' . ($ord['ref'] ?? $id) . '.xls';
                    $files[] = ['path' => $tmp, 'name' => $niceName, 'mime' => 'application/vnd.ms-excel'];
                    $attachNames[] = $niceName;
                } else {
                    $message = 'Не удалось подготовить спецификацию — письмо не отправлено.';
                    $messageType = 'err';
                }
            }

            if ($messageType !== 'err') {
                $r = mail_send($to, $subject !== '' ? $subject : ('Заказ ' . ($ord['ref'] ?? '')), $body, $files);
                foreach ($files as $f) @unlink($f['path']);   // временные файлы за собой убираем

                mail_log_write([
                    'fk_order' => $id, 'order_ref' => (string)($ord['ref'] ?? ''),
                    'fk_supplier' => (int)$ord['socid'], 'to_email' => $to, 'subject' => $subject,
                    'attachments' => implode(', ', $attachNames), 'sent_by' => $who,
                    'ok' => $r['ok'], 'error' => $r['error'],
                ]);

                flash_set($r['ok'] ? "Письмо отправлено на {$to}." : ('Не удалось отправить: ' . $r['error']),
                          $r['ok'] ? 'ok' : 'err');
                header('Location: order_view.php?id=' . $id);
                exit;
            }
        }
    } elseif ($action === 'save_note') {
        $ok = $api->updateSupplierOrderNote($id, trim($_POST['note_public'] ?? ''));
        if ($ok === null) {
            $message = 'Ошибка сохранения заметки: ' . $api->lastError;
            $messageType = 'err';
        } else {
            $message = 'Заметка сохранена.';
            $messageType = 'ok';
        }
    } elseif ($action === 'upload_document') {
        $order = $api->getSupplierOrder($id);
        if (!is_array($order) || empty($order['ref'])) {
            $message = 'Заказ не найден.';
            $messageType = 'err';
        } elseif (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = 'Выберите файл для загрузки.';
            $messageType = 'err';
        } else {
            $filename = basename($_FILES['document']['name']);
            $content = base64_encode(file_get_contents($_FILES['document']['tmp_name']));
            $res = $api->uploadOrderDocument($order['ref'], $filename, $content);
            if ($res === null) {
                $message = 'Ошибка загрузки файла: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $message = "Файл «{$filename}» загружен.";
                $messageType = 'ok';
                flash_set($message, $messageType);
                header('Location: order_view.php?id=' . $id);
                exit;
            }
        }
    } elseif ($action === 'delete_document') {
        $order = $api->getSupplierOrder($id);
        $filename = basename($_POST['filename'] ?? '');
        if (!is_array($order) || empty($order['ref']) || $filename === '') {
            $message = 'Не удалось определить файл для удаления.';
            $messageType = 'err';
        } else {
            $ok = $api->deleteOrderDocument($order['ref'], $filename);
            $message = $ok ? "Файл «{$filename}» удалён." : ('Ошибка удаления: ' . $api->lastError);
            $messageType = $ok ? 'ok' : 'err';
        }
    } elseif ($action === 'delete_draft') {
        $order = $api->getSupplierOrder($id);
        if (!is_array($order) || (int)($order['statut'] ?? -1) !== 0) {
            $message = 'Удалить целиком можно только черновик.';
            $messageType = 'err';
        } else {
            $ok = $api->deleteSupplierOrder($id);
            if (!$ok) {
                $message = 'Ошибка удаления: ' . $api->lastError;
                $messageType = 'err';
            } else {
                header('Location: orders.php');
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

$order = $api->getSupplierOrder($id);
if (!is_array($order)) {
    http_response_code(404);
    die('Заказ не найден.');
}

$statusLabels = [
    0 => 'Черновик', 1 => 'Проведён', 2 => 'Утверждён', 3 => 'Отправлен поставщику',
    4 => 'Частично получен', 5 => 'Получен полностью', 6 => 'Отменён', 7 => 'Отменён', 9 => 'Отклонён поставщиком',
];
$statut = (int)($order['statut'] ?? 0);
// Валюта заказа (B2, 04.09.2026): цены строк и итог показываем в ней, доллары — рядом справочно.
$orderCurrency = strtoupper(trim((string)($order['multicurrency_code'] ?? ''))) ?: 'USD';
$orderRate = (float)($order['multicurrency_tx'] ?? 1) ?: 1.0;
$orderCurLabel = currency_label($orderCurrency);
$isDraft = $statut === 0;
$canReopen = in_array($statut, [1, 2, 3], true);
$isLockedForever = in_array($statut, [4, 5, 6, 7, 9], true); // получен (есть складские движения) или отменён/отклонён
$supplier = $api->getThirdparty((int)$order['socid']);
$supplierName = is_array($supplier) ? ($supplier['name'] ?? $supplier['nom'] ?? '') : '';
// Условия оплаты поставщика (03.09.2026) — от них зависит, показывать ли кнопку "Зафиксировать как
// долг поставщика" при недопоставке: она нужна только при ПРЕДОПЛАТЕ (заплатили вперёд за то, что не
// привезли). При постоплате недопоставка проблемы не создаёт — счёт и так выставляется по факту.
$supplierPaymentTerms = is_array($supplier) ? ($supplier['array_options']['options_payment_terms'] ?? '') : '';

require_once __DIR__ . '/includes/logistics.php';
$expenses = logistics_get_expenses('order', $id);
// Имена перевозчиков для уже внесённых расходов — одним запросом (не в цикле, см. отчёт аудита P0#5).
$carrierIdsInExpenses = array_values(array_unique(array_filter(array_map(fn($e) => (int)($e['fk_carrier'] ?? 0), $expenses))));
$carrierNamesById = $carrierIdsInExpenses ? $api->getThirdpartiesByIds($carrierIdsInExpenses) : [];

// Приём по заказу — ЧТЕНИЕ того, что реально принял склад (Жамшид/MuhammadAli через TeplouxKassa),
// не отдельная параллельная запись (по решению пользователя, 03.09.2026). Товар из заказа сверяется
// с принятым по line_id, чтобы показать "заказано / принято / остаток" по каждой позиции.
$receiptsByLine = [];
foreach (get_order_receipts($id) as $r) {
    $receiptsByLine[$r['line_id']][] = $r;
}
$documents = $api->getOrderDocuments($order['ref'] ?? '');

// UX-N2 (внешний отчёт, 02.09.2026) — "+ Новый перевозчик" прямо из формы расхода: одноразовый маркер
// от carrier_form.php, читаем и сразу чистим (см. тот же приём в batches.php).
$justCreatedCarrier = $_SESSION['new_carrier_for_expense'] ?? null;
unset($_SESSION['new_carrier_for_expense']);

// BUG-N2 — только для ЗАГОЛОВКА, общая функция nt_order_display_ref() (includes/auth.php).
$displayRef = nt_order_display_ref($order['ref'] ?? '', $order['statut'] ?? -1, $id);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заказ <?= htmlspecialchars($displayRef) ?></h1>
<p><a href="orders.php" class="btn secondary">← Все заказы</a></p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <div><strong>Поставщик:</strong> <?= htmlspecialchars($supplierName) ?></div>
      <div><strong>Дата заказа:</strong> <?= !empty($order['date_commande']) ? date('d.m.Y', (int)$order['date_commande']) : '—' ?></div>
      <?php if (!empty($order['delivery_date'])): ?>
        <div><strong>Ожидаемая доставка:</strong> <?= date('d.m.Y', (int)$order['delivery_date']) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right">
      <span class="badge badge-neutral"><?= htmlspecialchars($statusLabels[$statut] ?? $statut) ?></span>
      <?php if (stripos((string)($order['note_private'] ?? ''), '[NodirTool] Заказ переоткрыт') !== false): ?>
        <div><span class="warn" style="font-size:12px">были правки после отправки поставщику</span></div>
      <?php endif; ?>
      <div style="font-size:19px; font-weight:700; margin-top:6px;">
        <?php if ($orderCurrency !== 'USD'): ?>
          <?= number_format((float)($order['multicurrency_total_ttc'] ?? 0), 2) ?> <?= htmlspecialchars($orderCurrency) ?>
          <div class="muted" style="font-size:13px; font-weight:400">≈ <?= number_format((float)($order['total_ttc'] ?? 0), 2) ?> $ по курсу <?= rtrim(rtrim(number_format($orderRate, 4, '.', ''), '0'), '.') ?></div>
        <?php else: ?>
          <?= number_format((float)($order['total_ttc'] ?? 0), 2) ?> $
        <?php endif; ?>
      </div>
      <?php if ($canReopen): ?>
        <form method="post" id="reopenForm" style="margin-top:8px" onsubmit="return submitReopen(this);">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="reopen_order">
          <input type="hidden" name="reopen_reason" id="reopenReason">
          <button type="submit" class="secondary small">Изменить заказ</button>
        </form>
        <script>
        function submitReopen(form) {
          // UX-K4 (02.09.2026): confirm() заменён на модалку приложения (см. assets/confirm-modal.js),
          // сам prompt() (причина) вне зоны этой правки — оставлен нативным. Та же двухпроходная схема,
          // что и в appConfirmSubmit(), просто с дополнительным шагом (заполнение reopenReason) внутри.
          if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return true; }
          const reason = prompt('Причина переоткрытия заказа (сохранится в заметке заказа):');
          if (reason === null || reason.trim() === '') { alert('Без причины переоткрыть нельзя.'); return false; }
          appConfirm('Переоткрыть заказ для правки? Он временно станет черновиком — после правок нужно будет заново провести/утвердить/отправить.').then(function (ok) {
            if (ok) {
              form.querySelector('#reopenReason').value = reason.trim();
              form.dataset.confirmed = '1';
              form.requestSubmit();
            }
          });
          return false;
        }
        </script>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <h2>Спецификация поставщику</h2>
  <p class="muted">Тот самый документ, который уходит поставщику и на таможню: номер контракта,
  коды ТНВЭД, наименования, количество и цены. Номер спецификации выдаётся автоматически и
  закрепляется за заказом — повторное скачивание даёт тот же номер, а не следующий.</p>
  <?php
    $specNo = (int)($order['array_options']['options_spec_number'] ?? 0);
    $noHs = 0;
    foreach ((array)($order['lines'] ?? []) as $l) {
        $pid = (int)($l['fk_product'] ?? 0);
        if (!$pid) { $noHs++; continue; }
    }
    $socOptsView = is_array($supplier) ? ($supplier['array_options'] ?? []) : [];
    $contractNoView = trim((string)($socOptsView['options_contract_number'] ?? ''));
  ?>
  <?php if ($specNo > 0): ?>
    <p>Спецификация <strong>№ <?= $specNo ?></strong> уже сформирована по этому заказу.</p>
  <?php endif; ?>
  <?php if ($contractNoView === ''): ?>
    <p class="warn">У поставщика не указан номер контракта — в шапке спецификации будет прочерк.
      <a href="supplier_form.php?ctx=suppliers&id=<?= (int)$order['socid'] ?>">Заполнить в карточке</a></p>
  <?php endif; ?>
  <?php if (empty($order['lines'])): ?>
    <p class="muted">В заказе нет позиций — формировать нечего.</p>
  <?php else: ?>
    <a class="btn" href="spec_excel.php?id=<?= $id ?>">📄 Скачать спецификацию (Excel)</a>
  <?php endif; ?>
</div>

<?php
// Отправка заказа поставщику письмом (05.09.2026).
require_once __DIR__ . '/includes/mailer.php';
$mailReady = mail_is_configured();
$supplierEmail = is_array($supplier) ? trim((string)($supplier['email'] ?? '')) : '';
$supplierNameView = is_array($supplier) ? ($supplier['name'] ?? $supplier['nom'] ?? '') : '';
$mailHistory = mail_log_for_order($id);
?>
<div class="card">
  <h2>Отправить поставщику</h2>
  <?php if (!$mailReady): ?>
    <p class="warn" style="display:inline-block">Почта ещё не настроена — отправлять пока нечем.</p>
    <p class="muted">Один раз заполните данные корпоративной почты в разделе
      <a href="mail_setup.php">«Настройка почты»</a>, и кнопка отправки появится здесь и на всех
      остальных заказах.</p>
  <?php elseif (empty($order['lines'])): ?>
    <p class="muted">В заказе нет позиций — отправлять нечего.</p>
  <?php else: ?>
    <?php if ($supplierEmail === ''): ?>
      <p class="note">В карточке поставщика не указана почта. Можно вписать адрес прямо здесь, но
      тогда он не сохранится на будущее —
      <a href="supplier_form.php?ctx=suppliers&id=<?= (int)$order['socid'] ?>">лучше заполнить в карточке</a>.</p>
    <?php endif; ?>
    <form method="post" onsubmit="return appConfirmSubmit(this, 'Отправить заказ поставщику письмом?')">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_to_supplier">
      <div class="row">
        <div style="flex:2"><label>Кому</label>
          <input type="text" name="to" value="<?= htmlspecialchars($supplierEmail) ?>"
                 placeholder="почта поставщика" required></div>
        <div style="flex:3"><label>Тема письма</label>
          <input type="text" name="subject"
                 value="<?= htmlspecialchars('Заказ ' . nt_order_display_ref($order['ref'] ?? '', (int)($order['statut'] ?? 0), $id) . ' — Теплолюкс') ?>"></div>
      </div>
      <label>Что дописать в начале письма <span class="muted">— необязательно</span></label>
      <textarea name="note" rows="3" placeholder="например: просим подтвердить наличие и сроки отгрузки"></textarea>
      <label style="font-weight:normal">
        <input type="checkbox" name="attach_spec" value="1" checked> приложить спецификацию файлом
      </label>
      <p class="muted">Список позиций с ценами в валюте заказа уходит прямо в тексте письма — поставщик
      увидит заказ, даже не открывая вложение.</p>
      <button type="submit">✉️ Отправить поставщику</button>
    </form>
  <?php endif; ?>

  <?php if ($mailHistory): ?>
    <h3 style="margin-top:18px">Что уже отправляли</h3>
    <table>
      <tr><th>Когда</th><th>Кому</th><th>Вложение</th><th>Кто</th><th>Результат</th></tr>
      <?php foreach ($mailHistory as $m): ?>
        <tr>
          <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($m['datec']))) ?></td>
          <td><?= htmlspecialchars($m['to_email']) ?></td>
          <td class="muted"><?= htmlspecialchars($m['attachments'] ?: '—') ?></td>
          <td><?= htmlspecialchars($m['sent_by']) ?></td>
          <td><?= $m['ok']
                ? '<span class="ok">отправлено</span>'
                : '<span class="err">не ушло: ' . htmlspecialchars(mb_substr((string)$m['error'], 0, 120)) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Позиции</h2>
  <?php if (empty($order['lines'])): ?>
    <p class="muted">Пусто.</p>
  <?php elseif ($isDraft): ?>
    <table>
      <tr><th>Товар</th><th>Кол-во</th><th>Цена, <?= htmlspecialchars($orderCurLabel) ?></th><th>Сумма</th><th></th></tr>
      <?php foreach ($order['lines'] as $l):
        $lineId = (int)($l['id'] ?? 0);
        $qty = (float)($l['qty'] ?? 0);
        // В валютном заказе редактируем и показываем цену В ВАЛЮТЕ — именно её видит поставщик.
        $pu = $orderCurrency !== 'USD'
            ? (float)($l['multicurrency_subprice'] ?? 0)
            : (float)($l['subprice'] ?? 0);
      ?>
        <tr>
          <td><?= htmlspecialchars($l['product_label'] ?? $l['desc'] ?? '') ?><div class="muted"><?= htmlspecialchars($l['ref'] ?? $l['product_ref'] ?? '') ?></div></td>
          <td>
            <form method="post" style="display:flex; gap:4px">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_line">
              <input type="hidden" name="line_id" value="<?= $lineId ?>">
              <input type="hidden" name="desc" value="<?= htmlspecialchars($l['desc'] ?? $l['product_label'] ?? '') ?>">
              <input type="hidden" name="product_id" value="<?= (int)($l['fk_product'] ?? 0) ?>">
              <input type="hidden" name="price" value="<?= htmlspecialchars((string)$pu) ?>">
              <input type="number" name="qty" value="<?= htmlspecialchars((string)$qty) ?>" step="any" min="0.001" style="width:70px; margin:0" onchange="this.form.submit()">
            </form>
          </td>
          <td>
            <form method="post" style="display:flex; gap:4px">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_line">
              <input type="hidden" name="line_id" value="<?= $lineId ?>">
              <input type="hidden" name="desc" value="<?= htmlspecialchars($l['desc'] ?? $l['product_label'] ?? '') ?>">
              <input type="hidden" name="product_id" value="<?= (int)($l['fk_product'] ?? 0) ?>">
              <input type="hidden" name="qty" value="<?= htmlspecialchars((string)$qty) ?>">
              <input type="number" name="price" value="<?= htmlspecialchars((string)$pu) ?>" step="0.01" min="0" style="width:80px; margin:0" onchange="this.form.submit()">
            </form>
            <a class="muted" style="font-size:12px" href="price_history_view.php?product_id=<?= (int)($l['fk_product'] ?? 0) ?>&supplier_id=<?= (int)$order['socid'] ?>">🕘 история цены</a>
          </td>
          <td><?= number_format($qty * $pu, 2) ?> <?= htmlspecialchars($orderCurLabel) ?></td>
          <td>
            <form method="post" onsubmit="return appConfirmSubmit(this, 'Удалить эту позицию?');">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_line">
              <input type="hidden" name="line_id" value="<?= $lineId ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <table>
      <tr><th>Товар</th><th>Кол-во</th><th>Цена, <?= htmlspecialchars($orderCurLabel) ?></th><th>Сумма</th></tr>
      <?php foreach ($order['lines'] as $l):
        $qty = (float)($l['qty'] ?? 0);
        $pu = $orderCurrency !== 'USD'
            ? (float)($l['multicurrency_subprice'] ?? 0)
            : (float)($l['subprice'] ?? 0);
      ?>
        <tr>
          <td><?= htmlspecialchars($l['product_label'] ?? $l['desc'] ?? '') ?><div class="muted"><?= htmlspecialchars($l['ref'] ?? $l['product_ref'] ?? '') ?></div></td>
          <td><?= htmlspecialchars((string)$qty) ?></td>
          <td><?= number_format($pu, 2) ?> <?= htmlspecialchars($orderCurLabel) ?> <a class="muted" style="font-size:12px" href="price_history_view.php?product_id=<?= (int)($l['fk_product'] ?? 0) ?>&supplier_id=<?= (int)$order['socid'] ?>">🕘</a></td>
          <td><?= number_format($qty * $pu, 2) ?> <?= htmlspecialchars($orderCurLabel) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if ($isDraft): ?>
    <h2 style="margin-top:18px">Добавить позицию</h2>
    <input type="text" id="productSearch" placeholder="Поиск по названию или артикулу..." data-extra-query="supplier_id=<?= (int)$order['socid'] ?>">
    <div id="productResults" class="result-list"></div>
  <?php elseif ($canReopen): ?>
    <p class="muted" style="margin-top:14px">Заказ уже проведён/утверждён/отправлен — чтобы поменять
      количество, цену, добавить или убрать позицию, сначала нажмите «Изменить заказ» выше.</p>
  <?php else: ?>
    <p class="muted" style="margin-top:14px">Позиции менять нельзя: заказ уже <?= $statut >= 4 && $statut <= 5 ? 'частично или полностью получен на склад — по нему есть реальные складские движения' : 'отменён/отклонён' ?>.
      Если что-то не так — нужен отдельный документ (новый заказ / корректировка склада), не правка этого.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Приём по заказу</h2>
  <p class="muted">Показывает то, что реально принял склад через кассу (TeplouxKassa) — здесь нельзя ничего отметить или исправить самому, только посмотреть.</p>
  <?php if (empty($order['lines'])): ?>
    <p class="muted">В заказе нет позиций.</p>
  <?php else: ?>
    <table>
      <tr><th>Товар</th><th>Заказано</th><th>Принято</th><th>Остаток</th></tr>
      <?php
        // Считаем сумму недопоставки — для кнопки "Зафиксировать как долг поставщика" (см. ниже).
        $shortfallUsdView = 0.0;
        $anyReceiptAtAll = false;
      ?>
      <?php foreach ($order['lines'] as $l):
        $lineId = (int)($l['id'] ?? 0);
        $orderedQty = (float)($l['qty'] ?? 0);
        $lineReceipts = $receiptsByLine[$lineId] ?? [];
        $receivedQty = array_sum(array_column($lineReceipts, 'qty'));
        $remaining = max(0, $orderedQty - $receivedQty);
        if ($receivedQty > 0.0001) $anyReceiptAtAll = true;
        $shortfallUsdView += $remaining * (float)($l['subprice'] ?? 0);
      ?>
        <tr>
          <td><?= htmlspecialchars($l['product_label'] ?? $l['desc'] ?? '') ?><div class="muted"><?= htmlspecialchars($l['ref'] ?? $l['product_ref'] ?? '') ?></div></td>
          <td><?= rtrim(rtrim(number_format($orderedQty, 3, '.', ''), '0'), '.') ?></td>
          <td class="<?= $receivedQty > 0.0001 ? 'ok' : '' ?>"><?= rtrim(rtrim(number_format($receivedQty, 3, '.', ''), '0'), '.') ?></td>
          <td class="<?= $remaining > 0.0001 ? 'err' : '' ?>"><?= rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php // Недопоставка при ПРЕДОПЛАТЕ — заплатили вперёд за то, что не привезли (03.09.2026).
          // Кнопка ручная, показывается только когда приёмка реально была И осталась недостача. ?>
    <?php if ($supplierPaymentTerms === 'prepay' && $anyReceiptAtAll && $shortfallUsdView > 0.01): ?>
      <div class="warn" style="margin-top:14px; display:block">
        <strong>Недопоставка на <?= number_format($shortfallUsdView, 2) ?> $.</strong>
        Поставщик работает по предоплате — значит за это уже заплачено, но товар не приехал.
        Можно зафиксировать это как долг поставщика (появится в его сальдо, «Поставщики / контракты» →
        Выписка). Деньги при этом никуда не двигаются — это фиксация уже сделанной предоплаты.
        <form method="post" style="margin-top:10px"
              onsubmit="return appConfirmSubmit(this, 'Зафиксировать: поставщик должен <?= number_format($shortfallUsdView, 2) ?> $ за непривезённое? Деньги не двигаются, документ появится в сальдо поставщика.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="record_shortfall_debt">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="secondary">Зафиксировать как долг поставщика</button>
        </form>
      </div>
    <?php elseif ($supplierPaymentTerms === 'postpay' && $anyReceiptAtAll && $shortfallUsdView > 0.01): ?>
      <p class="muted" style="margin-top:14px">Недопоставка на <?= number_format($shortfallUsdView, 2) ?> $, но
        поставщик работает по постоплате — счёт ему выставляется по фактически принятому количеству,
        отдельно фиксировать долг не нужно.</p>
    <?php endif; ?>
    <?php
      $allReceipts = array_merge(...array_values($receiptsByLine ?: [[]]));
      usort($allReceipts, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
    ?>
    <?php if (!empty($allReceipts)): ?>
      <h2 style="margin-top:18px">По событиям приёмки</h2>
      <table>
        <tr><th>Когда</th><th>Документ приёмки</th><th>Товар</th><th>Кол-во</th><th>Склад</th></tr>
        <?php foreach ($allReceipts as $r):
          $line = null;
          foreach (($order['lines'] ?? []) as $l) { if ((int)($l['id'] ?? 0) === $r['line_id']) { $line = $l; break; } }
        ?>
          <tr>
            <td class="muted"><?= htmlspecialchars($r['date'] ? substr((string)$r['date'], 0, 16) : '') ?></td>
            <td><?= htmlspecialchars($r['reception_ref'] ?: '—') ?></td>
            <td><?= htmlspecialchars($line['product_label'] ?? $line['desc'] ?? ('товар #' . $r['fk_product'])) ?></td>
            <td><?= rtrim(rtrim(number_format($r['qty'], 3, '.', ''), '0'), '.') ?></td>
            <td class="muted">склад #<?= $r['warehouse_id'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Документы к заказу</h2>
  <p class="muted">Инвойс поставщика, упаковочный лист, CMR/накладная перевозчика, ГТД, сертификаты — любые файлы, без ограничений по типу/размеру.</p>
  <?php if (empty($documents)): ?>
    <p class="muted">Пока ничего не загружено.</p>
  <?php else: ?>
    <table>
      <tr><th>Файл</th><th>Размер</th><th>Загружен</th><th></th></tr>
      <?php foreach ($documents as $d): ?>
        <tr>
          <td><a href="document_download.php?order_id=<?= $id ?>&filename=<?= urlencode($d['filename'] ?? $d['name'] ?? '') ?>"><?= htmlspecialchars($d['filename'] ?? $d['name'] ?? '') ?></a></td>
          <td class="muted"><?= isset($d['size']) ? number_format($d['size'] / 1024, 0) . ' КБ' : '' ?></td>
          <td class="muted"><?= !empty($d['date']) ? date('d.m.Y H:i', (int)$d['date']) : '' ?></td>
          <td>
            <form method="post" onsubmit="return appConfirmSubmit(this, 'Удалить файл «<?= htmlspecialchars($d['filename'] ?? $d['name'] ?? '', ENT_QUOTES) ?>»?');">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_document">
              <input type="hidden" name="filename" value="<?= htmlspecialchars($d['filename'] ?? $d['name'] ?? '') ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="margin-top:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_document">
    <input type="file" name="document" required>
    <button type="submit" style="margin-top:8px">Загрузить файл</button>
  </form>
</div>

<div class="card">
  <h2>Логистические расходы (только по этому заказу)</h2>
  <p class="muted">Расходы, относящиеся ко ВСЕЙ партии сразу (например транспорт, если этот заказ ехал одной машиной с другими) — вносите на странице <a href="batches.php">Партии / Логистика</a>, не здесь.</p>
  <form method="post" id="expenseForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_expense">
    <div class="row">
      <div>
        <label>Вид расхода</label>
        <select name="expense_type">
          <?php foreach (LOGISTICS_EXPENSE_TYPES as $key => $label): ?>
            <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Оплачено в</label>
        <select name="amount_mode" onchange="document.getElementById('usdBlockO').style.display=this.value=='usd'?'':'none'; document.getElementById('uzsBlockO').style.display=this.value=='uzs'?'':'none';">
          <option value="usd">$ напрямую</option>
          <option value="uzs">сумах (+ курс)</option>
        </select>
      </div>
    </div>
    <div class="row" id="usdBlockO">
      <div><label>Сумма, $</label><input type="number" step="0.01" min="0.01" name="usd_amount"></div>
    </div>
    <div class="row" id="uzsBlockO" style="display:none">
      <div><label>Сумма, сум</label><input type="number" step="1" min="1" name="uzs_amount"></div>
      <div><label>Курс (сум за 1$)</label><input type="number" step="0.01" min="0.01" name="rate"></div>
    </div>
    <div>
      <label>Перевозчик (необязательно — если выбран, деньги СЕЙЧАС не списываются, это становится долгом, оплатите его в разделе «Перевозчики»)</label>
      <input type="hidden" name="carrier_id" id="expCarrierIdO">
      <div id="expCarrierChosenO" style="display:none; padding:8px 10px; border:1px solid var(--border); border-radius:8px; margin-bottom:8px">
        <span id="expCarrierNameO"></span>
        <button type="button" class="secondary small" onclick="document.getElementById('expCarrierIdO').value='';document.getElementById('expCarrierChosenO').style.display='none';document.getElementById('expCarrierSearchWrapO').style.display='';">✕ убрать</button>
      </div>
      <div id="expCarrierSearchWrapO">
        <input type="text" id="expCarrierSearchO" placeholder="Платите сразу — оставьте пустым. Иначе начните печатать название перевозчика...">
        <div id="expCarrierResultsO" class="result-list"></div>
        <p class="muted" style="margin:4px 0 0"><a href="carrier_form.php?ctx=order_expense&return_id=<?= $id ?>">+ Новый перевозчик</a></p>
      </div>
    </div>
    <div><label>Комментарий (необязательно)</label><input type="text" name="comment"></div>
    <button type="submit">Сохранить расход</button>
  </form>

  <div class="row" style="align-items:center; margin-top:18px">
    <h2 style="margin:0">Уже внесено по этому заказу</h2>
    <form method="post" style="flex:0" onsubmit="return appConfirmSubmit(this, 'Пересчитать себестоимость этого заказа с нуля? Базовая точка (остаток до поставки) будет определена заново из ТЕКУЩЕГО остатка склада — если часть товара уже продана, результат будет приблизительным.');">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_recompute">
      <button type="submit" class="secondary small">🔄 Пересчитать с нуля</button>
    </form>
  </div>
  <?php if (!empty($expenses)): ?>
    <table>
      <tr><th>Вид</th><th>Сумма</th><th>$</th><th>Перевозчик</th><th>Когда</th><th>Комментарий</th><th></th></tr>
      <?php foreach ($expenses as $e): ?>
        <?php $carr = !empty($e['fk_carrier']) ? ($carrierNamesById[(int)$e['fk_carrier']] ?? null) : null; ?>
        <tr>
          <td><?= htmlspecialchars(LOGISTICS_EXPENSE_TYPES[$e['expense_type']] ?? $e['expense_type']) ?></td>
          <td><?= number_format((float)$e['native_amount'], 2) ?> <?= htmlspecialchars($e['native_currency']) ?><?= $e['rate'] ? ' (курс ' . number_format((float)$e['rate'], 2) . ')' : '' ?></td>
          <td><?= number_format((float)$e['usd_amount'], 2) ?> $</td>
          <td class="muted"><?= $carr ? htmlspecialchars($carr['name'] ?? $carr['nom'] ?? '') : '—' ?></td>
          <td class="muted"><?= htmlspecialchars(substr($e['datec'], 0, 16)) ?></td>
          <td class="muted"><?= htmlspecialchars($e['comment']) ?></td>
          <td>
            <form method="post" onsubmit="return appConfirmSubmit(this, 'Удалить этот расход? Если по нему списывались деньги — они будут возвращены на счёт, себестоимость пересчитается заново.');">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_expense">
              <input type="hidden" name="expense_id" value="<?= (int)$e['rowid'] ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <p class="muted">Пока пусто.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Заметка</h2>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_note">
    <textarea name="note_public" rows="3" style="width:100%; padding:10px; border:1px solid var(--border); border-radius:8px; font-family:inherit; font-size:14px;"><?= htmlspecialchars($order['note_public'] ?? '') ?></textarea>
    <div style="margin-top:8px"><button type="submit">Сохранить заметку</button></div>
  </form>
</div>

<?php if ($isDraft): ?>
<div class="card">
  <h2>Удалить черновик</h2>
  <p class="muted">Если весь черновик собран неверно — можно удалить целиком и начать заново (вместо
    того чтобы убирать позиции по одной).</p>
  <form method="post" onsubmit="return appConfirmSubmit(this, 'Удалить весь черновик заказа безвозвратно?');">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_draft">
    <button type="submit" class="danger">Удалить черновик целиком</button>
  </form>
</div>
<?php endif; ?>

<script src="assets/picker.js"></script>
<script>
function selectCarrierIntoExpenseFormO(c) {
  const idInput = document.getElementById('expCarrierIdO');
  if (!idInput) return;
  idInput.value = c.id;
  document.getElementById('expCarrierNameO').textContent = c.name;
  document.getElementById('expCarrierChosenO').style.display = '';
  document.getElementById('expCarrierSearchWrapO').style.display = 'none';
}
window.wireCarrierSearch && window.wireCarrierSearch('expCarrierSearchO', 'expCarrierResultsO', selectCarrierIntoExpenseFormO);
<?php if ($justCreatedCarrier): ?>
// UX-N2: только что создали перевозчика через "+ Новый перевозчик" — сразу подставляем в пикер.
selectCarrierIntoExpenseFormO({ id: <?= (int)$justCreatedCarrier['id'] ?>, name: <?= json_encode($justCreatedCarrier['name'], JSON_UNESCAPED_UNICODE) ?> });
<?php endif; ?>
window.wireProductSearch && window.wireProductSearch('productSearch', 'productResults', function (p) {
  const form = document.createElement('form');
  form.method = 'post';
  const startPrice = (p.supplier_price !== null && p.supplier_price !== undefined) ? p.supplier_price : 0;
  const qty = prompt('Количество:', '1');
  if (qty === null) return;
  const price = prompt('Цена поставщика за единицу, $:', startPrice);
  if (price === null) return;
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="add_line">' +
    '<input type="hidden" name="id" value="<?= $id ?>">' +
    '<input type="hidden" name="product_id" value="' + p.id + '">' +
    '<input type="hidden" name="label" value="' + p.label.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="qty" value="' + qty.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="price" value="' + price.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
