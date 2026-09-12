<?php
/**
 * Показ денежных сумм с обозначением валюты (05.09.2026, по прямой просьбе пользователя:
 * «долг показываешь на своих валютах — то есть то, что мы должны оплатить, себестоимость считаем
 * в долларах, везде ставь эмблемы валюты, чтобы не путаться»).
 *
 * До этого суммы выводились как `number_format(...) . ' $'` в 128 местах — доллар был вписан
 * жёстко, даже там, где сумма на самом деле в евро. Теперь везде идёт через эти функции.
 *
 * ВАЖНО про смысл: долг контрагенту показывается в валюте, в которой мы ему должны заплатить
 * (валюта счёта поставщика / договорённости с перевозчиком). Себестоимость товара при этом
 * по-прежнему считается в долларах — это базовая валюта учёта, иначе не сложить фрахт с ценой.
 *
 * Копия этого файла лежит в BossTool (как xls_helper.php) — правки вносить в обе.
 */

/** Обозначения валют. Ключ — код валюты Dolibarr. */
const CURRENCY_SYMBOLS = [
    'USD' => '$',
    'EUR' => '€',
    'RUB' => '₽',
    'UZS' => 'сум',
];

/** Сколько знаков после запятой показывать. У сумов копеек не бывает. */
const CURRENCY_DECIMALS = ['UZS' => 0];

/**
 * Обозначение валюты по коду. Терпимо относится к тому, что уже передали сам символ
 * (в BossTool раньше вызывали money($v, '$')) и к неизвестным кодам — тогда вернёт как есть.
 */
function cur_symbol(string $currency): string
{
    $c = strtoupper(trim($currency));
    if ($c === '') return CURRENCY_SYMBOLS['USD'];
    if (isset(CURRENCY_SYMBOLS[$c])) return CURRENCY_SYMBOLS[$c];
    return trim($currency);   // уже символ либо валюта, которой мы ещё не пользовались
}

/** Сумма с обозначением валюты: money(1234.5, 'EUR') → «1 234.50 €». */
function money(float $amount, string $currency = 'USD'): string
{
    $c = strtoupper(trim($currency));
    $dec = CURRENCY_DECIMALS[$c] ?? 2;
    return number_format($amount, $dec, '.', ' ') . ' ' . cur_symbol($currency);
}

/**
 * Долг, который может быть сразу в нескольких валютах: ['EUR' => 1200, 'USD' => 300]
 * → «1 200.00 € + 300.00 $». Складывать разные валюты в одно число нельзя, поэтому показываем
 * рядом. Нулевые и копеечные остатки отбрасываем, чтобы не мусорить.
 *
 * $zeroCurrency — что показать, когда долгов нет вовсе.
 */
function money_by_currency(array $byCurrency, string $zeroCurrency = 'USD'): string
{
    $parts = [];
    foreach ($byCurrency as $cur => $sum) {
        if (abs((float)$sum) < 0.005) continue;
        $parts[] = money((float)$sum, (string)$cur);
    }
    if (!$parts) return money(0.0, $zeroCurrency);
    return implode(' + ', $parts);
}

/**
 * Долларовый эквивалент набора сумм в разных валютах — ТОЛЬКО для сортировки списков
 * («кому должны больше всего») и для себестоимости. На экран это число не выводим: там всегда
 * настоящая валюта.
 *
 * $rates — сколько единиц валюты за 1 доллар (то же направление, что везде в проекте).
 */
function money_usd_equivalent(array $byCurrency, array $rates): float
{
    $usd = 0.0;
    foreach ($byCurrency as $cur => $sum) {
        $c = strtoupper(trim((string)$cur));
        if ($c === 'USD' || $c === '') { $usd += (float)$sum; continue; }
        $rate = (float)($rates[$c] ?? 0);
        if ($rate > 0) $usd += (float)$sum / $rate;
    }
    return round($usd, 2);
}
