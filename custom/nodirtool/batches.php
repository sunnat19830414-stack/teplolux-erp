<?php
/**
 * Партии (группы заказов поставщику, которые ехали одной машиной) + логистические расходы на
 * уровне партии целиком. Расходы на ОДИН конкретный заказ (например сертификат, который не
 * относится ко всей партии) вводятся на самой странице заказа (order_view.php).
 * См. CLAUDE.md 29.08.2026 "себестоимость товара".
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logistics.php';

if (!array_key_exists('selected_batch', $_SESSION)) $_SESSION['selected_batch'] = null;
reset_selection_unless_preserved('selected_batch');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_batch') {
        $label = trim($_POST['label'] ?? '');
        $batchId = logistics_create_batch($label);
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
        $message = 'Партия создана.';
        $messageType = 'ok';
    } elseif ($action === 'select_batch') {
        $_SESSION['selected_batch'] = (int)($_POST['batch_id'] ?? 0);
    } elseif ($action === 'clear_batch') {
        $_SESSION['selected_batch'] = null;
    } elseif ($action === 'add_order') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($batchId && $orderId) {
            $r = logistics_add_order_to_batch($batchId, $orderId);
            $message = $r['ok'] ? 'Заказ добавлен в партию.' : $r['error'];
            $messageType = $r['ok'] ? 'ok' : 'err';
        }
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
    } elseif ($action === 'remove_order') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);
        logistics_remove_order_from_batch($batchId, $orderId);
        $message = 'Заказ убран из партии.';
        $messageType = 'ok';
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
    } elseif ($action === 'close_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        logistics_close_batch($batchId, true);
        $message = 'Партия закрыта.';
        $messageType = 'ok';
    } elseif ($action === 'reopen_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        logistics_close_batch($batchId, false);
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
    } elseif ($action === 'add_expense') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $expenseType = $_POST['expense_type'] ?? '';
        $mode = $_POST['amount_mode'] ?? 'usd';
        $comment = trim($_POST['comment'] ?? '');
        $who = $_SESSION['user']['name'] ?? '';
        // Перевозчик (необязательно, топ-5 пункт 3) — если выбран, деньги не списываются сразу, это
        // становится долгом перевозчику (гасится отдельно в разделе "Перевозчики").
        $carrierId = (int)($_POST['carrier_id'] ?? 0) ?: null;

        if ($mode === 'usd') {
            $amount = (float)($_POST['usd_amount'] ?? 0);
            $r = logistics_record_expense('batch', $batchId, $expenseType, $amount, 'USD', null, (int)$cfg['currency_accounts']['USD'], $who, $comment, $carrierId);
        } else {
            $uzsAmount = (float)($_POST['uzs_amount'] ?? 0);
            $rate = (float)($_POST['rate'] ?? 0);
            $r = logistics_record_expense('batch', $batchId, $expenseType, $uzsAmount, 'UZS', $rate, (int)$cfg['uzs_account_id'], $who, $comment, $carrierId);
        }

        if (!($r['ok'] ?? false)) {
            $message = $r['error'] ?? 'Ошибка сохранения расхода.';
            $messageType = 'err';
        } else {
            $n = count($r['affected_products'] ?? []);
            $message = ($r['overdraft_warning'] ?? '') . "Расход внесён" . (isset($r['usd_amount']) ? " ({$r['usd_amount']} \$)" : '') . ". Себестоимость пересчитана для {$n} товаров." . (!empty($r['note']) ? ' ' . $r['note'] : '');
            $messageType = !empty($r['overdraft_warning']) ? 'warn' : 'ok';
        }
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
        // Если расход реально записан (деньги списаны) — редирект (POST → GET), чтобы F5 не отправил
        // эту же форму повторно и не списал сумму ещё раз. Если была ошибка — тоже редиректим (тут
        // резотправка всё равно ничего не портит, но так же убирает браузерное "отправить повторно?").
        flash_set($message, $messageType);
        header('Location: batches.php');
        exit;
    } elseif ($action === 'delete_expense') {
        // Единственный способ исправить неверно введённый расход (см. CLAUDE.md 02.09.2026,
        // отчёт аудита 4.3.1) — удалить и внести заново, не редактирование на месте.
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $r = logistics_delete_expense($expenseId);
        $message = $r['ok'] ? ('Расход удалён. ' . ($r['note'] ?? '')) : ($r['error'] ?? 'Ошибка удаления.');
        $messageType = $r['ok'] ? 'ok' : 'err';
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
        flash_set($message, $messageType);
        header('Location: batches.php');
        exit;
    } elseif ($action === 'reset_recompute') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        $r = logistics_reset_and_recompute('batch', $batchId);
        $n = count($r['affected_products'] ?? []);
        $message = "Пересчитано заново для {$n} товаров. " . ($r['note'] ?? '');
        $messageType = strpos($r['note'] ?? '', 'ВНИМАНИЕ') !== false ? 'warn' : 'ok';
        $_SESSION['selected_batch'] = $batchId;
        $_SESSION['_preserve_once']['selected_batch'] = true;
        flash_set($message, $messageType);
        header('Location: batches.php');
        exit;
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

// UX-N2 (внешний отчёт, 02.09.2026) — "+ Новый перевозчик" прямо из формы расхода: одноразовый маркер
// от carrier_form.php, читаем и сразу чистим (не 'preserve_once' — это не "сохранённый выбор", а
// разовое "только что создали, подставь в пикер"), дальше просто передаём в JS для автозаполнения.
$justCreatedCarrier = $_SESSION['new_carrier_for_expense'] ?? null;
unset($_SESSION['new_carrier_for_expense']);

$batches = logistics_get_batches(false);
$selectedBatch = !empty($_SESSION['selected_batch']) ? logistics_get_batch((int)$_SESSION['selected_batch']) : null;

$orderRefs = [];
$expenses = [];
if ($selectedBatch) {
    foreach ($selectedBatch['order_ids'] as $oid) {
        $o = $api->getSupplierOrder($oid);
        if (is_array($o)) {
            $soc = $api->getThirdparty((int)($o['socid'] ?? 0));
            $orderRefs[$oid] = [
                'ref' => $o['ref'] ?? "#$oid",
                'supplier' => is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '',
                'total_ttc' => (float)($o['total_ttc'] ?? 0),
            ];
        }
    }
    $expenses = logistics_get_expenses('batch', $selectedBatch['rowid']);
}
// Имена перевозчиков для уже внесённых расходов — одним запросом (не в цикле, см. отчёт аудита P0#5).
$carrierIdsInExpenses = array_values(array_unique(array_filter(array_map(fn($e) => (int)($e['fk_carrier'] ?? 0), $expenses))));
$carrierNamesById = $carrierIdsInExpenses ? $api->getThirdpartiesByIds($carrierIdsInExpenses) : [];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Партии / Логистика</h1>
<?php if ($selectedBatch): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_batch">
    <button type="submit" class="secondary">← Все партии</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<?php if (!$selectedBatch): ?>

<div class="card">
  <h2>Новая партия</h2>
  <p class="muted">Партия — группа заказов (даже от разных поставщиков), которые едут одной машиной. Не обязательна — если машина везёт один заказ, расходы вносите прямо на странице заказа.</p>
  <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_batch">
    <div><label>Название (необязательно)</label><input type="text" name="label" placeholder="например: Машина от 29.08"></div>
    <div style="flex:0"><button type="submit">Создать партию</button></div>
  </form>
</div>

<div class="card">
  <h2>Открытые партии</h2>
  <?php if (empty($batches)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <div class="debtor-grid">
      <?php foreach ($batches as $b): ?>
        <form method="post" class="debtor-block">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="select_batch">
          <input type="hidden" name="batch_id" value="<?= (int)$b['rowid'] ?>">
          <button type="submit" class="debtor-block-btn">
            <span class="debtor-block-name"><?= htmlspecialchars($b['label']) ?></span>
            <span class="muted"><?= htmlspecialchars(substr($b['datec'], 0, 16)) ?></span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php else: ?>

<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <h2 style="margin-bottom:2px"><?= htmlspecialchars($selectedBatch['label']) ?></h2>
      <div class="muted">Создана <?= htmlspecialchars(substr($selectedBatch['datec'], 0, 16)) ?> · <?= $selectedBatch['status'] == 1 ? 'закрыта' : 'открыта' ?></div>
    </div>
    <form method="post" style="flex:0">
  <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= (int)$selectedBatch['rowid'] ?>">
      <?php if ($selectedBatch['status'] == 1): ?>
        <input type="hidden" name="action" value="reopen_batch">
        <button type="submit" class="secondary">Открыть заново</button>
      <?php else: ?>
        <input type="hidden" name="action" value="close_batch">
        <button type="submit" class="secondary">Закрыть партию</button>
      <?php endif; ?>
    </form>
  </div>
  <form method="post" style="margin-top:10px" onsubmit="return appConfirmSubmit(this, 'Пересчитать себестоимость этой партии с нуля? Базовая точка (остаток до поставки) будет определена заново из ТЕКУЩЕГО остатка склада — если часть товара уже продана, результат будет приблизительным.');">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_recompute">
    <input type="hidden" name="batch_id" value="<?= (int)$selectedBatch['rowid'] ?>">
    <button type="submit" class="secondary small">🔄 Пересчитать себестоимость с нуля</button>
  </form>
</div>

<div class="card">
  <h2>Заказы в партии</h2>
  <?php if (empty($orderRefs)): ?>
    <p class="muted">Пока ни одного заказа не добавлено.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Поставщик</th><th>Сумма</th><th></th></tr>
      <?php foreach ($orderRefs as $oid => $o): ?>
        <tr>
          <td><a href="order_view.php?id=<?= $oid ?>"><?= htmlspecialchars($o['ref']) ?></a></td>
          <td><?= htmlspecialchars($o['supplier']) ?></td>
          <td><?= number_format($o['total_ttc'], 2) ?> $</td>
          <td>
            <form method="post" onsubmit="return appConfirmSubmit(this, 'Убрать этот заказ из партии?');">
  <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_order">
              <input type="hidden" name="batch_id" value="<?= (int)$selectedBatch['rowid'] ?>">
              <input type="hidden" name="order_id" value="<?= $oid ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <h2 style="margin-top:18px">Добавить заказ в партию</h2>
  <input type="text" id="orderSearch" placeholder="Поиск заказа по номеру...">
  <div id="orderResults" class="result-list"></div>
</div>

<div class="card">
  <h2>Внести расход (на всю партию)</h2>
  <p class="muted">Расходы, которые относятся только к ОДНОМУ заказу из этой партии (например сертификат не на все товары) — вносите на странице конкретного заказа, не здесь.</p>
  <form method="post" id="expenseForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_expense">
    <input type="hidden" name="batch_id" value="<?= (int)$selectedBatch['rowid'] ?>">
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
        <select name="amount_mode" id="amountMode" onchange="document.getElementById('usdBlock').style.display=this.value=='usd'?'':'none'; document.getElementById('uzsBlock').style.display=this.value=='uzs'?'':'none';">
          <option value="usd">$ напрямую</option>
          <option value="uzs">сумах (+ курс)</option>
        </select>
      </div>
    </div>
    <div class="row" id="usdBlock">
      <div><label>Сумма, $</label><input type="number" step="0.01" min="0.01" name="usd_amount"></div>
    </div>
    <div class="row" id="uzsBlock" style="display:none">
      <div><label>Сумма, сум</label><input type="number" step="1" min="1" name="uzs_amount"></div>
      <div><label>Курс (сум за 1$)</label><input type="number" step="0.01" min="0.01" name="rate"></div>
    </div>
    <div>
      <label>Перевозчик (необязательно — если выбран, деньги СЕЙЧАС не списываются, это становится долгом, оплатите его в разделе «Перевозчики»)</label>
      <input type="hidden" name="carrier_id" id="expCarrierId">
      <div id="expCarrierChosen" style="display:none; padding:8px 10px; border:1px solid var(--border); border-radius:8px; margin-bottom:8px">
        <span id="expCarrierName"></span>
        <button type="button" class="secondary small" onclick="document.getElementById('expCarrierId').value='';document.getElementById('expCarrierChosen').style.display='none';document.getElementById('expCarrierSearchWrap').style.display='';">✕ убрать</button>
      </div>
      <div id="expCarrierSearchWrap">
        <input type="text" id="expCarrierSearch" placeholder="Платите сразу — оставьте пустым. Иначе начните печатать название перевозчика...">
        <div id="expCarrierResults" class="result-list"></div>
        <p class="muted" style="margin:4px 0 0"><a href="carrier_form.php?ctx=batch_expense">+ Новый перевозчик</a></p>
      </div>
    </div>
    <div><label>Комментарий (необязательно)</label><input type="text" name="comment"></div>
    <button type="submit">Сохранить расход</button>
  </form>
</div>

<div class="card">
  <h2>Внесённые расходы по этой партии</h2>
  <?php if (empty($expenses)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <table>
      <tr><th>Вид</th><th>Сумма</th><th>$</th><th>Перевозчик</th><th>Кто/когда</th><th>Комментарий</th><th></th></tr>
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
              <input type="hidden" name="batch_id" value="<?= (int)$selectedBatch['rowid'] ?>">
              <input type="hidden" name="expense_id" value="<?= (int)$e['rowid'] ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<script src="assets/picker.js"></script>
<script>
function selectCarrierIntoExpenseForm(c) {
  const idInput = document.getElementById('expCarrierId');
  if (!idInput) return; // форма расхода не отрисована (партия не выбрана) — защита от JS-ошибки
  idInput.value = c.id;
  document.getElementById('expCarrierName').textContent = c.name;
  document.getElementById('expCarrierChosen').style.display = '';
  document.getElementById('expCarrierSearchWrap').style.display = 'none';
}
window.wireCarrierSearch && window.wireCarrierSearch('expCarrierSearch', 'expCarrierResults', selectCarrierIntoExpenseForm);
<?php if ($justCreatedCarrier): ?>
// UX-N2: только что создали перевозчика через "+ Новый перевозчик" — сразу подставляем в пикер.
selectCarrierIntoExpenseForm({ id: <?= (int)$justCreatedCarrier['id'] ?>, name: <?= json_encode($justCreatedCarrier['name'], JSON_UNESCAPED_UNICODE) ?> });
<?php endif; ?>
window.wireOrderSearch && window.wireOrderSearch('orderSearch', 'orderResults', function (o) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="add_order">' +
    '<input type="hidden" name="batch_id" value="<?= (int)$selectedBatch['rowid'] ?>">' +
    '<input type="hidden" name="order_id" value="' + o.id + '">';
  document.body.appendChild(form);
  form.submit();
});
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
