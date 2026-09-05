<?php
require_once __DIR__ . '/includes/auth.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $label = $_POST['label'] ?? '';
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $counted = $_POST['counted'] !== '' ? (float)$_POST['counted'] : null;
    $current = (float)($_POST['current'] ?? 0);

    if ($productId && $warehouseId && !in_array($warehouseId, $cfg['warehouse_ids'], false)) {
        // Склад строго из своего направления — иначе можно было бы, подставив чужой warehouse_id,
        // скорректировать остаток на складе другого направления.
        $message = 'Этот склад не относится к вашему направлению.';
        $messageType = 'err';
    } elseif ($productId && $warehouseId && $counted !== null) {
        // K-2 (внешний QA-аудит, раунд 2, 03.09.2026): раньше разница считалась от "current" — снимка,
        // сделанного в момент ОТКРЫТИЯ формы. Пока кассир физически считает товар на складе (это
        // занимает время), реальный остаток в системе мог уже измениться (продажа/приёмка/другой
        // кассир) — а разница всё равно применялась к этому УСТАРЕВШЕМУ числу, а не к живому остатку,
        // из-за чего корректировка либо задваивала расхождение, либо (если посчитанное случайно
        // совпало со старым снимком) молчала "расхождений нет", хотя реальное расхождение было.
        // Правильно: посчитанное кассиром число должно СТАТЬ остатком в системе как есть — читаем
        // живой остаток ПРЯМО СЕЙЧАС и считаем разницу от него, не от значения из формы.
        $freshProduct = $api->getProduct($productId, true);
        $liveCurrent = is_array($freshProduct) ? (float)($freshProduct['stock_warehouse'][$warehouseId]['real'] ?? 0) : null;

        if ($liveCurrent === null) {
            $message = 'Не удалось прочитать текущий остаток — попробуйте ещё раз.';
            $messageType = 'err';
        } else {
            $diff = $counted - $liveCurrent;
            $staleness = (abs($liveCurrent - $current) > 0.0000001)
                ? " (остаток изменился, пока вы считали: было {$current} при открытии формы, стало {$liveCurrent} сейчас)"
                : '';
            if (abs($diff) < 0.0000001) {
                $message = "Расхождений нет, остаток совпадает ({$liveCurrent}).{$staleness}";
                $messageType = 'ok';
            } else {
                $res = $api->createStockMovement([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => $diff, // может быть отрицательным — API сам разберётся по знаку через type
                    'type' => $diff > 0 ? 3 : 2,
                    'label' => "Инвентаризация: было {$liveCurrent}, стало {$counted}",
                ]);
                if ($res !== null) {
                    $message = "Скорректировано: \"{$label}\" было {$liveCurrent}, стало {$counted} (разница " . ($diff > 0 ? '+' : '') . "{$diff}).{$staleness}";
                    $messageType = 'ok';
                    // Корректировка уже реально записана — редирект (POST → GET), чтобы F5 не отправил
                    // эту же форму повторно (счётчик counted остался бы тем же, а живой остаток уже
                    // новый — задвоения корректировки это не создаёт при новом расчёте, но лишний
                    // повторный POST всё равно ни к чему).
                    flash_set($message, $messageType);
                    header('Location: inventory.php');
                    exit;
                } else {
                    $message = 'Ошибка: ' . $api->lastError;
                    $messageType = 'err';
                }
            }
        }
    } else {
        $message = 'Выберите товар, склад и введите фактическое количество.';
        $messageType = 'err';
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

require __DIR__ . '/includes/layout_top.php';
?>

<div class="card">
  <h1>Инвентаризация</h1>
  <p class="muted">Найдите товар, выберите склад, введите фактически посчитанное количество — система сама посчитает и запишет разницу.</p>
  <?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

  <div id="categoryTiles" class="cat-tiles"></div>
  <div id="categoryBackRow" style="display:none">
    <button type="button" id="btnBackToCats" class="secondary">← Все категории</button>
    <strong id="currentCatLabel"></strong>
  </div>
  <input type="text" id="productSearch" placeholder="Поиск товара по названию или артикулу...">
  <div id="productResults"></div>
</div>

<div class="card" id="countForm" style="display:none">
  <h2 id="countProductLabel"></h2>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="product_id" id="f_product_id">
    <input type="hidden" name="label" id="f_label">
    <label>Склад</label>
    <select name="warehouse_id" id="f_warehouse_id"></select>
    <label>Текущий остаток в системе</label>
    <input type="text" id="f_current_display" disabled>
    <input type="hidden" name="current" id="f_current">
    <label>Фактически посчитано</label>
    <input type="number" step="any" name="counted" id="f_counted" required>
    <button type="submit">Записать корректировку</button>
  </form>
</div>

<script>
window.CATEGORIES = <?= json_encode($cfg['categories'], JSON_UNESCAPED_UNICODE) ?>;
const warehouseIds = <?= json_encode($cfg['warehouse_ids']) ?>;
const warehouseLabels = <?= json_encode($cfg['warehouse_labels'], JSON_UNESCAPED_UNICODE) ?>;
// UX-K3 (внешний отчёт, 02.09.2026): раньше склад по умолчанию в этой форме — первый в массиве
// warehouse_ids (у Жоми это "01"), а не склад направления по умолчанию (04) — легко скорректировать
// не тот склад, если не переключить вручную.
const defaultWarehouseId = <?= json_encode($cfg['default_warehouse_id']) ?>;

window.onProductPick = function (p) {
  document.getElementById('countForm').style.display = 'block';
  document.getElementById('countProductLabel').textContent = p.label;
  document.getElementById('f_product_id').value = p.id;
  document.getElementById('f_label').value = p.label;
  const sel = document.getElementById('f_warehouse_id');
  sel.innerHTML = '';
  warehouseIds.forEach(id => {
    const opt = document.createElement('option');
    opt.value = id;
    opt.textContent = warehouseLabels[id];
    sel.appendChild(opt);
  });
  sel.value = defaultWarehouseId;
  const updateCurrent = () => {
    const whId = sel.value;
    const qty = (p.stock_by_warehouse && p.stock_by_warehouse[whId] !== undefined) ? p.stock_by_warehouse[whId] : 0;
    document.getElementById('f_current_display').value = qty;
    document.getElementById('f_current').value = qty;
  };
  sel.onchange = updateCurrent;
  updateCurrent();
  document.getElementById('f_counted').value = '';
  document.getElementById('f_counted').focus();
  window.scrollTo({top: document.getElementById('countForm').offsetTop, behavior: 'smooth'});
};
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
