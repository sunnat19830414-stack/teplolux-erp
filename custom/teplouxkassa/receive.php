<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dolibarr_direct.php';

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
                $warehouseId = (int)($warehouses[$i] ?? $cfg['default_warehouse_id']);
                if (!in_array($warehouseId, $cfg['warehouse_ids'], false)) {
                    $rejected[] = "{$fkProduct}: указан склад, не относящийся к направлению — строка отклонена.";
                    continue;
                }
                $lines[] = [
                    'line_id' => $lineIdInt,
                    'fk_product' => $fkProduct,
                    'qty' => $qty,
                    'warehouse' => $warehouseId,
                    'price' => $priceByLine[$lineIdInt] ?? 0,
                ];
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
            $result = $api->receiveSupplierOrder($orderId, $lines, true, 'Приёмка через кассу ' . $cfg['direction_label']);
            if ($result === null) {
                $message = ($rejectionNote ? $rejectionNote . "\n" : '') . 'Ошибка приёмки: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $message = ($rejectionNote ? $rejectionNote . "\n" : '') .
                    'Товар принят на склад (' . count($lines) . ' позиц.). Если в заказе оставались непривезённые позиции — он останется в списке ожидающих.';
                $messageType = $rejectionNote ? 'err' : 'ok';
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
    <form method="post">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="receive">
      <input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
      <table>
        <tr><th>Товар</th><th>Заказано</th><th>Уже принято</th><th>Остаток</th><th>Получено сейчас</th><th>Склад</th></tr>
        <?php foreach ($selectedOrder['lines'] as $line):
          $remaining = $line['remaining'];
        ?>
          <tr>
            <td>
              <?= htmlspecialchars($line['product_label'] ?? $line['label'] ?? '') ?>
              <div class="muted"><?= htmlspecialchars($line['product_ref'] ?? '') ?></div>
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
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="muted">Если какую-то позицию сегодня не привезли — поставьте у неё 0, заказ останется открытым для следующей приёмки.
         Нельзя ввести больше, чем в колонке "Остаток" — если по факту привезли больше или что-то незаказанное, сначала нужно поправить заказ (сообщите <?= htmlspecialchars($cfg['purchaser_label']) ?>).</p>
      <button type="submit">Принять на склад</button>
    </form>
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
