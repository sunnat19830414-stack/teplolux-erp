<?php
/**
 * Разбор введённой кассиром разбивки оплаты по способам (наличные/карта/QR/перевод), с учётом того,
 * что наличные принимаются сразу в долларах, а карта/QR/перевод физически проходят в сумах — курс
 * на момент операции кассир вводит каждый раз сам (не берём из настроек, см. CLAUDE.md 28.08.2026
 * "Учёт в разных валютах"). Используется во всех местах, где принимается оплата: sale.php,
 * debt.php (точечная оплата счёта и FIFO).
 */

/**
 * @param array $cfg  Конфиг направления (нужен payment_accounts с полем currency у каждого способа)
 * @param array $post $_POST целиком (читает 'pay', 'pay_uzs', 'pay_rate')
 * @return array{amounts: array<string,float>, detail: array<string,array>, errors: string[]}
 *   amounts — способ => сумма в долларах (уже посчитана из сум/курса, если способ в UZS)
 *   detail  — способ => ['usd'=>.., 'uzs'=>?float, 'rate'=>?float] — для квитанции/комментария
 */
function resolvePaySplit(array $cfg, array $post): array
{
    $amounts = [];
    $detail = [];
    $errors = [];

    foreach ($cfg['payment_accounts'] as $key => $acc) {
        $currency = $acc['currency'] ?? 'USD';

        if ($currency === 'UZS') {
            $uzs = (float)($post['pay_uzs'][$key] ?? 0);
            $rate = (float)($post['pay_rate'][$key] ?? 0);
            if ($uzs <= 0.5) continue; // это способ не использовался
            if ($rate <= 0.01) {
                $errors[] = "{$acc['label']}: укажите курс (сум за 1 доллар).";
                continue;
            }
            $usd = round($uzs / $rate, 2);
            $amounts[$key] = $usd;
            $detail[$key] = ['usd' => $usd, 'uzs' => $uzs, 'rate' => $rate];
        } else {
            $usd = (float)($post['pay'][$key] ?? 0);
            if ($usd <= 0.001) continue;
            $amounts[$key] = $usd;
            $detail[$key] = ['usd' => $usd, 'uzs' => null, 'rate' => null];
        }
    }

    return ['amounts' => $amounts, 'detail' => $detail, 'errors' => $errors];
}

/** Короткая подпись способа оплаты для комментария в Dolibarr / квитанции — с расшифровкой суммы в сумах. */
function paySplitLabel(string $methodLabel, array $detail): string
{
    if ($detail['uzs'] !== null) {
        $rateStr = rtrim(rtrim(number_format($detail['rate'], 2, '.', ''), '0'), '.');
        return $methodLabel . ': ' . number_format($detail['uzs'], 0, '.', ' ') . ' сум по курсу ' . $rateStr . ' = ' . number_format($detail['usd'], 2) . ' $';
    }
    return $methodLabel . ' ' . number_format($detail['usd'], 2) . ' $';
}

/**
 * Кладёт РЕАЛЬНУЮ сумму в сумах (ровно ту, что ввёл кассир — БЕЗ пересчёта через доллары и БЕЗ
 * участия курса Dolibarr) на единый сумовый банковский счёт компании — отдельной проводкой,
 * параллельно списанию долга клиента в долларах. Так сумовый счёт всегда отражает то, что реально
 * пришло на р/с, независимо от того, по какому курсу это списали с долга клиента.
 * Не блокирует основной платёж, если не получилось — только предупреждение в списке ошибок.
 *
 * @param array $payDetail  ['способ' => ['usd','uzs','rate'], ...] — из resolvePaySplit()
 * @return string[] Список ошибок (пусто, если всё прошло)
 */
/**
 * Проводка РЕАЛЬНЫХ денег для аванса/предоплаты — когда нет счёта, который "оплачивается" через
 * addPaymentDistributed (кредит-нота не оплачивается так же, как обычный счёт — это привело бы к
 * трактовке "возврат денег клиенту", а не "приняли деньги от клиента"). Поэтому деньги кладём
 * НАПРЯМУЮ: наличные — на кассу направления, сумы (карта/QR/перевод) — на общий сумовый счёт (как и
 * при обычной продаже, тем же принципом, что postUzsLedger, только для наличных тоже нужна своя
 * проводка — обычно это делает сам addPaymentDistributed, но здесь его не вызываем).
 */
