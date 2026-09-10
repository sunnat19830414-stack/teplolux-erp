<?php
/**
 * Рекламации (B8 отчёта «Пробелы NodirTool», 05.09.2026).
 *
 * По словам пользователя процесс такой: пришёл брак или недостача → пишем рекламацию → ждём ответа.
 * Заканчивается одним из четырёх исходов, и каждый по-своему касается денег:
 *   1. довозят товаром        — деньги не двигаются, помним, сколько штук за ними;
 *   2. скидывают со следующего счёта — это долг поставщика в деньгах (тот же механизм, что фиксация
 *      недопоставки: кредит-нота уменьшает сальдо);
 *   3. возвращают деньги      — реальное поступление на счёт;
 *   4. отказали               — закрываем с пометкой, убыток наш.
 *
 * Претензия бывает и к перевозчику (бой/недостача в пути) — тогда «скидка со следующего счёта»
 * означает уменьшение долга перевозчику, а не кредит-ноту поставщику: у перевозчиков долг живёт в
 * своих таблицах (см. includes/logistics.php), кредит-нот там нет.
 *
 * Модуль Ticket в Dolibarr выключен, готового механизма нет — своя таблица, как у партий и рейсов.
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/logistics.php';

const CLAIM_TARGETS = [
    'supplier' => 'Поставщику',
    'carrier'  => 'Перевозчику',
];

const CLAIM_RESOLUTIONS = [
    'replace'  => 'Довезут товаром',
    'discount' => 'Скинут со следующего счёта',
    'refund'   => 'Вернули деньги',
    'rejected' => 'Отказали — списываем',
];

function claims_db(): mysqli
{
    return logistics_db();
}

function claims_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    claims_db()->query("CREATE TABLE IF NOT EXISTS llx_nt_claim (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        target_type VARCHAR(10) NOT NULL,
        fk_party INT NOT NULL,
        fk_order INT DEFAULT NULL,
        fk_shipment INT DEFAULT NULL,
        fk_product INT DEFAULT NULL,
        product_label VARCHAR(255) DEFAULT NULL,
        qty DECIMAL(12,3) NOT NULL DEFAULT 0,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        description TEXT,
        status VARCHAR(10) NOT NULL DEFAULT 'open',
        resolution VARCHAR(12) DEFAULT NULL,
        resolution_note VARCHAR(255) DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        datec DATETIME NOT NULL,
        created_by VARCHAR(100) DEFAULT NULL,
        INDEX idx_party (target_type, fk_party),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function claim_get(int $id): ?array
{
    claims_ensure_tables();
    $stmt = claims_db()->prepare("SELECT * FROM llx_nt_claim WHERE rowid = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ?: null;
}

/** Список рекламаций. $onlyOpen — только незакрытые (для Сводки). */
function claims_list(bool $onlyOpen = false, ?string $targetType = null, ?int $partyId = null): array
{
    claims_ensure_tables();
    $db = claims_db();
    $sql = "SELECT * FROM llx_nt_claim WHERE 1=1";
    $params = []; $types = '';
    if ($onlyOpen)            { $sql .= " AND status = 'open'"; }
    if ($targetType !== null) { $sql .= " AND target_type = ?"; $params[] = $targetType; $types .= 's'; }
    if ($partyId !== null)    { $sql .= " AND fk_party = ?";    $params[] = $partyId;    $types .= 'i'; }
    $sql .= " ORDER BY (status='open') DESC, rowid DESC LIMIT 300";

    $stmt = $db->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
    return $out;
}

