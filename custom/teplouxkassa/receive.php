<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dolibarr_direct.php';
require_once __DIR__ . '/includes/defect_intake.php';

if (!array_key_exists('receive_order_id', $_SESSION)) $_SESSION['receive_order_id'] = null;

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — сбрасывает
// выбранный заказ, чтобы снова видеть список заказов, а не "застревать" на одном.
reset_selection_unless_preserved('receive_order_id');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_order') {
        $_SESSION['receive_order_id'] = (int)($_POST['order_id'] ?? 0);
    } elseif ($action === 'clear_order') {
        $_SESSION['receive_order_id'] = null;
    } elseif ($action === 'receive') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $lineIds = $_POST['line_id'] ?? [];
        $productIds = $_POST['fk_product'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $warehouses = $_POST['warehouse'] ?? [];

        // Настоящий остаток по каждой строке пересчитываем заново на сервере (не доверяем тому, что
        // было в форме) — заказанное количество берём из свежих данных заказа, уже принятое — прямым
        // запросом к БД (см. includes/dolibarr_direct.php). Позиция, где ввели больше остатка, целиком
        // ЗАБРАКОВЫВАЕТСЯ — приёмка остальных не блокируется, просто эта конкретная строка не проходит.
        $freshOrder = $orderId ? $api->getSupplierOrder($orderId) : null;
        $orderedByLine = [];
        $priceByLine = [];
        $productByLine = [];
        $labelByLine = [];
        $docPriceByLine = [];
        if (is_array($freshOrder)) {
            foreach (($freshOrder['lines'] ?? []) as $l) {
                $lid = (int)$l['id'];
                $orderedByLine[$lid] = (float)($l['qty'] ?? 0);
                // Реальная закупочная цена строки — без неё Dolibarr не обновит себестоимость (pmp)
                // при приёмке, см. CLAUDE.md 29.08.2026 "себестоимость товара".
                $priceByLine[$lid] = (float)($l['subprice'] ?? 0);
                // Товар строки — берём ИЗ РЕАЛЬНЫХ ДАННЫХ ЗАКАЗА, а не из скрытого поля формы: иначе
                // ничто на сервере не мешает подменить fk_product в POST и записать приход не на тот
                // товар, что реально был в заказе.
                $productByLine[$lid] = (int)($l['fk_product'] ?? 0);
                $labelByLine[$lid] = (string)($l['product_label'] ?? $l['label'] ?? $l['libelle'] ?? '');
                // цена в валюте заказа — сумма рекламации должна быть в той валюте, в которой платили
                $docPriceByLine[$lid] = (float)($l['multicurrency_subprice'] ?? 0);
            }
        }

        // Заказ должен относиться к нашему направлению — иначе кассир мог бы (зная/подобрав order_id)
        // принять товар по чужому заказу. Направление определяем так же, как и при показе списка —
        // по товару хотя бы одной строки заказа, принадлежащему нашему kod_sap-префиксу.
        $orderBelongsToDirection = false;
        if (is_array($freshOrder)) {
            foreach (($freshOrder['lines'] ?? []) as $l) {
                $fkP = (int)($l['fk_product'] ?? 0);
                if (!$fkP) continue;
                $prod = $api->getProduct($fkP, false);
                $kodSap = is_array($prod) ? ($prod['array_options']['options_kod_sap'] ?? '') : '';
                if (stripos($kodSap, $cfg['ref_prefix']) === 0) { $orderBelongsToDirection = true; break; }
            }
        }

        $lines = [];
        $rejected = [];
        $defects = [];
        if (is_array($freshOrder) && !$orderBelongsToDirection) {
            $rejected[] = 'Этот заказ не относится к вашему направлению.';
        } else {
            foreach ($lineIds as $i => $lineId) {
                $qty = (float)($qtys[$i] ?? 0);
                if ($qty <= 0) {
                    continue; // эту позицию сегодня не привезли — пропускаем строку целиком
                }
                $lineIdInt = (int)$lineId;
                $ordered = $orderedByLine[$lineIdInt] ?? null;
                $fkProduct = $productByLine[$lineIdInt] ?? null;
                if ($ordered === null || !$fkProduct) {
                    $rejected[] = "строка #$lineIdInt: не удалось проверить заказанное количество/товар — возможно, строка не из этого заказа";
                    continue;
                }
                $remaining = $ordered - get_already_received_qty($lineIdInt);
                if ($qty > $remaining + 0.0001) {
                    $rejected[] = "{$fkProduct}: ввели {$qty}, а остаток по заказу — " . number_format(max(0, $remaining), 2) .
                        ". Расхождение с заказом — сообщите " . $cfg['purchaser_label'] . ", чтобы поправили заказ, потом принимайте заново.";
                    continue;
                }
                // Цена-заглушка 0,01: приёмка по ней затёрла бы себестоимость товара (11.09.2026).
                // Защита стоит и в NodirTool при утверждении — здесь страховка для заказов, утверждённых
                // раньше неё. Строку не принимаем, остальные позиции идут как обычно.
                if (($priceByLine[$lineIdInt] ?? 0) <= 0.011) {
                    $rejected[] = ($labelByLine[$lineIdInt] ?: $fkProduct) . ': в заказе нет цены (0,01 — заглушка). Попросите ' .
                        $cfg['purchaser_label'] . ' вписать цену из спецификации — иначе себестоимость товара обнулится. Позиция не принята.';
                    continue;
                }
                $warehouseId = (int)($warehouses[$i] ?? $cfg['default_warehouse_id']);
                if (!in_array($warehouseId, $cfg['warehouse_ids'], false)) {
                    $rejected[] = "{$fkProduct}: указан склад, не относящийся к направлению — строка отклонена.";
                    continue;
                }
                // Брак: часть прибывшего количества. Годное — на выбранный склад, брак — на склад
                // брака направления, одна строка заказа уходит в Dolibarr двумя строками приёмки.
                [$defect, $reason, $note, $defErr] = defect_parse_line(
                    $qty, $_POST['defect_qty'][$i] ?? 0, $_POST['defect_reason'][$i] ?? '', $_POST['defect_note'][$i] ?? '');
                if ($defErr !== null) {
                    $rejected[] = ($labelByLine[$lineIdInt] ?: $fkProduct) . ": {$defErr} — строка не принята, исправьте и примите заново.";
                    continue;
                }
                if ($defect > 0 && empty($cfg['defect_warehouse_id'])) {
                    $rejected[] = ($labelByLine[$lineIdInt] ?: $fkProduct) . ': склад брака не настроен — сообщите администратору.';
                    continue;
                }
                $good = round($qty - $defect, 3);
                if ($good > 0) {
                    $lines[] = [
                        'line_id' => $lineIdInt,
                        'fk_product' => $fkProduct,
                        'qty' => $good,
                        'warehouse' => $warehouseId,
                        'price' => $priceByLine[$lineIdInt] ?? 0,
                    ];
                }
                if ($defect > 0) {
                    $lines[] = [
                        'line_id' => $lineIdInt,
                        'fk_product' => $fkProduct,
                        'qty' => $defect,
                        'warehouse' => (int)$cfg['defect_warehouse_id'],
                        // та же цена, что у годного: за брак заплачено, себестоимость одинаковая
                        'price' => $priceByLine[$lineIdInt] ?? 0,
                        'comment' => 'Брак при приёмке: ' . DEFECT_REASONS[$reason],
                    ];
                    $defects[] = [
                        'i' => $i, 'line_id' => $lineIdInt, 'fk_product' => $fkProduct,
                        'label' => $labelByLine[$lineIdInt] ?? '', 'qty' => $qty, 'defect' => $defect,
                        'reason' => $reason, 'note' => $note,
                        'price_doc' => $docPriceByLine[$lineIdInt] ?? 0,
                        'price_base' => $priceByLine[$lineIdInt] ?? 0,
                    ];
                }
            }
        }

        $rejectionNote = $rejected ? "Не приняты позиции с расхождением:\n" . implode("\n", $rejected) : '';

        if (!$orderId || empty($lines)) {
            $message = ($rejectionNote ? $rejectionNote . "\n" : '') . 'Укажите количество больше нуля хотя бы по одной корректной позиции.';
            $messageType = 'err';
        } else {
            // closeOrder всегда true: Dolibarr сам решает статус по факту накопленного количества —
            // если реально получено ещё не всё, он и так корректно оставит заказ "частично получен",
            // наш флаг влияет только на пограничный случай "получено ровно столько, сколько заказано"
            // Цены нового прихода (11.09.2026): средняя себестоимость ДО приёмки — для руководства
            require_once __DIR__ . '/includes/pricing.php';
            $pmpBefore = pricing_pmp_snapshot(array_column($lines, 'fk_product'));
            $result = $api->receiveSupplierOrder($orderId, $lines, true, 'Приёмка через кассу ' . $cfg['direction_label']);
            if ($result === null) {
                $message = ($rejectionNote ? $rejectionNote . "\n" : '') . 'Ошибка приёмки: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $message = ($rejectionNote ? $rejectionNote . "\n" : '') .
                    'Товар принят на склад (' . count($lines) . ' позиц.). Если в заказе оставались непривезённые позиции — он останется в списке ожидающих.';

                // Брак: рекламация поставщику на каждую строку с браком + фото к заказу.
                // Товар уже на складе, поэтому сбой здесь приёмку не отменяет — честно говорим,
                // что именно не получилось, чтобы закупщик завёл рекламацию руками.
                if ($defects) {
                    $who = ($_SESSION['direction'] ?? 'касса') === 'zhomi' ? 'Касса Жоми' : 'Касса Турк';
                    $orderRef = (string)($freshOrder['ref'] ?? '');
                    $orderCur = strtoupper((string)($freshOrder['multicurrency_code'] ?? '')) ?: 'USD';
                    $supplierId = (int)($freshOrder['socid'] ?? $freshOrder['fk_soc'] ?? 0);
                    $whName = $cfg['defect_warehouse_label'] ?? 'склад брака';
                    $defectNotes = [];
                    foreach ($defects as $d) {
                        $usesDoc = $d['price_doc'] > 0 && $orderCur !== 'USD';
                        $unit = $usesDoc ? $d['price_doc'] : $d['price_base'];
                        $cur  = $usesDoc ? $orderCur : 'USD';
                        $desc = 'Брак при приёмке заказа ' . $orderRef . ': ' . DEFECT_REASONS[$d['reason']] .
                                ($d['note'] !== '' ? ' — ' . $d['note'] : '') .
                                '. Прибыло ' . rtrim(rtrim(number_format($d['qty'], 3, '.', ''), '0'), '.') .
                                ', из них брак ' . rtrim(rtrim(number_format($d['defect'], 3, '.', ''), '0'), '.') .
                                '; брак лежит на складе «' . $whName . '». Принял: ' . $who . '.';
                        $cl = defect_create_claim([
                            'fk_party' => $supplierId, 'fk_order' => $orderId, 'fk_product' => $d['fk_product'],
                            'product_label' => $d['label'], 'qty' => $d['defect'],
                            'amount' => $d['defect'] * $unit, 'currency' => $cur, 'description' => $desc,
                        ], $who);
                        if (!$cl['ok']) {
                            $defectNotes[] = '⚠ ' . ($d['label'] ?: $d['fk_product']) . ': рекламация НЕ создана (' .
                                ($cl['error'] ?? 'ошибка') . ') — сообщите ' . $cfg['purchaser_label'] . '.';
                            continue;
                        }
                        [$photos, $pWarn] = defect_collect_photos((int)$d['i']);
                        $saved = 0;
                        foreach ($photos as $n => [$bytes, $ext]) {
                            $fname = 'brak_' . $cl['id'] . '_' . ($n + 1) . '.' . $ext;
                            if ($orderRef !== '' && $api->uploadOrderDocument($orderRef, $fname, base64_encode($bytes)) !== null) $saved++;
                            else $pWarn[] = "фото {$fname} не сохранилось: " . $api->lastError;
                        }
                        $defectNotes[] = 'Брак «' . ($d['label'] ?: $d['fk_product']) . '»: ' .
                            rtrim(rtrim(number_format($d['defect'], 3, '.', ''), '0'), '.') . ' шт на склад «' . $whName .
                            '», рекламация №' . $cl['id'] . ' передана ' . $cfg['purchaser_label'] .
                            ($saved ? ", фото: {$saved}" : ', без фото') .
                            ($pWarn ? ' (' . implode('; ', $pWarn) . ')' : '') . '.';
                    }
                    $message .= "\n" . implode("\n", $defectNotes);
                }
                // H4 (финансовый аудит 05.09.2026): пересчитываем себестоимость ПОСЛЕ приёмки.
                // Штатный механизм Dolibarr только что усреднил pmp по СЫРОЙ цене строки заказа —
                // если фрахт вносили, пока груз ехал, его вклад этим усреднением размывался
                // (на проверке терялось 16.67 из 20 $). Пересчёт ложится поверх и восстанавливает
                // правильную цифру. Ошибка пересчёта приёмку не отменяет — товар уже на складе.
                require_once __DIR__ . '/includes/landed_cost.php';
                $costRes = recompute_landed_cost_after_receipt($cfg, $orderId);
                if (!empty($costRes['note'])) $message .= ' ' . $costRes['note'];
                // принятые товары — в «Цены нового прихода» у руководства; сбой приёмку не отменяет
                try { pricing_register_receipt($orderId, $pmpBefore); }
                catch (Throwable $e) { $message .= ' (Не удалось передать приход на проверку цен: ' . $e->getMessage() . ' — сообщите Суннату.)'; }

                $messageType = ($rejectionNote || empty($costRes['ok'])) ? 'err' : 'ok';
                $_SESSION['receive_order_id'] = null; // назад к списку заказов
                // Приёмка уже реально записана — редирект (POST → GET). Само по себе повторное
                // принятие тех же позиций и так отбилось бы (остаток по строке пересчитывается заново
                // из БД при каждом заходе — см. get_already_received_qty), но без редиректа F5 всё
                // равно показывал бы браузерное предупреждение "повторно отправить форму?".
                flash_set($message, $messageType);
                header('Location: receive.php');
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

// --- Заказы поставщику, ожидающие приёма для этого направления ---
// Показываем ТОЛЬКО те, что Нодир/Абдурашид уже оформил И утвердил (approved/running/received_start).
// Черновики (ещё не утверждены) сюда не попадают — раздел должен быть пуст, пока заказа нет.
$pendingOrders = [];
if (empty($_SESSION['receive_order_id'])) {
    $rawOrders = [];
    foreach (['approved', 'running', 'received_start'] as $status) {
        $rows = $api->getSupplierOrdersByStatus($status, 'id,ref,socid,statut,date_commande');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $rawOrders[(int)$row['id']] = $row;
            }
        }
    }

    $productDirectionCache = []; // fk_product => bool (принадлежит ли направлению), чтобы не спрашивать API дважды
    $statusLabels = [2 => 'Утверждён', 3 => 'Отправлен поставщику', 4 => 'Частично получен'];

    foreach ($rawOrders as $orderId => $summary) {
        $full = $api->getSupplierOrder($orderId);
        if (!is_array($full) || empty($full['lines'])) continue;

        $matchingLines = [];
        foreach ($full['lines'] as $line) {
            $fkProduct = (int)($line['fk_product'] ?? 0);
            if (!$fkProduct) continue;
            if (!array_key_exists($fkProduct, $productDirectionCache)) {
                $prod = $api->getProduct($fkProduct, false);
                $kodSap = is_array($prod) ? ($prod['array_options']['options_kod_sap'] ?? '') : '';
                $productDirectionCache[$fkProduct] = (stripos($kodSap, $cfg['ref_prefix']) === 0);
            }
            if ($productDirectionCache[$fkProduct]) {
                $matchingLines[] = $line;
            }
        }

        if (empty($matchingLines)) continue; // заказ не нашего направления — пропускаем молча

        $supplierName = '';
        $soc = $api->getThirdparty((int)($full['socid'] ?? 0));
        if (is_array($soc)) $supplierName = $soc['name'] ?? $soc['nom'] ?? '';

        $pendingOrders[] = [
            'id' => $orderId,
            'ref' => $full['ref'] ?? $summary['ref'] ?? '',
            'supplier' => $supplierName,
            'date' => !empty($full['date_commande']) ? date('d.m.Y', (int)$full['date_commande']) : '',
            'status_label' => $statusLabels[(int)($full['statut'] ?? 0)] ?? '',
            'lines' => $matchingLines,
        ];
    }
}

// --- Заказ, выбранный для приёмки прямо сейчас ---
$selectedOrder = null;
if (!empty($_SESSION['receive_order_id'])) {
    $full = $api->getSupplierOrder((int)$_SESSION['receive_order_id']);
    if (is_array($full)) {
        $productDirectionCache = $productDirectionCache ?? [];
        $matchingLines = [];
        foreach (($full['lines'] ?? []) as $line) {
            $fkProduct = (int)($line['fk_product'] ?? 0);
            if (!$fkProduct) continue;
            if (!array_key_exists($fkProduct, $productDirectionCache)) {
                $prod = $api->getProduct($fkProduct, false);
                $kodSap = is_array($prod) ? ($prod['array_options']['options_kod_sap'] ?? '') : '';
                $productDirectionCache[$fkProduct] = (stripos($kodSap, $cfg['ref_prefix']) === 0);
            }
            if ($productDirectionCache[$fkProduct]) {
                // Точный остаток ПО ЭТОЙ СТРОКЕ (не по заказу в целом) — заказано минус уже реально
                // принято раньше (может быть несколько частичных приёмок). Раньше ориентировались на
                // статус всего заказа ("частично получен" → просто пустое поле для любой строки) —
                // это не различало, какая конкретно позиция уже закрыта, а какая ещё нет (см. пример
                // с трубой/краном в обсуждении). Теперь считаем честно по каждой позиции отдельно.
                $line['already_received'] = get_already_received_qty((int)$line['id']);
                $line['remaining'] = max(0, (float)$line['qty'] - $line['already_received']);
                $matchingLines[] = $line;
            }
        }
        $supplierName = '';
        $soc = $api->getThirdparty((int)($full['socid'] ?? 0));
        if (is_array($soc)) $supplierName = $soc['name'] ?? $soc['nom'] ?? '';
        $selectedOrder = [
            'id' => (int)$_SESSION['receive_order_id'],
            'ref' => $full['ref'] ?? '',
            'supplier' => $supplierName,
            'lines' => $matchingLines,
        ];
    } else {
        $_SESSION['receive_order_id'] = null;
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Приём товара</h1>
<p class="muted">Приёмка привязана к заказу поставщику — сюда попадают только заказы, которые
   <?= htmlspecialchars($cfg['purchaser_label']) ?> уже оформил и утвердил.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<?php if ($selectedOrder): ?>
<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <h2 style="margin-bottom:2px"><?= htmlspecialchars($selectedOrder['ref']) ?></h2>
      <div class="muted">Поставщик: <?= htmlspecialchars($selectedOrder['supplier']) ?></div>
    </div>
    <form method="post" style="flex:0">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_order">
      <button type="submit" class="secondary">← Все заказы</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>Что привезли</h2>
  <?php if (empty($selectedOrder['lines'])): ?>
    <p class="muted">В этом заказе нет позиций нашего направления.</p>
  <?php else: ?>
    <form method="post" enctype="multipart/form-data" id="receiveForm">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="receive">
      <input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
      <table>
        <tr><th>Товар</th><th>Заказано</th><th>Уже принято</th><th>Остаток</th><th>Получено сейчас</th><th>Склад</th><th>Из них брак</th></tr>
        <?php $rowIdx = 0; foreach ($selectedOrder['lines'] as $line):
          $remaining = $line['remaining'];
        ?>
          <tr>
            <td>
              <?= htmlspecialchars($line['product_label'] ?? $line['label'] ?? '') ?>
              <div class="muted"><?= htmlspecialchars($line['product_ref'] ?? '') ?></div>
              <?php if ((float)($line['subprice'] ?? 0) <= 0.011): ?>
                <div style="color:#dc2626;font-size:12px">⚠ в заказе нет цены — позицию не принять, сообщите <?= htmlspecialchars($cfg['purchaser_label']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= number_format((float)$line['qty'], 2) ?></td>
            <td><?= number_format($line['already_received'], 2) ?></td>
            <td><?= number_format($remaining, 2) ?></td>
            <td>
              <input type="hidden" name="line_id[]" value="<?= (int)$line['id'] ?>">
              <input type="hidden" name="fk_product[]" value="<?= (int)$line['fk_product'] ?>">
              <?php if ($remaining <= 0.0001): ?>
                <span class="muted">получено полностью</span>
                <input type="hidden" name="qty[]" value="0">
              <?php else: ?>
                <input type="number" step="any" min="0" max="<?= $remaining ?>" name="qty[]" value="<?= $remaining ?>" style="min-width:90px; margin:0">
              <?php endif; ?>
            </td>
            <td>
              <select name="warehouse[]" style="min-width:170px; margin:0">
                <?php foreach ($cfg['warehouse_ids'] as $whId): ?>
                  <option value="<?= $whId ?>" <?= $whId == $cfg['default_warehouse_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cfg['warehouse_labels'][$whId] ?? $whId) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="defect-cell">
              <?php if ($remaining > 0.0001): ?>
                <input type="number" step="any" min="0" max="<?= $remaining ?>" name="defect_qty[<?= $rowIdx ?>]" value="0"
                       class="defect-qty" style="width:80px; margin:0">
                <div class="defect-extra" hidden>
                  <select name="defect_reason[<?= $rowIdx ?>]" style="margin:6px 0 0; min-width:190px">
                    <option value="">— причина брака —</option>
                    <?php foreach (DEFECT_REASONS as $rk => $rl): ?>
                      <option value="<?= $rk ?>"><?= htmlspecialchars($rl) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="defect_note[<?= $rowIdx ?>]" placeholder="что именно не так (необязательно)"
                         style="margin:6px 0 0; min-width:190px">
                  <label class="muted" style="display:block;margin-top:6px">Фото (до <?= DEFECT_MAX_PHOTOS ?>):
                    <input type="file" name="defect_photo[<?= $rowIdx ?>][]" accept="image/*" multiple class="defect-photo">
                  </label>
                </div>
              <?php else: ?>
                <input type="hidden" name="defect_qty[<?= $rowIdx ?>]" value="0">
              <?php endif; ?>
            </td>
          </tr>
        <?php $rowIdx++; endforeach; ?>
      </table>
      <p class="muted">Брак: укажите, сколько из привезённого с браком — годное ляжет на выбранный склад,
         брак на склад «<?= htmlspecialchars($cfg['defect_warehouse_label']) ?>» (он не продаётся), и
         <?= htmlspecialchars($cfg['purchaser_label']) ?> сразу получит рекламацию поставщику с вашими фото.</p>
      <p class="muted">Если какую-то позицию сегодня не привезли — поставьте у неё 0, заказ останется открытым для следующей приёмки.
         Нельзя ввести больше, чем в колонке "Остаток" — если по факту привезли больше или что-то незаказанное, сначала нужно поправить заказ (сообщите <?= htmlspecialchars($cfg['purchaser_label']) ?>).</p>
      <button type="submit">Принять на склад</button>
    </form>
    <script>
    (function () {
      var form = document.getElementById('receiveForm');
      if (!form) return;

      // Причина и фото нужны, только когда брак больше нуля — иначе они только мешают.
      form.querySelectorAll('.defect-qty').forEach(function (inp) {
        var extra = inp.parentNode.querySelector('.defect-extra');
        var sync = function () { extra.hidden = !(parseFloat(inp.value) > 0); };
        inp.addEventListener('input', sync); sync();
      });

      // Фото с телефона весят 3–5 МБ, а IIS по умолчанию не пропускает запрос больше 30 МБ — пять фото
      // легко упираются в предел, и кассир видит непонятную ошибку. Сжимаем в браузере до 1600 px
      // (для рекламации достаточно), получается ~0,3–0,6 МБ на фото. Если браузер не умеет — шлём как есть.
      function shrink(file) {
        return new Promise(function (resolve) {
          if (!/^image\//.test(file.type) || !window.createImageBitmap || !window.DataTransfer) return resolve(file);
          createImageBitmap(file).then(function (bmp) {
            var max = 1600, k = Math.min(1, max / Math.max(bmp.width, bmp.height));
            var c = document.createElement('canvas');
            c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            c.toBlob(function (b) {
              resolve(b ? new File([b], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type: 'image/jpeg'}) : file);
            }, 'image/jpeg', 0.82);
          }).catch(function () { resolve(file); });
        });
      }

      var ready = false;
      form.addEventListener('submit', function (e) {
        if (ready) return;
        // брак без причины сервер всё равно отобьёт, но лучше сказать сразу, не теряя введённое
        var missing = [];
        form.querySelectorAll('.defect-qty').forEach(function (inp) {
          if (parseFloat(inp.value) > 0) {
            var sel = inp.parentNode.querySelector('select');
            if (sel && !sel.value) missing.push(sel);
          }
        });
        if (missing.length) { e.preventDefault(); missing[0].focus(); missing[0].style.outline = '2px solid #dc2626'; return; }

        var inputs = Array.prototype.slice.call(form.querySelectorAll('.defect-photo')).filter(function (i) { return i.files && i.files.length; });
        if (!inputs.length || !window.DataTransfer) return;
        e.preventDefault();
        var btn = form.querySelector('button[type=submit]');
        if (btn) { btn.disabled = true; btn.textContent = 'Готовлю фото…'; }
        Promise.all(inputs.map(function (inp) {
          return Promise.all(Array.prototype.slice.call(inp.files, 0, <?= DEFECT_MAX_PHOTOS ?>).map(shrink)).then(function (files) {
            var dt = new DataTransfer(); files.forEach(function (f) { dt.items.add(f); }); inp.files = dt.files;
          });
        })).then(function () { ready = true; form.submit(); });
      });
    })();
    </script>
  <?php endif; ?>
</div>

<?php else: ?>

<div class="card">
  <h2>Заказы, ожидающие приёма</h2>
  <?php if (empty($pendingOrders)): ?>
    <?php // K-4: убрано слово "Dolibarr" — кассиру оно ни о чём не говорит. ?>
    <p class="muted">Пока пусто — здесь появятся заказы, как только <?= htmlspecialchars($cfg['purchaser_label']) ?>
       оформит заказ поставщику и утвердит его.</p>
  <?php else: ?>
    <div class="debtor-grid">
      <?php foreach ($pendingOrders as $o): ?>
        <form method="post" class="debtor-block">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="select_order">
          <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
          <button type="submit" class="debtor-block-btn">
            <span class="debtor-block-name"><?= htmlspecialchars($o['ref']) ?><br>
              <span class="muted"><?= htmlspecialchars($o['supplier']) ?></span>
            </span>
            <span class="badge badge-ok"><?= htmlspecialchars($o['status_label']) ?></span>
            <span class="muted"><?= count($o['lines']) ?> позиц., от <?= htmlspecialchars($o['date']) ?></span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
