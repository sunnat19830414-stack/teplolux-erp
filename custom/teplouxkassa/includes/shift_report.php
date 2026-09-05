<?php
/**
 * Сменный отчёт кассира — "сколько я сегодня продал/принял/выдал/вернул" за один день. Общая функция
 * для shift.php (экран), shift_excel.php и shift_print.php — чтобы не считать одно и то же трижды.
 *
 * Два независимых источника данных, каждый отвечает на свой вопрос:
 * - Счета/возвраты за день (getInvoicesForDirectionByDate) — "сколько продано/возвращено ТОВАРОМ",
 *   включая то, что ушло в долг (реального движения денег может не быть вообще).
 * - Банковские проводки за день (getBankLinesForDate, по кассовому + электронному счёту направления)
 *   — "сколько денег реально поступило/выдано", независимо от того, к продаже какого дня это относится
 *   (оплата долга за вчерашнюю продажу — это ДЕНЬГИ сегодняшнего дня, а продажа "в долг" сегодня —
 *   ноль движения денег, хотя и попадёт в "продано").
 */
function build_shift_report(DolibarrApi $api, array $cfg, string $dateYmd): array
{
    $docs = $api->getInvoicesForDirectionByDate($cfg['ref_prefix'], $dateYmd);
    $sold = 0.0;
    $onCredit = 0.0;
    $returned = 0.0;
    $saleCount = 0;
    $returnCount = 0;
    $adviceCount = 0;
    // K-2 (внешняя приёмка, 03.09.2026): возвраты БЕЗ привязки к счёту — отдельной строкой в отчёте.
    // Это единственный способ уменьшить долг клиента без документа-основания, поэтому за день такие
    // возвраты должны быть видны отдельно, с причинами, а не растворяться в общей сумме "Возвращено".
    $freeReturns = [];
    $freeReturnsTotal = 0.0;

    if (is_array($docs)) {
        foreach ($docs as $d) {
            $type = (int)($d['type'] ?? -1);
            if ($type === 0) {
                $sold += (float)($d['total_ttc'] ?? 0);
                $onCredit += max(0, (float)($d['remaintopay'] ?? 0));
                $saleCount++;
            } elseif ($type === 2) {
                // Отличаем настоящий возврат товара от аванса И от "выдачи денег" (payout.php, режим
                // "другая сумма" — тоже кредит-нота с одной обобщённой строкой без товара, см.
                // CLAUDE.md "TeplouxKassa — выдача денег клиенту") — иначе такая выдача ошибочно
                // попадала бы в "Возвращено" (найдено и исправлено при тестировании этого отчёта:
                // сумма "Возвращено" была завышена ровно на сумму тестовой выдачи денег).
                // mb_stripos, НЕ stripos — см. CLAUDE.md про кириллицу в UTF-8.
                $full = $api->getInvoice((int)$d['id']);
                if (!is_array($full)) continue;
                $lines = $full['lines'] ?? [];
                $isGenericLine = count($lines) === 1 && empty($lines[0]['fk_product'] ?? null);
                $lineLabel = $isGenericLine ? (string)($lines[0]['label'] ?? $lines[0]['desc'] ?? '') : '';
                $isAdvance = $isGenericLine && mb_stripos($lineLabel, 'аванс') !== false;
                $isPayout = $isGenericLine && mb_stripos($lineLabel, 'выдача денег') !== false;
                if ($isAdvance) {
                    $adviceCount++;
                } elseif ($isPayout) {
                    // Не считаем ни в "Возвращено" (это не товар), ни отдельно здесь — сумма уже
                    // корректно попадает в "Выдано" через банковские проводки ниже.
                } else {
                    $amount = abs((float)($d['total_ttc'] ?? 0));
                    $returned += $amount;
                    $returnCount++;
                    // K-2: возврат без счёта отличаем по отсутствию fk_facture_source (у возврата ПО
                    // счёту это поле заполнено — штатная связь Dolibarr, см. createCreditNote()).
                    // Причину берём из заметки документа, куда её кладёт return.php.
                    if (empty($full['fk_facture_source'])) {
                        $note = (string)($full['note_public'] ?? '');
                        $reason = '';
                        if (preg_match('/Причина:\s*(.+?)\s*\(/u', $note, $mR)) $reason = trim($mR[1]);
                        $freeReturns[] = [
                            'ref' => $full['ref'] ?? ('#' . $d['id']),
                            'amount' => $amount,
                            'reason' => $reason !== '' ? $reason : '(причина не указана — возврат оформлен до введения этого поля)',
                        ];
                        $freeReturnsTotal += $amount;
                    }
                }
            }
        }
    }

    // Реальное движение денег — по банковским проводкам кассы/электронного счёта направления, НЕ по
    // суммам счетов (см. докблок выше).
    $moneyIn = ['cash' => 0.0, 'electronic' => 0.0];
    $moneyOut = ['cash' => 0.0, 'electronic' => 0.0];
    $cashAccId = $cfg['payment_accounts']['cash']['id'] ?? null;
    $electronicAccId = $cfg['payment_accounts']['card']['id'] ?? null; // card/qr/transfer - один общий счёт
    foreach (['cash' => $cashAccId, 'electronic' => $electronicAccId] as $key => $accId) {
        if (!$accId) continue;
        $lines = $api->getBankLinesForDate((int)$accId, $dateYmd);
        if (!is_array($lines)) continue;
        foreach ($lines as $l) {
            $label = (string)($l['label'] ?? '');
            // Передача кассы старшему — не приём/выдача клиенту, отдельная операция (видна в debt.php).
            if (mb_stripos($label, 'Передача наличной кассы') !== false) continue;
            $amt = (float)($l['amount'] ?? 0);
            if ($amt > 0.001) $moneyIn[$key] += $amt;
            elseif ($amt < -0.001) $moneyOut[$key] += abs($amt);
        }
    }

    $cashBalanceNow = $cashAccId ? $api->getAccountBalance((int)$cashAccId) : null;

    return [
        'date' => $dateYmd,
        'sold' => $sold,
        'on_credit' => $onCredit,
        'returned' => $returned,
        'sale_count' => $saleCount,
        'return_count' => $returnCount,
        'advance_count' => $adviceCount,
        // K-2: возвраты без счёта — отдельно, с причинами (входят и в общую сумму 'returned' тоже).
        'free_returns' => $freeReturns,
        'free_returns_total' => $freeReturnsTotal,
        'money_in' => $moneyIn,
        'money_out' => $moneyOut,
        'cash_balance_now' => $cashBalanceNow,
    ];
}
