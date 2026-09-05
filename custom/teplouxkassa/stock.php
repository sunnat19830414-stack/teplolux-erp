<?php
require_once __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="card">
  <h1>Остатки на складе</h1>
  <div id="categoryTiles" class="cat-tiles"></div>
  <div id="categoryBackRow" style="display:none">
    <button type="button" id="btnBackToCats" class="secondary">← Все категории</button>
    <strong id="currentCatLabel"></strong>
  </div>
  <input type="text" id="productSearch" placeholder="Поиск товара по названию или артикулу...">
  <div id="productResults"></div>
</div>

<script>
window.CATEGORIES = <?= json_encode($cfg['categories'], JSON_UNESCAPED_UNICODE) ?>;
const warehouseLabels = <?= json_encode($cfg['warehouse_labels'], JSON_UNESCAPED_UNICODE) ?>;

window.onProductPick = function (p) { /* только просмотр, действие не требуется */ };

window.renderProductResult = function (p) {
  const rows = Object.keys(warehouseLabels).map(id =>
    '<tr><td>' + warehouseLabels[id] + '</td><td>' + (p.stock_by_warehouse[id] ?? 0) + '</td></tr>'
  ).join('');
  return '<strong>' + p.label + '</strong><br><span class="muted">' + p.ref + ' · цена: ' + p.price.toFixed(2) + ' $ · всего: ' + p.stock + '</span>' +
    '<table style="margin-top:8px">' + rows + '</table>';
};
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
