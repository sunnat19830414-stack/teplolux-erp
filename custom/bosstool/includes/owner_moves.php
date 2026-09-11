<?php
/**
 * Вложения и изъятия собственника (11.09.2026, решение пользователя — «вариант 1»).
 *
 * Задача. Умид пополняет счета компании, в том числе из СВОИХ денег. Сколько у него денег всего,
 * программа не знает и знать не должна — он вряд ли станет это вносить. Раньше пополнение шло только
 * «передачей» из его кассы, а касса была пустой: передать больше остатка было нельзя вовсе.
 *
 * Как устроено:
 *  - «Касса Умида» = только деньги КОМПАНИИ, которые у него на руках (передал Суннатилла и т.п.);
 *  - пополнение из своих денег = ВЛОЖЕНИЕ собственника: одна проводка «+сумма» на счёт компании,
 *    касса не трогается;
 *  - пополнение «из кассы» больше, чем в ней есть: из кассы уходит сколько есть, остальное — вложение
 *    (это делает cash.php, здесь две отдельные операции);
 *  - деньги компании, которые Умид забирает себе, = ИЗЪЯТИЕ собственника (решение пользователя):
 *    проводка «−сумма» с его кассы или со счёта компании. Не расход компании — прибыль не уменьшает.
 *
 * Каждая операция — строка в llx_nt_owner_move + проводка в llx_bank одной транзакцией. Подпись
 * проводки начинается с «Собственник:» — по ней отчёт «Деньги» отделяет вложения/изъятия от
 * выручки и расходов (includes/reports.php).
 *
 * Курс — «единиц валюты за 1 $», как везде в проекте; нужен только для долларового итога.
 */

require_once __DIR__ . '/boss_cash.php';
require_once __DIR__ . '/bank_transfer.php';   // bank_account_balance_direct(), блокировки

const OWNER_LABEL_PREFIX = 'Собственник: ';

