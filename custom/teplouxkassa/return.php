<?php
/**
 * Возврат товара от клиента — ДВА независимых режима (по прямой просьбе пользователя оставить оба):
 * 1. "Возврат по счёту" — выбирается конкретный счёт-продажа клиента, возврат оформляется РОВНО теми
 *    ценами/количествами, что были в этом счёте (не текущей ценой из карточки товара — раньше это
 *    приводило к расхождению долга клиента, если цена товара менялась между продажей и возвратом).
 *    Кредит-нота связывается с исходным счётом через штатное поле Dolibarr fk_facture_source.
 * 2. "Возврат без привязки к счёту" — прежний свободный режим (поиск товара, текущая цена) — оставлен
 *    без изменений, для случаев, когда исходного счёта нет/не важно (клиент без документа и т.п.).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dolibarr_direct.php'; // get_invoice_line_warehouses()

/**
 * K-1 (внешний QA-аудит, раунд 2, 03.09.2026): сколько по каждому товару уже вернули РАНЬШЕ по этому
 * счёту — сумма количества по всем ПРОВЕДЁННЫМ (не черновик) кредит-нотам, связанным через
 * fk_facture_source. Возвращает [fk_product => qty]. Черновики не считаются — недоведённая до конца
 * попытка возврата (например, оборвалась на добавлении строки) не должна блокировать повторную попытку.
 */
function already_returned_qty_by_product(DolibarrApi $api, int $sourceInvoiceId): array
{
    $result = [];
    foreach ($api->getCreditNotesForSourceInvoice($sourceInvoiceId) as $cn) {
        if ((int)($cn['statut'] ?? 0) === 0) continue; // черновик — не считаем
        foreach (($cn['lines'] ?? []) as $line) {
            $fkProduct = (int)($line['fk_product'] ?? 0);
            if (!$fkProduct) continue;
            $result[$fkProduct] = ($result[$fkProduct] ?? 0) + abs((float)($line['qty'] ?? 0));
        }
    }
    return $result;
}

