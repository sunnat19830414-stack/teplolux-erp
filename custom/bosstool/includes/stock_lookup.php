<?php
/**
 * Складские справки для заявок и отчёта «что пора закупать». Только чтение, прямой mysqli — тот же
 * лёгкий приём, что в NodirTool/includes/product_lookup.php (батч-выборок такого рода REST не даёт,
 * а по одному товару за раз это десятки запросов на каждое нажатие клавиши в поиске).
 */

function stock_lookup_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function stock_id_list(array $ids): ?string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return empty($ids) ? null : implode(',', $ids);
}

/**
 * Заводские (закупочные) цены нескольких товаров от ОДНОГО поставщика, одним запросом.
 * Возвращает [fk_product => ['price'=>USD, 'currency'=>'EUR'|'', 'rate'=>float, 'native'=>в валюте]].
 * `unitprice` в Dolibarr всегда в базовой валюте компании (у нас доллары), цена в валюте договора
 * лежит рядом — поставщику показываем именно её.
 */
function get_purchase_prices_bulk(array $productIds, int $supplierId): array
{
    $list = stock_id_list($productIds);
    if ($list === null || $supplierId <= 0) return [];

    $db = stock_lookup_db();
    $res = $db->query(
        "SELECT fk_product, unitprice, multicurrency_code, multicurrency_tx, multicurrency_unitprice
         FROM llx_product_fournisseur_price
         WHERE fk_soc = " . (int)$supplierId . " AND fk_product IN ($list)"
    );
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
 * Отчёт «Склад: цены» (04.09.2026, по образцу SR Lux, который показал пользователь): в одной таблице
 * заводская цена, себестоимость, цена продажи и остаток — и всё это правится на месте.
 *
 * Заводская цена показывается И в валюте договора поставщика, И в долларах (просьба пользователя):
 * с поставщиком договариваются в евро, а считает компания в долларах.
 *
 * @param array $f фильтры: ref, label, supplier, stock_from, stock_to, only_stock
 */
function stock_price_rows(array $directions, array $f = [], int $limit = 400): array
{
    $db = stock_lookup_db();

    $where = ['p.tobuy = 1'];
    if (count($directions) === 1) {
        $where[] = "e.kod_sap LIKE '" . $db->real_escape_string($directions[0]) . "%'";
    }
    if (!empty($f['ref'])) {
        $where[] = "p.ref LIKE '%" . $db->real_escape_string($f['ref']) . "%'";
    }
    if (!empty($f['label'])) {
        $where[] = "p.label LIKE '%" . $db->real_escape_string($f['label']) . "%'";
    }
    if (!empty($f['supplier'])) {
        $where[] = "EXISTS (SELECT 1 FROM llx_product_fournisseur_price fx
                            WHERE fx.fk_product = p.rowid AND fx.fk_soc = " . (int)$f['supplier'] . ")";
    }
    if (isset($f['stock_from']) && $f['stock_from'] !== '') {
        $where[] = 'p.stock >= ' . (float)$f['stock_from'];
    }
    if (isset($f['stock_to']) && $f['stock_to'] !== '') {
        $where[] = 'p.stock <= ' . (float)$f['stock_to'];
    }
    if (!empty($f['only_stock'])) {
        $where[] = 'p.stock > 0';
    }

    // Заводская цена — от поставщика с самой свежей записью (у товара их может быть несколько).
    $sql = "SELECT p.rowid AS id, p.ref, p.label, p.price, p.pmp, p.stock, e.kod_sap,
                   f.fk_soc, f.unitprice AS f_usd, f.multicurrency_code AS f_cur,
                   f.multicurrency_unitprice AS f_native, f.multicurrency_tx AS f_rate,
                   s.nom AS supplier
            FROM llx_product p
            LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
            LEFT JOIN llx_product_fournisseur_price f
                   ON f.rowid = (SELECT fx.rowid FROM llx_product_fournisseur_price fx
                                 WHERE fx.fk_product = p.rowid ORDER BY fx.tms DESC, fx.rowid DESC LIMIT 1)
            LEFT JOIN llx_societe s ON s.rowid = f.fk_soc
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.ref
            LIMIT " . (int)$limit;

    $res = $db->query($sql);
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $cur = strtoupper(trim((string)$r['f_cur'])) ?: 'USD';
        $rate = (float)$r['f_rate'];
        $out[] = [
            'id' => (int)$r['id'],
            'ref' => (string)$r['ref'],
            'label' => (string)$r['label'],
            'supplier' => (string)($r['supplier'] ?? ''),
            'supplier_id' => (int)($r['fk_soc'] ?? 0),
            'factory_cur' => $cur,
            // В валюте договора: для долларовых поставщиков валютной цены нет — берём долларовую.
            'factory_native' => $cur === 'USD' ? (float)$r['f_usd'] : (float)$r['f_native'],
            'factory_usd' => (float)$r['f_usd'],
            'factory_rate' => $rate > 0 ? $rate : 1.0,
            'cost' => (float)$r['pmp'],
            'sale' => (float)$r['price'],
            'stock' => (float)$r['stock'],
        ];
    }
    return $out;
}

