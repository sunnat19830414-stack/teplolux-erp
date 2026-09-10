<?php
/**
 * Касса руководства: расходы и передача денег дальше.
 *
 * Расходы пишутся в ТУ ЖЕ таблицу `llx_nt_household_expense`, что и хозрасходы Абдурашида, и с теми
 * же категориями — чтобы траты компании считались в одном месте, а не в двух параллельных учётах.
 * В поле `who` попадает логин (umid/sunnatilla), так что видно, кто потратил.
 */

/** id служебного пользователя Dolibarr, от чьего имени пишутся прямые проводки (api_boss). */
const BOSS_API_USER_ID = 5;

function boss_cash_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Начиная с PHP 8.1 mysqli бросает исключение вместо возврата false — см. пояснение в requests.php. */
function boss_exec(mysqli_stmt $stmt, string $failMessage = 'Ошибка сохранения'): array
{
    try {
        $stmt->execute();
        return ['ok' => true];
    } catch (mysqli_sql_exception $e) {
        return ['ok' => false, 'error' => $failMessage . ': ' . $e->getMessage()];
    }
}

/** Категории расходов — общий справочник с хозрасходами закупщиков. */
function boss_expense_categories(): array
{
    $res = boss_cash_db()->query("SELECT rowid, name FROM llx_nt_expense_category WHERE active = 1 ORDER BY name");
    $out = [];
    if ($res) { while ($row = $res->fetch_assoc()) $out[] = $row; }
    return $out;
}

/**
 * Записать расход: сначала документ в общую таблицу, потом реальное списание с кассы. Порядок такой
 * же, как в остальных инструментах проекта — сначала след, потом деньги, чтобы деньги не ушли без
 * записи, если что-то сорвётся.
 */
