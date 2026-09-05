<?php
/**
 * Отдать браузеру файл, прикреплённый к заказу поставщику (см. order_view.php "Документы") — сам
 * файл лежит в Dolibarr, REST API отдаёт его как base64 в JSON, здесь просто перекладываем в обычный
 * HTTP-ответ с нужными заголовками.
 */
require_once __DIR__ . '/includes/auth.php';

$orderId = (int)($_GET['order_id'] ?? 0);
$filename = basename($_GET['filename'] ?? ''); // basename — на случай попытки выйти за пределы папки заказа

$order = $orderId ? $api->getSupplierOrder($orderId) : null;
if (!is_array($order) || empty($order['ref']) || $filename === '') {
    http_response_code(404);
    die('Файл не найден.');
}

$doc = $api->downloadOrderDocument($order['ref'], $filename);
if (!is_array($doc) || !isset($doc['content'])) {
    http_response_code(404);
    die('Файл не найден: ' . htmlspecialchars($api->lastError));
}

$content = base64_decode($doc['content']);
header('Content-Type: ' . ($doc['content-type'] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($doc['filename'] ?? $filename) . '"');
header('Content-Length: ' . strlen($content));
echo $content;
