<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
$rows = $api->searchCarriers($term, 50);
$out = [];
foreach ($rows as $c) {
    $out[] = [
        'id' => (int)$c['id'],
        'name' => $c['name'] ?? $c['nom'] ?? '',
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