/** Открыть рекламацию. Деньгами пока ничего не делаем — это делает решение (claim_resolve). */
function claim_create(array $d, string $who): array
{
    claims_ensure_tables();

    $target = ($d['target_type'] ?? '') === 'carrier' ? 'carrier' : 'supplier';
    $partyId = (int)($d['fk_party'] ?? 0);
    $qty = round((float)($d['qty'] ?? 0), 3);
    $amount = round((float)($d['amount'] ?? 0), 2);
    $currency = strtoupper(trim((string)($d['currency'] ?? 'USD'))) ?: 'USD';
    $desc = trim((string)($d['description'] ?? ''));

    if (!$partyId) return ['ok' => false, 'error' => 'Не выбран ' . ($target === 'carrier' ? 'перевозчик' : 'поставщик') . '.'];
    if ($desc === '') return ['ok' => false, 'error' => 'Опишите, что не так — без этого рекламацию не отправить.'];
    if ($amount <= 0) return ['ok' => false, 'error' => 'Укажите сумму претензии.'];

    $db = claims_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_claim
        (target_type, fk_party, fk_order, fk_shipment, fk_product, product_label,
         qty, amount, currency, description, status, datec, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'open', NOW(), ?)");
    $orderId = (int)($d['fk_order'] ?? 0) ?: null;
    $shipId  = (int)($d['fk_shipment'] ?? 0) ?: null;
    $prodId  = (int)($d['fk_product'] ?? 0) ?: null;
    $label   = trim((string)($d['product_label'] ?? ''));
    $stmt->bind_param('siiiisddsss', $target, $partyId, $orderId, $shipId, $prodId, $label,
                      $qty, $amount, $currency, $desc, $who);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    return ['ok' => true, 'id' => $id];
}

/**
 * Закрыть рекламацию выбранным исходом. Здесь и происходит всё, что касается денег.
 *
 * $api нужен только для 'discount' у поставщика (создание кредит-ноты) — у перевозчика и у
 * остальных исходов REST не задействован.
 */
