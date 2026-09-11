<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/price_history.php';
require_once __DIR__ . '/includes/currency.php';

if (!array_key_exists('po_supplier', $_SESSION)) $_SESSION['po_supplier'] = null;
if (empty($_SESSION['po_cart'])) $_SESSION['po_cart'] = [];

// Обычный (не форма) заход в раздел — вернулись сюда через сайдбар из другого раздела — сбрасывает
// выбранного поставщика, чтобы не "застревать" на нём. Корзину не трогаем: если уже начали набирать
// заказ и просто отвлеклись на другой раздел, позиции не должны потеряться.
reset_selection_unless_preserved('po_supplier');

// Вернулись из формы "+ Новый товар" (product_form.php, пункт B9) — сразу кладём созданный товар
// в корзину. Именно ради этого форма и открывалась, повторно искать его вручную не нужно.
if (!empty($_SESSION['po_new_product'])) {
    $np = $_SESSION['po_new_product'];
    unset($_SESSION['po_new_product']);
    $_SESSION['po_cart'][] = [
        'product_id' => (int)$np['id'],
        'ref' => $np['ref'],
        'label' => $np['label'],
        'price' => (float)$np['price'],
        'qty' => 1,
    ];
}

$message = '';
$messageType = '';

// Правки корзины без перезагрузки (assets/line_table.js, 11.09.2026) — запрос с ajax=1 получает JSON.
$isAjax = !empty($_POST['ajax']);
function po_cart_json(array $r): void
{
    $cur = $_SESSION['po_supplier']['currency'] ?? 'USD';
    $rate = (float)($_SESSION['po_supplier']['rate'] ?? 1);
    $total = 0.0;
    foreach ($_SESSION['po_cart'] as $it) $total += (float)$it['price'] * (float)$it['qty'];
    $r['totals'] = [
        'main' => number_format($total, 2) . ' ' . currency_label($cur),
        'sub' => $cur !== 'USD' ? '≈ ' . number_format(to_base_currency($total, $cur, $rate), 2) . ' $ по курсу '
                                  . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') : '',
        'count' => count($_SESSION['po_cart']),
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_supplier') {
        // Валюту берём из карточки поставщика (B2): заказ и спецификация будут в ней, а не в долларах.
        $socId = (int)($_POST['supplier_id'] ?? 0);
        $soc = $socId ? $api->getThirdparty($socId) : null;
        $curr = supplier_currency(is_array($soc) ? $soc : null);
        $_SESSION['po_supplier'] = [
            'id' => $socId,
            'name' => $_POST['supplier_name'] ?? '',
            'currency' => $curr,
            'rate' => dolibarr_currency_rate($curr) ?? 1.0,   // подсказка, курс всё равно редактируется
        ];
    } elseif ($action === 'set_rate') {
        [$r, $err] = validate_currency_rate($_SESSION['po_supplier']['currency'] ?? 'USD', $_POST['rate'] ?? '');
        if ($err !== '') {
            $message = $err;
            $messageType = 'err';
        } else {
            $_SESSION['po_supplier']['rate'] = $r;
        }
    } elseif ($action === 'clear_supplier') {
        $_SESSION['po_supplier'] = null;
        $_SESSION['po_cart'] = [];
        // Корзину очистили — связь с заявкой руководства тоже сбрасываем, иначе следующий, совсем
        // другой заказ закрыл бы ту заявку как выполненную.
        unset($_SESSION['po_from_request']);
    } elseif ($action === 'add_to_cart') {
        $_SESSION['po_cart'][] = [
            'product_id' => (int)($_POST['product_id'] ?? 0),
            'ref' => $_POST['ref'] ?? '',
            'label' => $_POST['label'] ?? '',
            'price' => (float)($_POST['price'] ?? 0),
            'qty' => max((float)($_POST['qty'] ?? 1), 0.001),   // qty — только при «Вернуть» удалённую позицию
        ];
        if ($isAjax) po_cart_json(['ok' => true, 'key' => array_key_last($_SESSION['po_cart'])]);
    } elseif ($action === 'update_cart_item') {
        $idx = (int)($_POST['idx'] ?? $_POST['key'] ?? -1);
        if (!isset($_SESSION['po_cart'][$idx])) {
            if ($isAjax) po_cart_json(['ok' => false, 'error' => 'Позиции уже нет в корзине — обновите страницу.']);
        } else {
            // 11.09.2026: меняем только то, что пришло. Раньше форма количества не передавала цену, а
            // отсутствующая цена читалась как 0 — смена количества в корзине ОБНУЛЯЛА цену позиции.
            if (isset($_POST['qty']) && (float)$_POST['qty'] > 0) $_SESSION['po_cart'][$idx]['qty'] = (float)$_POST['qty'];
            if (isset($_POST['price']) && $_POST['price'] !== '' && (float)$_POST['price'] >= 0) $_SESSION['po_cart'][$idx]['price'] = (float)$_POST['price'];
            $it = $_SESSION['po_cart'][$idx];
            if ($isAjax) po_cart_json(['ok' => true, 'qty' => (float)$it['qty'], 'price' => (float)$it['price'],
                'line_total_raw' => round($it['qty'] * $it['price'], 2),
                'line_total' => number_format($it['qty'] * $it['price'], 2) . ' ' . currency_label($_SESSION['po_supplier']['currency'] ?? 'USD')]);
        }
        $_SESSION['_preserve_once']['po_supplier'] = true;
        header('Location: orders.php#cart-' . $idx);
        exit;
    } elseif ($action === 'remove_from_cart') {
        $keys = isset($_POST['keys']) ? array_map('intval', (array)$_POST['keys']) : [(int)($_POST['idx'] ?? -1)];
        $deleted = [];
        foreach ($keys as $k) if (isset($_SESSION['po_cart'][$k])) { unset($_SESSION['po_cart'][$k]); $deleted[] = $k; }
        if ($isAjax) po_cart_json(['ok' => true, 'deleted' => $deleted]);
        $_SESSION['_preserve_once']['po_supplier'] = true;
        header('Location: orders.php');
        exit;
    } elseif ($action === 'create_order') {
        $currency = $_SESSION['po_supplier']['currency'] ?? 'USD';
        [$rate, $rateErr] = validate_currency_rate($currency, $_SESSION['po_supplier']['rate'] ?? 1);

        if (empty($_SESSION['po_supplier']['id'])) {
            $message = 'Сначала выберите поставщика.';
            $messageType = 'err';
        } elseif (empty($_SESSION['po_cart'])) {
            $message = 'Добавьте хотя бы одну позицию.';
            $messageType = 'err';
        } elseif ($rateErr !== '') {
            $message = $rateErr;
            $messageType = 'err';
        } else {
            $orderId = $api->createSupplierOrder((int)$_SESSION['po_supplier']['id'], $currency, $rate);
            if (!$orderId) {
                $message = 'Ошибка создания заказа: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $supplierId = (int)$_SESSION['po_supplier']['id'];
                $lineErrors = [];
                foreach ($_SESSION['po_cart'] as $item) {
                    $r = $api->addSupplierOrderLine($orderId, $item['product_id'], $item['label'], $item['qty'], $item['price'], 0, $currency);
                    if ($r === null) {
                        $lineErrors[] = $item['label'] . ': ' . $api->lastError;
                        continue;
                    }
                    // По просьбе пользователя: цена, вписанная при оформлении, тут же сохраняется как
                    // "цена от поставщика" — так изначально пустая таблица закупочных цен заполняется
                    // сама по мере реальных заказов, отдельно вносить ничего не нужно. Старое значение
                    // при этом не теряется — save_purchase_price_with_history() ведёт журнал изменений
                    // (includes/price_history.php), чтобы можно было проверить, если цена окажется
                    // испорчена опечаткой.
                    save_purchase_price_with_history($api, (int)$item['product_id'], $supplierId, (float)$item['price'], $_SESSION['user']['name'] ?? '', $currency, $rate);
                }
                if ($lineErrors) {
                    $message = "Заказ #$orderId создан, но не все позиции добавились: " . implode('; ', $lineErrors);
                    $messageType = 'err';
                } else {
                    $message = "Заказ #$orderId создан (черновик). Теперь его нужно провести и утвердить в списке ниже.";
                    $messageType = 'ok';
                }
                // Корзина была собрана из заявки руководства — закрываем заявку и связываем с заказом,
                // чтобы шеф увидел у себя «заказ оформлен», а не гадал, дошёл ли список.
                if (!empty($_SESSION['po_from_request'])) {
                    require_once __DIR__ . '/includes/requests.php';
                    $fromRequest = (int)$_SESSION['po_from_request'];
                    unset($_SESSION['po_from_request']);
                    request_set_status($fromRequest, 'ordered', ['closed_at' => true, 'fk_order' => $orderId]);
                    $message .= " Заявка №$fromRequest отмечена выполненной.";
                }
                $_SESSION['po_cart'] = [];
                $_SESSION['po_supplier'] = null;
                // Заказ уже реально создан — редирект (POST → GET), чтобы F5 не отправил эту же форму
                // повторно. Корзина и так уже очищена (см. выше) — резотправка сама по себе отбилась бы
                // ("добавьте хотя бы одну позицию"), но без редиректа F5 всё равно спросил бы браузером
                // "отправить форму повторно?", что путает.
                flash_set($message, $messageType);
                header('Location: orders.php');
                exit;
            }
        }
    } elseif (in_array($action, ['validate_order', 'approve_order', 'send_order'], true)) {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $who = $_SESSION['user']['name'] ?? '';
        $result = null;
        $label = '';
        // Цена-заглушка (0,01) не должна уйти дальше черновика (11.09.2026). В справочнике 784 товара
        // с закупочной ценой 0,01 USD, и при оформлении заказа она подставляется сама. Если такой
        // заказ примут на склад, Dolibarr пересчитает себестоимость по 0,01 — затрёт настоящую,
        // а весь фрахт (он делится пропорционально стоимости строк) ляжет на остальные позиции.
        // Проводить черновик можно — утвердить и отправить нельзя, пока цены не исправлены.
        if (in_array($action, ['approve_order', 'send_order'], true)) {
            $chk = $api->getSupplierOrder($orderId);
            $stub = [];
            foreach ((array)($chk['lines'] ?? []) as $ln) {
                if ((float)($ln['subprice'] ?? 0) <= 0.011) $stub[] = ($ln['ref'] ?? $ln['product_ref'] ?? '') . ' ' . mb_substr((string)($ln['product_label'] ?? $ln['label'] ?? ''), 0, 40);
            }
            if ($stub) {
                flash_set('Заказ #' . $orderId . ' не ' . ($action === 'approve_order' ? 'утверждён' : 'отправлен') .
                    ': у ' . count($stub) . ' позиц. цена не указана (0,01 — заглушка). Откройте заказ, «Изменить заказ» и впишите цену из спецификации поставщика: ' .
                    implode('; ', array_slice($stub, 0, 6)) . (count($stub) > 6 ? ' …' : ''), 'err');
                header('Location: orders.php');
                exit;
            }
        }
        if ($action === 'validate_order') { $result = $api->validateSupplierOrder($orderId); $label = 'проведён'; }
        elseif ($action === 'approve_order') { $result = $api->approveSupplierOrder($orderId); $label = 'утверждён'; }
        elseif ($action === 'send_order') { $result = $api->sendSupplierOrder($orderId); $label = 'отправлен поставщику'; }
        if ($result === null) {
            $message = "Ошибка ($action) по заказу #$orderId: " . $api->lastError;
            $messageType = 'err';
        } else {
            $message = "Заказ #$orderId $label ($who).";
            $messageType = 'ok';
            // Статус заказа уже реально изменён — редирект (POST → GET), та же причина, что и выше.
            flash_set($message, $messageType);
            header('Location: orders.php');
            exit;
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

// --- Список ВСЕХ заказов (черновик..получен/отменён) — можно открыть любой и посмотреть детали ---
$statusLabels = [
    0 => 'Черновик', 1 => 'Проведён', 2 => 'Утверждён', 3 => 'Отправлен поставщику',
    4 => 'Частично получен', 5 => 'Получен полностью', 6 => 'Отменён', 7 => 'Отменён', 9 => 'Отклонён поставщиком',
];
$rawOrders = [];
foreach (['draft', 'validated', 'approved', 'running', 'received_start', 'received_end', 'cancelled', 'refused'] as $st) {
    $rows = $api->getSupplierOrdersByStatus($st, 'id,ref,socid,statut,date_commande,date_creation,total_ttc,multicurrency_code,multicurrency_total_ttc');
    if (is_array($rows)) {
        foreach ($rows as $row) $rawOrders[(int)$row['id']] = $row;
    }
}

// Имена поставщиков всех заказов в списке — ОДНИМ запросом, не getThirdparty() по одному на каждого
// различного поставщика (раньше был дедуп-кэш в рамках запроса, но каждый ОТДЕЛЬНЫЙ поставщик всё
// равно бил свой собственный запрос — см. отчёт ревью P0#5).
$socIds = array_map(fn($row) => (int)($row['socid'] ?? 0), $rawOrders);
$supplierNameCache = [];
foreach ($api->getThirdpartiesByIds($socIds) as $socid => $soc) {
    $supplierNameCache[$socid] = $soc['name'] ?? $soc['nom'] ?? "#$socid";
}
$orders = [];
foreach ($rawOrders as $id => $row) {
    $socid = (int)($row['socid'] ?? 0);
    if (!array_key_exists($socid, $supplierNameCache)) {
        $supplierNameCache[$socid] = "#$socid";
    }
    $statut = (int)($row['statut'] ?? 0);
    // BUG-N2 — общая функция nt_order_display_ref() (includes/auth.php), см. пояснение там.
    $displayRef = nt_order_display_ref($row['ref'] ?? '', $statut, $id);
    // date_commande пуст у части черновиков (проставляется позже, при проведении/утверждении) — тогда
    // показываем дату СОЗДАНИЯ заказа вместо пустой колонки.
    $dateTs = !empty($row['date_commande']) ? (int)$row['date_commande'] : (int)($row['date_creation'] ?? 0);
    $orders[] = [
        'id' => $id,
        'ref' => $displayRef,
        'supplier' => $supplierNameCache[$socid],
        'statut' => $statut,
        'total_ttc' => (float)($row['total_ttc'] ?? 0),
        // Заказ мог быть оформлен в валюте поставщика — показываем сумму в ней, доллары рядом.
        'currency' => strtoupper(trim((string)($row['multicurrency_code'] ?? ''))) ?: 'USD',
        'total_native' => (float)($row['multicurrency_total_ttc'] ?? 0),
        'date' => $dateTs ? date('d.m.Y', $dateTs) : '',
    ];
}
usort($orders, fn($a, $b) => $b['id'] <=> $a['id']);

// Корзина ведётся В ВАЛЮТЕ ПОСТАВЩИКА (B2). Долларовый эквивалент показываем рядом справочно —
// именно его увидит бухгалтерия и себестоимость, поэтому закупщик должен его видеть сразу, а не
// узнавать постфактум из уже созданного заказа.
$poCurrency = $_SESSION['po_supplier']['currency'] ?? 'USD';
$poRate = (float)($_SESSION['po_supplier']['rate'] ?? 1);
$poCurLabel = currency_label($poCurrency);
$cartTotal = 0;
foreach ($_SESSION['po_cart'] as $item) { $cartTotal += $item['price'] * $item['qty']; }
$cartTotalUsd = to_base_currency($cartTotal, $poCurrency, $poRate);

// Справочные («заводские») цены поставщика по позициям корзины — одним запросом, для подсказки
// под полем цены: видно, совпадает ли введённая цифра с тем, что в базе, и есть ли она вообще.
$cartRefPrices = [];
if (!empty($_SESSION['po_cart']) && !empty($_SESSION['po_supplier']['id'])) {
    require_once __DIR__ . '/includes/product_lookup.php';
    $refInfo = get_purchase_prices_bulk(
        array_map(fn($i) => (int)$i['product_id'], $_SESSION['po_cart']),
        (int)$_SESSION['po_supplier']['id']
    );
    foreach ($refInfo as $pid => $info) {
        $cartRefPrices[$pid] = purchase_price_for_order($info, $poCurrency, $poRate);
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заказы поставщику</h1>
<?php if ($_SESSION['po_supplier']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_supplier">
    <button type="submit" class="secondary">← Сменить поставщика</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="grid-2col">
<div>

<div class="card">
  <h2>Поставщик</h2>
  <?php if ($_SESSION['po_supplier']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['po_supplier']['name']) ?></strong>
        <?php if ($poCurrency !== 'USD'): ?>
          <div><span class="badge badge-neutral">Договор в <?= htmlspecialchars($poCurrency) ?> — цены вводите в <?= htmlspecialchars($poCurLabel) ?></span></div>
        <?php endif; ?>
        <div><a href="supplier_form.php?ctx=orders&id=<?= (int)$_SESSION['po_supplier']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_supplier">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
    <?php if ($poCurrency !== 'USD'): ?>
      <?php $refRate = dolibarr_currency_rate($poCurrency); ?>
      <form method="post" class="row" style="align-items:end; margin-top:12px">
      <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_rate">
        <div style="max-width:220px">
          <label>Курс сделки: 1 $ = сколько <?= htmlspecialchars($poCurrency) ?></label>
          <input type="number" name="rate" id="poRate" value="<?= htmlspecialchars(rtrim(rtrim(number_format($poRate, 6, '.', ''), '0'), '.')) ?>" step="0.0001" min="0.0001" style="margin:0">
        </div>
        <div style="flex:0"><button type="submit" class="secondary">Применить курс</button></div>
        <div class="muted" style="align-self:center">
          <span id="poRateHint"></span>
          <?php if ($refRate): ?><br>по справочнику сегодня: <?= rtrim(rtrim(number_format($refRate, 4, '.', ''), '0'), '.') ?><?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <input type="text" id="supplierSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать название...">
    <div id="supplierResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="supplier_form.php?ctx=orders" class="btn secondary small">+ Новый поставщик</a></p>
  <?php endif; ?>
</div>

<?php if ($_SESSION['po_supplier']): ?>
<div class="card">
  <h2>Добавить товар</h2>
  <input type="text" id="productSearch" placeholder="Поиск по названию или артикулу...">
  <div id="productResults" class="result-list"></div>
  <p style="margin-top:8px"><a href="product_form.php?ctx=orders" class="btn secondary small">+ Новый товар</a>
    <span class="muted">— если поставщик прислал новинку, которой ещё нет в каталоге</span></p>
</div>
<?php endif; ?>

</div>
<div>

<div class="card">
  <h2>Позиции нового заказа</h2>
  <?php if (empty($_SESSION['po_cart'])): ?>
    <p class="muted">Пусто</p>
  <?php else: ?>
    <div class="row" style="align-items:center; margin-bottom:6px">
      <p class="muted" style="margin:0">Правки сохраняются сами. Щёлкните заголовок, чтобы отсортировать.</p>
      <div style="flex:0"><button type="button" class="danger small" id="cartBulkDel" style="display:none; white-space:nowrap">Удалить отмеченные</button></div>
    </div>
    <div style="overflow-x:auto">
    <table class="nt-lines" id="cartLines">
      <thead><tr><th style="width:28px"><input type="checkbox" class="nt-check-all" title="Отметить все" style="width:auto"></th>
        <th data-sort="ref">Артикул</th><th data-sort="name">Наименование</th><th data-sort="qty">Кол-во</th>
        <th data-sort="price">Цена, <?= htmlspecialchars($poCurLabel) ?></th><th data-sort="sum">Сумма</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($_SESSION['po_cart'] as $idx => $item):
        // Справочная цена от поставщика — чтобы было видно, откуда взялась цифра и не разошлась ли
        // она с прайсом после ручной правки. Пусто — цены от этого поставщика в справочнике нет.
        $refp = $cartRefPrices[(int)$item['product_id']] ?? null;
        $refAttr = ($refp !== null && $refp > 0) ? rtrim(rtrim(number_format($refp, 4, '.', ''), '0'), '.') : '';
        $q = (float)$item['qty']; $pr = (float)$item['price'];
      ?>
        <tr id="cart-<?= $idx ?>" data-key="<?= $idx ?>" data-product="<?= (int)$item['product_id'] ?>"
            data-ref="<?= htmlspecialchars($item['ref']) ?>" data-name="<?= htmlspecialchars($item['label']) ?>" data-desc="<?= htmlspecialchars($item['label']) ?>"
            data-qty="<?= $q ?>" data-price="<?= $pr ?>" data-sum="<?= round($q * $pr, 2) ?>" data-refprice="<?= $refAttr ?>">
          <td><input type="checkbox" class="nt-check" style="width:auto"></td>
          <td style="white-space:nowrap"><?= htmlspecialchars($item['ref']) ?></td>
          <td><?= htmlspecialchars($item['label']) ?></td>
          <td><input type="number" class="nt-edit" data-field="qty" value="<?= htmlspecialchars((string)$q) ?>" step="any" min="0.001"></td>
          <td><input type="number" class="nt-edit" data-field="price" value="<?= htmlspecialchars((string)$pr) ?>" step="any" min="0">
            <div class="muted nt-hint" style="font-size:11.5px; margin-top:2px"></div></td>
          <td class="nt-sum" style="white-space:nowrap"><?= number_format($q * $pr, 2) ?> <?= htmlspecialchars($poCurLabel) ?></td>
          <td><button type="button" class="secondary small nt-del" title="Убрать из корзины">✕</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="total" style="text-align:right; font-weight:700; margin-top:10px;">
      Итого (<span id="cartCount"><?= count($_SESSION['po_cart']) ?></span> поз.): <span id="cartTotalMain"><?= number_format($cartTotal, 2) ?> <?= htmlspecialchars($poCurLabel) ?></span>
      <div class="muted" style="font-weight:400" id="cartTotalSub"><?php if ($poCurrency !== 'USD'): ?>≈ <?= number_format($cartTotalUsd, 2) ?> $ по курсу <?= rtrim(rtrim(number_format($poRate, 4, '.', ''), '0'), '.') ?><?php endif; ?></div>
    </div>
    <script src="assets/line_table.js?v=20260911"></script>
    <script>
    ntLineTable(document.getElementById('cartLines'), {
      endpoint: 'orders.php', csrf: <?= json_encode(csrf_token()) ?>,
      update: 'update_cart_item', remove: 'remove_from_cart', undo: 'add_to_cart', storeKey: 'nt_sort_cart',
      bulkButton: document.getElementById('cartBulkDel'),
      onTotals: t => {
        document.getElementById('cartTotalMain').textContent = t.main;
        document.getElementById('cartTotalSub').textContent = t.sub;
        document.getElementById('cartCount').textContent = t.count;
      }
    });
    </script>
    <form method="post" style="margin-top:10px">
  <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_order">
      <button type="submit">Создать заказ (черновик)</button>
    </form>
  <?php endif; ?>
</div>

</div>
</div>

<div class="card">
  <h2>Все заказы</h2>
  <?php if (empty($orders)): ?>
    <p class="muted">Заказов пока нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Поставщик</th><th>Дата</th><th>Сумма</th><th>Статус</th><th></th></tr>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= $o['id'] ?>"><?= htmlspecialchars($o['ref']) ?></a></td>
          <td><?= htmlspecialchars($o['supplier']) ?></td>
          <td><?= htmlspecialchars($o['date']) ?></td>
          <td>
            <?php if ($o['currency'] !== 'USD' && $o['total_native'] > 0): ?>
              <?= number_format($o['total_native'], 2) ?> <?= htmlspecialchars($o['currency']) ?>
              <div class="muted">≈ <?= number_format($o['total_ttc'], 2) ?> $</div>
            <?php else: ?>
              <?= number_format($o['total_ttc'], 2) ?> $
            <?php endif; ?>
          </td>
          <td><span class="badge badge-neutral"><?= htmlspecialchars($statusLabels[$o['statut']] ?? $o['statut']) ?></span></td>
          <td class="stage-row">
            <a href="order_view.php?id=<?= $o['id'] ?>" class="btn secondary small">Открыть</a>
            <?php if ($o['statut'] === 0): ?>
              <form method="post" style="display:inline">
  <?= csrf_field() ?><input type="hidden" name="action" value="validate_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><button type="submit" class="small">Провести</button></form>
            <?php elseif ($o['statut'] === 1): ?>
              <form method="post" style="display:inline">
  <?= csrf_field() ?><input type="hidden" name="action" value="approve_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><button type="submit" class="small">Утвердить</button></form>
            <?php elseif ($o['statut'] === 2): ?>
              <form method="post" style="display:inline">
  <?= csrf_field() ?><input type="hidden" name="action" value="send_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><button type="submit" class="small">Отправить поставщику</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

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
// Направление курса легко понять наоборот (для EUR это 0.86, а не 1.16) — показываем обратную
// величину сразу под полем, чтобы ошибка бросалась в глаза до создания заказа.
(function () {
  const rateInput = document.getElementById('poRate');
  const hint = document.getElementById('poRateHint');
  if (!rateInput || !hint) return;
  const cur = <?= json_encode($poCurrency) ?>;
  function upd() {
    const v = parseFloat(rateInput.value);
    hint.textContent = (v > 0) ? ('то есть 1 ' + cur + ' = ' + (1 / v).toFixed(4) + ' $') : '';
  }
  rateInput.addEventListener('input', upd);
  upd();
})();
window.wireProductSearch && window.wireProductSearch('productSearch', 'productResults', function (p) {
  const form = document.createElement('form');
  form.method = 'post';
  // Цена поставщика (если уже известна) сразу подставляется в корзину — можно поправить на месте,
  // до её заполнения в базе просто идёт 0, тоже редактируется вручную.
  // ВАЖНО: корзина ведётся в валюте заказа. `supplier_price` всегда в долларах, поэтому для
  // валютного заказа берём цену в валюте (она хранится рядом), иначе в поле «Цена, EUR» попало бы
  // долларовое число — примерно на 16% больше настоящего.
  const poCur = <?= json_encode($poCurrency) ?>;
  const poRateJs = <?= json_encode($poRate) ?>;
  let startPrice = 0;
  if (p.supplier_price !== null && p.supplier_price !== undefined) {
    const stored = (p.supplier_currency || 'USD').toUpperCase();
    if (stored === poCur) {
      startPrice = (poCur === 'USD') ? p.supplier_price : (p.supplier_native_price || 0);
    } else if (poCur === 'USD') {
      startPrice = p.supplier_price;
    } else {
      startPrice = Math.round(p.supplier_price * poRateJs * 10000) / 10000;
    }
  }
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="add_to_cart">' +
    '<input type="hidden" name="product_id" value="' + p.id + '">' +
    '<input type="hidden" name="ref" value="' + p.ref.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="label" value="' + p.label.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="price" value="' + startPrice + '">';
  document.body.appendChild(form);
  form.submit();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
