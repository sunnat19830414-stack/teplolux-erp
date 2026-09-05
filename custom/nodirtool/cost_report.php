<?php
/**
 * Себестоимость по товарам (топ-5-b/(d), 02.09.2026, отчёт аудита 4.3.4) — список ВСЕХ поставок, для
 * которых хоть раз считали landed-цену (llx_supplier_landed_result — снимок последнего пересчёта,
 * отдельно от live `llx_product.pmp`, который смешивает все поставки товара за всё время в одно число).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logistics.php';

$rows = logistics_get_landed_report();

// Имена товаров — по одному getProduct() на уникальный id (масштаб отчёта небольшой, см. интервью).
$productIds = array_values(array_unique(array_map(fn($r) => (int)$r['fk_product'], $rows)));
$productLabel = [];
$productPmp = []; // BUG-N3 (внешний отчёт, 02.09.2026) — см. пояснение у заголовка колонки ниже
foreach ($productIds as $pid) {
    $p = $api->getProduct($pid);
    $productLabel[$pid] = is_array($p) ? trim(($p['ref'] ?? "#$pid") . ' — ' . ($p['label'] ?? '')) : "#$pid";
    $productPmp[$pid] = is_array($p) ? (float)($p['pmp'] ?? 0) : 0.0;
}

// Ярлык поставки для заказов ВНЕ партии (scope_type='order') — номер заказа + поставщик.
$orderLabel = [];
foreach ($rows as $r) {
    if ($r['scope_type'] === 'order' && !isset($orderLabel[$r['scope_id']])) {
        $o = $api->getSupplierOrder((int)$r['scope_id']);
        $supplierName = '';
        if (is_array($o) && !empty($o['socid'])) {
            $soc = $api->getThirdparty((int)$o['socid']);
            $supplierName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';
        }
        $orderLabel[$r['scope_id']] = (is_array($o) ? ($o['ref'] ?? "#{$r['scope_id']}") : "#{$r['scope_id']}") . ($supplierName ? " ({$supplierName})" : '');
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<?php // K-4 / N-2 (внешняя приёмка, 03.09.2026): из текста убраны слова разработчиков — «pmp»,
      // «landed», «Dolibarr». Пояснение то же самое, но словами склада. ?>
<h1>Себестоимость по товарам</h1>
<p class="muted">Каждая строка — товар из конкретной поставки (партии или отдельного заказа), с последним
посчитанным результатом. Одна и та же поставка обновляет свою строку при каждом пересчёте.</p>
<p class="muted">⚠️ <strong>Смотреть нужно колонку «Себестоимость»</strong> — это цена товара из ЭТОЙ
поставки, вместе с её фрахтом, таможней и остальными расходами. Соседняя колонка «Средняя по всем
поставкам» — это цена, усреднённая по всему остатку товара на складе: если часть остатка приехала
раньше, по другой цене, расходы этой поставки в ней «размазаны» и на неё тоже. Для назначения цены
берите первую колонку, не вторую.</p>
<style>
  /* N-1: подсветка неполной поставки — себестоимость посчитана от заказанного количества */
  .warn-cell{background:var(--warn-bg,#fef3c7); color:var(--warn,#b45309); font-weight:600;}
</style>

<div class="card">
  <input type="text" id="reportFilter" placeholder="Фильтр по названию/артикулу товара...">
</div>

<div class="card">
  <div class="row" style="margin-bottom:10px">
    <div style="flex:0"><a href="cost_report_excel.php" class="btn secondary">📄 Скачать в Excel</a></div>
  </div>
  <?php if (empty($rows)): ?>
    <p class="muted">Пока ни одной поставки с посчитанной себестоимостью нет.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table id="reportTable">
      <tr>
        <th>Товар</th><th>Поставка</th><th>Заказано</th><th>Принято</th><th>Цена поставщика, $/шт</th>
        <th>Логистика всей поставки</th><th>Себестоимость, $/шт</th>
        <th title="Средняя цена товара по всем поставкам сразу — может отличаться от себестоимости этой конкретной поставки">Средняя по всем поставкам</th>
        <th>Посчитано</th>
      </tr>
      <?php foreach ($rows as $r): ?>
        <?php
          $pid = (int)$r['fk_product'];
          $scopeLabel = $r['scope_type'] === 'batch'
            ? '📦 Партия «' . ($r['scope_label'] ?? ('#' . $r['scope_id'])) . '»'
            : '📄 Заказ ' . ($orderLabel[$r['scope_id']] ?? ('#' . $r['scope_id']));
          $expenseParts = [];
          foreach ($r['expenses'] as $type => $amt) {
            if ($amt > 0.01) $expenseParts[] = (LOGISTICS_EXPENSE_TYPES[$type] ?? $type) . ': ' . number_format($amt, 2) . '$';
          }
        ?>
        <tr data-product-name="<?= htmlspecialchars(mb_strtolower($productLabel[$pid] ?? '')) ?>">
          <td><?= htmlspecialchars($productLabel[$pid] ?? "#$pid") ?></td>
          <td class="muted"><?= htmlspecialchars($scopeLabel) ?></td>
          <?php $fmtQty = fn($n) => rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); ?>
          <td><?= $fmtQty($r['qty']) ?></td>
          <?php // N-1 (внешняя приёмка, 03.09.2026): при недопоставке себестоимость посчитана от
                // ЗАКАЗАННОГО (осознанное решение), но это должно быть видно — иначе цифру примут
                // за точную и назначат цену ниже реальной. ?>
          <td<?= !empty($r['is_partial']) ? ' class="warn-cell"' : '' ?>>
            <?= $fmtQty($r['received_qty'] ?? 0) ?>
            <?php if (!empty($r['is_partial'])): ?>
              <div class="muted" style="font-size:12px">поставка неполная</div>
            <?php endif; ?>
          </td>
          <td><?= number_format((float)$r['raw_price_per_unit'], 4) ?></td>
          <td class="muted"><?php // UX-N3 (внешний отчёт, 02.09.2026): раньше все виды расхода были в
                                   // одну длинную строку через запятую — при 3+ видах не читалось.
                                   // Теперь каждый вид на своей строке. ?>
            <?= $expenseParts ? implode('<br>', array_map('htmlspecialchars', $expenseParts)) : '—' ?>
          </td>
          <td>
            <strong><?= number_format((float)$r['landed_cost_per_unit'], 4) ?></strong>
            <?php if (!empty($r['is_partial'])): ?>
              <div class="muted" style="font-size:12px">занижена: часть<br>расходов на непривезённое</div>
            <?php endif; ?>
          </td>
          <td class="muted"><?= number_format($productPmp[$pid] ?? 0, 4) ?></td>
          <td class="muted"><?= htmlspecialchars(substr($r['computed_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="muted" id="reportEmptyFilter" style="display:none">По фильтру ничего не найдено.</p>
  <?php endif; ?>
</div>

<script>
(function () {
  const input = document.getElementById('reportFilter');
  if (!input) return;
  const table = document.getElementById('reportTable');
  const emptyMsg = document.getElementById('reportEmptyFilter');
  input.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let shown = 0;
    table.querySelectorAll('tr[data-product-name]').forEach(function (row) {
      const match = row.dataset.productName.includes(q);
      row.style.display = match ? '' : 'none';
      if (match) shown++;
    });
    emptyMsg.style.display = (q && shown === 0) ? '' : 'none';
  });
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
