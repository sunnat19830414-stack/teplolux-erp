<?php
/**
 * Быстрые справки по товарам для поиска при оформлении заказа (04.09.2026, пункт B5 отчёта
 * «Пробелы NodirTool»): сколько на складе и сколько уже едет, плюс закупочные цены поставщика
 * ОДНИМ запросом вместо одного вызова API на каждый товар.
 *
 * Почему напрямую в базу, а не через REST: закупочная цена в API отдаётся только поштучно
 * (`GET /products/{id}/purchase_prices`) — на 50 найденных товаров это 50 запросов на КАЖДОЕ
 * нажатие клавиши в поиске. Батч-эндпоинта у Dolibarr для этого нет. Тот же лёгкий mysqli-паттерн,
 * что в includes/order_receipts.php и includes/logistics.php — только чтение, ничего не пишет.
 */

function product_lookup_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Список id в безопасный список для IN (...) — только целые, пустой список даёт null. */
function product_lookup_id_list(array $ids): ?string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return empty($ids) ? null : implode(',', $ids);
}

/**
 * Закупочные цены нескольких товаров от ОДНОГО поставщика, одним запросом.
 * Возвращает [fk_product => ['price'=>USD, 'currency'=>'EUR'|'', 'rate'=>float, 'native'=>цена в валюте]].
 * `price`/`unitprice` в Dolibarr всегда в базовой валюте компании (у нас USD), валютная — рядом.
 */
function get_purchase_prices_bulk(array $productIds, int $supplierId): array
{
    $list = product_lookup_id_list($productIds);
    if ($list === null || $supplierId <= 0) return [];

    $db = product_lookup_db();
    $sql = "SELECT fk_product, unitprice, multicurrency_code, multicurrency_tx, multicurrency_unitprice
            FROM llx_product_fournisseur_price
            WHERE fk_soc = " . (int)$supplierId . " AND fk_product IN ($list)";
    $res = $db->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $rate = (float)$row['multicurrency_tx'];
        $out[(int)$row['fk_product']] = [
            'price'    => (float)$row['unitprice'],
            'currency' => (string)$row['multicurrency_code'],
            'rate'     => $rate > 0 ? $rate : 1.0,
            'native'   => (float)$row['multicurrency_unitprice'],
        ];
    }
    return $out;
}

/**
 * Закупочная цена ИМЕННО в валюте заказа (04.09.2026). Заказ у европейского поставщика ведётся в
 * евро, а `price` в справочнике всегда хранится в долларах — если подставить её как есть, в поле
 * «Цена, EUR» попадёт долларовое число, то есть завышенное примерно на 16%.
 *
 * $info — строка из get_purchase_prices_bulk() (или null, если цены ещё нет).
 */
function purchase_price_for_order(?array $info, string $orderCurrency, float $orderRate): float
{
    if ($info === null) return 0.0;

    $orderCurrency = strtoupper(trim($orderCurrency)) ?: 'USD';
    $stored = strtoupper(trim((string)($info['currency'] ?? ''))) ?: 'USD';
    $usd = (float)($info['price'] ?? 0);

    // Цена уже в нужной валюте — берём как есть, без пересчёта: это ровно та цифра из прайса.
    if ($stored === $orderCurrency) {
        return $stored === 'USD' ? $usd : (float)($info['native'] ?? 0);
    }
    // Валюты разные — пересчитываем через доллары по курсу ЗАКАЗА (курс сделки, а не старый курс записи).
    if ($orderCurrency === 'USD') return $usd;
    return $orderRate > 0 ? round($usd * $orderRate, 4) : 0.0;
}

/**
 * Коды ТНВЭД и артикулы нескольких товаров одним запросом — для спецификации поставщику (B1).
 * Возвращает [fk_product => ['hs' => код, 'ref' => артикул, 'label' => название]].
 */
function get_product_customs_bulk(array $productIds): array
{
    $list = product_lookup_id_list($productIds);
    if ($list === null) return [];

    $db = product_lookup_db();
    $res = $db->query("SELECT rowid, ref, label, customcode FROM llx_product WHERE rowid IN ($list)");
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['rowid']] = [
            'hs' => trim((string)$row['customcode']),
            'ref' => (string)$row['ref'],
            'label' => (string)$row['label'],
        ];
    }
    return $out;
}

/**
 * Следующий свободный код направления (J01498 / T00811) для нового товара — пункт B9 отчёта.
 * Код живёт в доп.поле `kod_sap` (см. CLAUDE.md 27.08.2026: `ref` у товаров это короткий код
 * поставщика, а J/T-код переехал в это поле — именно по нему TeplouxKassa отличает направления).
 * Нестандартные значения (без пяти цифр после буквы) игнорируются, чтобы не сбивать счётчик.
 */
function next_kod_sap(string $prefix): string
{
    $prefix = strtoupper(substr($prefix, 0, 1));
    if ($prefix !== 'J' && $prefix !== 'T') $prefix = 'J';

    $db = product_lookup_db();
    $res = $db->query(
        "SELECT MAX(CAST(SUBSTRING(kod_sap, 2) AS UNSIGNED)) AS n
         FROM llx_product_extrafields
         WHERE kod_sap REGEXP '^" . $prefix . "[0-9]{5}$'"
    );
    $max = (int)($res->fetch_assoc()['n'] ?? 0);
    return $prefix . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

/** Есть ли уже товар с таким `ref` (артикулом поставщика) — Dolibarr требует уникальности. */
function product_ref_exists(string $ref): bool
{
    $db = product_lookup_db();
    $stmt = $db->prepare("SELECT rowid FROM llx_product WHERE ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();
    return $found;
}

/**
 * Сколько товара «уже едет»: заказано у поставщиков в открытых заказах (проведён / утверждён /
 * отправлен / получен частично — статусы 1..4) МИНУС то, что по этим заказам уже реально принято
 * складом. Черновики (0) не считаем — это ещё не обязательство поставщика.
 * Возвращает [fk_product => количество в пути] (только положительные).
 */
function get_incoming_qty_bulk(array $productIds): array
{
    $list = product_lookup_id_list($productIds);
    if ($list === null) return [];

    $db = product_lookup_db();

    $ordered = [];
    $res = $db->query(
        "SELECT d.fk_product, SUM(d.qty) AS qty
         FROM llx_commande_fournisseurdet d
         JOIN llx_commande_fournisseur c ON c.rowid = d.fk_commande
         WHERE c.fk_statut IN (1,2,3,4) AND d.fk_product IN ($list)
         GROUP BY d.fk_product"
    );
    while ($row = $res->fetch_assoc()) $ordered[(int)$row['fk_product']] = (float)$row['qty'];

    $received = [];
    $res = $db->query(
        "SELECT b.fk_product, SUM(b.qty) AS qty
         FROM llx_receptiondet_batch b
         JOIN llx_commande_fournisseur c ON c.rowid = b.fk_element
         WHERE c.fk_statut IN (1,2,3,4) AND b.fk_product IN ($list)
         GROUP BY b.fk_product"
    );
    while ($row = $res->fetch_assoc()) $received[(int)$row['fk_product']] = (float)$row['qty'];

    $out = [];
    foreach ($ordered as $pid => $qty) {
        $left = $qty - ($received[$pid] ?? 0);
        if ($left > 0.0001) $out[$pid] = $left;
    }
    return $out;
}
