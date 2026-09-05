<?php
/**
 * Отдать браузеру файл, прикреплённый к перевозчику (см. carriers.php "Документы") — зеркало
 * document_download.php, только modulepart='societe' и id вместо ref заказа.
 */
require_once __DIR__ . '/includes/auth.php';

$carrierId = (int)($_GET['carrier_id'] ?? 0);
$filename = basename($_GET['filename'] ?? '');

if (!$carrierId || $filename === '') {
    http_response_code(404);
    die('Файл не найден.');
}

$doc = $api->downloadCarrierDocument($carrierId, $filename);
if (!is_array($doc) || !isset($doc['content'])) {
    http_response_code(404);
    die('Файл не найден: ' . htmlspecialchars($api->lastError));
}

$content = base64_decode($doc['content']);
header('Content-Type: ' . ($doc['content-type'] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($doc['filename'] ?? $filename) . '"');
header('Content-Length: ' . strlen($content));
echo $content;
