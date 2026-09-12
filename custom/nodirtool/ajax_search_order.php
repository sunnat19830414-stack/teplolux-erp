<?php
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');

// Пустое поле — показываем ДЕЙСТВУЮЩИЕ заказы: утверждённые, отправленные поставщику и принятые
// частично. Раньше на пустой запрос отдавался пустой список, и при щелчке в поле «Номер заказа»
// ничего не выпадало, хотя у перевозчиков тот же щелчок сразу показывает всех (замечание
// пользователя 11.09.2026, «Перевозки»). Черновики и уже полностью полученные/отменённые не нужны:
// везут и собирают в партии именно действующие. Поиск по номеру по-прежнему находит любые.
if ($term === '') {
    $rows = [];
    foreach (['approved', 'running', 'received_start'] as $st) {
        foreach ((array)$api->getSupplierOrdersByStatus($st, 'id,ref,socid,statut,total_ttc') as $o) $rows[] = $o;
    }
    usort($rows, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
    $rows = array_slice($rows, 0, 30);
} else {
    $rows = $api->searchSupplierOrders($term, 20);
}
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