if (empty($_SESSION['return_cart'])) $_SESSION['return_cart'] = [];
if (empty($_SESSION['return_client'])) $_SESSION['return_client'] = null;
if (!array_key_exists('return_source_invoice_id', $_SESSION)) $_SESSION['return_source_invoice_id'] = null;

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — сбрасывает
// выбранного клиента, чтобы не "застревать" на нём. Корзину не трогаем — если уже начали набирать
// возврат и просто отвлеклись на другой раздел, позиции не должны потеряться.
reset_selection_unless_preserved('return_client');
// Выбранный счёт для "Возврата по счёту" — сбрасывается вместе с клиентом на обычном заходе (никакой
// редирект на эту переменную не завязан, поэтому простого правила "не POST -> сбросить" достаточно,
// в отличие от return_client, для которого нужен _preserve_once после client_form.php).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['return_source_invoice_id'] = null;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $ref = $_POST['ref'] ?? '';
        $label = $_POST['label'] ?? '';
        $price = (float)($_POST['price'] ?? 0);
        if ($productId) {
            $_SESSION['return_cart'][] = [
                'product_id' => $productId, 'ref' => $ref, 'label' => $label, 'price' => $price,
                'qty' => 1, 'warehouse_id' => $cfg['default_warehouse_id'],
            ];
        }
    } elseif ($action === 'update_cart_item') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($_SESSION['return_cart'][$idx])) {
            $qty = (float)($_POST['qty'] ?? 0);
            $wh = (int)($_POST['warehouse_id'] ?? $cfg['default_warehouse_id']);
            if ($qty > 0) {
                $_SESSION['return_cart'][$idx]['qty'] = $qty;
                $_SESSION['return_cart'][$idx]['warehouse_id'] = $wh;
            }
        }
    } elseif ($action === 'remove_from_cart') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($_SESSION['return_cart'][$idx])) unset($_SESSION['return_cart'][$idx]);
    } elseif ($action === 'select_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!select_client_for_direction($api, $cfg, 'return_client', $clientId, $_POST['client_name'] ?? '')) {
            $message = 'Клиент не найден или относится к другому направлению.';
            $messageType = 'err';
        }
        $_SESSION['return_source_invoice_id'] = null; // новый клиент — сбросить ранее выбранный счёт
    } elseif ($action === 'select_source_invoice') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $srcCheck = $invId ? $api->getInvoice($invId) : null;
        if (!is_array($srcCheck) || (int)($srcCheck['socid'] ?? 0) !== (int)($_SESSION['return_client']['id'] ?? 0) || (int)($srcCheck['type'] ?? -1) !== 0) {
            $message = 'Счёт не найден или относится к другому клиенту.';
            $messageType = 'err';
        } else {
            $_SESSION['return_source_invoice_id'] = $invId;
        }
    } elseif ($action === 'clear_source_invoice') {
        $_SESSION['return_source_invoice_id'] = null;
    } elseif ($action === 'checkout_by_invoice') {
        $sourceInvoiceId = (int)$_SESSION['return_source_invoice_id'];
        if (empty($_SESSION['return_client']['id'])) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif (!$sourceInvoiceId) {
            $message = 'Счёт не выбран.';
            $messageType = 'err';
        } else {
            // Перепроверяем счёт ЗАНОВО на сервере — не доверяем ценам/суммам из формы, только тому,
            // какие строки кассир отметил и сколько поправил (checkbox/qty/склад).
            $srcInvoice = $api->getInvoice($sourceInvoiceId);
            if (!is_array($srcInvoice) || (int)($srcInvoice['socid'] ?? 0) !== (int)$_SESSION['return_client']['id'] || (int)($srcInvoice['type'] ?? -1) !== 0) {
                $message = 'Счёт не найден или относится к другому клиенту — обновите страницу.';
                $messageType = 'err';
            } else {
                // K-1: пересчитываем ЗАНОВО, сколько уже возвращено по каждому товару этого счёта —
                // не доверяем состоянию, отрисованному раньше на экране (могло устареть, если кто-то
                // уже оформил другой возврат по этому же счёту параллельно).
                $alreadyReturnedNow = already_returned_qty_by_product($api, $sourceInvoiceId);
                $checkedLines = array_map('intval', (array)($_POST['return_line'] ?? []));
                $qtys = $_POST['qty'] ?? [];
                $whs = $_POST['warehouse_id'] ?? [];
                $itemsToReturn = [];
                $capExceeded = [];
                foreach (($srcInvoice['lines'] ?? []) as $line) {
                    $lineId = (int)($line['id'] ?? 0);
                    $fkProduct = (int)($line['fk_product'] ?? 0);
                    if (!$fkProduct || !in_array($lineId, $checkedLines, true)) continue;
                    $origQty = abs((float)($line['qty'] ?? 0));
                    $alreadyReturned = $alreadyReturnedNow[$fkProduct] ?? 0;
                    $availableToReturn = max(0, $origQty - $alreadyReturned);
                    $qty = (float)($qtys[$lineId] ?? 0);
                    if ($qty <= 0) continue;
                    if ($qty > $availableToReturn + 0.0001) {
                        // K-1: нельзя вернуть больше, чем реально осталось непогашенным (продано минус
                        // уже возвращённое раньше по этому счёту) — раньше проверялось только "не
                        // больше проданного", без учёта прошлых возвратов.
                        $capExceeded[] = ($line['product_label'] ?? $line['desc'] ?? "товар #$fkProduct") . " (доступно к возврату: " . rtrim(rtrim(number_format($availableToReturn, 3, '.', ''), '0'), '.') . ", запрошено: {$qty})";
                        continue;
                    }
                    $wh = (int)($whs[$lineId] ?? $cfg['default_warehouse_id']);
                    if (!in_array($wh, $cfg['warehouse_ids'], false)) $wh = $cfg['default_warehouse_id'];
                    $itemsToReturn[] = [
                        'product_id' => $fkProduct,
                        'label' => $line['product_label'] ?? $line['desc'] ?? '',
                        // ИСТОРИЧЕСКАЯ цена именно из этого счёта — не текущая цена товара.
                        'price' => (float)($line['subprice'] ?? 0),
                        'qty' => $qty,
                        'warehouse_id' => $wh,
                    ];
                }

                if (!empty($capExceeded)) {
                    // K-1: блокируем ВЕСЬ возврат целиком, если хоть одна позиция превышает доступное
                    // к возврату количество — не проводим документ частично молча, кассир должен
                    // осознанно поправить количество и повторить попытку.
                    $message = "Превышено доступное к возврату количество:\n" . implode("\n", $capExceeded);
                    $messageType = 'err';
                } elseif (empty($itemsToReturn)) {
                    $message = 'Отметьте хотя бы одну позицию для возврата.';
                    $messageType = 'err';
                } else {
                    $creditNoteId = $api->createCreditNote((int)$_SESSION['return_client']['id'], $sourceInvoiceId);
                    if (!$creditNoteId) {
                        $message = 'Ошибка создания возврата: ' . $api->lastError;
                        $messageType = 'err';
                    } else {
                        $linesOk = true;
                        foreach ($itemsToReturn as $item) {
                            $r = $api->addInvoiceLine($creditNoteId, $item['product_id'], $item['label'], $item['qty'], $item['price'], $cfg['vat_rate']);
                            if ($r === null) { $linesOk = false; break; }
                        }
                        if (!$linesOk) {
                            $message = 'Ошибка добавления позиции в возврат: ' . $api->lastError;
                            $messageType = 'err';
                        } else {
                            $val = $api->validateInvoice($creditNoteId);
                            if ($val === null) {
                                $message = 'Ошибка проведения возврата: ' . $api->lastError;
                                $messageType = 'err';
                            } else {
                                $stockWarnings = [];
                                foreach ($itemsToReturn as $item) {
                                    $sres = $api->createStockMovement([
                                        'product_id' => $item['product_id'],
                                        'warehouse_id' => $item['warehouse_id'],
                                        'qty' => abs($item['qty']),
                                        'type' => 3,
                                        'label' => 'Возврат по счёту ' . ($srcInvoice['ref'] ?? '') . ', документ #' . $creditNoteId,
                                    ]);
                                    if ($sres === null) {
                                        $whLabel = $cfg['warehouse_labels'][$item['warehouse_id']] ?? $item['warehouse_id'];
                                        $stockWarnings[] = "\"{$item['label']}\" не зачислен на склад ({$whLabel}): {$api->lastError}";
                                    }
                                }
                                $warnText = $stockWarnings ? ("\nВНИМАНИЕ: " . implode('; ', $stockWarnings)) : '';
                                $message = "Готово! Возврат #$creditNoteId по счёту {$srcInvoice['ref']} оформлен (" . count($itemsToReturn) . " позиц.), долг клиента уменьшен, товар зачислен на склад.$warnText";
                                $messageType = $stockWarnings ? 'err' : 'ok';
                                $_SESSION['return_client'] = null;
                                $_SESSION['return_source_invoice_id'] = null;
                                flash_set($message, $messageType);
                                header('Location: return.php');
                                exit;
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'clear_client') {
        $_SESSION['return_client'] = null;
        $_SESSION['return_source_invoice_id'] = null;
    } elseif ($action === 'checkout') {
        // K-2 (внешняя приёмка, 03.09.2026): свободный возврат (без привязки к счёту) — единственное
        // место, где долг клиента можно уменьшить на любую сумму без всякого основания. Режим оставлен
        // по прямому решению пользователя (бывают возвраты без документа), но теперь требует ПРИЧИНУ:
        // она попадает и в сам документ возврата в Dolibarr, и в сменный отчёт — то есть остаётся след.
        $freeReturnReason = trim($_POST['free_return_reason'] ?? '');
        if (empty($_SESSION['return_client']['id'])) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif (empty($_SESSION['return_cart'])) {
            $message = 'Список возврата пуст.';
            $messageType = 'err';
        } elseif (mb_strlen($freeReturnReason) < 5) {
            $message = 'Укажите причину возврата без счёта — коротко, но по делу (например: «брак, чек потерян» или «привезли не тот товар, продажа была в июле»). Она сохранится в документе возврата.';
            $messageType = 'err';
        } else {
            $creditNoteId = $api->createCreditNote((int)$_SESSION['return_client']['id']);
            if (!$creditNoteId) {
                $message = 'Ошибка создания возврата: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $linesOk = true;
                foreach ($_SESSION['return_cart'] as $item) {
                    $r = $api->addInvoiceLine($creditNoteId, $item['product_id'], $item['label'], $item['qty'], $item['price'], $cfg['vat_rate']);
                    if ($r === null) { $linesOk = false; break; }
                }
                // K-2: причина сохраняется в самом документе возврата — видна и в Dolibarr, и в отчётах,
                // не только в нашем интерфейсе. Если поле заметки записать не удалось — возврат всё равно
                // оформляем (документ уже создан, причина продублирована в сменном отчёте), но это
                // отдельная строка предупреждения, а не тихая потеря.
                $noteText = 'Возврат без привязки к счёту. Причина: ' . $freeReturnReason
                    . ' (' . ($cfg['direction_label'] ?? '') . ', ' . date('d.m.Y H:i') . ')';
                $noteOk = $api->put("invoices/{$creditNoteId}", ['note_public' => $noteText]) !== null;
                if (!$linesOk) {
                    $message = 'Ошибка добавления позиции в возврат: ' . $api->lastError;
                    $messageType = 'err';
                } else {
                    $val = $api->validateInvoice($creditNoteId);
                    if ($val === null) {
                        $message = 'Ошибка проведения возврата: ' . $api->lastError;
                        $messageType = 'err';
                    } else {
                        $stockWarnings = [];
                        foreach ($_SESSION['return_cart'] as $item) {
                            $sres = $api->createStockMovement([
                                'product_id' => $item['product_id'],
                                'warehouse_id' => $item['warehouse_id'] ?? $cfg['default_warehouse_id'],
                                'qty' => abs($item['qty']),
                                'type' => 3,
                                'label' => 'Возврат от клиента, документ #' . $creditNoteId,
                            ]);
                            if ($sres === null) {
                                $whLabel = $cfg['warehouse_labels'][$item['warehouse_id']] ?? $item['warehouse_id'];
                                $stockWarnings[] = "\"{$item['label']}\" не зачислен на склад ({$whLabel}): {$api->lastError}";
                            }
                        }
                        $warnText = $stockWarnings ? ("\nВНИМАНИЕ: " . implode('; ', $stockWarnings)) : '';
                        if (!$noteOk) $warnText .= "\nПричину не удалось записать в документ — она сохранена только в сменном отчёте.";
                        $message = "Готово! Возврат #$creditNoteId (без счёта, причина: «{$freeReturnReason}») оформлен, долг клиента уменьшен, товар зачислен на склад.$warnText";
                        $messageType = ($stockWarnings || !$noteOk) ? 'err' : 'ok';
                        $_SESSION['return_cart'] = [];
                        $_SESSION['return_client'] = null;
                        // Документ уже реальный — редирект (POST → GET), чтобы обновление страницы
                        // (F5) не повторило отправку формы и не создало второй возврат.
                        flash_set($message, $messageType);
                        header('Location: return.php');
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
}

$cartTotal = 0;
foreach ($_SESSION['return_cart'] as $item) {
    $cartTotal += $item['price'] * $item['qty'];
}

// --- Данные для режима "Возврат по счёту" ---
$clientInvoices = [];
$selectedInvoice = null;
$selectedInvoiceLineWarehouses = [];
$alreadyReturnedByProduct = [];
if ($_SESSION['return_client']) {
    $socId = (int)$_SESSION['return_client']['id'];
    if ($_SESSION['return_source_invoice_id']) {
        $full = $api->getInvoice((int)$_SESSION['return_source_invoice_id']);
        if (is_array($full) && (int)($full['socid'] ?? 0) === $socId && (int)($full['type'] ?? -1) === 0) {
            $selectedInvoice = $full;
            $selectedInvoiceLineWarehouses = get_invoice_line_warehouses((int)$full['id']);
            $alreadyReturnedByProduct = already_returned_qty_by_product($api, (int)$full['id']);
        } else {
            $_SESSION['return_source_invoice_id'] = null;
        }
    }
    if (!$selectedInvoice) {
        // Только настоящие счета-продажи (type=0), не возвраты/авансы — их же и возвращаем.
        $summaries = $api->getInvoicesForClient($socId, 40);
        if (is_array($summaries)) {
            foreach ($summaries as $s) {
                if ((int)($s['type'] ?? -1) === 0) {
                    $clientInvoices[] = [
                        'id' => (int)$s['id'],
                        'ref' => $s['ref'] ?? '',
                        'date' => !empty($s['date']) ? date('d.m.Y', (int)$s['date']) : '',
                        'total' => (float)($s['total_ttc'] ?? 0),
                    ];
                }
            }
            usort($clientInvoices, fn($a, $b) => $b['id'] <=> $a['id']);
        }
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Возврат товара от клиента</h1>
<?php if ($_SESSION['return_client']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_client">
    <button type="submit" class="secondary">← Сменить клиента</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <h2>Клиент</h2>
  <?php if ($_SESSION['return_client']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['return_client']['name']) ?></strong>
        <div><a href="client_form.php?ctx=return&id=<?= (int)$_SESSION['return_client']['id'] ?>" class="muted">✏️ Редактировать</a></div>
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
    <p style="margin-top:8px"><a href="client_form.php?ctx=return" class="btn secondary small">+ Новый клиент</a></p>
  <?php endif; ?>
</div>

<?php if ($_SESSION['return_client']): ?>
<div class="card">
  <h2>Возврат по счёту <span class="muted" style="font-weight:400">(рекомендуется — точные цены и количества берутся из самого счёта)</span></h2>
  <?php if ($selectedInvoice): ?>
    <p><a href="javascript:void(0)" onclick="document.getElementById('clearInvoiceForm').submit()" class="muted">← Выбрать другой счёт</a></p>
    <form method="post" id="clearInvoiceForm" style="display:none"><?= csrf_field() ?><input type="hidden" name="action" value="clear_source_invoice"></form>
    <form method="post" onsubmit="return appConfirmSubmit(this, 'Оформить возврат по счёту <?= htmlspecialchars($selectedInvoice['ref'] ?? '', ENT_QUOTES) ?>?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="checkout_by_invoice">
      <p class="muted">Счёт <strong><?= htmlspecialchars($selectedInvoice['ref'] ?? '') ?></strong> от <?= !empty($selectedInvoice['date']) ? date('d.m.Y', (int)$selectedInvoice['date']) : '' ?>, на сумму <?= number_format((float)($selectedInvoice['total_ttc'] ?? 0), 2) ?> $. Отметьте, что возвращают:</p>
      <table>
        <tr><th></th><th>Товар</th><th>Продано</th><th>Уже возвращено</th><th>Доступно к возврату</th><th>Цена в счёте</th><th>Возврат — кол-во</th><th>Склад зачисления</th></tr>
        <?php foreach (($selectedInvoice['lines'] ?? []) as $line):
          $lineId = (int)($line['id'] ?? 0);
          $fkProduct = (int)($line['fk_product'] ?? 0);
          if (!$fkProduct) continue;
          $origQty = abs((float)($line['qty'] ?? 0));
          // K-1: сколько уже вернули по этому товару раньше (по всем прошлым возвратам этого счёта) —
          // доступно к возврату теперь показывается честно, а не всегда "продано".
          $alreadyReturned = $alreadyReturnedByProduct[$fkProduct] ?? 0;
          $availableToReturn = max(0, $origQty - $alreadyReturned);
          $price = (float)($line['subprice'] ?? 0);
          $defaultWh = $selectedInvoiceLineWarehouses[$fkProduct][0] ?? $cfg['default_warehouse_id'];
          $fmt = fn($n) => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        ?>
          <tr<?= $availableToReturn <= 0.0001 ? ' class="muted"' : '' ?>>
            <td><input type="checkbox" name="return_line[]" value="<?= $lineId ?>" <?= $availableToReturn <= 0.0001 ? 'disabled' : '' ?>></td>
            <td><?= htmlspecialchars($line['product_label'] ?? $line['desc'] ?? '') ?><div class="muted"><?= htmlspecialchars($line['product_ref'] ?? '') ?></div></td>
            <td><?= $fmt($origQty) ?></td>
            <td><?= $alreadyReturned > 0 ? $fmt($alreadyReturned) : '—' ?></td>
            <td><?= $availableToReturn <= 0.0001 ? '<strong>нечего возвращать</strong>' : '<strong>' . $fmt($availableToReturn) . '</strong>' ?></td>
            <td><?= number_format($price, 2) ?> $</td>
            <td><input type="number" name="qty[<?= $lineId ?>]" value="<?= $fmt($availableToReturn) ?>" step="any" min="0.001" max="<?= $availableToReturn ?>" style="width:90px; margin:0" <?= $availableToReturn <= 0.0001 ? 'disabled' : '' ?>></td>
            <td>
              <select name="warehouse_id[<?= $lineId ?>]" style="min-width:160px; margin:0" <?= $availableToReturn <= 0.0001 ? 'disabled' : '' ?>>
                <?php foreach ($cfg['warehouse_labels'] as $whId => $whLabel): ?>
                  <option value="<?= $whId ?>" <?= $whId == $defaultWh ? 'selected' : '' ?>><?= htmlspecialchars($whLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="muted">Не отмеченные галочкой позиции в возврат не попадут. Количество нельзя указать больше, чем реально доступно к возврату (продано минус уже возвращённое раньше по этому счёту) — по одному счёту можно оформлять возврат несколько раз (например, частями), просто сумма всех возвратов не превысит проданное.</p>
      <button type="submit">Оформить возврат по счёту</button>
    </form>
  <?php else: ?>
    <?php if (empty($clientInvoices)): ?>
      <p class="muted">У этого клиента нет счетов-продаж — используйте «Возврат без привязки к счёту» ниже.</p>
    <?php else: ?>
      <input type="text" id="invoiceFilter" placeholder="Фильтр по номеру счёта..." oninput="filterInvoices(this.value)">
      <table id="invoiceListTable">
        <tr><th>Счёт</th><th>Дата</th><th>Сумма</th><th></th></tr>
        <?php foreach ($clientInvoices as $inv): ?>
          <tr data-ref="<?= htmlspecialchars(mb_strtolower($inv['ref'])) ?>">
            <td><?= htmlspecialchars($inv['ref']) ?></td>
            <td class="muted"><?= htmlspecialchars($inv['date']) ?></td>
            <td><?= number_format($inv['total'], 2) ?> $</td>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="select_source_invoice">
                <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                <button type="submit" class="small">Выбрать</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <script>
      function filterInvoices(term) {
        term = term.trim().toLowerCase();
        document.querySelectorAll('#invoiceListTable tr[data-ref]').forEach(function (row) {
          row.style.display = row.dataset.ref.includes(term) ? '' : 'none';
        });
      }
      </script>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<h2 style="margin-top:24px">Возврат без привязки к счёту</h2>
<p class="muted">Свободный ввод — цена берётся ТЕКУЩАЯ из карточки товара, не из старого чека. Используйте, только если счёта нет или он не важен.</p>

<div class="grid-2col">
<div>

<div class="card">
  <h2>Какой товар вернули</h2>
  <div id="categoryTiles" class="cat-tiles"></div>
  <div id="categoryBackRow" style="display:none">
    <button type="button" id="btnBackToCats" class="secondary">← Все категории</button>
    <strong id="currentCatLabel"></strong>
  </div>
  <input type="text" id="productSearch" placeholder="Поиск по названию или артикулу...">
  <div id="productResults" class="result-list"></div>
</div>

</div>
<div>

<div class="card">
  <h2>Возвращаемые позиции</h2>
  <?php if (empty($_SESSION['return_cart'])): ?>
    <p class="muted">Пусто</p>
  <?php else: ?>
    <div class="cart-table">
      <?php foreach ($_SESSION['return_cart'] as $idx => $item): ?>
        <div class="cart-row" data-idx="<?= $idx ?>" data-price="<?= htmlspecialchars($item['price']) ?>">
          <div class="cart-row-main">
            <div class="cart-row-name">
              <?= htmlspecialchars($item['label']) ?>
              <span class="muted">· <?= htmlspecialchars($item['ref']) ?> · <?= number_format($item['price'], 2) ?> $/шт</span>
            </div>
            <button type="button" class="cart-remove" data-idx="<?= $idx ?>" title="Убрать">✕</button>
          </div>
          <div class="cart-row-controls">
            <input type="number" class="cart-qty" step="any" min="0.001" value="<?= htmlspecialchars($item['qty']) ?>">
            <select class="cart-warehouse">
              <?php foreach ($cfg['warehouse_labels'] as $whId => $whLabel): ?>
                <option value="<?= $whId ?>" <?= $whId == ($item['warehouse_id'] ?? $cfg['default_warehouse_id']) ? 'selected' : '' ?>><?= htmlspecialchars($whLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="cart-subtotal"><?= number_format($item['qty'] * $item['price'], 2) ?> $</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="total">Итого к возврату: <span id="cartGrandTotal"><?= number_format($cartTotal, 2) ?></span> $</div>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['return_cart'])): ?>
<div class="card">
  <?php // K-2 (внешняя приёмка, 03.09.2026): возврат без счёта уменьшает долг клиента без всякого
        // документа-основания. Режим оставлен, но теперь требует причину — она пишется в сам документ
        // возврата (видно в Dolibarr) и попадает в сменный отчёт отдельной строкой. ?>
  <form method="post" onsubmit="return appConfirmSubmit(this, 'Оформить возврат без счёта? Долг клиента уменьшится, товар вернётся на склад.');">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="checkout">
    <label>Причина возврата без счёта (обязательно)</label>
    <input type="text" name="free_return_reason" required minlength="5"
           placeholder="например: брак, чек потерян · привезли не тот товар, продажа была в июле">
    <p class="muted" style="margin:-4px 0 10px">Сохранится в документе возврата и в сменном отчёте —
    чтобы потом было понятно, почему долг уменьшился без продажи.</p>
    <button type="submit">Оформить возврат</button>
  </form>
</div>
<?php endif; ?>

</div>
</div>

<script>
window.CATEGORIES = <?= json_encode($cfg['categories'], JSON_UNESCAPED_UNICODE) ?>;

window.onProductPick = function (p) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="add_to_cart">' +
    '<input type="hidden" name="product_id" value="' + p.id + '">' +
    '<input type="hidden" name="ref" value="' + p.ref.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="label" value="' + p.label.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="price" value="' + p.price + '">';
  document.body.appendChild(form);
  form.submit();
};

window.onClientPick = function (c) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_client">' +
    '<input type="hidden" name="client_id" value="' + c.id + '">' +
    '<input type="hidden" name="client_name" value="' + c.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
};

function recalcRow(row) {
  const price = parseFloat(row.dataset.price) || 0;
  const qty = parseFloat(row.querySelector('.cart-qty').value) || 0;
  row.querySelector('.cart-subtotal').textContent = (price * qty).toFixed(2) + ' $';
}
function recalcGrandTotal() {
  let total = 0;
  document.querySelectorAll('.cart-row').forEach(row => {
    total += (parseFloat(row.dataset.price) || 0) * (parseFloat(row.querySelector('.cart-qty').value) || 0);
  });
  const el = document.getElementById('cartGrandTotal');
  if (el) el.textContent = total.toFixed(2);
}
function persistCartItem(row) {
  fetch('return.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ _csrf: '<?= csrf_token() ?>', action: 'update_cart_item', idx: row.dataset.idx, qty: row.querySelector('.cart-qty').value, warehouse_id: row.querySelector('.cart-warehouse').value }),
  }).catch(() => {});
}
document.querySelectorAll('.cart-row').forEach(row => {
  row.querySelector('.cart-qty').addEventListener('input', () => { recalcRow(row); recalcGrandTotal(); });
  row.querySelector('.cart-qty').addEventListener('change', () => { if (parseFloat(row.querySelector('.cart-qty').value) > 0) persistCartItem(row); });
  row.querySelector('.cart-warehouse').addEventListener('change', () => persistCartItem(row));
  row.querySelector('.cart-remove').addEventListener('click', () => {
    fetch('return.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf: '<?= csrf_token() ?>', action: 'remove_from_cart', idx: row.dataset.idx }),
    }).then(() => {
      row.remove();
      recalcGrandTotal();
      if (document.querySelectorAll('.cart-row').length === 0) location.reload();
    });
  });
});
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