/** Сколько всего товаров подходит под фильтр и на какую сумму лежит склад (по себестоимости). */
function stock_price_totals(array $directions, array $f = []): array
{
    $rows = stock_price_rows($directions, $f, 100000);
    $sum = 0.0;
    foreach ($rows as $r) $sum += $r['cost'] * $r['stock'];
    return ['count' => count($rows), 'stock_value' => round($sum, 2)];
}

/**
 * Себестоимость (`pmp`) через REST не записывается — Dolibarr игнорирует её в PUT (проверено и нами,
 * и внешним аудитом). Пишем прямым запросом, как при массовой простановке себестоимости 03.09.2026.
 */
function save_product_cost(int $productId, float $cost): bool
{
    $db = stock_lookup_db();
    $stmt = $db->prepare("UPDATE llx_product SET pmp = ?, cost_price = ? WHERE rowid = ?");
    $stmt->bind_param('ddi', $cost, $cost, $productId);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

/**
 * Записать изменение заводской цены в тот же журнал, которым пользуются закупщики
 * (`llx_supplier_price_history`, см. NodirTool/includes/price_history.php) — чтобы правка цены шефом
 * была видна там же, где правки Нодира, а не терялась.
 */
function log_price_change(int $productId, int $supplierId, ?float $oldPrice, float $newPrice, string $who): void
{
    $db = stock_lookup_db();
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_price_history (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_product INT NOT NULL,
        fk_supplier INT NOT NULL,
        old_price DECIMAL(18,4) DEFAULT NULL,
        new_price DECIMAL(18,4) NOT NULL,
        changed_by VARCHAR(100) DEFAULT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_product_supplier (fk_product, fk_supplier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $db->prepare("INSERT INTO llx_supplier_price_history
        (fk_product, fk_supplier, old_price, new_price, changed_by, datec) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('iidds', $productId, $supplierId, $oldPrice, $newPrice, $who);
    try { $stmt->execute(); } catch (mysqli_sql_exception $e) { /* журнал не должен ронять сохранение цены */ }
    $stmt->close();
}

/** Валюта банковского счёта компании — счета у нас реально разновалютные (EUR-MAIN держит евро). */
function account_currency(int $accountId): string
{
    static $cache = [];
    if (isset($cache[$accountId])) return $cache[$accountId];

    $db = stock_lookup_db();
    $stmt = $db->prepare("SELECT currency_code FROM llx_bank_account WHERE rowid = ?");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$accountId] = strtoupper(trim((string)($row['currency_code'] ?? ''))) ?: 'USD';
}

/** Валюта договора поставщика; пусто в карточке означает доллары. */
function supplier_contract_currency(int $supplierId): string
{
    if ($supplierId <= 0) return 'USD';
    $db = stock_lookup_db();
    $stmt = $db->prepare("SELECT multicurrency_code FROM llx_societe WHERE rowid = ?");
    $stmt->bind_param('i', $supplierId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return strtoupper(trim((string)($row['multicurrency_code'] ?? ''))) ?: 'USD';
}

/** Свежий курс валюты из справочника Dolibarr (единиц за 1 доллар). Обновляется им раз в сутки. */
function currency_rate(string $code): float
{
    $code = strtoupper(trim($code));
    if ($code === '' || $code === 'USD') return 1.0;

    $db = stock_lookup_db();
    $stmt = $db->prepare(
        "SELECT r.rate FROM llx_multicurrency_rate r
         JOIN llx_multicurrency m ON m.rowid = r.fk_multicurrency
         WHERE m.code = ? ORDER BY r.date_sync DESC LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $rate = (float)($row['rate'] ?? 0);
    return $rate > 0 ? $rate : 1.0;
}

/**
 * Следующий свободный код направления (J01507 / T00812) для нового товара.
 * Код живёт в доп.поле `kod_sap`: именно по нему TeplouxKassa различает направления (у `ref` лежит
 * код поставщика, см. CLAUDE.md 27.08.2026). Товар без этого кода не увидит ни один продавец, то
 * есть «создали и потеряли» — поэтому код выдаётся сразу, а не оставляется на потом.
 * Нестандартные значения (без пяти цифр после буквы) счётчик не сбивают.
 */
function next_kod_sap(string $prefix): string
{
    $prefix = strtoupper(substr($prefix, 0, 1));
    if ($prefix !== 'J' && $prefix !== 'T') $prefix = 'J';

    $db = stock_lookup_db();
    $res = $db->query(
        "SELECT MAX(CAST(SUBSTRING(kod_sap, 2) AS UNSIGNED)) AS n
         FROM llx_product_extrafields
         WHERE kod_sap REGEXP '^" . $prefix . "[0-9]{5}$'"
    );
    $max = (int)($res->fetch_assoc()['n'] ?? 0);
    return $prefix . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

/** Есть ли уже товар с таким артикулом — Dolibarr требует уникальности `ref`. */
function product_ref_exists(string $ref): bool
{
    $db = stock_lookup_db();
    $stmt = $db->prepare("SELECT rowid FROM llx_product WHERE ref = ? LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();
    return $found;
}

/**
 * Сколько товара «уже едет»: заказано у поставщиков в открытых заказах (статусы 1..4 — проведён,
 * утверждён, отправлен, частично получен) минус то, что уже реально принято складом. Черновики не
 * считаем: это ещё не обязательство поставщика.
 */
function get_incoming_qty_bulk(array $productIds): array
{
    $list = stock_id_list($productIds);
    if ($list === null) return [];
    $db = stock_lookup_db();

    $ordered = [];
    $res = $db->query("SELECT d.fk_product, SUM(d.qty) qty
                       FROM llx_commande_fournisseurdet d
                       JOIN llx_commande_fournisseur c ON c.rowid = d.fk_commande
                       WHERE c.fk_statut IN (1,2,3,4) AND d.fk_product IN ($list)
                       GROUP BY d.fk_product");
    while ($r = $res->fetch_assoc()) $ordered[(int)$r['fk_product']] = (float)$r['qty'];

    $received = [];
    $res = $db->query("SELECT b.fk_product, SUM(b.qty) qty
                       FROM llx_receptiondet_batch b
                       JOIN llx_commande_fournisseur c ON c.rowid = b.fk_element
                       WHERE c.fk_statut IN (1,2,3,4) AND b.fk_product IN ($list)
                       GROUP BY b.fk_product");
    while ($r = $res->fetch_assoc()) $received[(int)$r['fk_product']] = (float)$r['qty'];

    $out = [];
    foreach ($ordered as $pid => $qty) {
        $left = $qty - ($received[$pid] ?? 0);
        if ($left > 0.0001) $out[$pid] = $left;
    }
    return $out;
}