function claim_resolve(int $id, string $resolution, array $d, string $who, DolibarrApi $api): array
{
    claims_ensure_tables();
    if (!isset(CLAIM_RESOLUTIONS[$resolution])) return ['ok' => false, 'error' => 'Неизвестный исход.'];

    // M4 (финансовый аудит 05.09.2026): закрытие рекламации двигает деньги, поэтому берём
    // блокировку по конкретной претензии и ПЕРЕЧИТЫВАЕМ её состояние уже под ней — иначе два
    // параллельных запроса оба увидели бы «открыта» и оба провели бы деньги.
    require_once __DIR__ . '/named_lock.php';
    if (!acquire_named_lock('nodirtool_claim_' . $id)) {
        return ['ok' => false, 'error' => 'Эта рекламация сейчас закрывается в другом окне — подождите несколько секунд.'];
    }

    $c = claim_get($id);
    if (!$c) return ['ok' => false, 'error' => 'Рекламация не найдена.'];
    if ($c['status'] !== 'open') return ['ok' => false, 'error' => 'Эта рекламация уже закрыта.'];

    $note = trim((string)($d['resolution_note'] ?? ''));
    $amount = (float)$c['amount'];
    $currency = (string)$c['currency'];
    $moneyNote = '';
    $inTransaction = false;

    if ($resolution === 'discount') {
        // Долг контрагента в деньгах.
        if ($c['target_type'] === 'supplier') {
            // Тот же приём, что и фиксация недопоставки: кредит-нота без привязки к счёту сразу и
            // корректно уменьшает сальдо поставщика (проверено 02-03.09.2026).
            require_once __DIR__ . '/supplier_statement.php';
            $ref = 'РЕКЛАМАЦИЯ-' . $id . '-' . date('YmdHis');
            $cnId = create_supplier_prepayment_document($api, (int)$c['fk_party'], $amount,
                'Рекламация №' . $id . ($note !== '' ? '. ' . $note : ''), $ref, $currency);
            if (!$cnId) return ['ok' => false, 'error' => 'Не удалось записать долг поставщика: ' . $api->lastError];
            $moneyNote = 'Записано как долг поставщика на ' . money($amount, $currency)
                       . ' — сальдо уменьшилось, зачтётся следующим счётом.';
        } else {
            // У перевозчика кредит-нот нет: уменьшаем его долг отдельной строкой оплаты БЕЗ движения
            // денег — по смыслу это «часть долга закрыта не деньгами, а признанной претензией».
            $db = claims_db();
            $db->begin_transaction();   // M4: запись и закрытие статуса — вместе (см. ниже)
            $now = date('Y-m-d H:i:s');
            $ok = $db->query("INSERT INTO llx_carrier_payment
                (fk_carrier, native_amount, native_currency, rate, usd_amount, fk_bank, datec, fk_user, comment, fk_shipment)
                VALUES (" . (int)$c['fk_party'] . ", " . (float)$amount . ", '" . $db->real_escape_string($currency) . "',
                NULL, 0, NULL, '$now', " . LOGISTICS_API_USER_ID . ",
                '" . $db->real_escape_string('Рекламация №' . $id . ' — признана перевозчиком (без движения денег)') . "',
                " . ($c['fk_shipment'] ? (int)$c['fk_shipment'] : 'NULL') . ")");
            if (!$ok) { $db->rollback(); return ['ok' => false, 'error' => 'Не удалось уменьшить долг перевозчика: ' . $db->error]; }
            $inTransaction = true;
            $moneyNote = 'Долг перевозчика уменьшен на ' . money($amount, $currency) . ' — деньги не двигались.';
        }
    } elseif ($resolution === 'refund') {
        // Реальное поступление на счёт.
        $accountId = (int)($d['account_id'] ?? 0);
        if (!$accountId) return ['ok' => false, 'error' => 'Выберите счёт, куда пришли деньги.'];
        require_once __DIR__ . '/currency.php';
        $accCur = account_currency($accountId);
        if (strtoupper($accCur) !== strtoupper($currency)) {
            return ['ok' => false, 'error' => "Счёт в {$accCur}, а претензия в {$currency} — выберите счёт в той же валюте."];
        }
        $db = claims_db();
        $db->begin_transaction();   // M4: проводка и закрытие статуса — вместе (см. ниже)
        $now = date('Y-m-d H:i:s');
        $label = 'Возврат по рекламации №' . $id . ($note !== '' ? '. ' . $note : '');
        $ok = $db->query("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro)
            VALUES ('$now', '" . date('Y-m-d') . "', '" . date('Y-m-d') . "', " . (float)$amount . ",
            '" . $db->real_escape_string($label) . "', " . $accountId . ", 'VIR', " . LOGISTICS_API_USER_ID . ", 0)");
        if (!$ok) { $db->rollback(); return ['ok' => false, 'error' => 'Не удалось записать поступление: ' . $db->error]; }
        $inTransaction = true;
        $moneyNote = 'Поступление ' . money($amount, $currency) . ' записано на счёт.';
    } elseif ($resolution === 'replace') {
        $moneyNote = 'Деньги не двигались — ждём довоза '
                   . rtrim(rtrim(number_format((float)$c['qty'], 3, '.', ''), '0'), '.') . ' шт.';
    } else { // rejected
        $moneyNote = 'Убыток ' . money($amount, $currency) . ' остаётся на нас.';
    }

    // M4: закрытие статуса — В ТОЙ ЖЕ транзакции, что и движение денег. Раньше они шли порознь:
    // деньги проводились, а если закрытие падало, претензия оставалась открытой — и её можно было
    // закрыть повторно, проведя деньги ещё раз.
    $db = claims_db();
    // mysqli с PHP 8.1 бросает исключение вместо false — без try/catch до отката дело бы не дошло
    // (см. запись 04.09.2026 про payroll_exec).
    $closed = false;
    try {
        $stmt = $db->prepare("UPDATE llx_nt_claim
            SET status='closed', resolution=?, resolution_note=?, resolved_at=NOW() WHERE rowid=?");
        $stmt->bind_param('ssi', $resolution, $note, $id);
        $closed = $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        $closed = false;
    }

    if ($inTransaction) {
        if (!$closed) {
            $db->rollback();   // деньги откатываются вместе со статусом — состояние как было
            return ['ok' => false, 'error' => 'Не удалось закрыть рекламацию — деньги НЕ проведены, повторите.'];
        }
        $db->commit();
    } elseif (!$closed) {
        // Сюда попадаем только для исхода «скинут со следующего счёта» у ПОСТАВЩИКА: кредит-нота
        // создаётся в Dolibarr через REST и в нашу транзакцию не входит по своей природе.
        return ['ok' => false, 'error' => 'Документ долга поставщика создан, но рекламацию закрыть не удалось — '
            . 'закройте её повторно; денег это не касается, документ уже в сальдо. Сообщите Суннату.'];
    }

    return ['ok' => true, 'money_note' => $moneyNote];
}

/** Открытые рекламации — сумма по валютам, для Сводки. */
function claims_open_totals(): array
{
    claims_ensure_tables();
    $res = claims_db()->query("SELECT UPPER(COALESCE(NULLIF(currency,''),'USD')) cur, SUM(amount) s
                                 FROM llx_nt_claim WHERE status='open' GROUP BY cur");
    $out = [];
    if ($res) while ($r = $res->fetch_assoc()) $out[$r['cur']] = round((float)$r['s'], 2);
    return $out;
}
