<?php
/**
 * Заявки на закупку (04.09.2026). Список того, что нужно купить, составляет САМ шеф (Умид), а по
 * турецкой линии — Суннатилла; Нодир и Абдурашид берут заявку и делают по ней всю остальную работу:
 * переговоры, заказ, документы, логистику, оплату. Раньше такой список уходил в чат или на почту —
 * иногда прямо поставщику, мимо закупщиков; от этого и уходим, чтобы заказ всегда был в системе.
 *
 * Свои таблицы (не сущность Dolibarr) — тот же принцип, что у партий/логистики в NodirTool: заявка
 * это внутренний документ компании, в Dolibarr ей соответствовать нечему, а заказ поставщику из неё
 * рождается уже настоящий.
 *
 * ⚠️ Файл ОДИНАКОВЫЙ в C:\BossTool\includes\ и C:\NodirTool\includes\ — обе стороны работают с одними
 * и теми же таблицами. Правки вносить в обе копии.
 */

function requests_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/**
 * Обёртка над execute(): начиная с PHP 8.1 mysqli БРОСАЕТ mysqli_sql_exception вместо возврата
 * false, поэтому привычная проверка `if (!$stmt->execute())` не срабатывает никогда и нарушение
 * ограничения даёт белый экран вместо понятного сообщения (поймано 04.09.2026 на дублях отделов).
 */
function requests_exec(mysqli_stmt $stmt, string $failMessage = 'Ошибка сохранения'): array
{
    try {
        $stmt->execute();
        return ['ok' => true];
    } catch (mysqli_sql_exception $e) {
        return ['ok' => false, 'errno' => $e->getCode(), 'error' => $failMessage . ': ' . $e->getMessage()];
    }
}

