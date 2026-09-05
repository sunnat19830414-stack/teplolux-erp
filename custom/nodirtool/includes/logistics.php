<?php
/**
 * Партии (группы заказов, которые ехали одной машиной) + логистические расходы, распределяемые в
 * себестоимость товара (`pmp`/`cost_price`). См. CLAUDE.md 29.08.2026 "себестоимость товара".
 *
 * Чистый SQL напрямую (mysqli, те же реквизиты, что и Dolibarr) — НЕ через master.inc.php: ничего
 * здесь не использует классы Dolibarr, только собственные вспомогательные таблицы + настоящие
 * проводки в llx_bank. Раз не нужен тяжёлый bootstrap — не подключаем его, в отличие от
 * includes/dolibarr_direct.php (там он реально нужен ради классов CommandeFournisseur/User).
 */

const LOGISTICS_API_USER_ID = 4; // api_purchasing — тот же, кто владеет API-ключом всего инструмента

function logistics_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function logistics_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $db = logistics_db();
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_shipment_batch (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(255) NOT NULL,
        datec DATETIME NOT NULL,
        status TINYINT NOT NULL DEFAULT 0,
        fk_user_creat INT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_shipment_batch_order (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_batch INT NOT NULL,
        fk_order INT NOT NULL,
        UNIQUE KEY uk_batch_order (fk_batch, fk_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_logistics_expense (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        scope_type VARCHAR(10) NOT NULL,
        scope_id INT NOT NULL,
        expense_type VARCHAR(20) NOT NULL,
        native_amount DECIMAL(18,2) NOT NULL,
        native_currency VARCHAR(3) NOT NULL,
        rate DECIMAL(18,4) DEFAULT NULL,
        usd_amount DECIMAL(18,2) NOT NULL,
        fk_bank INT DEFAULT NULL,
        datec DATETIME NOT NULL,
        fk_user INT DEFAULT NULL,
        comment VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_landed_baseline (
        scope_type VARCHAR(10) NOT NULL,
        scope_id INT NOT NULL,
        fk_product INT NOT NULL,
        prior_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
        prior_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (scope_type, scope_id, fk_product)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Перевозчики (топ-5 пункт 3, 02.09.2026): расход можно привязать к конкретному перевозчику —
    // тогда это НЕ мгновенная оплата, а долг (см. logistics_record_expense() ниже), гасится отдельно
    // через llx_carrier_payment. fk_carrier ссылается на llx_societe.rowid (реальный контрагент
    // Dolibarr, помеченный extrafield is_carrier=1) — своей FK-связи с чужой БД-схемой Dolibarr не
    // делаем (тот же принцип, что и везде в этом файле), просто INT.
    $db->query("ALTER TABLE llx_supplier_logistics_expense ADD COLUMN IF NOT EXISTS fk_carrier INT NULL DEFAULT NULL");
    $db->query("CREATE TABLE IF NOT EXISTS llx_carrier_payment (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_carrier INT NOT NULL,
        native_amount DECIMAL(18,2) NOT NULL,
        native_currency VARCHAR(3) NOT NULL,
        rate DECIMAL(18,4) DEFAULT NULL,
        usd_amount DECIMAL(18,2) NOT NULL,
        fk_bank INT DEFAULT NULL,
        datec DATETIME NOT NULL,
        fk_user INT DEFAULT NULL,
        comment VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Отчёт "Себестоимость по товарам" (02.09.2026, п. 4.3.4) — последний посчитанный результат
    // logistics_recompute_cost() по каждой паре (поставка, товар), одна строка ЗАМЕНЯЕТСЯ при
    // каждом пересчёте (не история — history не просили, только актуальное состояние). Источник
    // истины для отчёта, отдельно от live `llx_product.pmp` (тот — единое число по товару сразу
    // по ВСЕМ поставкам вместе, здесь — разбивка по конкретной поставке).
    $db->query("CREATE TABLE IF NOT EXISTS llx_supplier_landed_result (
        scope_type VARCHAR(10) NOT NULL,
        scope_id INT NOT NULL,
        fk_product INT NOT NULL,
        qty DECIMAL(12,3) NOT NULL,
        raw_price_per_unit DECIMAL(12,4) NOT NULL,
        landed_cost_per_unit DECIMAL(12,4) NOT NULL,
        computed_at DATETIME NOT NULL,
        PRIMARY KEY (scope_type, scope_id, fk_product)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

const LOGISTICS_EXPENSE_TYPES = [
    'freight'         => 'Фрахт',
    'customs'         => 'Таможня',
    'certificate'     => 'Сертификат',
    'bank_fee'        => 'Комиссия банка',
    'other'           => 'Прочее',
    'customs_storage' => 'Таможенный склад (хранения)',
    'declarant'       => 'Расходы декларанта',
];

/** Создать партию (необязательная группа заказов, которые ехали одной машиной). */
function logistics_create_batch(string $label): int
{
    logistics_ensure_tables();
    $db = logistics_db();
    $label = $label !== '' ? $label : ('Партия от ' . date('d.m.Y'));
    $db->query("INSERT INTO llx_supplier_shipment_batch (label, datec, status, fk_user_creat) VALUES ('" .
        $db->real_escape_string($label) . "', '" . date('Y-m-d H:i:s') . "', 0, " . LOGISTICS_API_USER_ID . ")");
    return (int)$db->insert_id;
}

/** Список партий (по умолчанию только открытые — закрытые не мешают активному списку). */
function logistics_get_batches(bool $includeClosed = false): array
{
    logistics_ensure_tables();
    $db = logistics_db();
    $sql = "SELECT rowid, label, datec, status FROM llx_supplier_shipment_batch";
    if (!$includeClosed) $sql .= " WHERE status=0";
    $sql .= " ORDER BY rowid DESC";
    $res = $db->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

/** Одна партия + список id заказов, которые в неё входят. */
function logistics_get_batch(int $batchId): ?array
{
    logistics_ensure_tables();
    $db = logistics_db();
    $res = $db->query("SELECT rowid, label, datec, status FROM llx_supplier_shipment_batch WHERE rowid=" . (int)$batchId);
    if (!$res || $res->num_rows === 0) return null;
    $batch = $res->fetch_assoc();
    $res2 = $db->query("SELECT fk_order FROM llx_supplier_shipment_batch_order WHERE fk_batch=" . (int)$batchId);
    $orderIds = [];
    while ($row = $res2->fetch_assoc()) $orderIds[] = (int)$row['fk_order'];
    $batch['order_ids'] = $orderIds;
    return $batch;
}

/**
 * Добавить заказ в партию — БЛОКИРУЕТ, если заказ уже состоит в ДРУГОЙ партии (себестоимость считается
 * на уровне партии целиком, один заказ сразу в двух партиях запутывает распределение расходов — см.
 * CLAUDE.md 02.09.2026, отчёт аудита 4.3.3). Возвращает ['ok'=>bool,'error'=>string].
 */
function logistics_add_order_to_batch(int $batchId, int $orderId): array
{
    logistics_ensure_tables();
    $db = logistics_db();

    $resExisting = $db->query("SELECT sb.rowid, sb.label FROM llx_supplier_shipment_batch_order o
        JOIN llx_supplier_shipment_batch sb ON sb.rowid = o.fk_batch
        WHERE o.fk_order=" . (int)$orderId . " AND o.fk_batch != " . (int)$batchId);
    if ($resExisting && $resExisting->num_rows > 0) {
        $other = $resExisting->fetch_assoc();
        return ['ok' => false, 'error' => "Этот заказ уже в партии «{$other['label']}» — сначала уберите его оттуда (кнопка ✕ на странице той партии), потом добавляйте сюда."];
    }

    $ok = $db->query("INSERT IGNORE INTO llx_supplier_shipment_batch_order (fk_batch, fk_order) VALUES (" . (int)$batchId . "," . (int)$orderId . ")") !== false;
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => $db->error];
}

function logistics_remove_order_from_batch(int $batchId, int $orderId): bool
{
    logistics_ensure_tables();
    $db = logistics_db();
    return $db->query("DELETE FROM llx_supplier_shipment_batch_order WHERE fk_batch=" . (int)$batchId . " AND fk_order=" . (int)$orderId) !== false;
}

function logistics_close_batch(int $batchId, bool $close = true): bool
{
    logistics_ensure_tables();
    $db = logistics_db();
    return $db->query("UPDATE llx_supplier_shipment_batch SET status=" . ($close ? 1 : 0) . " WHERE rowid=" . (int)$batchId) !== false;
}

/**
 * Внести расход и пересчитать себестоимость. $scopeType — 'order' или 'batch'.
 * $nativeAmount/$nativeCurrency — что реально заплатили/начислили (USD напрямую, или UZS+курс — тогда
 * $rate обязателен). $accountId — с какого счёта списать СЕЙЧАС ЖЕ (UZS-MAIN или USD-MAIN); игнорируется,
 * если указан $carrierId.
 *
 * $carrierId (топ-5 пункт 3, 02.09.2026) — если указан, деньги СЕЙЧАС НЕ СПИСЫВАЮТСЯ: расход всё
 * равно немедленно влияет на себестоимость (как и раньше), но это становится долгом перед перевозчиком,
 * гасится отдельно через logistics_record_carrier_payment() (раздел "Перевозчики"). Без $carrierId
 * поведение ПОЛНОСТЬЮ прежнее — реальная проводка списания в момент ввода (сохранено ради обратной
 * совместимости с уже работающими типами расходов вроде "Таможня"/"Комиссия банка", которые обычно
 * платятся сразу, а не перевозчику).
 *
 * Возвращает ['ok'=>bool,'error'=>string,'affected_products'=>[[fk_product,new_cost],...]].
 */
function logistics_record_expense(
    string $scopeType,
    int $scopeId,
    string $expenseType,
    float $nativeAmount,
    string $nativeCurrency,
    ?float $rate,
    int $accountId,
    string $who,
    string $comment = '',
    ?int $carrierId = null
): array {
    logistics_ensure_tables();
    $db = logistics_db();

    if (!isset(LOGISTICS_EXPENSE_TYPES[$expenseType])) {
        return ['ok' => false, 'error' => 'Неизвестный вид расхода.'];
    }
    if ($nativeAmount <= 0) {
        return ['ok' => false, 'error' => 'Сумма должна быть больше нуля.'];
    }
    // Курс нужен для ЛЮБОЙ недолларовой валюты, а не только для сумов (04.09.2026): счета у нас
    // разновалютные, и расход с EUR-счёта раньше засчитывался в себестоимость как доллары —
    // то есть занижался примерно на 16%. Та же ошибка, что нашлась в оплате поставщику.
    if ($nativeCurrency !== 'USD' && (!$rate || $rate <= 0)) {
        return ['ok' => false, 'error' => "Укажите курс для суммы в {$nativeCurrency}."];
    }
    $usdAmount = $nativeCurrency === 'USD' ? round($nativeAmount, 2) : round($nativeAmount / $rate, 2);

    $typeLabel = LOGISTICS_EXPENSE_TYPES[$expenseType];
    $scopeLabel = $scopeType === 'batch' ? "партия #$scopeId" : "заказ #$scopeId";
    $label = "Логистика ($scopeLabel) — $typeLabel ($who)";

    $now = date('Y-m-d H:i:s');
    $overdraftWarning = '';
    $bankId = null;

    $db->begin_transaction();

    if ($carrierId === null) {
        // Старое поведение без изменений — реальная проводка списания сразу же.
        $resBal = $db->query("SELECT COALESCE(SUM(amount),0) as bal FROM llx_bank WHERE fk_account=" . (int)$accountId);
        $balanceBefore = $resBal ? (float)$resBal->fetch_assoc()['bal'] : null;
        if ($balanceBefore !== null && $nativeAmount > $balanceBefore + 0.01) {
            $overdraftWarning = 'ВНИМАНИЕ: на счету было ' . number_format($balanceBefore, 2) . ' — после этого расхода счёт уйдёт в минус. ';
        }

        $r1 = $db->query("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro)
            VALUES ('$now', '" . date('Y-m-d') . "', '" . date('Y-m-d') . "', -" . (float)$nativeAmount . ",
            '" . $db->real_escape_string($label) . "', " . (int)$accountId . ", 'VIR', " . LOGISTICS_API_USER_ID . ", 0)");
        if (!$r1) {
            $db->rollback();
            return ['ok' => false, 'error' => 'Ошибка проводки: ' . $db->error];
        }
        $bankId = (int)$db->insert_id;
    }
    // Если $carrierId указан — деньги не двигаем вообще, это только начисление долга (см. докблок).

    $r2 = $db->query("INSERT INTO llx_supplier_logistics_expense
        (scope_type, scope_id, expense_type, native_amount, native_currency, rate, usd_amount, fk_bank, fk_carrier, datec, fk_user, comment)
        VALUES ('" . $db->real_escape_string($scopeType) . "', " . (int)$scopeId . ", '" . $db->real_escape_string($expenseType) . "',
        " . (float)$nativeAmount . ", '" . $db->real_escape_string($nativeCurrency) . "', " . ($rate !== null ? (float)$rate : 'NULL') . ",
        $usdAmount, " . ($bankId !== null ? $bankId : 'NULL') . ", " . ($carrierId !== null ? (int)$carrierId : 'NULL') . ",
        '$now', " . LOGISTICS_API_USER_ID . ", '" . $db->real_escape_string($comment) . "')");
    if (!$r2) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Ошибка сохранения расхода: ' . $db->error];
    }

    $db->commit();

    $result = logistics_recompute_cost($scopeType, $scopeId);
    $result['ok'] = true;
    $result['usd_amount'] = $usdAmount;
    $result['overdraft_warning'] = $overdraftWarning;
    $result['note'] = $carrierId !== null
        ? 'Деньги НЕ списаны — это долг перевозчику, оплатите его в разделе «Перевозчики».'
        : ($result['note'] ?? '');
    return $result;
}

/**
 * Оплата перевозчику (топ-5 пункт 3) — реальное списание со счёта, отдельно от начисления расхода
 * (см. logistics_record_expense() выше). Может быть частичной/произвольной суммой, не привязана к
 * конкретному расходу — гасит общий долг (см. logistics_get_carrier_debt()), тем же принципом, что и
 * оплата счетов поставщику (создали долг → потом оплатили, возможно по частям).
 */
function logistics_record_carrier_payment(
    int $carrierId,
    float $nativeAmount,
    string $nativeCurrency,
    ?float $rate,
    int $accountId,
    string $who,
    string $comment = ''
): array {
    logistics_ensure_tables();
    $db = logistics_db();

    if ($nativeAmount <= 0) {
        return ['ok' => false, 'error' => 'Сумма должна быть больше нуля.'];
    }
    // В отличие от logistics_record_expense() (только USD/UZS), сюда можно платить с любого из счетов
    // проекта, включая EUR-MAIN — курс нужен для любой валюты, кроме USD, чтобы верно уменьшить долг,
    // который всегда считается в USD.
    if ($nativeCurrency !== 'USD' && (!$rate || $rate <= 0)) {
        return ['ok' => false, 'error' => 'Укажите курс для пересчёта в доллары.'];
    }
    $usdAmount = $nativeCurrency === 'USD' ? round($nativeAmount, 2) : round($nativeAmount / $rate, 2);
    $label = "Оплата перевозчику #$carrierId ($who)";
    $now = date('Y-m-d H:i:s');

    $overdraftWarning = '';
    $resBal = $db->query("SELECT COALESCE(SUM(amount),0) as bal FROM llx_bank WHERE fk_account=" . (int)$accountId);
    $balanceBefore = $resBal ? (float)$resBal->fetch_assoc()['bal'] : null;
    if ($balanceBefore !== null && $nativeAmount > $balanceBefore + 0.01) {
        $overdraftWarning = 'ВНИМАНИЕ: на счету было ' . number_format($balanceBefore, 2) . ' — после этой оплаты счёт уйдёт в минус. ';
    }

    $db->begin_transaction();

    $r1 = $db->query("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro)
        VALUES ('$now', '" . date('Y-m-d') . "', '" . date('Y-m-d') . "', -" . (float)$nativeAmount . ",
        '" . $db->real_escape_string($label) . "', " . (int)$accountId . ", 'VIR', " . LOGISTICS_API_USER_ID . ", 0)");
    if (!$r1) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Ошибка проводки: ' . $db->error];
    }
    $bankId = (int)$db->insert_id;

    $r2 = $db->query("INSERT INTO llx_carrier_payment
        (fk_carrier, native_amount, native_currency, rate, usd_amount, fk_bank, datec, fk_user, comment)
        VALUES (" . (int)$carrierId . ", " . (float)$nativeAmount . ", '" . $db->real_escape_string($nativeCurrency) . "',
        " . ($rate !== null ? (float)$rate : 'NULL') . ", $usdAmount, $bankId, '$now', " . LOGISTICS_API_USER_ID . ",
        '" . $db->real_escape_string($comment) . "')");
    if (!$r2) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Ошибка сохранения оплаты: ' . $db->error];
    }

    $db->commit();

    return ['ok' => true, 'usd_amount' => $usdAmount, 'overdraft_warning' => $overdraftWarning];
}

/** Расходы, начисленные конкретному перевозчику (fk_carrier), по всем заказам/партиям сразу. */
function logistics_get_carrier_expenses(int $carrierId): array
{
    logistics_ensure_tables();
    $db = logistics_db();
    $res = $db->query("SELECT * FROM llx_supplier_logistics_expense WHERE fk_carrier=" . (int)$carrierId . " ORDER BY rowid DESC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

/** Оплаты, сделанные конкретному перевозчику. */
function logistics_get_carrier_payments(int $carrierId): array
{
    logistics_ensure_tables();
    $db = logistics_db();
    $res = $db->query("SELECT * FROM llx_carrier_payment WHERE fk_carrier=" . (int)$carrierId . " ORDER BY rowid DESC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

/** Долг конкретному перевозчику (начислено минус оплачено), в USD. Может быть отрицательным (переплата). */
function logistics_get_carrier_debt(int $carrierId): float
{
    logistics_ensure_tables();
    $db = logistics_db();
    $charged = $db->query("SELECT COALESCE(SUM(usd_amount),0) s FROM llx_supplier_logistics_expense WHERE fk_carrier=" . (int)$carrierId)->fetch_assoc()['s'];
    $paid = $db->query("SELECT COALESCE(SUM(usd_amount),0) s FROM llx_carrier_payment WHERE fk_carrier=" . (int)$carrierId)->fetch_assoc()['s'];
    return round((float)$charged - (float)$paid, 2);
}

/**
 * Долги ВСЕХ перевозчиков сразу (для дашборда "кому должны") — одним проходом по каждой таблице
 * (GROUP BY), не в цикле по каждому перевозчику отдельно (тот же принцип, что и везде в проекте после
 * отчёта аудита P0#5 — см. CLAUDE.md). Возвращает [fk_carrier => ['charged'=>, 'paid'=>, 'debt'=>]],
 * только те, у кого есть хоть один расход (перевозчики без единого расхода сюда не попадают).
 */
function logistics_get_all_carrier_debts(): array
{
    logistics_ensure_tables();
    $db = logistics_db();
    $out = [];
    $res = $db->query("SELECT fk_carrier, COALESCE(SUM(usd_amount),0) s FROM llx_supplier_logistics_expense WHERE fk_carrier IS NOT NULL GROUP BY fk_carrier");
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['fk_carrier']] = ['charged' => (float)$row['s'], 'paid' => 0.0, 'debt' => (float)$row['s']];
    }
    $res2 = $db->query("SELECT fk_carrier, COALESCE(SUM(usd_amount),0) s FROM llx_carrier_payment GROUP BY fk_carrier");
    while ($row = $res2->fetch_assoc()) {
        $cid = (int)$row['fk_carrier'];
        if (!isset($out[$cid])) $out[$cid] = ['charged' => 0.0, 'paid' => 0.0, 'debt' => 0.0];
        $out[$cid]['paid'] = (float)$row['s'];
        $out[$cid]['debt'] = round($out[$cid]['charged'] - $out[$cid]['paid'], 2);
    }
    return $out;
}

/** Список внесённых расходов по scope (для отображения). */
function logistics_get_expenses(string $scopeType, int $scopeId): array
{
    logistics_ensure_tables();
    $db = logistics_db();
    $res = $db->query("SELECT * FROM llx_supplier_logistics_expense WHERE scope_type='" . $db->real_escape_string($scopeType) . "' AND scope_id=" . (int)$scopeId . " ORDER BY rowid DESC");
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    return $out;
}

/**
 * Пересчитать себестоимость товаров. Ключевая сложность (найдена и исправлена эмпирически при
 * тестировании): один и тот же заказ может одновременно иметь расход НА СЕБЯ (например сертификат —
 * не относится к остальным заказам партии) И расход НА ВСЮ ПАРТИЮ (например транспорт) — если считать
 * их независимо друг от друга (как было в первой версии), получается двойной учёт: расходы партии
 * блендятся в себестоимость, а затем расходы заказа блендятся ПОВЕРХ уже блендированного результата,
 * искажая цифру. Поэтому здесь всегда пересчитываем ОБЕ группы расходов разом (уровень заказа делится
 * ТОЛЬКО между строками этого заказа, уровень партии — между строками ВСЕХ заказов партии), и только
 * ОДИН раз блендим итог с базовой точкой (остаток до всей этой поставки).
 *
 * $scopeType/$scopeId — то, ЧТО вызвало пересчёт (после какого именно действия), не обязательно то,
 * что реально учитывается — если у заказа есть партия, пересчёт всегда идёт на уровне всей партии.
 */
/**
 * S-3 (внешний QA-аудит, раунд 2, 03.09.2026) — сколько единиц КОНКРЕТНОГО товара реально принято на
 * склад по заказам этой поставки. Источник — те же таблицы приёмки, что читает раздел "Приём по
 * заказу" (`includes/order_receipts.php`), только агрегатом. Нужно, чтобы базовая точка себестоимости
 * не зависела от порядка действий (внесли расход до приёмки или после) — см. вызов в
 * logistics_recompute_cost().
 */
function logistics_received_qty_for_product(mysqli $db, array $orderIds, int $fkProduct): float
{
    if (empty($orderIds) || !$fkProduct) return 0.0;
    $idsCsv = implode(',', array_map('intval', $orderIds));
    $res = $db->query("SELECT COALESCE(SUM(qty),0) AS q FROM llx_receptiondet_batch
        WHERE fk_element IN ($idsCsv) AND fk_product=" . (int)$fkProduct);
    return $res ? (float)$res->fetch_assoc()['q'] : 0.0;
}

function logistics_recompute_cost(string $scopeType, int $scopeId): array
{
    logistics_ensure_tables();
    $db = logistics_db();

    // Определяем, входит ли задействованный заказ (или любой заказ партии) в партию — тогда всегда
    // считаем на уровне ВСЕЙ партии, чтобы расходы уровня заказа и уровня партии не расходились.
    if ($scopeType === 'batch') {
        $batchId = $scopeId;
    } else {
        $resBatch = $db->query("SELECT fk_batch FROM llx_supplier_shipment_batch_order WHERE fk_order=" . (int)$scopeId . " LIMIT 1");
        $batchId = ($resBatch && $resBatch->num_rows > 0) ? (int)$resBatch->fetch_assoc()['fk_batch'] : null;
    }

    if ($batchId !== null) {
        $batch = logistics_get_batch($batchId);
        $orderIds = $batch['order_ids'] ?? [];
        $shipmentKeyType = 'batch';
        $shipmentKeyId = $batchId;
    } else {
        $orderIds = [$scopeId];
        $shipmentKeyType = 'order';
        $shipmentKeyId = $scopeId;
    }
    if (empty($orderIds)) {
        return ['affected_products' => [], 'note' => 'В партии нет заказов — распределять не на что.'];
    }
    $idsCsv = implode(',', array_map('intval', $orderIds));

    // Строки ПО КАЖДОМУ заказу отдельно (нужно для распределения расходов уровня заказа только
    // внутри него самого) — ключ "orderId:fkProduct".
    $res = $db->query("SELECT fk_commande, fk_product, SUM(qty) as total_qty, SUM(qty*subprice) as total_price
        FROM llx_commande_fournisseurdet
        WHERE fk_commande IN ($idsCsv) AND fk_product > 0
        GROUP BY fk_commande, fk_product");
    $lines = [];
    $orderTotalRaw = [];
    $totalRawAllOrders = 0;
    while ($row = $res->fetch_assoc()) {
        $oid = (int)$row['fk_commande'];
        $pid = (int)$row['fk_product'];
        $qty = (float)$row['total_qty'];
        $raw = (float)$row['total_price'];
        $lines[] = ['order' => $oid, 'product' => $pid, 'qty' => $qty, 'raw' => $raw];
        $orderTotalRaw[$oid] = ($orderTotalRaw[$oid] ?? 0) + $raw;
        $totalRawAllOrders += $raw;
    }
    if (empty($lines) || $totalRawAllOrders <= 0) {
        return ['affected_products' => [], 'note' => 'Нет позиций с ценой — распределять не на что.'];
    }

    // Расходы уровня ПАРТИИ (делятся между ВСЕМИ строками всех заказов партии) — 0, если заказ не в партии.
    $batchExpensesUsd = 0.0;
    if ($batchId !== null) {
        $resE = $db->query("SELECT COALESCE(SUM(usd_amount),0) as s FROM llx_supplier_logistics_expense WHERE scope_type='batch' AND scope_id=" . (int)$batchId);
        $batchExpensesUsd = (float)$resE->fetch_assoc()['s'];
    }
    // Расходы уровня КАЖДОГО ЗАКАЗА (делятся только между строками ЭТОГО заказа) — свои у каждого.
    $orderExpensesUsd = [];
    foreach ($orderIds as $oid) {
        $resE = $db->query("SELECT COALESCE(SUM(usd_amount),0) as s FROM llx_supplier_logistics_expense WHERE scope_type='order' AND scope_id=" . (int)$oid);
        $orderExpensesUsd[$oid] = (float)$resE->fetch_assoc()['s'];
    }

    // Агрегируем по товару (один товар мог встретиться в нескольких заказах партии).
    $perProduct = []; // fkProduct => ['qty'=>, 'raw'=>, 'landed'=>]
    foreach ($lines as $line) {
        $oid = $line['order'];
        $pid = $line['product'];
        $batchShare = $totalRawAllOrders > 0 ? $line['raw'] / $totalRawAllOrders : 0;
        $orderShare = ($orderTotalRaw[$oid] ?? 0) > 0 ? $line['raw'] / $orderTotalRaw[$oid] : 0;
        $logisticsForLine = $batchExpensesUsd * $batchShare + ($orderExpensesUsd[$oid] ?? 0) * $orderShare;

        if (!isset($perProduct[$pid])) $perProduct[$pid] = ['qty' => 0, 'raw' => 0, 'landed' => 0];
        $perProduct[$pid]['qty'] += $line['qty'];
        $perProduct[$pid]['raw'] += $line['raw'];
        $perProduct[$pid]['landed'] += $line['raw'] + $logisticsForLine;
    }

    $affected = [];
    foreach ($perProduct as $fkProduct => $info) {
        $qty = max($info['qty'], 0.0001);
        $landedCostPerUnit = round($info['landed'] / $qty, 4);
        $rawPricePerUnit = $info['raw'] / $qty;

        // Базовая точка (остаток/себестоимость ДО ВСЕЙ этой поставки — заказа или партии целиком) —
        // фиксируется один раз при первом расчёте для этой пары "поставка + товар", иначе повторный
        // ввод расхода (сначала фрахт, потом отдельно таможня) усреднял бы уже со своим же предыдущим
        // результатом и задваивал эффект. Ключ — ВСЕГДА уровень поставки (партия целиком, если заказ в
        // ней состоит, иначе сам заказ) — одна и та же точка, из какого бы действия ни запустился пересчёт.
        $resB = $db->query("SELECT prior_qty, prior_cost FROM llx_supplier_landed_baseline
            WHERE scope_type='" . $db->real_escape_string($shipmentKeyType) . "' AND scope_id=" . (int)$shipmentKeyId . " AND fk_product=" . (int)$fkProduct);
        $baseline = ($resB && $resB->num_rows > 0) ? $resB->fetch_assoc() : null;

        if ($baseline) {
            $priorQty = (float)$baseline['prior_qty'];
            $priorCost = (float)$baseline['prior_cost'];
        } else {
            $resStock = $db->query("SELECT COALESCE(SUM(reel),0) as qty FROM llx_product_stock WHERE fk_product=" . (int)$fkProduct);
            $currentStock = (float)$resStock->fetch_assoc()['qty'];
            $resPmp = $db->query("SELECT pmp FROM llx_product WHERE rowid=" . (int)$fkProduct);
            $currentPmp = (float)$resPmp->fetch_assoc()['pmp'];

            // S-3 (внешний QA-аудит, раунд 2, 03.09.2026): раньше здесь безусловно вычиталось ВСЁ
            // ЗАКАЗАННОЕ количество ($qty) — на неявном допущении, что товар этой поставки уже принят
            // на склад. Если расход (фрахт и т.п.) вносили ДО приёмки, допущение неверно: этих единиц
            // на складе ещё нет, и логистика "размазывалась" задним числом на СТАРЫЙ остаток, к этой
            // поставке не относящийся — результат зависел от порядка действий (сначала расход или
            // сначала приёмка). Пользователь подтвердил: расход должен относиться только к товару своей
            // поставки, независимо от порядка ввода. Теперь вычитаем РЕАЛЬНО ПРИНЯТОЕ количество (из тех
            // же таблиц приёмки, что использует раздел "Приём по заказу"): не принято ничего →
            // priorQty = весь текущий остаток (он весь "старый", вклада этой поставки в нём нет).
            $receivedQty = logistics_received_qty_for_product($db, $orderIds, (int)$fkProduct);
            $priorQty = max(0, $currentStock - $receivedQty);
            // ВАЖНО: обычная приёмка (после фикса CLAUDE.md 29.08.2026 "себестоимость товара") сама
            // честно вписывает РЕАЛЬНУЮ (сырую, без логистики) закупочную цену в pmp штатным механизмом
            // Dolibarr — значит $currentPmp уже включает вклад ПРИНЯТОЙ части этой поставки. Отматываем
            // назад ровно принятую часть (не всю заказанную), обратной формулой средневзвешенного —
            // получаем настоящую цену остатка, который был на складе ДО этой поставки.
            if ($priorQty > 0) {
                $priorCost = max(0, (($currentPmp * ($priorQty + $receivedQty)) - ($receivedQty * $rawPricePerUnit)) / $priorQty);
            } else {
                $priorCost = 0; // не было остатка вообще, блендить не с чем
            }
            $db->query("INSERT INTO llx_supplier_landed_baseline (scope_type, scope_id, fk_product, prior_qty, prior_cost)
                VALUES ('" . $db->real_escape_string($shipmentKeyType) . "', " . (int)$shipmentKeyId . ", " . (int)$fkProduct . ", $priorQty, $priorCost)");
        }

        $totalQty = $priorQty + $qty;
        $weightedCost = $totalQty > 0
            ? round(($priorQty * $priorCost + $qty * $landedCostPerUnit) / $totalQty, 4)
            : $landedCostPerUnit;

        $db->query("UPDATE llx_product SET pmp=$weightedCost, cost_price=$weightedCost WHERE rowid=" . (int)$fkProduct);
        $affected[] = ['fk_product' => $fkProduct, 'landed_cost_per_unit' => $landedCostPerUnit, 'new_pmp' => $weightedCost];

        // Отчёт "Себестоимость по товарам" (4.3.4) — снимок ЭТОЙ поставки+товара, отдельно от live
        // pmp (который смешивает ВСЕ поставки этого товара за всё время в одно число).
        $db->query("REPLACE INTO llx_supplier_landed_result
            (scope_type, scope_id, fk_product, qty, raw_price_per_unit, landed_cost_per_unit, computed_at)
            VALUES ('" . $db->real_escape_string($shipmentKeyType) . "', " . (int)$shipmentKeyId . ", " . (int)$fkProduct . ",
            $qty, " . round($rawPricePerUnit, 4) . ", $landedCostPerUnit, '" . date('Y-m-d H:i:s') . "')");
    }

    return ['affected_products' => $affected];
}

/**
 * Удалить неверно введённый расход — единственный способ исправления (не редактирование "на месте",
 * см. CLAUDE.md 02.09.2026). Если по этому расходу реально списывались деньги (fk_bank заполнен —
 * не было перевозчика) — деньги ВОЗВРАЩАЮТСЯ обратной проводкой на тот же счёт, откуда ушли. После
 * удаления автоматически пересчитывает себестоимость (безопасно — пересчёт использует УЖЕ
 * зафиксированную базовую точку, не зависит от того, что произошло со складом с тех пор).
 */
function logistics_delete_expense(int $expenseId): array
{
    logistics_ensure_tables();
    $db = logistics_db();

    $res = $db->query("SELECT * FROM llx_supplier_logistics_expense WHERE rowid=" . (int)$expenseId);
    if (!$res || $res->num_rows === 0) {
        return ['ok' => false, 'error' => 'Расход не найден.'];
    }
    $expense = $res->fetch_assoc();

    $reversalNote = '';
    if (!empty($expense['fk_bank'])) {
        $resBank = $db->query("SELECT fk_account, amount FROM llx_bank WHERE rowid=" . (int)$expense['fk_bank']);
        $bankLine = ($resBank && $resBank->num_rows > 0) ? $resBank->fetch_assoc() : null;
        if ($bankLine) {
            $accountId = (int)$bankLine['fk_account'];
            $refundAmount = abs((float)$bankLine['amount']); // изначально списание было отрицательным
            $label = "Сторно расхода #$expenseId (удалён, ошибочно внесён)";
            $r = $db->query("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro)
                VALUES ('" . date('Y-m-d H:i:s') . "', '" . date('Y-m-d') . "', '" . date('Y-m-d') . "', $refundAmount,
                '" . $db->real_escape_string($label) . "', $accountId, 'VIR', " . LOGISTICS_API_USER_ID . ", 0)");
            if (!$r) {
                return ['ok' => false, 'error' => 'Не удалось вернуть деньги на счёт: ' . $db->error . '. Расход НЕ удалён.'];
            }
            $reversalNote = 'Деньги (' . number_format($refundAmount, 2) . ' $) возвращены на счёт.';
        }
    }

    $scopeType = $expense['scope_type'];
    $scopeId = (int)$expense['scope_id'];

    if (!$db->query("DELETE FROM llx_supplier_logistics_expense WHERE rowid=" . (int)$expenseId)) {
        return ['ok' => false, 'error' => 'Ошибка удаления: ' . $db->error];
    }

    $result = logistics_recompute_cost($scopeType, $scopeId);
    $result['ok'] = true;
    $result['note'] = trim(($reversalNote ? $reversalNote . ' ' : '') . ($result['note'] ?? '') . ' Себестоимость пересчитана.');
    return $result;
}

/**
 * "Пересчитать с нуля" — отдельная, более радикальная операция от простого удаления расхода: сбрасывает
 * САМУ базовую точку (llx_supplier_landed_baseline) для этой поставки и пересчитывает её заново из
 * ТЕКУЩЕГО остатка/pmp. Нужна, если сама базовая точка была зафиксирована неверно (например расчёт
 * впервые запустили раньше, чем нужно).
 *
 * ⚠️ ПРИБЛИЗИТЕЛЬНО, если часть товара из этой поставки уже продана — Dolibarr не ведёт учёт по
 * партиям/поставкам ВНУТРИ остатка, только общий остаток склада, поэтому нельзя точно отличить "сколько
 * из текущего остатка — именно из этой поставки". Если текущий остаток МЕНЬШЕ, чем количество из этой
 * поставки — возвращает предупреждение (не блокирует, по решению пользователя 02.09.2026).
 */
function logistics_reset_and_recompute(string $scopeType, int $scopeId): array
{
    logistics_ensure_tables();
    $db = logistics_db();

    // Та же логика определения ключа поставки (партия целиком, если заказ в ней), что и в recompute.
    if ($scopeType === 'batch') {
        $shipmentKeyType = 'batch';
        $shipmentKeyId = $scopeId;
        $orderIds = (logistics_get_batch($scopeId)['order_ids'] ?? []);
    } else {
        $resBatch = $db->query("SELECT fk_batch FROM llx_supplier_shipment_batch_order WHERE fk_order=" . (int)$scopeId . " LIMIT 1");
        $batchId = ($resBatch && $resBatch->num_rows > 0) ? (int)$resBatch->fetch_assoc()['fk_batch'] : null;
        if ($batchId !== null) {
            $shipmentKeyType = 'batch';
            $shipmentKeyId = $batchId;
            $orderIds = (logistics_get_batch($batchId)['order_ids'] ?? []);
        } else {
            $shipmentKeyType = 'order';
            $shipmentKeyId = $scopeId;
            $orderIds = [$scopeId];
        }
    }

    $warning = '';
    if ($orderIds) {
        $idsCsv = implode(',', array_map('intval', $orderIds));
        $resLines = $db->query("SELECT fk_product, SUM(qty) as qty FROM llx_commande_fournisseurdet
            WHERE fk_commande IN ($idsCsv) AND fk_product > 0 GROUP BY fk_product");
        $shortfalls = [];
        while ($line = $resLines->fetch_assoc()) {
            $pid = (int)$line['fk_product'];
            // S-3: сравнивать надо с РЕАЛЬНО ПРИНЯТЫМ количеством, а не с заказанным — если товар ещё
            // не привезли (или привезли частично), остаток склада законно меньше заказанного, и это
            // НЕ значит, что "часть уже продана". Раньше в таком (совершенно нормальном) случае
            // выдавалось ложное предупреждение о приблизительности результата.
            $receivedQty = logistics_received_qty_for_product($db, $orderIds, $pid);
            $resStock = $db->query("SELECT COALESCE(SUM(reel),0) as qty FROM llx_product_stock WHERE fk_product=" . $pid);
            $currentStock = (float)$resStock->fetch_assoc()['qty'];
            if ($receivedQty > 0 && $currentStock < $receivedQty) {
                $shortfalls[] = $pid;
            }
        }
        if ($shortfalls) {
            $warning = 'ВНИМАНИЕ: часть товара из этой поставки, похоже, уже продана (текущий остаток меньше, чем пришло) — ' .
                'точно отделить "остаток именно из этой поставки" от более ранних поставок того же товара невозможно ' .
                '(Dolibarr не ведёт учёт по партиям внутри остатка), результат для ' . count($shortfalls) . ' товар(ов) приблизительный.';
        }
    }

    $db->query("DELETE FROM llx_supplier_landed_baseline WHERE scope_type='" . $db->real_escape_string($shipmentKeyType) . "' AND scope_id=" . (int)$shipmentKeyId);

    $result = logistics_recompute_cost($scopeType, $scopeId);
    $result['ok'] = true;
    $result['note'] = trim(($warning ? $warning . ' ' : '') . ($result['note'] ?? '') . ' Базовая точка пересчитана заново.');
    return $result;
}

/**
 * Отчёт "Себестоимость по товарам" (4.3.4) — все поставки, для которых хоть раз считали landed-цену,
 * с разбивкой расходов по видам. Источник — llx_supplier_landed_result (обновляется на каждом
 * recompute), не сам pmp (тот один на товар сразу по всем поставкам, для отчёта нужна разбивка ПО
 * КОНКРЕТНОЙ поставке).
 */
function logistics_get_landed_report(): array
{
    logistics_ensure_tables();
    $db = logistics_db();

    $rows = [];
    $res = $db->query("SELECT * FROM llx_supplier_landed_result ORDER BY computed_at DESC");
    while ($row = $res->fetch_assoc()) { $rows[] = $row; }
    if (!$rows) return [];

    // Расходы по каждой поставке (scope_type+scope_id), сгруппированные по виду — одним проходом.
    $expensesByScope = []; // "type:id" => [expense_type => usd_amount]
    $resE = $db->query("SELECT scope_type, scope_id, expense_type, SUM(usd_amount) as s FROM llx_supplier_logistics_expense GROUP BY scope_type, scope_id, expense_type");
    while ($row = $resE->fetch_assoc()) {
        $key = $row['scope_type'] . ':' . $row['scope_id'];
        $expensesByScope[$key][$row['expense_type']] = (float)$row['s'];
    }

    // Ярлык поставки (номер партии/заказа) — batch label или "заказ #id" (сам ref заказа подтягивает
    // вызывающая страница через Dolibarr API, здесь его нет).
    $batchLabels = [];
    $resB = $db->query("SELECT rowid, label FROM llx_supplier_shipment_batch");
    while ($row = $resB->fetch_assoc()) { $batchLabels[(int)$row['rowid']] = $row['label']; }

    // Заказы каждой партии — нужно, чтобы посчитать РЕАЛЬНО ПРИНЯТОЕ количество по поставке (N-1).
    $batchOrders = [];
    $resBO = $db->query("SELECT fk_batch, fk_order FROM llx_supplier_shipment_batch_order");
    while ($row = $resBO->fetch_assoc()) { $batchOrders[(int)$row['fk_batch']][] = (int)$row['fk_order']; }

    foreach ($rows as &$row) {
        $key = $row['scope_type'] . ':' . $row['scope_id'];
        $row['expenses'] = $expensesByScope[$key] ?? [];
        $row['scope_label'] = $row['scope_type'] === 'batch'
            ? ($batchLabels[(int)$row['scope_id']] ?? ('Партия #' . $row['scope_id']))
            : null; // для заказа ярлык (ref) подтягивается по fk_order на странице отчёта через Dolibarr API
        // Для заказов, входящих в партию, полезно знать саму партию тоже — но результат уже посчитан
        // НА УРОВНЕ партии целиком (scope_type='batch' в этом случае), так что для строк scope_type='order'
        // здесь всегда именно самостоятельные заказы (не в партии).

        // N-1 (внешняя приёмка, 03.09.2026): landed-цена по решению пользователя считается от
        // ЗАКАЗАННОГО количества (при недопоставке часть логистики "повисает" на непривезённом товаре —
        // осознанное решение, т.к. поставщик обычно довозит остаток следующей партией бесплатно).
        // Но в отчёте это должно быть ВИДНО: показываем, сколько из заказанного реально принято, чтобы
        // при неполной поставке к цифре относились с поправкой, а не принимали её за точную.
        $orderIds = $row['scope_type'] === 'batch'
            ? ($batchOrders[(int)$row['scope_id']] ?? [])
            : [(int)$row['scope_id']];
        $row['received_qty'] = logistics_received_qty_for_product($db, $orderIds, (int)$row['fk_product']);
        $row['is_partial'] = ($row['received_qty'] + 0.0001) < (float)$row['qty'];
    }
    unset($row);

    return $rows;
}
