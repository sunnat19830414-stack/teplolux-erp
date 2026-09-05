<?php
/**
 * Поиск товара для заявки. Два ограничения сразу:
 *  - направление: Суннатилла видит только товары Турк (по доп.полю kod_sap, не по ref);
 *  - поставщик: если он выбран в заявке, показываем только товары, связанные с ним (по просьбе
 *    пользователя — шеф сначала выбирает поставщика и видит его товары).
 * Остаток показываем сразу: первый вопрос перед закупкой — сколько уже есть и сколько едет.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stock_lookup.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
$supplierId = (int)($_GET['supplier_id'] ?? 0);

$rows = $api->searchProducts($term, visible_directions($cfg), $supplierId, 60);
$ids = array_map(fn($p) => (int)$p['id'], $rows);
$incoming = get_incoming_qty_bulk($ids);

$out = [];
foreach ($rows as $p) {
    $id = (int)$p['id'];
    $out[] = [
        'id' => $id,
        'ref' => $p['ref'] ?? '',
        'label' => $p['label'] ?? '',
        'stock' => (float)($p['stock_reel'] ?? 0),
        'incoming' => (float)($incoming[$id] ?? 0),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
