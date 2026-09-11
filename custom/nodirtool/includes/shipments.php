<?php
/**
 * Рейсы перевозчиков (B6 отчёта «Пробелы NodirTool», 05.09.2026).
 *
 * ⚠️ Что здесь СОЗНАТЕЛЬНО не делается: заявка на перевозку и акт выполненных работ НЕ формируются.
 * Разбор реальных документов Теплолюкс (`Desktop\Новая папка\ТРАНСПОРТНЫЙ\`) показал, что и заявка,
 * и акт приходят на бланке САМОГО перевозчика (шапка «BİG WAY LOGISTICS», их адрес и почта) — их
 * составляет он, а не Абдурашид. Пользователь подтвердил: он договаривается о рейсе и цене,
 * организует встречу водителя с поставщиком, дальше груз встречают декларант и директор.
 * Поэтому здесь ведётся договорённость и деньги, а присланные документы просто прикладываются.
 *
 * Как связано с остальным:
 *  - Долг перевозчику и попадание фрахта в себестоимость — через уже готовый
 *    `logistics_record_expense()` с указанным перевозчиком: деньги при этом НЕ двигаются, это
 *    начисление долга (см. includes/logistics.php). Рейс лишь хранит ссылку на созданную строку.
 *  - Долг показывается в валюте договорённости (см. includes/debt.php, 05.09.2026).
 *    Курс нужен только для себестоимости — она считается в долларах.
 *  - Оплата перевозчику — `carrier_pay()` / `shipment_pay()` ниже: две суммы, если валюта счёта
 *    не совпадает с валютой долга (11.09.2026).
 */

require_once __DIR__ . '/logistics.php';
require_once __DIR__ . '/money.php';

/** Размеры машины — из реальных заявок Теплолюкс (тент 90 м³, консолидация и т.п.). */
const SHIPMENT_TRUCK_TYPES = [
    'tent'    => 'Тент (фура)',
    'ref'     => 'Рефрижератор',
    'consol'  => 'Консолидация (сборный груз)',
    'container' => 'Контейнер',
    'small'   => 'Малотоннажная',
    'parovoz' => 'Паровоз (120 м³)',   // добавлено по просьбе Абдурашида, 05.09.2026
    'other'   => 'Другое',
];

function shipments_db(): mysqli
{
    return logistics_db();
}

