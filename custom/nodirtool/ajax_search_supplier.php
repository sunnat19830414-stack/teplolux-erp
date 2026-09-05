<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
$rows = $api->searchSuppliers($term, 50);
$out = [];
foreach ($rows as $s) {
    $out[] = [
        'id' => (int)$s['id'],
        'name' => $s['name'] ?? $s['nom'] ?? '',
        'code' => $s['code_fournisseur'] ?? '',
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
