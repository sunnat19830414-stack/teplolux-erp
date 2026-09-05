<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
if ($term === '') { echo '[]'; exit; }

$rows = $api->searchSupplierOrders($term, 20);
$statusLabels = [
    0 => 'Черновик', 1 => 'Проведён', 2 => 'Утверждён', 3 => 'Отправлен поставщику',
    4 => 'Частично получен', 5 => 'Получен полностью', 6 => 'Отменён', 7 => 'Отменён', 9 => 'Отклонён',
];
// Имена поставщиков всех найденных заказов — ОДНИМ запросом (это живой поиск-по-мере-ввода, N лишних
// запросов на каждое нажатие клавиши особенно заметно тормозит — см. отчёт ревью P0#5).
$socIds = array_map(fn($o) => (int)($o['socid'] ?? 0), (array)$rows);
$socNames = $api->getThirdpartiesByIds($socIds);
$out = [];
foreach ((array)$rows as $o) {
    $soc = $socNames[(int)($o['socid'] ?? 0)] ?? null;
    $out[] = [
        'id' => (int)$o['id'],
        // BUG-N2 — в поиске (добавление заказа в партию) черновики тоже могут попадаться, показываем
        // "черновик #NN" вместо сырого "(PROV..)" — см. nt_order_display_ref() в includes/auth.php.
        'ref' => nt_order_display_ref($o['ref'] ?? '', $o['statut'] ?? 0, (int)$o['id']),
        'supplier' => is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '',
        'status_label' => $statusLabels[(int)($o['statut'] ?? 0)] ?? '',
        'total_ttc' => (float)($o['total_ttc'] ?? 0),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