function requests_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $db = requests_db();
    $db->query("CREATE TABLE IF NOT EXISTS llx_boss_request (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        direction CHAR(1) NOT NULL,
        fk_supplier INT NULL,
        supplier_name VARCHAR(190) NULL,
        label VARCHAR(190) NULL,
        note TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        created_by VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        taken_by VARCHAR(64) NULL,
        taken_at DATETIME NULL,
        fk_order INT NULL,
        decline_reason VARCHAR(255) NULL,
        closed_at DATETIME NULL,
        INDEX idx_status (status),
        INDEX idx_direction (direction)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS llx_boss_request_line (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_request INT NOT NULL,
        fk_product INT NOT NULL,
        ref VARCHAR(128) NULL,
        label VARCHAR(255) NULL,
        qty DOUBLE NOT NULL DEFAULT 1,
        comment VARCHAR(255) NULL,
        INDEX idx_request (fk_request)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Человеческие названия статусов — одинаковые по обе стороны, чтобы не разъезжались. */
function request_status_label(string $status): string
{
    return [
        'draft'     => 'Черновик',
        'sent'      => 'Отправлена закупщику',
        'taken'     => 'Взята в работу',
        'ordered'   => 'Заказ оформлен',
        'declined'  => 'Отклонена',
        'cancelled' => 'Отменена',
    ][$status] ?? $status;
}

function request_status_badge(string $status): string
{
    return [
        'draft' => 'badge-neutral', 'sent' => 'badge-warn', 'taken' => 'badge-warn',
        'ordered' => 'badge-ok', 'declined' => 'badge-debt', 'cancelled' => 'badge-neutral',
    ][$status] ?? 'badge-neutral';
}

/** Создать пустую заявку (черновик) и вернуть её id. */
function request_create(string $direction, string $createdBy, ?int $supplierId, ?string $supplierName, string $label): int
{
    requests_ensure_tables();
    $db = requests_db();
    $stmt = $db->prepare("INSERT INTO llx_boss_request (direction, fk_supplier, supplier_name, label, status, created_by, created_at)
                          VALUES (?, ?, ?, ?, 'draft', ?, NOW())");
    $stmt->bind_param('sisss', $direction, $supplierId, $supplierName, $label, $createdBy);
    $r = requests_exec($stmt, 'Не удалось создать заявку');
    $id = $r['ok'] ? $db->insert_id : 0;
    $stmt->close();
    return $id;
}

function request_get(int $id): ?array
{
    requests_ensure_tables();
    $db = requests_db();
    $stmt = $db->prepare("SELECT * FROM llx_boss_request WHERE rowid = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['lines'] = request_lines($id);
    return $row;
}

function request_lines(int $id): array
{
    requests_ensure_tables();
    $db = requests_db();
    $stmt = $db->prepare("SELECT * FROM llx_boss_request_line WHERE fk_request = ? ORDER BY rowid");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    $stmt->close();
    return $out;
}

function request_add_line(int $requestId, int $productId, string $ref, string $label, float $qty, string $comment = ''): bool
{
    requests_ensure_tables();
    $db = requests_db();
    $stmt = $db->prepare("INSERT INTO llx_boss_request_line (fk_request, fk_product, ref, label, qty, comment)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iissds', $requestId, $productId, $ref, $label, $qty, $comment);
    $r = requests_exec($stmt, 'Не удалось добавить позицию');
    $stmt->close();
    return $r['ok'];
}

function request_update_line(int $lineId, float $qty, string $comment): bool
{
    $db = requests_db();
    $stmt = $db->prepare("UPDATE llx_boss_request_line SET qty = ?, comment = ? WHERE rowid = ?");
    $stmt->bind_param('dsi', $qty, $comment, $lineId);
    $r = requests_exec($stmt, 'Не удалось изменить позицию');
    $stmt->close();
    return $r['ok'];
}

function request_delete_line(int $lineId): bool
{
    $db = requests_db();
    $stmt = $db->prepare("DELETE FROM llx_boss_request_line WHERE rowid = ?");
    $stmt->bind_param('i', $lineId);
    $r = requests_exec($stmt, 'Не удалось убрать позицию');
    $stmt->close();
    return $r['ok'];
}

/** Обновить шапку заявки (поставщик/пометка/примечание) — только пока черновик. */
function request_update_head(int $id, ?int $supplierId, ?string $supplierName, string $label, string $note): bool
{
    $db = requests_db();
    $stmt = $db->prepare("UPDATE llx_boss_request SET fk_supplier = ?, supplier_name = ?, label = ?, note = ?
                          WHERE rowid = ? AND status = 'draft'");
    $stmt->bind_param('isssi', $supplierId, $supplierName, $label, $note, $id);
    $r = requests_exec($stmt, 'Не удалось сохранить заявку');
    $stmt->close();
    return $r['ok'];
}

function request_set_status(int $id, string $status, array $extra = []): bool
{
    $db = requests_db();
    $sets = ['status = ?'];
    $types = 's';
    $vals = [$status];
    foreach (['sent_at', 'taken_at', 'closed_at'] as $f) {
        if (!empty($extra[$f])) { $sets[] = "$f = NOW()"; }
    }
    foreach (['taken_by' => 's', 'decline_reason' => 's', 'fk_order' => 'i'] as $f => $t) {
        if (array_key_exists($f, $extra)) { $sets[] = "$f = ?"; $types .= $t; $vals[] = $extra[$f]; }
    }
    $types .= 'i';
    $vals[] = $id;
    $stmt = $db->prepare("UPDATE llx_boss_request SET " . implode(', ', $sets) . " WHERE rowid = ?");
    $stmt->bind_param($types, ...$vals);
    $r = requests_exec($stmt, 'Не удалось изменить статус заявки');
    $stmt->close();
    return $r['ok'];
}

/**
 * Список заявок. $directions — какие направления показывать (Суннатилла видит только своё).
 * $statuses — фильтр по статусам; пустой список означает «все».
 */
function request_list(array $directions, array $statuses = [], int $limit = 200): array
{
    requests_ensure_tables();
    $db = requests_db();

    $where = [];
    if ($directions) {
        $safe = array_map(fn($d) => "'" . $db->real_escape_string($d) . "'", $directions);
        $where[] = 'direction IN (' . implode(',', $safe) . ')';
    }
    if ($statuses) {
        $safe = array_map(fn($s) => "'" . $db->real_escape_string($s) . "'", $statuses);
        $where[] = 'status IN (' . implode(',', $safe) . ')';
    }
    $sql = "SELECT r.*, (SELECT COUNT(*) FROM llx_boss_request_line l WHERE l.fk_request = r.rowid) AS line_count,
                   (SELECT COALESCE(SUM(l.qty),0) FROM llx_boss_request_line l WHERE l.fk_request = r.rowid) AS total_qty
            FROM llx_boss_request r";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY r.rowid DESC LIMIT ' . (int)$limit;

    $res = $db->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

/** Сколько заявок ждёт закупщика — для бейджа на «Сводке» в обоих инструментах. */
function requests_waiting_count(array $directions = []): int
{
    requests_ensure_tables();
    $db = requests_db();
    $sql = "SELECT COUNT(*) n FROM llx_boss_request WHERE status IN ('sent','taken')";
    if ($directions) {
        $safe = array_map(fn($d) => "'" . $db->real_escape_string($d) . "'", $directions);
        $sql .= ' AND direction IN (' . implode(',', $safe) . ')';
    }
    return (int)$db->query($sql)->fetch_assoc()['n'];
}
