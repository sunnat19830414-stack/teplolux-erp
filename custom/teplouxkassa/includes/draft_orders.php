<?php
/**
 * Черновики продаж — заказы, которые можно создавать заранее (несколько сразу), не влияют на остатки
 * склада и долг клиента, пока не будут переведены в настоящую продажу через sale.php. Хранятся в
 * своей таблице (не в Dolibarr) — переживают перезапуск сайта/сервера, в отличие от PHP-сессии.
 *
 * Формат items_json — тот же массив, что и $_SESSION['cart'] в sale.php (product_id/ref/label/price/
 * qty/warehouse_id/stock_by_warehouse), чтобы загрузка черновика в корзину была простым присваиванием.
 */

function draft_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function draft_orders_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    $db = draft_db();
    $db->query("CREATE TABLE IF NOT EXISTS llx_draft_order (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        direction VARCHAR(10) NOT NULL,
        fk_societe INT NOT NULL,
        client_name VARCHAR(255) NOT NULL DEFAULT '',
        label VARCHAR(255) DEFAULT NULL,
        items_json MEDIUMTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        fk_invoice INT DEFAULT NULL,
        datec DATETIME NOT NULL,
        date_converted DATETIME DEFAULT NULL,
        date_cancelled DATETIME DEFAULT NULL,
        INDEX idx_direction_status (direction, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Создать новый черновик или обновить уже существующий (если кассир открыл черновик, поправил
 * корзину и снова нажал "Сохранить как черновик" — не плодим дубли). Возвращает id черновика.
 */
function save_draft_order(string $direction, int $clientId, string $clientName, string $label, array $items, ?int $existingId = null): int
{
    draft_orders_ensure_table();
    $db = draft_db();
    $itemsJson = $db->real_escape_string(json_encode(array_values($items), JSON_UNESCAPED_UNICODE));
    $clientNameEsc = $db->real_escape_string($clientName);
    $labelEsc = $db->real_escape_string($label);
    $directionEsc = $db->real_escape_string($direction);

    if ($existingId) {
        // Обновляем, только если черновик ещё open и принадлежит нашему направлению — иначе (если,
        // например, его уже успели отменить с другого устройства) создаём новую запись, а не тихо
        // реанимируем отменённую/переведённую.
        $existing = get_draft($existingId, $direction);
        if ($existing && $existing['status'] === 'open') {
            $db->query("UPDATE llx_draft_order SET fk_societe=" . (int)$clientId . ", client_name='{$clientNameEsc}',
                label='{$labelEsc}', items_json='{$itemsJson}' WHERE rowid=" . (int)$existingId);
            return $existingId;
        }
    }

    $db->query("INSERT INTO llx_draft_order (direction, fk_societe, client_name, label, items_json, status, datec)
        VALUES ('{$directionEsc}', " . (int)$clientId . ", '{$clientNameEsc}', '{$labelEsc}', '{$itemsJson}', 'open', '" . date('Y-m-d H:i:s') . "')");
    return (int)$db->insert_id;
}

/** Один черновик по id — null, если не найден ИЛИ относится к другому направлению (защита от IDOR). */
function get_draft(int $id, string $direction): ?array
{
    if (!$id) return null;
    draft_orders_ensure_table();
    $db = draft_db();
    $res = $db->query("SELECT * FROM llx_draft_order WHERE rowid=" . (int)$id . " AND direction='" . $db->real_escape_string($direction) . "'");
    if (!$res || $res->num_rows === 0) return null;
    $row = $res->fetch_assoc();
    $row['items'] = json_decode($row['items_json'], true) ?: [];
    return $row;
}

/** Открытые (ещё не переведённые/не отменённые) черновики направления — для дашборда. */
function get_open_drafts(string $direction): array
{
    draft_orders_ensure_table();
    $db = draft_db();
    $res = $db->query("SELECT * FROM llx_draft_order WHERE direction='" . $db->real_escape_string($direction) . "' AND status='open' ORDER BY rowid DESC");
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $row['items'] = json_decode($row['items_json'], true) ?: [];
        $out[] = $row;
    }
    return $out;
}

/** История (переведённые/отменённые) — для необязательного раздела "показать историю". */
function get_draft_history(string $direction, int $limit = 50): array
{
    draft_orders_ensure_table();
    $db = draft_db();
    $res = $db->query("SELECT * FROM llx_draft_order WHERE direction='" . $db->real_escape_string($direction) . "' AND status != 'open' ORDER BY rowid DESC LIMIT " . (int)$limit);
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $row['items'] = json_decode($row['items_json'], true) ?: [];
        $out[] = $row;
    }
    return $out;
}

/** Отменить черновик (не удаляем — остаётся в истории). Возвращает false, если не найден/чужое направление/уже не open. */
function cancel_draft_order(int $id, string $direction): bool
{
    $draft = get_draft($id, $direction);
    if (!$draft || $draft['status'] !== 'open') return false;
    $db = draft_db();
    $db->query("UPDATE llx_draft_order SET status='cancelled', date_cancelled='" . date('Y-m-d H:i:s') . "' WHERE rowid=" . (int)$id);
    return true;
}

/** Пометить черновик переведённым в продажу — вызывается сразу после успешного оформления в sale.php. */
function mark_draft_converted(int $id, string $direction, int $invoiceId): bool
{
    $draft = get_draft($id, $direction);
    if (!$draft || $draft['status'] !== 'open') return false;
    $db = draft_db();
    $db->query("UPDATE llx_draft_order SET status='converted', fk_invoice=" . (int)$invoiceId . ", date_converted='" . date('Y-m-d H:i:s') . "' WHERE rowid=" . (int)$id);
    return true;
}