function postAdvanceMoney(DolibarrApi $api, array $cfg, array $payDetail, string $comment): array
{
    $errors = [];
    foreach ($payDetail as $key => $detail) {
        $acc = $cfg['payment_accounts'][$key] ?? null;
        if (!$acc) continue;
        if ($detail['uzs'] !== null) {
            // карта/QR/перевод — реальная сумма в сумах идёт на единый сумовый счёт
            $uzsAccountId = $cfg['uzs_account_id'] ?? null;
            if (!$uzsAccountId) { $errors[] = 'Сумовый счёт не настроен.'; continue; }
            $res = $api->addBankLine((int)$uzsAccountId, $comment, $detail['uzs'], $acc['code'] ?? 'VIR');
        } else {
            // наличные — реальные доллары идут прямо в кассу направления
            $res = $api->addBankLine((int)$acc['id'], $comment, $detail['usd'], $acc['code'] ?? 'LIQ');
        }
        if ($res === null) {
            $errors[] = "{$acc['label']}: {$api->lastError}";
        }
    }
    return $errors;
}

/**
 * Проводка РЕАЛЬНЫХ денег для физической выдачи денег клиенту (payout.php) — зеркало
 * postAdvanceMoney(), только суммы СПИСЫВАЮТСЯ со счёта (отрицательные), а не зачисляются. Как и там,
 * не идём через addPaymentDistributed (это была бы семантика "клиент нам заплатил", а не наоборот) —
 * прямая проводка в кассу/сумовый счёт, независимо от закрытия кредит-ноты через setInvoicePaid().
 */
function postPayoutMoney(DolibarrApi $api, array $cfg, array $payDetail, string $comment): array
{
    $errors = [];
    foreach ($payDetail as $key => $detail) {
        $acc = $cfg['payment_accounts'][$key] ?? null;
        if (!$acc) continue;
        if ($detail['uzs'] !== null) {
            $uzsAccountId = $cfg['uzs_account_id'] ?? null;
            if (!$uzsAccountId) { $errors[] = 'Сумовый счёт не настроен.'; continue; }
            $res = $api->addBankLine((int)$uzsAccountId, $comment, -1 * $detail['uzs'], $acc['code'] ?? 'VIR');
        } else {
            $res = $api->addBankLine((int)$acc['id'], $comment, -1 * $detail['usd'], $acc['code'] ?? 'LIQ');
        }
        if ($res === null) {
            $errors[] = "{$acc['label']}: {$api->lastError}";
        }
    }
    return $errors;
}

/**
 * S-2 (внешний QA-аудит, раунд 2, 03.09.2026): если кассир принял по FIFO (debt.php::receive_payment)
 * больше денег, чем весь долг клиента, "лишнее" не применяется ни к одному счёту (уже было решено
 * раньше — не гасить долг сверх реального) — но для НАЛИЧНЫХ (USD, не через UZS-курс) этот излишек
 * физически оставался у кассира в ящике и НИГДЕ не фиксировался: касса в Dolibarr не получала эти
 * деньги, хотя кассир их реально принял. Для сумовых способов такой проблемы никогда не было —
 * postUzsLedger() и так всегда кладёт РЕАЛЬНУЮ введённую сумму в сумах на сумовый счёт, независимо от
 * того, сколько из неё применилось к долгу. Эта функция делает то же самое для наличных: кладёт
 * оставшийся (неприменённый) остаток напрямую на кассовый счёт направления — деньги были у кассира,
 * значит должны быть и в кассе, даже если формально они "сверх долга" (не привязаны к конкретному
 * счёту-долгу, просто лежат на кассе как есть — как обычная переплата, честно отражённая по факту).
 *
 * @param array $leftoverByMethod  ['ключ_способа' => неприменённая сумма в USD, ...] — ТОЛЬКО
 *   доллар-способы (наличные); сумовые сюда не передаются — они уже полностью покрыты postUzsLedger().
 */
function postCashOverage(DolibarrApi $api, array $cfg, array $leftoverByMethod, string $comment): array
{
    $errors = [];
    foreach ($leftoverByMethod as $key => $amount) {
        if ($amount <= 0.01) continue;
        $acc = $cfg['payment_accounts'][$key] ?? null;
        if (!$acc) continue;
        $res = $api->addBankLine((int)$acc['id'], $comment, $amount, $acc['code'] ?? 'LIQ');
        if ($res === null) {
            $errors[] = "{$acc['label']}: {$api->lastError}";
        }
    }
    return $errors;
}

function postUzsLedger(DolibarrApi $api, array $cfg, array $payDetail, string $comment): array
{
    $uzsAccountId = $cfg['uzs_account_id'] ?? null;
    if (!$uzsAccountId) {
        foreach ($payDetail as $detail) {
            if ($detail['uzs'] !== null) return ['Сумовый счёт не настроен — реальная сумма в сумах никуда не записана.'];
        }
        return [];
    }
    $errors = [];
    foreach ($payDetail as $key => $detail) {
        if ($detail['uzs'] === null) continue; // способ в долларах (наличные) — сюда не относится
        $code = $cfg['payment_accounts'][$key]['code'] ?? 'VIR';
        $res = $api->addBankLine((int)$uzsAccountId, $comment, $detail['uzs'], $code);
        if ($res === null) {
            $errors[] = "Сумовый счёт ({$key}): {$api->lastError}";
        }
    }
    return $errors;
}
