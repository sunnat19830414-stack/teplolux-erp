<?php
/**
 * Перевозки — рейсы перевозчиков (B6, 05.09.2026).
 *
 * Заявку и акт мы не составляем: их присылает сам перевозчик на своём бланке (см. докблок
 * includes/shipments.php). Здесь ведётся то, о чём Абдурашид реально договаривается — кто везёт,
 * откуда и куда, что именно едет, какая машина и за сколько, — и деньги: долг, инвойс, оплата.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/shipments.php';
require_once __DIR__ . '/includes/debt.php';
require_once __DIR__ . '/includes/currency.php';

if (!array_key_exists('selected_shipment', $_SESSION)) $_SESSION['selected_shipment'] = null;

// Прямая ссылка с карточки перевозчика / Сводки
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['id'])) {
    $_SESSION['selected_shipment'] = (int)$_GET['id'];
    $_SESSION['_preserve_once']['selected_shipment'] = true;
}
// Обычный заход через меню — всегда список, а не тот рейс, на котором остановились в прошлый раз.
reset_selection_unless_preserved('selected_shipment');

$message = '';
$messageType = '';
$confirmInvoice = null;   // данные для переспроса, когда сумма инвойса разошлась с договорённостью

$who = $_SESSION['user']['name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_shipment') {
        $_SESSION['selected_shipment'] = (int)($_POST['shipment_id'] ?? 0);
    } elseif ($action === 'clear_shipment') {
        $_SESSION['selected_shipment'] = null;
    } elseif ($action === 'create') {
        $r = shipment_create($_POST, $who);
        if (empty($r['ok'])) {
            $message = $r['error'];
            $messageType = 'err';
        } else {
            $_SESSION['selected_shipment'] = (int)$r['id'];
            $_SESSION['_preserve_once']['selected_shipment'] = true;
            flash_set('Рейс записан. Долг перевозчику начислен, фрахт учтён в себестоимости.', 'ok');
            header('Location: shipments.php');
            exit;
        }
    } elseif ($action === 'update') {
        $r = shipment_update((int)($_POST['shipment_id'] ?? 0), $_POST, $who);
        if (empty($r['ok'])) {
            $message = $r['error'];
            $messageType = 'err';
        } else {
            $_SESSION['_preserve_once']['selected_shipment'] = true;
            $msg = $r['money_changed']
                ? 'Рейс изменён. Долг перевозчику и себестоимость пересчитаны.'
                : 'Рейс изменён.';
            if (!empty($r['warning'])) $msg .= ' ' . $r['warning'];
            flash_set($msg, empty($r['warning']) ? 'ok' : 'err');
            header('Location: shipments.php');
            exit;
        }
    } elseif ($action === 'set_invoice') {
        $sid = (int)($_POST['shipment_id'] ?? 0);
        $confirmed = !empty($_POST['confirmed']);
        $r = shipment_set_invoice($sid, $_POST, $who, $confirmed);
        if (!empty($r['needs_confirm'])) {
            // Не применяем молча — показываем обе цифры и просим подтвердить (решение пользователя).
            $confirmInvoice = $r + ['posted' => $_POST];
            $message = $r['error'];
            $messageType = 'warn';
        } elseif (empty($r['ok'])) {
            $message = $r['error'];
            $messageType = 'err';
        } else {
            $_SESSION['_preserve_once']['selected_shipment'] = true;
            $msg = $r['recalculated']
                ? 'Инвойс записан. Сумма отличалась от договорённости — долг и себестоимость пересчитаны на фактическую.'
                : 'Инвойс записан.';
            if (!empty($r['warning'])) $msg .= ' ' . $r['warning'];
            flash_set($msg, empty($r['warning']) ? 'ok' : 'err');
            header('Location: shipments.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $r = shipment_delete((int)($_POST['shipment_id'] ?? 0));
        if (empty($r['ok'])) {
            $message = $r['error'];
            $messageType = 'err';
        } else {
            $_SESSION['selected_shipment'] = null;
            flash_set('Рейс удалён, начисление снято.', 'ok');
            header('Location: shipments.php');
            exit;
        }
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$selected = $_SESSION['selected_shipment'] ? shipment_get((int)$_SESSION['selected_shipment']) : null;
$shipments = $selected ? [] : shipments_list();

// Имена перевозчиков — одним запросом на весь список
$carrierNames = [];
if ($shipments) {
    $carrierNames = $api->getThirdpartiesByIds(array_unique(array_map(fn($s) => (int)$s['fk_carrier'], $shipments)));
}
$selCarrier = $selected ? $api->getThirdparty((int)$selected['fk_carrier']) : null;

// Партии для выбора «что едет»
$batches = logistics_get_batches(false);

// Только что созданный перевозчик (вернулись из carrier_form.php) — одноразовый маркер, тот же
// приём, что в batches.php: подставляем его в пикер и сразу забываем.
$justCreatedCarrier = $_SESSION['new_carrier_for_shipment'] ?? null;
unset($_SESSION['new_carrier_for_shipment']);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Перевозки</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if ($selected): ?>
  <form method="post" style="margin-bottom:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_shipment">
    <button type="submit" class="secondary small">← Все рейсы</button>
  </form>
<?php endif; ?>

<?php if (!$selected): ?>

  <div class="card">
    <h2>Новый рейс</h2>
    <p class="muted">Записывается то, о чём договорились с транспортной компанией. Как только рейс
    сохранён, сумма становится долгом перевозчику, а фрахт попадает в себестоимость того, что едет.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="fk_carrier" id="carrierId" value="">

      <label>Перевозчик</label>
      <input type="text" id="carrierSearch" placeholder="начните вводить название" autocomplete="off">
      <div id="carrierResults" class="search-results"></div>
      <div id="carrierChosen" class="muted" style="margin-bottom:8px"></div>
      <p class="muted" style="margin-top:-4px">
        Нужного нет? <a href="carrier_form.php?ctx=shipments">+ Новый перевозчик</a>
      </p>

      <div class="row">
        <div style="flex:1"><label>Откуда</label>
          <input type="text" name="route_from" placeholder="например Istanbul (TR)"></div>
        <div style="flex:1"><label>Куда</label>
          <input type="text" name="route_to" placeholder="например Tashkent (UZ)"></div>
      </div>

      <label>Машина</label>
      <select name="truck_type">
        <?php foreach (SHIPMENT_TRUCK_TYPES as $k => $lbl): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>

      <label>Что едет</label>
      <div class="row">
        <div style="flex:0 0 150px">
          <select name="scope_type" id="scopeType">
            <option value="batch">Партия</option>
            <option value="order">Один заказ</option>
          </select>
        </div>
        <div style="flex:1">
          <div id="scopeBatch">
            <select name="scope_id_batch">
              <option value="">— выберите партию —</option>
              <?php foreach ($batches as $b): ?>
                <option value="<?= (int)$b['rowid'] ?>"><?= htmlspecialchars($b['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="scopeOrder" style="display:none">
            <input type="text" id="orderSearch" placeholder="номер заказа поставщику" autocomplete="off">
            <div id="orderResults" class="search-results"></div>
            <input type="hidden" name="scope_id_order" id="orderId" value="">
            <div id="orderChosen" class="muted"></div>
          </div>
        </div>
      </div>
      <input type="hidden" name="scope_id" id="scopeId" value="">

      <div class="row">
        <div style="flex:1"><label>Согласованная цена</label>
          <input type="number" step="0.01" min="0.01" name="agreed_amount" required></div>
        <div style="flex:0 0 130px"><label>Валюта</label>
          <select name="currency" id="curSel">
            <option value="USD">USD — $</option>
            <option value="EUR">EUR — €</option>
            <option value="RUB">RUB — ₽</option>
            <option value="UZS">UZS — сум</option>
          </select></div>
        <div style="flex:0 0 190px" id="rateBox" style="display:none">
          <label>Курс за 1 $</label>
          <input type="number" step="0.0001" min="0.0001" name="rate" id="rateInput"></div>
      </div>
      <p class="muted" id="rateHint" style="display:none">
        Курс нужен только чтобы фрахт попал в себестоимость — она считается в долларах.
        Долг перевозчику останется в валюте договорённости.
      </p>

      <label>Примечание <span class="muted">— необязательно</span></label>
      <input type="text" name="comment" placeholder="например: 100% после выгрузки">

      <button type="submit">Записать рейс</button>
    </form>
  </div>

  <div class="card">
    <h2>Рейсы</h2>
    <?php if (empty($shipments)): ?>
      <p class="muted">Пока ни одного рейса.</p>
    <?php else: ?>
      <table>
        <tr><th>Перевозчик</th><th>Маршрут</th><th>Что едет</th><th>Цена</th><th>Инвойс</th><th>Положение</th><th></th></tr>
        <?php foreach ($shipments as $s): ?>
          <?php $soc = $carrierNames[(int)$s['fk_carrier']] ?? null; ?>
          <tr>
            <td><?= htmlspecialchars(is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : ('#' . $s['fk_carrier'])) ?></td>
            <td class="muted"><?= htmlspecialchars(trim($s['route_from'] . ' → ' . $s['route_to'], ' →')) ?></td>
            <td class="muted"><?= htmlspecialchars(shipment_scope_label($s, $api)) ?></td>
            <td><?= htmlspecialchars(money((float)$s['agreed_amount'], $s['currency'])) ?></td>
            <td class="muted"><?= $s['invoice_number'] ? htmlspecialchars($s['invoice_number']) : '—' ?></td>
            <td><span class="badge badge-<?= $s['status']['cls'] === 'ok' ? 'ok' : ($s['status']['cls'] === 'warn' ? 'warn' : 'debt') ?>"><?= htmlspecialchars($s['status']['label']) ?></span></td>
            <td>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="select_shipment">
                <input type="hidden" name="shipment_id" value="<?= (int)$s['rowid'] ?>">
                <button type="submit" class="small secondary">Открыть</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php
    $paid = shipment_paid((int)$selected['rowid']);
    $due = $selected['invoice_amount'] !== null ? (float)$selected['invoice_amount'] : (float)$selected['agreed_amount'];
    $status = shipment_status($selected + ['paid_native' => $paid]);
    $editable = $paid <= 0.01;
  ?>
  <div class="card">
    <h2><?= htmlspecialchars(is_array($selCarrier) ? ($selCarrier['name'] ?? $selCarrier['nom'] ?? '') : 'Перевозчик') ?></h2>
    <div class="row" style="align-items:center">
      <div>
        <div class="muted"><?= htmlspecialchars(trim($selected['route_from'] . ' → ' . $selected['route_to'], ' →')) ?>
          · <?= htmlspecialchars(SHIPMENT_TRUCK_TYPES[$selected['truck_type']] ?? '') ?>
          · <?= htmlspecialchars(shipment_scope_label($selected, $api)) ?></div>
        <div style="font-size:22px; font-weight:700"><?= htmlspecialchars(money($due, $selected['currency'])) ?></div>
        <?php if ($paid > 0.01): ?>
          <div class="muted">оплачено <?= htmlspecialchars(money($paid, $selected['currency'])) ?>,
            осталось <?= htmlspecialchars(money($due - $paid, $selected['currency'])) ?></div>
        <?php endif; ?>
      </div>
      <div style="flex:0"><span class="badge badge-<?= $status['cls'] === 'ok' ? 'ok' : 'warn' ?>"><?= htmlspecialchars($status['label']) ?></span></div>
    </div>
    <?php if ($selected['comment']): ?><p class="muted"><?= htmlspecialchars($selected['comment']) ?></p><?php endif; ?>
    <p class="muted">Оплатить перевозчику — в разделе
      <a href="carriers.php?carrier_id=<?= (int)$selected['fk_carrier'] ?>">«Перевозчики»</a>,
      там же его общий долг и документы.</p>
  </div>

  <div class="card">
    <h2>Инвойс перевозчика</h2>
    <?php if ($selected['invoice_number'] && !$confirmInvoice): ?>
      <p>Инвойс <strong><?= htmlspecialchars($selected['invoice_number']) ?></strong>
         на <?= htmlspecialchars(money((float)$selected['invoice_amount'], $selected['currency'])) ?>
         от <?= htmlspecialchars($selected['invoice_date']) ?>.</p>
      <?php if (abs((float)$selected['invoice_amount'] - (float)$selected['agreed_amount']) > 0.005): ?>
        <p class="muted">Договаривались на <?= htmlspecialchars(money((float)$selected['agreed_amount'], $selected['currency'])) ?>
           — долг пересчитан на сумму инвойса.</p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($confirmInvoice): ?>
      <p class="warn">Договаривались на <strong><?= htmlspecialchars(money((float)$confirmInvoice['agreed'], $confirmInvoice['currency'])) ?></strong>,
         в инвойсе <strong><?= htmlspecialchars(money((float)$confirmInvoice['invoice'], $confirmInvoice['currency'])) ?></strong>.
         Пересчитать долг и себестоимость на сумму инвойса?</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_invoice">
        <input type="hidden" name="shipment_id" value="<?= (int)$selected['rowid'] ?>">
        <input type="hidden" name="confirmed" value="1">
        <input type="hidden" name="invoice_number" value="<?= htmlspecialchars($confirmInvoice['posted']['invoice_number'] ?? '') ?>">
        <input type="hidden" name="invoice_amount" value="<?= htmlspecialchars($confirmInvoice['posted']['invoice_amount'] ?? '') ?>">
        <input type="hidden" name="invoice_date" value="<?= htmlspecialchars($confirmInvoice['posted']['invoice_date'] ?? '') ?>">
        <button type="submit">Да, пересчитать</button>
      </form>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_invoice">
        <input type="hidden" name="shipment_id" value="<?= (int)$selected['rowid'] ?>">
        <div class="row">
          <div style="flex:1"><label>Номер инвойса</label>
            <input type="text" name="invoice_number" value="<?= htmlspecialchars((string)$selected['invoice_number']) ?>" required></div>
          <div style="flex:1"><label>Сумма, <?= htmlspecialchars(cur_symbol($selected['currency'])) ?></label>
            <input type="number" step="0.01" min="0.01" name="invoice_amount"
                   value="<?= $selected['invoice_amount'] !== null ? htmlspecialchars(number_format((float)$selected['invoice_amount'], 2, '.', '')) : htmlspecialchars(number_format((float)$selected['agreed_amount'], 2, '.', '')) ?>" required></div>
          <div style="flex:0 0 170px"><label>Дата</label>
            <input type="date" name="invoice_date" value="<?= htmlspecialchars($selected['invoice_date'] ?: date('Y-m-d')) ?>"></div>
        </div>
        <button type="submit">Записать инвойс</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Изменить рейс</h2>
    <?php if (!$editable): ?>
      <p class="muted">По рейсу уже прошла оплата — менять его нельзя.</p>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="shipment_id" value="<?= (int)$selected['rowid'] ?>">
        <div class="row">
          <div style="flex:1"><label>Откуда</label>
            <input type="text" name="route_from" value="<?= htmlspecialchars($selected['route_from']) ?>"></div>
          <div style="flex:1"><label>Куда</label>
            <input type="text" name="route_to" value="<?= htmlspecialchars($selected['route_to']) ?>"></div>
        </div>
        <label>Машина</label>
        <select name="truck_type">
          <?php foreach (SHIPMENT_TRUCK_TYPES as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $selected['truck_type'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="row">
          <div style="flex:1"><label>Согласованная цена</label>
            <input type="number" step="0.01" min="0.01" name="agreed_amount"
                   value="<?= htmlspecialchars(number_format((float)$selected['agreed_amount'], 2, '.', '')) ?>" required></div>
          <div style="flex:0 0 130px"><label>Валюта</label>
            <select name="currency" id="curSelEdit">
              <?php foreach (['USD' => 'USD — $', 'EUR' => 'EUR — €', 'RUB' => 'RUB — ₽', 'UZS' => 'UZS — сум'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $selected['currency'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div style="flex:0 0 190px"><label>Курс за 1 $</label>
            <input type="number" step="0.0001" min="0.0001" name="rate"
                   value="<?= $selected['rate'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float)$selected['rate'], 4, '.', ''), '0'), '.')) : '' ?>"></div>
        </div>
        <label>Примечание</label>
        <input type="text" name="comment" value="<?= htmlspecialchars((string)$selected['comment']) ?>">
        <button type="submit">Сохранить</button>
      </form>

      <form method="post" style="margin-top:12px"
            onsubmit="return appConfirmSubmit(this, 'Удалить рейс? Начисленный долг перевозчику будет снят, себестоимость пересчитается.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="shipment_id" value="<?= (int)$selected['rowid'] ?>">
        <button type="submit" class="secondary small">Удалить рейс</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
// Валюта → показывать ли курс. Долг остаётся в валюте договорённости; курс нужен себестоимости.
(function () {
  const cur = document.getElementById('curSel');
  const box = document.getElementById('rateBox');
  const hint = document.getElementById('rateHint');
  const input = document.getElementById('rateInput');
  if (!cur || !box) return;
  function sync() {
    const foreign = cur.value !== 'USD';
    box.style.display = foreign ? '' : 'none';
    if (hint) hint.style.display = foreign ? '' : 'none';
    if (input) input.required = foreign;
  }
  cur.addEventListener('change', sync);
  sync();
})();

// Что едет: партия или один заказ
(function () {
  const type = document.getElementById('scopeType');
  const bBox = document.getElementById('scopeBatch');
  const oBox = document.getElementById('scopeOrder');
  const scopeId = document.getElementById('scopeId');
  if (!type) return;
  const batchSel = bBox.querySelector('select');
  const orderId = document.getElementById('orderId');

  function sync() {
    const isBatch = type.value === 'batch';
    bBox.style.display = isBatch ? '' : 'none';
    oBox.style.display = isBatch ? 'none' : '';
    scopeId.value = isBatch ? (batchSel.value || '') : (orderId.value || '');
  }
  type.addEventListener('change', sync);
  batchSel.addEventListener('change', sync);
  window.wireOrderSearch && window.wireOrderSearch('orderSearch', 'orderResults', function (o) {
    orderId.value = o.id;
    document.getElementById('orderChosen').textContent = 'Выбран заказ ' + (o.ref || ('#' + o.id));
    sync();
  });
  sync();
})();

// Перевозчик
function selectCarrierIntoShipmentForm(c) {
  const idEl = document.getElementById('carrierId');
  const chosenEl = document.getElementById('carrierChosen');
  if (!idEl || !chosenEl) return;          // мы на карточке рейса, формы создания нет
  idEl.value = c.id;
  chosenEl.textContent = 'Выбран: ' + (c.name || ('#' + c.id));
}
window.wireCarrierSearch && window.wireCarrierSearch('carrierSearch', 'carrierResults', selectCarrierIntoShipmentForm);
<?php if ($justCreatedCarrier): ?>
selectCarrierIntoShipmentForm(<?= json_encode($justCreatedCarrier, JSON_UNESCAPED_UNICODE) ?>);
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
