<?php
require_once __DIR__ . '/includes/auth.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $label = $_POST['label'] ?? '';
    $qty = (float)($_POST['qty'] ?? 0);
    $fromWh = (int)($_POST['from_warehouse'] ?? 0);
    $toWh = (int)($_POST['to_warehouse'] ?? 0);

    if (!$productId || $qty <= 0) {
        $message = 'Выберите товар и укажите количество больше нуля.';
        $messageType = 'err';
    } elseif (!in_array($fromWh, $cfg['warehouse_ids'], false) || !in_array($toWh, $cfg['warehouse_ids'], false)) {
        // Склады строго из своего направления — иначе можно было бы, подставив чужой warehouse_id,
        // подвинуть остатки на складе другого направления (Жоми <-> Турк).
        $message = 'Один из складов не относится к вашему направлению.';
        $messageType = 'err';
    } elseif ($fromWh === $toWh) {
        $message = 'Склад отправления и склад назначения не должны совпадать.';
        $messageType = 'err';
    } else {
        $ok = $api->transferStock($productId, $fromWh, $toWh, $qty, 'Перемещение между складами');
        if ($ok) {
            $fromLabel = $cfg['warehouse_labels'][$fromWh] ?? $fromWh;
            $toLabel = $cfg['warehouse_labels'][$toWh] ?? $toWh;
            $message = "Перемещено: {$qty} шт. \"{$label}\" со склада \"{$fromLabel}\" на \"{$toLabel}\".";
            $messageType = 'ok';
            // Движение уже реально записано — редирект (POST → GET), чтобы F5 не отправил эту же форму
            // повторно и не переместил тот же товар ещё раз.
            flash_set($message, $messageType);
            header('Location: transfer.php');
            exit;
        } else {
            $message = 'Ошибка перемещения: ' . $api->lastError;
            $messageType = 'err';
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Перемещение между складами</h1>
<p class="muted">Найдите товар, укажите откуда и куда переместить, введите количество.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <div id="categoryTiles" class="cat-tiles"></div>
  <div id="categoryBackRow" style="display:none">
    <button type="button" id="btnBackToCats" class="secondary">← Все категории</button>
    <strong id="currentCatLabel"></strong>
  </div>
  <input type="text" id="productSearch" placeholder="Поиск товара по названию или артикулу...">
  <div id="productResults" class="result-list"></div>
</div>

<div class="card" id="transferForm" style="display:none">
  <h2 id="transferProductLabel"></h2>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="product_id" id="f_product_id">
    <input type="hidden" name="label" id="f_label">
    <div class="row">
      <div>
        <label>Откуда (склад)</label>
        <select name="from_warehouse" id="f_from"></select>
      </div>
      <div>
        <label>Куда (склад)</label>
        <select name="to_warehouse" id="f_to"></select>
      </div>
    </div>
    <label>Количество</label>
    <input type="number" step="any" min="0.001" name="qty" id="f_qty" required>
    <button type="submit">Переместить</button>
  </form>
</div>

<script>
window.CATEGORIES = <?= json_encode($cfg['categories'], JSON_UNESCAPED_UNICODE) ?>;
const warehouseIds = <?= json_encode($cfg['warehouse_ids']) ?>;
const warehouseLabels = <?= json_encode($cfg['warehouse_labels'], JSON_UNESCAPED_UNICODE) ?>;
const defaultWarehouse = <?= (int)$cfg['default_warehouse_id'] ?>;

window.onProductPick = function (p) {
  document.getElementById('transferForm').style.display = 'block';
  document.getElementById('transferProductLabel').textContent = p.label;
  document.getElementById('f_product_id').value = p.id;
  document.getElementById('f_label').value = p.label;

  const fillSelect = (sel, stockHints) => {
    sel.innerHTML = '';
    warehouseIds.forEach(id => {
      const opt = document.createElement('option');
      opt.value = id;
      const stock = (p.stock_by_warehouse && p.stock_by_warehouse[id] !== undefined) ? p.stock_by_warehouse[id] : 0;
      opt.textContent = warehouseLabels[id] + (stockHints ? ' · ост. ' + stock : '');
      sel.appendChild(opt);
    });
  };
  const fromSel = document.getElementById('f_from');
  const toSel = document.getElementById('f_to');
  fillSelect(fromSel, true);
  fillSelect(toSel, false);
  fromSel.value = defaultWarehouse;
  // выбрать "куда" по умолчанию — первый склад, отличный от "откуда"
  toSel.value = warehouseIds.find(id => id != defaultWarehouse) || defaultWarehouse;

  document.getElementById('f_qty').value = '';
  document.getElementById('f_qty').focus();
  window.scrollTo({top: document.getElementById('transferForm').offsetTop, behavior: 'smooth'});
};
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
