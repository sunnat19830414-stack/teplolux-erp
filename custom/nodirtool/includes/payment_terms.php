<?php
/**
 * Срок оплаты счетов поставщику (B7 отчёта «Пробелы NodirTool», 05.09.2026).
 *
 * У Dolibarr всё для этого уже есть и мы просто этим не пользовались: справочник условий оплаты
 * (`llx_c_payment_term` — по факту, 10/14/30/45/60 дней), поле условия в карточке контрагента
 * (`llx_societe.cond_reglement_supplier`) и срок на самом счёте (`llx_facture_fourn.date_lim_reglement`).
 * До 05.09.2026 они были пустыми у ВСЕХ счетов.
 *
 * ⚠️ Справочник читается прямым SQL, а не через REST: эндпоинт `setup/dictionary/payment_terms`
 * отвечает `Forbidden` — у `api_purchasing` нет права на чтение настроек Dolibarr, и выдавать его
 * ради статического справочника не стоит (тот же принцип минимальных прав, что и везде в проекте).
 *
 * Напоминание про смысл: у 39 поставщиков из 52 стоит предоплата — им мы платим ДО отгрузки, и срок
 * кредита для них не нужен. Поле пригодится тем 13, кто отпускает товар в долг.
 */

function payment_terms_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Человеческие названия — в справочнике Dolibarr они по-английски. */
const PAYMENT_TERM_LABELS = [
    'RECEP'        => 'По факту (сразу)',
    '10D'          => '10 дней',
    '14D'          => '14 дней',
    '30D'          => '30 дней',
    '45D'          => '45 дней',
    '60D'          => '60 дней',
    '10DENDMONTH'  => '10 дней с конца месяца',
    '14DENDMONTH'  => '14 дней с конца месяца',
    '30DENDMONTH'  => '30 дней с конца месяца',
    '45DENDMONTH'  => '45 дней с конца месяца',
    '60DENDMONTH'  => '60 дней с конца месяца',
    'PT_ORDER'     => 'При заказе',
    'PT_DELIVERY'  => 'При поставке',
    'PT_5050'      => '50% и 50%',
];

/** Активные условия оплаты: [id => ['code'=>, 'label'=>, 'days'=>, 'end_month'=>bool]]. */
function payment_terms_list(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $db = payment_terms_db();
    $res = $db->query("SELECT rowid, code, libelle, nbjour, decalage
                         FROM llx_c_payment_term
                        WHERE active = 1
                        ORDER BY nbjour, rowid");
    $cache = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $code = (string)$r['code'];
            $cache[(int)$r['rowid']] = [
                'code'      => $code,
                'label'     => PAYMENT_TERM_LABELS[$code] ?? (string)$r['libelle'],
                'days'      => (int)$r['nbjour'],
                'end_month' => str_contains($code, 'ENDMONTH'),
            ];
        }
    }
    return $cache;
}

/**
 * Срок оплаты счёта: дата счёта + условие. Повторяет расчёт самого Dolibarr
 * (`CommonObject::calculate_date_lim_reglement`) в той части, которой мы пользуемся: сдвиг на
 * nbjour дней, а для условий «с конца месяца» — до конца того месяца, в который попали.
 *
 * Возвращает 'Y-m-d' или null, если условие не задано.
 */
function payment_term_due_date(?int $termId, string $invoiceDate): ?string
{
    if (!$termId) return null;
    $terms = payment_terms_list();
    if (!isset($terms[$termId])) return null;

    $ts = strtotime($invoiceDate);
    if ($ts === false) return null;

    $t = $terms[$termId];
    $due = strtotime('+' . max(0, $t['days']) . ' days', $ts);
    if ($t['end_month']) $due = strtotime(date('Y-m-t', $due));
    return date('Y-m-d', $due);
}

/** Условие оплаты, заданное в карточке поставщика (родное поле Dolibarr). */
function supplier_payment_term(int $socId): ?int
{
    $db = payment_terms_db();
    $stmt = $db->prepare("SELECT cond_reglement_supplier FROM llx_societe WHERE rowid = ?");
    $stmt->bind_param('i', $socId);
    $stmt->execute();
    $v = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($v['cond_reglement_supplier']) ? (int)$v['cond_reglement_supplier'] : null;
}

/**
 * Записать условие оплаты в карточку поставщика — штатным REST
 * (`PUT /thirdparties/{id}` с `cond_reglement_supplier_id`; проверено эмпирически 05.09.2026, поле
 * сохраняется). Прямой SQL здесь не нужен — в отличие от `pmp` у товара, который REST игнорирует.
 */
function set_supplier_payment_term(DolibarrApi $api, int $socId, ?int $termId): bool
{
    return $api->put("thirdparties/{$socId}", ['cond_reglement_supplier_id' => $termId ?: null]) !== null;
}

/** Проставить счёту условие оплаты и посчитанный из него срок. */
function set_invoice_due_date(int $invoiceId, ?int $termId, ?string $dueDate): bool
{
    $db = payment_terms_db();
    $stmt = $db->prepare("UPDATE llx_facture_fourn SET fk_cond_reglement = ?, date_lim_reglement = ? WHERE rowid = ?");
    $t = $termId ?: null;
    $stmt->bind_param('isi', $t, $dueDate, $invoiceId);
    $r = $stmt->execute();
    $stmt->close();
    return (bool)$r;
}

/**
 * Сроки оплаты по списку счетов — одним запросом.
 *
 * ⚠️ Нужно потому, что СПИСОК счетов (`GET /supplierinvoices`) поле `date_lim_reglement` не отдаёт
 * вовсе (проверено эмпирически 05.09.2026) — оно есть только в ответе по одному счёту. Запрашивать
 * каждый счёт отдельно ради даты — это N+1, поэтому берём прямо из базы.
 *
 * Возвращает [invoiceId => 'Y-m-d'].
 */
function invoice_due_dates(array $invoiceIds): array
{
    $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
    if (!$ids) return [];
    $db = payment_terms_db();
    $in = implode(',', $ids);   // только целые после intval
    $res = $db->query("SELECT rowid, date_lim_reglement FROM llx_facture_fourn
                        WHERE rowid IN ($in) AND date_lim_reglement IS NOT NULL");
    $out = [];
    if ($res) while ($r = $res->fetch_assoc()) $out[(int)$r['rowid']] = (string)$r['date_lim_reglement'];
    return $out;
}

/**
 * Просроченные и близкие к сроку счета поставщикам — для Сводки.
 * $soonDays — за сколько дней до срока начинать показывать.
 *
 * Остаток считается в валюте счёта (см. includes/debt.php): платить надо именно её.
 */
function overdue_supplier_invoices(int $soonDays = 3): array
{
    require_once __DIR__ . '/debt.php';
    $today = strtotime(date('Y-m-d'));
    $out = [];
    foreach (supplier_unpaid_invoices() as $inv) {
        if ((float)$inv['remaining_native'] <= 0.01) continue;
        if (empty($inv['date_lim_reglement'])) continue;      // срок не задан — торопить нечем
        $dueTs = strtotime($inv['date_lim_reglement']);
        if ($dueTs === false) continue;
        $daysLeft = (int)round(($dueTs - $today) / 86400);
        if ($daysLeft > $soonDays) continue;
        $inv['days_left'] = $daysLeft;
        $out[] = $inv;
    }
    usort($out, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
    return $out;
}
