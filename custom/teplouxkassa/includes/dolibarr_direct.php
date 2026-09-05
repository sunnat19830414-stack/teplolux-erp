<?php
/**
 * Лёгкое прямое подключение к БД Dolibarr, ТОЛЬКО для чтения — нужно для одного запроса, которого
 * нет в REST API: сколько по КОНКРЕТНОЙ строке заказа уже реально принято на склад раньше (может
 * быть несколько частичных приёмок подряд). См. CLAUDE.md 29.08.2026 "TeplouxKassa: точный остаток
 * по строке при приёмке".
 *
 * Не полный bootstrap master.inc.php (тяжелее, не нужен ради одного SUM-запроса) — просто mysqli
 * напрямую, теми же реквизитами, что и сам Dolibarr (см. CLAUDE.md, "MariaDB БД Dolibarr").
 */

function dolibarr_db_readonly(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Сколько уже реально принято по конкретной строке заказа — сумма по всем прошлым приёмкам. */
function get_already_received_qty(int $lineId): float
{
    if (!$lineId) return 0.0;
    $conn = dolibarr_db_readonly();
    $stmt = $conn->prepare('SELECT COALESCE(SUM(qty), 0) AS s FROM llx_receptiondet_batch WHERE fk_elementdet = ?');
    $stmt->bind_param('i', $lineId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float)($row['s'] ?? 0);
}

/**
 * Со скольких складов физически списан/на какие зачислен каждый товар в документе (счёте/возврате) —
 * в самом счёте склад НЕ хранится, списание/зачисление это отдельная операция (см. sale.php/return.php),
 * помеченная в llx_stock_mouvement.label текстом вида "...счёт #123" / "...документ #123". Нужно для
 * фильтра отчёта "по складу". Возвращает [fk_product => [entrepot_id, entrepot_id, ...]].
 */
function get_invoice_line_warehouses(int $invoiceId): array
{
    if (!$invoiceId) return [];
    $conn = dolibarr_db_readonly();
    $stmt = $conn->prepare('SELECT fk_product, fk_entrepot FROM llx_stock_mouvement WHERE label LIKE ?');
    $needle = '%#' . $invoiceId;
    $stmt->bind_param('s', $needle);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['fk_product']][] = (int)$row['fk_entrepot'];
    }
    return $out;
}

/**
 * Уже отменяли/возвращали этот счёт раньше? (по кредит-ноте, связанной через fk_facture_source —
 * см. "Возврат по счёту" в return.php и "Отменить продажу" в sale.php). Нужно, чтобы не дать отменить
 * один и тот же счёт дважды (двойной клик/F5 — plus доп. подстраховка к общей защите из пункта 1).
 */
function has_existing_credit_note_for_invoice(int $invoiceId): bool
{
    if (!$invoiceId) return false;
    $conn = dolibarr_db_readonly();
    $stmt = $conn->prepare('SELECT 1 FROM llx_facture WHERE fk_facture_source = ? LIMIT 1');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/** Категории (бренды) товара — для фильтра отчёта "по категории". Кэш в рамках одного запроса. */
function get_product_category_ids(int $productId): array
{
    static $cache = [];
    if (!$productId) return [];
    if (isset($cache[$productId])) return $cache[$productId];
    $conn = dolibarr_db_readonly();
    $stmt = $conn->prepare('SELECT fk_categorie FROM llx_categorie_product WHERE fk_product = ?');
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) $ids[] = (int)$row['fk_categorie'];
    return $cache[$productId] = $ids;
}