function owner_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    boss_cash_db()->query("CREATE TABLE IF NOT EXISTS llx_nt_owner_move (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(3) NOT NULL,              -- in = вложение, out = изъятие
        amount DECIMAL(18,2) NOT NULL,
        currency VARCHAR(3) NOT NULL,
        rate DECIMAL(18,6) DEFAULT NULL,       -- единиц валюты за 1 $ (для не-долларовых)
        amount_usd DECIMAL(18,2) NOT NULL,
        fk_account INT NOT NULL,
        fk_bank INT NOT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        who VARCHAR(100) NOT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_kind (kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Одна проводка + строка учёта одной транзакцией. */
function owner_write(string $kind, int $accountId, string $currency, float $amount, ?float $rate,
                     string $who, string $comment, string $label): array
{
    owner_ensure_table();
    $currency = strtoupper($currency) ?: 'USD';
    $amount = round($amount, 2);
    if ($amount <= 0) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
    if ($currency !== 'USD' && (!$rate || $rate <= 0)) return ['ok' => false, 'error' => "Укажите курс: сколько {$currency} за 1 \$."];
    $usd = $currency === 'USD' ? $amount : round($amount / $rate, 2);
    if ($currency === 'USD') $rate = null;

    $db = boss_cash_db();
    $now = date('Y-m-d H:i:s'); $today = date('Y-m-d');
    $signed = $kind === 'in' ? $amount : -$amount;
    $uid = BOSS_API_USER_ID;
    try {
        $db->begin_transaction();
        $st = $db->prepare("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro, numero_compte)
                            VALUES (?, ?, ?, ?, ?, ?, 'LIQ', ?, 0, '')");
        $st->bind_param('sssdsii', $now, $today, $today, $signed, $label, $accountId, $uid);
        $st->execute(); $bankId = (int)$db->insert_id; $st->close();
        $st = $db->prepare("INSERT INTO llx_nt_owner_move (kind, amount, currency, rate, amount_usd, fk_account, fk_bank, comment, who, datec)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->bind_param('sdsddiisss', $kind, $amount, $currency, $rate, $usd, $accountId, $bankId, $comment, $who, $now);
        $st->execute(); $st->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Не записано, деньги не тронуты: ' . $e->getMessage()];
    }
    return ['ok' => true, 'amount' => $amount, 'currency' => $currency, 'usd' => $usd];
}

/** Вложение: +сумма на счёт компании в его валюте. */
function owner_contribute(int $toAccountId, string $toLabel, string $currency, float $amount, ?float $rate,
                          string $who, string $comment = ''): array
{
    $label = OWNER_LABEL_PREFIX . 'вложение (' . $who . ')' . ($comment !== '' ? ' — ' . $comment : '');
    if (strtoupper($currency) !== 'USD' && $rate > 0) {
        $label .= ' (≈ ' . number_format($amount / $rate, 2, '.', '') . ' $ по курсу ' . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . ')';
    }
    return owner_write('in', $toAccountId, $currency, $amount, $rate, $who, $comment, $label);
}

/**
 * Изъятие: −сумма с кассы собственника или со счёта компании (11.09.2026 — «добавь изъятие со счёта
 * компании»). Больше остатка забрать нельзя. $rate — единиц валюты счёта за 1 $, для не-долларовых.
 */
function owner_withdraw(int $fromAccountId, string $currency, float $amount, string $who, string $comment = '',
                        ?float $rate = null): array
{
    if (!acquire_named_lock('bank_transfer_acc_' . $fromAccountId)) {
        return ['ok' => false, 'error' => 'С этой кассой сейчас идёт другая операция — подождите несколько секунд и повторите.'];
    }
    $bal = bank_account_balance_direct($fromAccountId);
    if (round($amount, 2) > $bal + 0.001) {
        return ['ok' => false, 'error' => 'На счёте только ' . number_format($bal, 2, '.', ' ') . ' ' . strtoupper($currency) . ' — больше забрать нельзя.'];
    }
    $label = OWNER_LABEL_PREFIX . 'изъятие (' . $who . ')' . ($comment !== '' ? ' — ' . $comment : '');
    if (strtoupper($currency) !== 'USD' && $rate > 0) {
        $label .= ' (≈ ' . number_format($amount / $rate, 2, '.', '') . ' $ по курсу ' . rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . ')';
    }
    return owner_write('out', $fromAccountId, $currency, $amount, strtoupper($currency) === 'USD' ? null : $rate, $who, $comment, $label);
}

/** Убрать ошибочную запись — вместе с её проводкой. */
function owner_delete(int $id): array
{
    owner_ensure_table();
    $db = boss_cash_db();
    $m = $db->query("SELECT * FROM llx_nt_owner_move WHERE rowid = " . (int)$id)->fetch_assoc();
    if (!$m) return ['ok' => false, 'error' => 'Запись не найдена.'];
    $b = $db->query("SELECT rappro FROM llx_bank WHERE rowid = " . (int)$m['fk_bank'])->fetch_assoc();
    if ($b && (int)$b['rappro'] === 1) return ['ok' => false, 'error' => 'Проводка уже сверена с банком — удалить нельзя.'];
    try {
        $db->begin_transaction();
        $db->query("DELETE FROM llx_bank WHERE rowid = " . (int)$m['fk_bank']);
        $db->query("DELETE FROM llx_nt_owner_move WHERE rowid = " . (int)$id);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Не удалено: ' . $e->getMessage()];
    }
    return ['ok' => true, 'kind' => $m['kind']];
}

/** Итоги по валютам и последние операции. */
function owner_summary(int $limit = 30): array
{
    owner_ensure_table();
    $db = boss_cash_db();
    $in = []; $out = []; $inUsd = 0.0; $outUsd = 0.0;
    $r = $db->query("SELECT kind, currency, SUM(amount) a, SUM(amount_usd) u FROM llx_nt_owner_move GROUP BY kind, currency");
    while ($x = $r->fetch_assoc()) {
        if ($x['kind'] === 'in') { $in[$x['currency']] = (float)$x['a']; $inUsd += (float)$x['u']; }
        else { $out[$x['currency']] = (float)$x['a']; $outUsd += (float)$x['u']; }
    }
    $moves = $db->query("SELECT m.*, a.label account_label FROM llx_nt_owner_move m
                         LEFT JOIN llx_bank_account a ON a.rowid = m.fk_account
                         ORDER BY m.rowid DESC LIMIT " . (int)$limit)->fetch_all(MYSQLI_ASSOC);
    return ['in' => $in, 'out' => $out, 'in_usd' => round($inUsd, 2), 'out_usd' => round($outUsd, 2),
            'net_usd' => round($inUsd - $outUsd, 2), 'moves' => $moves];
}
