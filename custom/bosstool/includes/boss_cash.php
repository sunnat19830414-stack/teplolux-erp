<?php
/**
 * Касса руководства: расходы и передача денег дальше.
 *
 * Расходы пишутся в ТУ ЖЕ таблицу `llx_nt_household_expense`, что и хозрасходы Абдурашида, и с теми
 * же категориями — чтобы траты компании считались в одном месте, а не в двух параллельных учётах.
 * В поле `who` попадает логин (umid/sunnatilla), так что видно, кто потратил.
 */

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

    $balance = $api->getAccountBalance($fromAccountId);
    if ($balance === null) return ['ok' => false, 'error' => 'Не удалось узнать остаток кассы: ' . $api->lastError];
    if ($amount > $balance + 0.001) {
        return ['ok' => false, 'error' => 'На счёте всего ' . number_format($balance, 2) . ' ' . $fromCur
            . ' — больше передать нельзя.'];
    }

    $suffix = $comment !== '' ? ' — ' . $comment : '';
    if (!$sameCurrency) {
        $suffix .= ' (' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . ' ' . $fromCur
                 . ' → ' . rtrim(rtrim(number_format($received, 2, '.', ''), '0'), '.') . ' ' . $toCur
                 . ' по курсу ' . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . ')';
    }

    $out = $api->addBankLine($fromAccountId, 'Передача: ' . $toLabel . ' (' . $who . ')' . $suffix, -1 * $amount, 'LIQ');
    if ($out === null) {
        return ['ok' => false, 'error' => 'Не удалось списать деньги: ' . $api->lastError];
    }
    // На счёт получателя — сумма В ЕГО валюте, а не то же число.
    $in = $api->addBankLine($toAccountId, 'Получено от: ' . $who . $suffix, $received, 'LIQ');
    if ($in === null) {
        return ['ok' => false, 'error' => 'Деньги СПИСАНЫ с вашей кассы, но НЕ зачислены получателю: '
            . $api->lastError . '. Обязательно сообщите Суннату — иначе сумма потеряется.'];
    }
    return ['ok' => true, 'received' => $received, 'same_currency' => $sameCurrency];
}
