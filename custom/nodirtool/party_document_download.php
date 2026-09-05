<?php
/**
 * Отдать браузеру файл, прикреплённый к контрагенту (поставщику или перевозчику — в Dolibarr это
 * одна и та же сущность `societe`). Зеркало carrier_document_download.php, но с нейтральным
 * параметром party_id — используется карточкой поставщика (04.09.2026, пункт B3 отчёта).
 */
require_once __DIR__ . '/includes/auth.php';

$partyId = (int)($_GET['party_id'] ?? 0);
$filename = basename($_GET['filename'] ?? '');

if (!$partyId || $filename === '') {
    http_response_code(404);
    die('Файл не найден.');
}

$doc = $api->downloadPartyDocument($partyId, $filename);
if (!is_array($doc) || !isset($doc['content'])) {
    http_response_code(404);
    die('Файл не найден: ' . htmlspecialchars($api->lastError));
}

$content = base64_decode($doc['content']);
header('Content-Type: ' . ($doc['content-type'] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . rawurlencode($doc['filename'] ?? $filename) . '"');
header('Content-Length: ' . strlen($content));
echo $content;
