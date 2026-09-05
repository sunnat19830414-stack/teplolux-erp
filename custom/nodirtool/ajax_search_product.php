<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_lookup.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
if ($term === '') { echo '[]'; exit; }

// Чья цена нас интересует — явно передан supplier_id (например, из уже открытого заказа), или,
// по умолчанию, поставщик, выбранный в разделе "Заказы поставщику" (сессия).
$supplierId = (int)($_GET['supplier_id'] ?? ($_SESSION['po_supplier']['id'] ?? 0));

$rows = $api->searchProducts($term, 50);

// 04.09.2026 (B5 + производительность): раньше на КАЖДЫЙ найденный товар шёл отдельный вызов
// getPurchasePriceForSupplier() — до 50 запросов к API на одно нажатие клавиши. Теперь цены и
// «сколько уже едет» берутся батчем (см. includes/product_lookup.php), а остаток приезжает
// в самом ответе поиска (`includestockdata=1` запрашивался и раньше, но молча выбрасывался).
$ids = array_map(fn($p) => (int)$p['id'], (array)$rows);
$prices = get_purchase_prices_bulk($ids, $supplierId);
$incoming = get_incoming_qty_bulk($ids);

$out = [];
foreach ($rows as $p) {
    $id = (int)$p['id'];
    $info = $prices[$id] ?? null;
    $out[] = [
        'id' => $id,
        'ref' => $p['ref'] ?? '',
        'label' => $p['label'] ?? '',
        'price' => (float)($p['price'] ?? 0),
        'supplier_price' => $info === null ? null : $info['price'],
        'supplier_currency' => $info === null ? '' : $info['currency'],
        'supplier_native_price' => $info === null ? null : $info['native'],
        'stock' => (float)($p['stock_reel'] ?? 0),
        'incoming' => (float)($incoming[$id] ?? 0),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
