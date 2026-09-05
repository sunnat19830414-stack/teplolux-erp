<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');

$clients = $api->searchThirdparties($term, $cfg['ref_prefix'], 50);
if (!is_array($clients)) {
    echo json_encode(['error' => $api->lastError]);
    exit;
}

$out = [];
foreach ($clients as $c) {
    $out[] = [
        'id' => $c['id'],
        'code_client' => $c['code_client'] ?? '',
        'name' => $c['name'] ?? $c['nom'] ?? '',
        'phone' => $c['phone'] ?? '',
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