function boss_record_expense(DolibarrApi $api, int $accountId, int $categoryId, float $amountUsd,
                             string $comment, string $who): array
{
    if ($amountUsd <= 0) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
    if ($categoryId <= 0) return ['ok' => false, 'error' => 'Выберите вид расхода.'];

    $db = boss_cash_db();
    $today = date('Y-m-d');
    $stmt = $db->prepare("INSERT INTO llx_nt_household_expense
        (fk_category, expense_date, amount_usd, fk_bank, comment, who, datec)
        VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('isdiss', $categoryId, $today, $amountUsd, $accountId, $comment, $who);
    $r = boss_exec($stmt, 'Не удалось записать расход');
    $stmt->close();
    if (!$r['ok']) return $r;

    $balance = $api->getAccountBalance($accountId);
    $warning = '';
    if ($balance !== null && $amountUsd > $balance + 0.01) {
        $warning = 'В кассе было ' . number_format($balance, 2) . ' $ — после этого расхода она уйдёт в минус. ';
    }

    $label = 'Расход (' . $who . ')' . ($comment !== '' ? ' — ' . $comment : '');
    $bank = $api->addBankLine($accountId, $label, -1 * $amountUsd, 'LIQ');
    if ($bank === null) {
        return ['ok' => false, 'error' => 'Расход записан, но деньги НЕ списаны с кассы: ' . $api->lastError
            . '. Поправьте вручную или сообщите Суннату.'];
    }
    return ['ok' => true, 'warning' => $warning];
}

/** Свои расходы за последние 60 дней — чтобы можно было убрать ошибочную запись. */
function boss_my_expenses(string $who, int $limit = 40): array
{
    $db = boss_cash_db();
    $stmt = $db->prepare("SELECT e.rowid, e.expense_date, e.amount_usd, e.comment, e.fk_bank, c.name AS category_name
        FROM llx_nt_household_expense e
        LEFT JOIN llx_nt_expense_category c ON c.rowid = e.fk_category
        WHERE e.who = ? AND e.expense_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ORDER BY e.expense_date DESC, e.rowid DESC LIMIT ?");
    $stmt->bind_param('si', $who, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
    return $out;
}

/**
 * Удалить свой расход: деньги возвращаются обратной проводкой на тот же счёт.
 *
 * LOW-пункт финансового аудита (05.09.2026). Раньше удаление было только в NodirTool и не смотрело
 * на автора — то есть расход руководства мог убрать закупщик. Теперь удаляет только автор, и, чтобы
 * ошибочную запись было чем исправить, кнопка появилась и здесь.
 */
function boss_delete_expense(DolibarrApi $api, int $expenseId, string $who): array
{
    $db = boss_cash_db();
    $stmt = $db->prepare("SELECT * FROM llx_nt_household_expense WHERE rowid = ?");
    $stmt->bind_param('i', $expenseId);
    $stmt->execute();
    $e = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$e) return ['ok' => false, 'error' => 'Расход не найден.'];

    if (mb_strtolower(trim((string)$e['who'])) !== mb_strtolower(trim($who))) {
        return ['ok' => false, 'error' => 'Этот расход внёс ' . ($e['who'] !== '' ? $e['who'] : 'другой сотрудник')
            . ' — удалить его может только он.'];
    }

    // Сколько реально ушло со счёта: у расходов, внесённых здесь, это сумма в долларах; у внесённых
    // в NodirTool может быть сумма в валюте счёта (native_amount) — возвращаем ровно её.
    $back = (float)($e['native_amount'] ?? 0) > 0 ? (float)$e['native_amount'] : (float)$e['amount_usd'];
    $note = '';
    if (!empty($e['fk_bank']) && $back > 0) {
        $r = $api->addBankLine((int)$e['fk_bank'], 'Отмена расхода #' . (int)$expenseId, $back, 'LIQ');
        $note = $r === null
            ? ' ВНИМАНИЕ: деньги на счёт вернуть не удалось (' . $api->lastError . ') — сообщите Суннату.'
            : ' Деньги возвращены на счёт.';
    }
    $del = $db->prepare("DELETE FROM llx_nt_household_expense WHERE rowid = ?");
    $del->bind_param('i', $expenseId);
    $del->execute();
    $del->close();
    return ['ok' => true, 'note' => trim($note)];
}

/**
 * Передать деньги на другой счёт: списание со своей кассы + зачисление на целевой. Если зачисление
 * не прошло, списание НЕ откатываем (обратная проводка вслепую опаснее), но говорим об этом прямо —
 * тот же подход, что при передаче кассы в TeplouxKassa.
 */
/**
 * ⚠️ Валюта (04.09.2026): счета компании разновалютные. Передача 100 из долларовой кассы на сумовый
 * счёт — это НЕ «100 сум»: со счёта уходит 100 долларов, а приходит столько сумов, сколько дал обмен.
 * Поэтому при разных валютах обязателен курс, и на каждый счёт пишется СВОЯ сумма. Раньше одно и то
 * же число писалось на оба счёта — та же ошибка, что нашлась в оплате поставщику.
 *
 * $rate — сколько единиц валюты ПОЛУЧАТЕЛЯ дают за 1 единицу валюты отправителя (например
 * 12700 сум за 1 доллар). При одинаковых валютах не нужен.
 */
function boss_transfer(DolibarrApi $api, int $fromAccountId, int $toAccountId, string $toLabel,
                       float $amount, string $who, string $comment = '',
                       string $fromCur = 'USD', string $toCur = 'USD', float $rate = 1.0): array
{
    if ($amount <= 0) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
    if ($fromAccountId === $toAccountId) return ['ok' => false, 'error' => 'Счёт получателя совпадает с вашим.'];

    $sameCurrency = ($fromCur === $toCur);
    if (!$sameCurrency && $rate <= 0) {
        return ['ok' => false, 'error' => "Укажите курс: сколько {$toCur} за 1 {$fromCur}."];
    }
    $received = $sameCurrency ? $amount : round($amount * $rate, 2);

    $suffix = $comment !== '' ? ' — ' . $comment : '';
    if (!$sameCurrency) {
        $suffix .= ' (' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . ' ' . $fromCur
                 . ' → ' . rtrim(rtrim(number_format($received, 2, '.', ''), '0'), '.') . ' ' . $toCur
                 . ' по курсу ' . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . ')';
    }

    // LOW-пункт финансового аудита (05.09.2026): обе проводки одной транзакцией — на счёт получателя
    // при этом идёт сумма В ЕГО валюте, а не то же число. Раньше при сбое зачисления деньги уже были
    // списаны, и оставалось только предупредить. Остаток проверяется внутри bank_transfer(), под
    // блокировкой по счёту-источнику — два одновременных нажатия не передадут одну сумму дважды.
    require_once __DIR__ . '/bank_transfer.php';
    $tr = bank_transfer([
        'from_account' => $fromAccountId,
        'to_account'   => $toAccountId,
        'amount'       => $amount,
        'received'     => $received,
        'out_label'    => 'Передача: ' . $toLabel . ' (' . $who . ')' . $suffix,
        'in_label'     => 'Получено от: ' . $who . $suffix,
        'type'         => 'LIQ',
        'user_id'      => BOSS_API_USER_ID,
    ]);
    if (empty($tr['ok'])) return ['ok' => false, 'error' => $tr['error']];

    return ['ok' => true, 'received' => $received, 'same_currency' => $sameCurrency];
}
