<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category_id'] ?? 0);

if ($term === '' && $categoryId === 0) {
    echo json_encode([]);
    exit;
}

$products = $api->searchProducts($term, $cfg['ref_prefix'], $categoryId, 50);
if (!is_array($products)) {
    echo json_encode(['error' => $api->lastError]);
    exit;
}

$out = [];
foreach ($products as $p) {
    $stockTotal = 0;
    $byWarehouse = [];
    foreach ($cfg['warehouse_ids'] as $whId) {
        $byWarehouse[$whId] = 0;
    }
    if (!empty($p['stock_warehouse']) && is_array($p['stock_warehouse'])) {
        foreach ($p['stock_warehouse'] as $whId => $whData) {
            $whId = (int)$whId;
            if (in_array($whId, $cfg['warehouse_ids'], true)) {
                $qty = (float)($whData['real'] ?? 0);
                $byWarehouse[$whId] = $qty;
                $stockTotal += $qty;
            }
        }
    }
    $out[] = [
        'id' => $p['id'],
        'ref' => $p['ref'],
        'label' => $p['label'],
        'price' => (float)($p['price'] ?? 0),
        'stock' => $stockTotal,
        'stock_by_warehouse' => $byWarehouse,
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
