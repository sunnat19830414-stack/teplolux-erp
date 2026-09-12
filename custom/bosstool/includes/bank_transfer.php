<?php
/**
 * Перевод денег между счетами: списание и зачисление либо ОБА, либо ни одного.
 *
 * Зачем (LOW-пункт финансового аудита 05.09.2026). Раньше передача кассы делалась двумя отдельными
 * вызовами REST: сначала списание, потом зачисление. Между ними ничего не связывало их в одно целое —
 * если вторая проводка срывалась (обрыв связи с Dolibarr ровно в этот момент), деньги уходили с
 * кассы отправителя и никуда не приходили. Программа об этом честно предупреждала, но поправить надо
 * было руками. Теперь обе строки пишутся напрямую в llx_bank ОДНОЙ транзакцией — при сбое второй
 * первая откатывается сама, и состояние остаётся ровно таким, каким было до нажатия кнопки.
 *
 * Почему прямой SQL, а не REST. У Dolibarr нет вызова «переведи между счетами атомарно», а два вызова
 * подряд — это ровно та проблема, которую чиним. Строка в llx_bank пишется теми же колонками, что
 * пишет сам Dolibarr (сверено построчно с проводкой, созданной через REST: отличается только
 * numero_compte — пустая строка против NULL, на расчёты не влияет). Тем же приёмом в проекте уже
 * пишутся проводки логистики и рекламаций.
 *
 * Заодно закрыта соседняя дыра того же места: остаток счёта проверяется ВНУТРИ блокировки по
 * счёту-источнику. Без неё два одновременных нажатия «Передать» оба видели один и тот же остаток и
 * оба проводили передачу — счёт уходил в минус, а получатель получал вдвое больше (та же природа,
 * что гонки C1/C2/H1 из того же аудита).
 *
 * ОДИНАКОВАЯ копия файла лежит в TeplouxKassa, NodirTool и BossTool — правки вносить во все три
 * (та же договорённость, что для money.php и xls_helper.php).
 */

require_once __DIR__ . '/named_lock.php';

function bank_transfer_db(): mysqli
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
 * Остаток счёта — ровно так же, как его считает сам Dolibarr (Account::solde(1)): будущие операции
 * не учитываются. Иначе цифра в проверке разошлась бы с той, что показана на экране.
 */
function bank_account_balance_direct(int $accountId): float
{
    $db = bank_transfer_db();
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) AS s FROM llx_bank
                          WHERE fk_account = ? AND dateo <= NOW()");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float)($r['s'] ?? 0);
}

/**
 * Перевести деньги со счёта на счёт.
 *
 * $opts:
 *   from_account  int    — откуда списываем (обязательно)
 *   to_account    int    — куда зачисляем (обязательно; без него деньги НЕ трогаем вообще)
 *   amount        ?float — сколько списать; null = весь остаток счёта-источника
 *   received      ?float — сколько зачислить получателю (для разных валют); null = столько же
 *   out_label     string — подпись проводки списания
 *   in_label      string — подпись проводки зачисления
 *   type          string — код способа (c_paiement), по умолчанию 'LIQ' (наличные)
 *   user_id       int    — от чьего имени в Dolibarr
 *
 * Возвращает ['ok'=>bool, 'error'=>string, 'amount'=>float, 'received'=>float].
 */
function bank_transfer(array $opts): array
{
    $from = (int)($opts['from_account'] ?? 0);
    $to   = (int)($opts['to_account'] ?? 0);
    $type = (string)($opts['type'] ?? 'LIQ');
    $userId = (int)($opts['user_id'] ?? 0);
    $outLabel = (string)($opts['out_label'] ?? 'Перевод');
    $inLabel  = (string)($opts['in_label'] ?? 'Перевод');

    if (!$from) return ['ok' => false, 'error' => 'Не указан счёт списания.'];
    if (!$to)   return ['ok' => false, 'error' => 'Счёт получателя не настроен — деньги не тронуты, обратитесь к администратору.'];
    if ($from === $to) return ['ok' => false, 'error' => 'Счёт получателя совпадает со счётом списания.'];

    // Блокировка по счёту-источнику: параллельные передачи с РАЗНЫХ счетов друг другу не мешают.
    if (!acquire_named_lock('bank_transfer_acc_' . $from)) {
        return ['ok' => false, 'error' => 'С этой кассой сейчас идёт другая операция — подождите несколько секунд и повторите.'];
    }

    // Остаток читаем уже под блокировкой — иначе решение принималось бы по устаревшей цифре.
    $balance = bank_account_balance_direct($from);
    $amount = array_key_exists('amount', $opts) && $opts['amount'] !== null
        ? round((float)$opts['amount'], 2)
        : round($balance, 2);

    if ($amount <= 0.001)             return ['ok' => false, 'error' => 'Передавать нечего — укажите сумму больше нуля.'];
    if ($amount > $balance + 0.001)   return ['ok' => false, 'error' => 'На счёте только ' . number_format($balance, 2, '.', ' ') . ' — больше передать нельзя.'];

    $received = isset($opts['received']) && $opts['received'] !== null
        ? round((float)$opts['received'], 2)
        : $amount;
    if ($received <= 0.001) return ['ok' => false, 'error' => 'Сумма к зачислению получилась нулевой — проверьте курс.'];

    $db = bank_transfer_db();
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    try {
        $db->begin_transaction();
        $stmt = $db->prepare("INSERT INTO llx_bank
            (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro, numero_compte)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, '')");

        $outAmount = -1 * $amount;
        $stmt->bind_param('sssdsisi', $now, $today, $today, $outAmount, $outLabel, $from, $type, $userId);
        $stmt->execute();

        $stmt->bind_param('sssdsisi', $now, $today, $today, $received, $inLabel, $to, $type, $userId);
        $stmt->execute();
        $stmt->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();   // ни списания, ни зачисления — как будто кнопку не нажимали
        return ['ok' => false, 'error' => 'Передача не выполнена, деньги остались на месте: ' . $e->getMessage()];
    }

    return ['ok' => true, 'error' => '', 'amount' => $amount, 'received' => $received];
}
