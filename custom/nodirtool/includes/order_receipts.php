<?php
/**
 * Реальные приёмки товара по заказу поставщику — ЧТЕНИЕ напрямую из тех же таблиц, что использует
 * TeplouxKassa/receive.php при самой приёмке (llx_receptiondet_batch + llx_reception). Только чтение,
 * ничего не пишет — раздел "Приём по заказу" в NodirTool показывает то, что реально принял склад,
 * не дублирует и не подменяет саму приёмку (по решению пользователя, 03.09.2026).
 *
 * Лёгкий отдельный mysqli-коннект (тот же паттерн, что в includes/logistics.php) — не через
 * master.inc.php, для одного SELECT это не нужно.
 */

function order_receipts_db(): mysqli
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
 * Все события приёмки по заказу — по каждой позиции: товар, количество, склад, дата, номер
 * приёмного документа (реального Dolibarr Reception, если приёмка шла через штатный механизм).
 * fk_element в receptiondet_batch — это id САМОГО заказа поставщику (проверено по исходникам
 * CommandeFournisseur::dispatchProduct(), не по документации — синтаксис INSERT там явный).
 */
function get_order_receipts(int $orderId): array
{
    if (!$orderId) return [];
    $db = order_receipts_db();
    $stmt = $db->prepare(
        "SELECT rb.fk_product, rb.qty, rb.fk_entrepot, rb.datec, rb.fk_elementdet,
                r.ref AS reception_ref, r.date_reception
         FROM llx_receptiondet_batch rb
         LEFT JOIN llx_reception r ON r.rowid = rb.fk_reception
         WHERE rb.fk_element = ?
         ORDER BY rb.datec DESC"
    );
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'fk_product' => (int)$row['fk_product'],
            'qty' => (float)$row['qty'],
            'warehouse_id' => (int)$row['fk_entrepot'],
            'date' => $row['date_reception'] ?: $row['datec'],
            'reception_ref' => $row['reception_ref'] ?: '',
            'line_id' => (int)$row['fk_elementdet'],
        ];
    }
    return $out;
}