function shipments_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $db = shipments_db();

    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_shipment (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_carrier INT NOT NULL,
        route_from VARCHAR(190) NOT NULL DEFAULT '',
        route_to VARCHAR(190) NOT NULL DEFAULT '',
        truck_type VARCHAR(20) NOT NULL DEFAULT '',
        scope_type VARCHAR(10) NOT NULL,
        scope_id INT NOT NULL,
        agreed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        rate DECIMAL(18,6) DEFAULT NULL,
        fk_expense INT DEFAULT NULL,
        invoice_number VARCHAR(64) DEFAULT NULL,
        invoice_amount DECIMAL(18,2) DEFAULT NULL,
        invoice_date DATE DEFAULT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        datec DATETIME NOT NULL,
        created_by VARCHAR(100) DEFAULT NULL,
        INDEX idx_carrier (fk_carrier),
        INDEX idx_scope (scope_type, scope_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Оплата перевозчику может относиться к конкретному рейсу/инвойсу — как в вашем акте сверки,
    // где у каждой оплаты указан номер инвойса. Колонка добавляется к уже существующей таблице.
    $db->query("ALTER TABLE llx_carrier_payment ADD COLUMN IF NOT EXISTS fk_shipment INT DEFAULT NULL");

    $done = true;
}

/** Один рейс. */
function shipment_get(int $id): ?array
{
    shipments_ensure_tables();
    $db = shipments_db();
    $stmt = $db->prepare("SELECT * FROM llx_nt_shipment WHERE rowid = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Список рейсов. $carrierId — только по одному перевозчику (для его карточки).
 * Свежие сверху: закупщик работает с текущими рейсами, а не с архивом.
 */
function shipments_list(?int $carrierId = null, int $limit = 200): array
{
    shipments_ensure_tables();
    $db = shipments_db();
    $sql = "SELECT s.*,
                   COALESCE(p.paid, 0) AS paid_native
              FROM llx_nt_shipment s
              LEFT JOIN (
                    SELECT fk_shipment, SUM(native_amount) AS paid
                      FROM llx_carrier_payment
                     WHERE fk_shipment IS NOT NULL
                     GROUP BY fk_shipment
              ) p ON p.fk_shipment = s.rowid";
    $params = []; $types = '';
    if ($carrierId !== null) { $sql .= " WHERE s.fk_carrier = ?"; $params[] = $carrierId; $types .= 'i'; }
    $sql .= " ORDER BY s.rowid DESC LIMIT " . (int)$limit;

    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $r['status'] = shipment_status($r);
        $out[] = $r;
    }
    $stmt->close();
    return $out;
}

/**
 * Положение рейса — выводится из данных, отдельного поля-статуса нет: так оно не может разойтись
 * с реальностью (частая беда «статус проставили, а деньги нет»).
 */
function shipment_status(array $s): array
{
    $agreed = (float)$s['agreed_amount'];
    $invoiced = $s['invoice_amount'] !== null ? (float)$s['invoice_amount'] : null;
    $due = $invoiced ?? $agreed;
    $paid = (float)($s['paid_native'] ?? 0);

    if ($paid + 0.01 >= $due && $due > 0) return ['code' => 'paid',    'label' => 'Оплачен',       'cls' => 'ok'];
    if ($paid > 0.01)                     return ['code' => 'partial', 'label' => 'Оплачен частично', 'cls' => 'warn'];
    if ($invoiced !== null)               return ['code' => 'invoiced','label' => 'Инвойс получен', 'cls' => 'warn'];
    return ['code' => 'agreed', 'label' => 'Договорились', 'cls' => 'muted'];
}

/** Оплачено по рейсу (в валюте рейса). */
function shipment_paid(int $shipmentId): float
{
    shipments_ensure_tables();
    $db = shipments_db();
    $stmt = $db->prepare("SELECT COALESCE(SUM(native_amount),0) s FROM llx_carrier_payment WHERE fk_shipment = ?");
    $stmt->bind_param('i', $shipmentId);
    $stmt->execute();
    $v = (float)$stmt->get_result()->fetch_assoc()['s'];
    $stmt->close();
    return round($v, 2);
}

/**
 * Создать рейс: записываем договорённость и СРАЗУ начисляем долг перевозчику (решение пользователя —
 * долг возникает уже по заявке, до того как груз доехал). Фрахт при этом попадает в себестоимость
 * товаров той партии/заказа.
 *
 * $rate — курс (единиц валюты за доллар); нужен ТОЛЬКО для пересчёта в себестоимость, на размер
 * долга не влияет: долг остаётся в валюте договорённости.
 */
function shipment_create(array $d, string $who): array
{
    shipments_ensure_tables();

    $carrierId = (int)($d['fk_carrier'] ?? 0);
    $amount    = round((float)($d['agreed_amount'] ?? 0), 2);
    $currency  = strtoupper(trim((string)($d['currency'] ?? 'USD'))) ?: 'USD';
    $rate      = isset($d['rate']) && $d['rate'] !== '' ? (float)$d['rate'] : null;
    $scopeType = ($d['scope_type'] ?? '') === 'batch' ? 'batch' : 'order';
    $scopeId   = (int)($d['scope_id'] ?? 0);

    if (!$carrierId)            return ['ok' => false, 'error' => 'Не выбран перевозчик.'];
    if ($amount <= 0)           return ['ok' => false, 'error' => 'Укажите согласованную цену.'];
    if (!$scopeId)              return ['ok' => false, 'error' => 'Укажите, какая партия или заказ едет.'];
    if ($currency !== 'USD' && (!$rate || $rate <= 0)) {
        return ['ok' => false, 'error' => "Укажите курс для суммы в {$currency} — он нужен, чтобы фрахт попал в себестоимость."];
    }

    // Долг + себестоимость: тем же механизмом, что и обычный расход с указанным перевозчиком.
    // accountId здесь не используется (деньги не двигаются, перевозчик указан), передаём 0.
    $comment = trim((string)($d['comment'] ?? ''));
    $label = 'Рейс ' . trim(($d['route_from'] ?? '') . ' — ' . ($d['route_to'] ?? ''), ' —');
    $exp = logistics_record_expense(
        $scopeType, $scopeId, 'freight', $amount, $currency, $rate, 0, $who,
        trim($label . ($comment !== '' ? '. ' . $comment : '')), $carrierId
    );
    if (empty($exp['ok'])) return ['ok' => false, 'error' => $exp['error'] ?? 'Не удалось начислить долг перевозчику.'];

    $db = shipments_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_shipment
        (fk_carrier, route_from, route_to, truck_type, scope_type, scope_id,
         agreed_amount, currency, rate, fk_expense, comment, datec, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)");
    $from = (string)($d['route_from'] ?? '');
    $to   = (string)($d['route_to'] ?? '');
    $truck = (string)($d['truck_type'] ?? '');
    $expId = (int)($exp['expense_id'] ?? 0);
    $stmt->bind_param('issssidsdiss', $carrierId, $from, $to, $truck, $scopeType, $scopeId,
                      $amount, $currency, $rate, $expId, $comment, $who);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();

    return ['ok' => true, 'id' => $id, 'cost' => $exp];
}

/**
 * Изменить рейс. Правка разрешена, пока по нему не прошло ни одной оплаты — после оплаты цифры уже
 * участвуют в расчётах с перевозчиком, менять их задним числом нельзя.
 *
 * Если поменялась ставка/валюта — СТАРОЕ начисление удаляется и создаётся новое, чтобы долг и
 * себестоимость не разошлись с договорённостью. Денег это не касается: у начислений перевозчику
 * банковской проводки нет (см. logistics_record_expense).
 */
function shipment_update(int $id, array $d, string $who): array
{
    shipments_ensure_tables();
    $s = shipment_get($id);
    if (!$s) return ['ok' => false, 'error' => 'Рейс не найден.'];
    if (shipment_paid($id) > 0.01) {
        return ['ok' => false, 'error' => 'По рейсу уже прошла оплата — менять его нельзя. Если ошиблись, сообщите Суннату.'];
    }

    $amount   = round((float)($d['agreed_amount'] ?? 0), 2);
    $currency = strtoupper(trim((string)($d['currency'] ?? 'USD'))) ?: 'USD';
    $rate     = isset($d['rate']) && $d['rate'] !== '' ? (float)$d['rate'] : null;
    if ($amount <= 0) return ['ok' => false, 'error' => 'Укажите согласованную цену.'];
    if ($currency !== 'USD' && (!$rate || $rate <= 0)) {
        return ['ok' => false, 'error' => "Укажите курс для суммы в {$currency}."];
    }

    $moneyChanged = abs($amount - (float)$s['agreed_amount']) > 0.005
                 || $currency !== $s['currency']
                 || (float)($rate ?? 0) !== (float)($s['rate'] ?? 0);

    $newExpenseId = (int)$s['fk_expense'];
    $replaceWarning = '';
    if ($moneyChanged) {
        // M2 (финансовый аудит 05.09.2026): порядок был обратный — сначала удаляли старое
        // начисление, потом создавали новое. Если создание падало, рейс оставался ВООБЩЕ БЕЗ
        // начисления: долг перевозчика и себестоимость молча теряли эту сумму. Теперь наоборот —
        // сначала создаём, старое убираем только после успеха. Порядок выбран так намеренно: при
        // сбое остаётся ЛИШНЕЕ начисление (сразу видно по удвоенному долгу и легко убрать), а не
        // пропавшее (не видно вообще).
        $label = 'Рейс ' . trim(((string)($d['route_from'] ?? $s['route_from'])) . ' — ' . ((string)($d['route_to'] ?? $s['route_to'])), ' —');
        $exp = logistics_record_expense(
            $s['scope_type'], (int)$s['scope_id'], 'freight', $amount, $currency, $rate, 0, $who,
            $label, (int)$s['fk_carrier']
        );
        if (empty($exp['ok'])) return ['ok' => false, 'error' => $exp['error'] ?? 'Не удалось начислить долг.'];
        $newExpenseId = (int)($exp['expense_id'] ?? 0);

        if ($s['fk_expense']) {
            $del = logistics_delete_expense((int)$s['fk_expense']);
            if (empty($del['ok'])) {
                $replaceWarning = 'ВНИМАНИЕ: новая сумма начислена, но прежнее начисление №'
                    . (int)$s['fk_expense'] . ' убрать не удалось (' . ($del['error'] ?? '')
                    . ') — долг перевозчика сейчас задвоен, сообщите Суннату.';
            }
        }
    }

    $db = shipments_db();
    $stmt = $db->prepare("UPDATE llx_nt_shipment
        SET route_from=?, route_to=?, truck_type=?, agreed_amount=?, currency=?, rate=?, fk_expense=?, comment=?
        WHERE rowid=?");
    $from = (string)($d['route_from'] ?? $s['route_from']);
    $to   = (string)($d['route_to'] ?? $s['route_to']);
    $truck = (string)($d['truck_type'] ?? $s['truck_type']);
    $comment = trim((string)($d['comment'] ?? $s['comment']));
    $stmt->bind_param('sssdsdisi', $from, $to, $truck, $amount, $currency, $rate, $newExpenseId, $comment, $id);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'money_changed' => $moneyChanged, 'warning' => $replaceWarning];
}

/**
 * Записать инвойс перевозчика. Если сумма расходится с договорённостью — НЕ применяем молча:
 * возвращаем 'needs_confirm' с обеими цифрами (решение пользователя). Повторный вызов с
 * $confirmed = true пересчитывает долг и себестоимость на фактическую сумму.
 */
function shipment_set_invoice(int $id, array $d, string $who, bool $confirmed = false): array
{
    shipments_ensure_tables();
    $s = shipment_get($id);
    if (!$s) return ['ok' => false, 'error' => 'Рейс не найден.'];

    $number = trim((string)($d['invoice_number'] ?? ''));
    $amount = round((float)($d['invoice_amount'] ?? 0), 2);
    $date   = trim((string)($d['invoice_date'] ?? '')) ?: date('Y-m-d');
    if ($number === '') return ['ok' => false, 'error' => 'Укажите номер инвойса.'];
    if ($amount <= 0)   return ['ok' => false, 'error' => 'Укажите сумму инвойса.'];

    $agreed = (float)$s['agreed_amount'];
    $differs = abs($amount - $agreed) > 0.005;

    if ($differs && !$confirmed) {
        return [
            'ok' => false,
            'needs_confirm' => true,
            'agreed' => $agreed,
            'invoice' => $amount,
            'currency' => $s['currency'],
            'error' => 'Сумма инвойса отличается от договорённости — подтвердите пересчёт.',
        ];
    }

    // Сумма изменилась и подтверждена — переначисляем долг на фактическую.
    if ($differs) {
        if (shipment_paid($id) > 0.01) {
            return ['ok' => false, 'error' => 'По рейсу уже прошла оплата — пересчитать сумму нельзя. Сообщите Суннату.'];
        }
        // M2: тот же порядок, что и при правке рейса — сначала создаём, потом убираем старое.
        $exp = logistics_record_expense(
            $s['scope_type'], (int)$s['scope_id'], 'freight', $amount, $s['currency'],
            $s['rate'] !== null ? (float)$s['rate'] : null, 0, $who,
            'Рейс по инвойсу ' . $number, (int)$s['fk_carrier']
        );
        if (empty($exp['ok'])) return ['ok' => false, 'error' => $exp['error'] ?? 'Не удалось пересчитать долг.'];
        $newExp = (int)($exp['expense_id'] ?? 0);

        if ($s['fk_expense']) {
            $del = logistics_delete_expense((int)$s['fk_expense']);
            if (empty($del['ok'])) {
                $invoiceWarning = 'ВНИМАНИЕ: сумма пересчитана, но прежнее начисление №'
                    . (int)$s['fk_expense'] . ' убрать не удалось — долг перевозчика сейчас задвоен, сообщите Суннату.';
            }
        }
        $db = shipments_db();
        $st = $db->prepare("UPDATE llx_nt_shipment SET fk_expense=? WHERE rowid=?");
        $st->bind_param('ii', $newExp, $id);
        $st->execute(); $st->close();
    }

    $db = shipments_db();
    $stmt = $db->prepare("UPDATE llx_nt_shipment SET invoice_number=?, invoice_amount=?, invoice_date=? WHERE rowid=?");
    $stmt->bind_param('sdsi', $number, $amount, $date, $id);
    $stmt->execute();
    $stmt->close();

    return ['ok' => true, 'recalculated' => $differs, 'agreed' => $agreed, 'invoice' => $amount,
            'warning' => $invoiceWarning ?? ''];
}

/**
 * Удалить рейс — только пока не было оплат. Начисление долга снимается вместе с ним, себестоимость
 * пересчитывается (эту работу делает logistics_delete_expense).
 */
function shipment_delete(int $id): array
{
    shipments_ensure_tables();
    $s = shipment_get($id);
    if (!$s) return ['ok' => false, 'error' => 'Рейс не найден.'];
    if (shipment_paid($id) > 0.01) {
        return ['ok' => false, 'error' => 'По рейсу уже прошла оплата — удалить нельзя.'];
    }
    if ($s['fk_expense']) {
        $del = logistics_delete_expense((int)$s['fk_expense']);
        if (empty($del['ok'])) return ['ok' => false, 'error' => 'Не удалось снять начисление: ' . ($del['error'] ?? '')];
    }
    $db = shipments_db();
    $st = $db->prepare("DELETE FROM llx_nt_shipment WHERE rowid=?");
    $st->bind_param('i', $id);
    $st->execute(); $st->close();
    return ['ok' => true];
}

/** Человеческое название того, что едет: «партия «X»» или «заказ PO…». */
function shipment_scope_label(array $s, ?DolibarrApi $api = null): string
{
    if ($s['scope_type'] === 'batch') {
        $b = logistics_get_batch((int)$s['scope_id']);
        return $b ? ('партия «' . $b['label'] . '»') : ('партия #' . (int)$s['scope_id']);
    }
    if ($api) {
        $o = $api->getSupplierOrder((int)$s['scope_id']);
        if (is_array($o)) {
            return 'заказ ' . nt_order_display_ref($o['ref'] ?? '', $o['statut'] ?? 0, (int)$s['scope_id']);
        }
    }
    return 'заказ #' . (int)$s['scope_id'];
}

/**
 * Сколько выбрано по договору с перевозчиком — сумма рейсов с даты начала договора, В ВАЛЮТЕ
 * договора. Тот же смысл, что и «потрачено» по контракту поставщика (см. suppliers.php), только
 * считается по рейсам, а не по заказам.
 *
 * Рейсы в других валютах в лимит НЕ засчитываются: пересчитывать их по сегодняшнему курсу значило бы
 * показывать лимит, который меняется сам по себе. Такие рейсы возвращаются отдельно, чтобы о них
 * можно было честно сказать.
 */
function shipments_contract_usage(int $carrierId, string $contractCurrency, ?int $sinceTs): array
{
    shipments_ensure_tables();
    $db = shipments_db();
    $contractCurrency = strtoupper($contractCurrency ?: 'USD');

    $sql = "SELECT UPPER(COALESCE(NULLIF(currency,''),'USD')) cur,
                   SUM(COALESCE(invoice_amount, agreed_amount)) s, COUNT(*) n
              FROM llx_nt_shipment WHERE fk_carrier = ?";
    $params = [$carrierId]; $types = 'i';
    if ($sinceTs) { $sql .= " AND datec >= ?"; $params[] = date('Y-m-d 00:00:00', $sinceTs); $types .= 's'; }
    $sql .= " GROUP BY cur";

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $used = 0.0; $count = 0; $otherCurrencies = [];
    while ($r = $res->fetch_assoc()) {
        if ($r['cur'] === $contractCurrency) { $used = (float)$r['s']; $count = (int)$r['n']; }
        else $otherCurrencies[$r['cur']] = (float)$r['s'];
    }
    $stmt->close();
    return ['used' => round($used, 2), 'count' => $count, 'other' => $otherCurrencies];
}

/**
 * Рейс, которым едет этот заказ (R3 отчёта «Пробелы NodirTool», 05.09.2026).
 *
 * Ищет и прямой рейс на заказ, и рейс на партию, в которую заказ входит. Нужно, чтобы в разделе
 * «Логистика» перевозчик показывался НАСТОЯЩИМ контрагентом — раньше там было отдельное текстовое
 * поле, никак не связанное с разделом «Перевозчики», и одна компания записывалась двумя способами.
 */
function shipment_for_order(int $orderId): ?array
{
    shipments_ensure_tables();
    $db = shipments_db();
    $stmt = $db->prepare("
        SELECT s.* FROM llx_nt_shipment s
         WHERE (s.scope_type = 'order' AND s.scope_id = ?)
            OR (s.scope_type = 'batch' AND s.scope_id IN (
                    SELECT fk_batch FROM llx_supplier_shipment_batch_order WHERE fk_order = ?))
         ORDER BY s.rowid DESC LIMIT 1");
    $stmt->bind_param('ii', $orderId, $orderId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ?: null;
}

/** Рейсы сразу по списку заказов — [orderId => рейс]. Без N+1 на странице «Логистика». */
function shipments_for_orders(array $orderIds): array
{
    $out = [];
    foreach (array_unique(array_map('intval', $orderIds)) as $oid) {
        $sh = shipment_for_order($oid);
        if ($sh) $out[$oid] = $sh;
    }
    return $out;
}

/**
 * Оплата перевозчику с учётом валюты долга (11.09.2026, решение пользователя).
 *
 * Долг перевозчику ведётся в валюте договорённости (debt.php, carrier_debt_by_currency), а платят
 * часто в другой: рейс в 3 500 EUR оплатили долларами из кассы или сумами с банка. Раньше оплата
 * записывалась в валюте счёта — и долг показывался как «3 500 EUR и переплата 4 070 USD» вместо
 * «долга нет», а в рейсе «оплачено 4 070 €». Поэтому две суммы:
 *   $bankAmount — сколько реально ушло со счёта, в ЕГО валюте (проводка в банке/кассе);
 *   $debtAmount — сколько этим закрыто долга, в валюте ДОЛГА (строка оплаты перевозчику).
 * Сколько евро закрыто, вводит человек, а не программа: при наличной оплате курс договорной,
 * и знает его только тот, кто договаривался. Если валюты совпадают — это одно число.
 *
 * $bankRate — «единиц валюты счёта за 1 $», нужен для долларового эквивалента, если счёт не в долларах.
 *
 * ⚠️ Не учитывается курсовая разница: фрахт в себестоимости зафиксирован при записи рейса (по курсу
 * на тот момент). Если заплатили по другому курсу, разница в себестоимость сама не попадёт.
 */
function carrier_pay(int $carrierId, int $accountId, string $accountCurrency, float $bankAmount, ?float $bankRate,
                     string $debtCurrency, float $debtAmount, string $who, string $comment = '',
                     ?int $shipmentId = null): array
{
    $accountCurrency = strtoupper($accountCurrency);
    $debtCurrency = strtoupper($debtCurrency) ?: 'USD';
    if ($accountCurrency === $debtCurrency) $debtAmount = $bankAmount;   // одна валюта — одно число
    $bankAmount = round($bankAmount, 2);
    $debtAmount = round($debtAmount, 2);
    if ($carrierId <= 0) return ['ok' => false, 'error' => 'Перевозчик не определён.'];
    if ($bankAmount <= 0) return ['ok' => false, 'error' => 'Укажите, сколько ушло со счёта.'];
    if ($debtAmount <= 0) return ['ok' => false, 'error' => "Укажите, сколько {$debtCurrency} этим закрыто."];
    if ($accountCurrency !== 'USD' && (!$bankRate || $bankRate <= 0))
        return ['ok' => false, 'error' => "Укажите курс {$accountCurrency} за 1 \$."];

    // долларовый эквивалент — для сводок; берём с той стороны, где есть доллары
    $usd = $accountCurrency === 'USD' ? $bankAmount
         : ($debtCurrency === 'USD' ? $debtAmount : round($bankAmount / $bankRate, 2));

    $db = logistics_db();
    $bal = (float)$db->query("SELECT COALESCE(SUM(amount),0) b FROM llx_bank WHERE fk_account=" . (int)$accountId)->fetch_assoc()['b'];
    $warn = $bankAmount > $bal + 0.01
        ? 'ВНИМАНИЕ: на счету было ' . number_format($bal, 2, '.', ' ') . ' — после этой оплаты счёт ушёл в минус. ' : '';

    $label = "Оплата перевозчику #{$carrierId}" . ($shipmentId ? ", рейс #{$shipmentId}" : '') . " ({$who})";
    $now = date('Y-m-d H:i:s'); $today = date('Y-m-d'); $uid = LOGISTICS_API_USER_ID;
    $fullComment = trim($comment . ($accountCurrency !== $debtCurrency
        ? " [списано {$bankAmount} {$accountCurrency} за {$debtAmount} {$debtCurrency}]" : ''));
    $db->begin_transaction();
    try {
        $st = $db->prepare("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro)
                            VALUES (?, ?, ?, ?, ?, ?, 'VIR', ?, 0)");
        $neg = -$bankAmount;
        $st->bind_param('sssdsii', $now, $today, $today, $neg, $label, $accountId, $uid);
        $st->execute(); $bankId = (int)$db->insert_id; $st->close();

        $st = $db->prepare("INSERT INTO llx_carrier_payment
            (fk_carrier, native_amount, native_currency, rate, usd_amount, fk_bank, datec, fk_user, comment, fk_shipment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->bind_param('idsddisisi', $carrierId, $debtAmount, $debtCurrency, $bankRate, $usd, $bankId, $now, $uid, $fullComment, $shipmentId);
        $st->execute(); $st->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Оплата не записана: ' . $e->getMessage()];
    }
    return ['ok' => true, 'warning' => $warn, 'bank_amount' => $bankAmount, 'debt_amount' => $debtAmount,
            'message' => 'Оплачено ' . money($bankAmount, $accountCurrency) .
                         ($accountCurrency !== $debtCurrency ? ' — закрыто ' . money($debtAmount, $debtCurrency) . ' долга' : '') . '.'];
}

/**
 * Оплата конкретного рейса — из его карточки или из «Перевозчиков» с выбранным рейсом.
 * Перевозчик и валюта долга берутся из рейса; оплатить больше остатка нельзя.
 * Проверка остатка и запись — под одной блокировкой, иначе двойное нажатие оплатит дважды.
 */
function shipment_pay(int $shipmentId, int $accountId, string $accountCurrency, float $bankAmount,
                      ?float $bankRate, float $debtAmount, string $who, string $comment = ''): array
{
    require_once __DIR__ . '/named_lock.php';
    return with_named_lock('shipment_pay_' . $shipmentId, function () use (
        $shipmentId, $accountId, $accountCurrency, $bankAmount, $bankRate, $debtAmount, $who, $comment) {

        $s = shipment_get($shipmentId);
        if (!$s) return ['ok' => false, 'error' => 'Рейс не найден.'];
        $debtCur = strtoupper((string)$s['currency']) ?: 'USD';
        if (strtoupper($accountCurrency) === $debtCur) $debtAmount = $bankAmount;

        $due = $s['invoice_amount'] !== null ? (float)$s['invoice_amount'] : (float)$s['agreed_amount'];
        $left = round($due - shipment_paid($shipmentId), 2);
        if (round($debtAmount, 2) > $left + 0.01)
            return ['ok' => false, 'error' => 'По рейсу осталось оплатить ' . money($left, $debtCur) .
                   ' — больше закрыть нельзя. Если перевозчик выставил другую сумму, сначала запишите его инвойс.'];

        $r = carrier_pay((int)$s['fk_carrier'], $accountId, $accountCurrency, $bankAmount, $bankRate,
                         $debtCur, $debtAmount, $who, $comment, $shipmentId);
        if (!empty($r['ok'])) {
            $leftAfter = round($left - $r['debt_amount'], 2);
            $r['message'] .= ' ' . ($leftAfter > 0.01 ? 'По рейсу осталось ' . money($leftAfter, $debtCur) . '.' : 'Рейс оплачен полностью.');
        }
        return $r;
    });
}

/**
 * Защита от двойного фрахта (11.09.2026, решение пользователя).
 *
 * Фрахт по рейсу уже начислен: долг перевозчику + фрахт в себестоимости. Если потом «оплатить» его
 * через логистический расход заказа или партии, получится не оплата, а ВТОРОЙ расход: деньги уйдут,
 * долг перевозчику не уменьшится, фрахт ляжет в себестоимость дважды (а с выбранным перевозчиком —
 * ещё и долг удвоится). Возвращает рейс, который уже везёт этот заказ/партию, или null.
 */
function shipment_blocking_freight(string $scopeType, int $scopeId): ?array
{
    shipments_ensure_tables();
    $db = shipments_db();
    if ($scopeType === 'order') return shipment_for_order($scopeId);

    // партия: рейс на саму партию или рейс на любой её заказ по отдельности
    $st = $db->prepare("SELECT s.* FROM llx_nt_shipment s
                         WHERE (s.scope_type = 'batch' AND s.scope_id = ?)
                            OR (s.scope_type = 'order' AND s.scope_id IN (
                                  SELECT fk_order FROM llx_supplier_shipment_batch_order WHERE fk_batch = ?))
                         ORDER BY s.rowid DESC LIMIT 1");
    $st->bind_param('ii', $scopeId, $scopeId);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

/** Текст отказа — один на обе формы. */
function shipment_freight_block_message(array $s): string
{
    return 'Фрахт по этому грузу уже начислен рейсом №' . (int)$s['rowid'] . ' (' . money((float)$s['agreed_amount'], (string)$s['currency']) .
           '). Здесь его вносить нельзя: деньги уйдут, а долг перевозчику не уменьшится и фрахт попадёт в себестоимость дважды. ' .
           'Оплатить перевозчику — «Перевозчики» → перевозчик → «Оплатить», в поле «За какой рейс» выберите этот рейс.';
}
