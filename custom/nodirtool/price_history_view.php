<?php
/**
 * Просмотр истории закупочных цен товара у поставщика (см. includes/price_history.php) — что цена
 * менялась молча при каждом заказе, это уже принятое решение; здесь можно посмотреть, что было
 * раньше, если новое значение окажется испорченной опечаткой.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/price_history.php';

$productId = (int)($_GET['product_id'] ?? 0);
$supplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int)$_GET['supplier_id'] : null;

$product = $productId ? $api->getProduct($productId, false) : null;
$supplier = $supplierId ? $api->getThirdparty($supplierId) : null;
$rows = $productId ? get_price_history($productId, $supplierId) : [];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>История закупочных цен</h1>
<p class="muted">
  Товар: <strong><?= htmlspecialchars(is_array($product) ? ($product['label'] ?? $product['ref'] ?? "#$productId") : "#$productId") ?></strong>
  <?php if ($supplier): ?> · Поставщик: <strong><?= htmlspecialchars($supplier['name'] ?? $supplier['nom'] ?? '') ?></strong><?php endif; ?>
</p>
<p><a href="javascript:history.back()" class="btn secondary">← Назад</a></p>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="muted">Изменений цены ещё не записано.</p>
  <?php else: ?>
    <table>
      <tr><th>Когда</th><th>Было</th><th>Стало</th><th>Кто</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr($r['datec'], 0, 16)) ?></td>
          <td><?= $r['old_price'] !== null ? number_format((float)$r['old_price'], 2) . ' $' : '<span class="muted">не было</span>' ?></td>
          <td><strong><?= number_format((float)$r['new_price'], 2) ?> $</strong></td>
          <td class="muted"><?= htmlspecialchars($r['changed_by'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
