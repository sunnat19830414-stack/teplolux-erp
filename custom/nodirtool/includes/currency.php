<?php
/**
 * Валюта поставщика и курс (04.09.2026, пункт B2 отчёта «Пробелы NodirTool»).
 *
 * Зачем: с Caleffi, MUT, De Dietrich, ZILIO договариваются и платят в ЕВРО — спецификации подписаны
 * в евро, часть закупок в рублях. В базе это уже отражено (1010 закупочных цен в EUR, 149 в RUB),
 * а интерфейс до сих пор везде показывал и записывал доллары — закупщик пересчитывал в уме.
 *
 * Как считает Dolibarr (проверено эмпирически на живом заказе, 04.09.2026): у заказа поставщику есть
 * `multicurrency_code` и `multicurrency_tx`; если у СТРОКИ передать `multicurrency_subprice`, базовая
 * долларовая цена вычисляется САМА как «цена в валюте / курс заказа» — именно по нашему курсу, а не
 * по справочному курсу Dolibarr. Патчить ядро не понадобилось.
 *
 * Направление курса — как у Dolibarr и как в TeplouxKassa с сумами: СКОЛЬКО ЕДИНИЦ ВАЛЮТЫ ЗА 1 ДОЛЛАР
 * (EUR ≈ 0.86, RUB ≈ 87, UZS ≈ 12700). В интерфейсе рядом всегда показывается обратная величина
 * («1 EUR = 1.16 $»), чтобы направление нельзя было понять неправильно.
 */

const BASE_CURRENCY = 'USD';

/** Валюта договора поставщика; пустое значение в карточке означает доллары. */
function supplier_currency(?array $soc): string
{
    $code = strtoupper(trim((string)($soc['multicurrency_code'] ?? '')));
    return $code === '' ? BASE_CURRENCY : $code;
}

/** Символ/подпись для сумм — короткая, чтобы влезала в таблицы. */
function currency_label(string $code): string
{
    return $code === BASE_CURRENCY ? '$' : $code;
}

/**
 * Свежий курс из справочника Dolibarr (обновляется автоматически раз в сутки) — используется как
 * ПОДСКАЗКА при оформлении: поле предзаполняется этим значением, но остаётся редактируемым, потому
 * что реальный курс сделки может отличаться от справочного (решение пользователя — «курс вводится
 * в моменте»). Возвращает null, если валюта не заведена.
 */
function dolibarr_currency_rate(string $code): ?float
{
    if ($code === BASE_CURRENCY) return 1.0;

    static $cache = [];
    if (array_key_exists($code, $cache)) return $cache[$code];

    $db = require __DIR__ . '/../config/db.local.php';
    $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    $conn->set_charset('utf8mb4');
    $stmt = $conn->prepare(
        "SELECT r.rate FROM llx_multicurrency_rate r
         JOIN llx_multicurrency m ON m.rowid = r.fk_multicurrency
         WHERE m.code = ? ORDER BY r.date_sync DESC LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $rate = $row && (float)$row['rate'] > 0 ? (float)$row['rate'] : null;
    $cache[$code] = $rate;
    return $rate;
}

/**
 * Валюта банковского счёта компании (04.09.2026). Счета у нас реально разновалютные: UZS-MAIN держит
 * сумы, EUR-MAIN — евро. Значит и списание с них должно быть в ИХ валюте, а не в долларах.
 */
function account_currency(int $accountId): string
{
    static $cache = [];
    if (isset($cache[$accountId])) return $cache[$accountId];

    $db = require __DIR__ . '/../config/db.local.php';
    $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    $conn->set_charset('utf8mb4');
    $stmt = $conn->prepare("SELECT currency_code FROM llx_bank_account WHERE rowid = ?");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $cache[$accountId] = strtoupper(trim((string)($row['currency_code'] ?? ''))) ?: 'USD';
}

/**
 * Поправить сумму банковской проводки, созданной платежом Dolibarr.
 *
 * ЗАЧЕМ (найдено пользователем 04.09.2026, реальная потеря денег): Dolibarr пишет в банковскую строку
 * сумму платежа в БАЗОВОЙ валюте компании (долларах), не глядя на валюту самого счёта. У нас счета
 * разновалютные, поэтому оплата счёта на 1000 $ с евро-счёта списывала «1000» с EUR-MAIN — то есть
 * 1000 ЕВРО вместо 1000 долларов. Ни настройкой, ни параметром API это не лечится: `accountid` в
 * `POST /supplierinvoices/{id}/payments` обязателен, а сумму строки Dolibarr берёт свою.
 *
 * Поэтому после платежа переписываем сумму ИМЕННО этой строки на ту, что реально ушла со счёта.
 * Сам платёж (llx_paiementfourn.amount) остаётся в долларах — так и надо, счёт-фактура закрывается
 * долларовой суммой.
 */
function fix_payment_bank_amount(int $paymentId, float $accountAmount): bool
{
    $db = require __DIR__ . '/../config/db.local.php';
    $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    $conn->set_charset('utf8mb4');

    $stmt = $conn->prepare("SELECT fk_bank FROM llx_paiementfourn WHERE rowid = ?");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $bankLineId = (int)($row['fk_bank'] ?? 0);
    if (!$bankLineId) { $conn->close(); return false; }

    $signed = -abs($accountAmount);   // оплата — всегда списание
    $stmt = $conn->prepare("UPDATE llx_bank SET amount = ? WHERE rowid = ?");
    $stmt->bind_param('di', $signed, $bankLineId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return (bool)$ok;
}

/** Пересчёт цены из валюты поставщика в доллары. Курс — единиц валюты за 1 доллар. */
function to_base_currency(float $amount, string $code, float $rate): float
{
    if ($code === BASE_CURRENCY || $rate <= 0) return $amount;
    return $amount / $rate;
}

/**
 * Проверить курс, введённый закупщиком. Возвращает [курс, текст ошибки]; для долларов курс всегда 1.
 * Явно ловим «перевёрнутый» ввод: если для EUR вписать 1.16 вместо 0.86, суммы поедут почти в полтора
 * раза — а формально число выглядит правдоподобно, поэтому сверяем со справочным курсом Dolibarr.
 */
function validate_currency_rate(string $code, $raw): array
{
    if ($code === BASE_CURRENCY) return [1.0, ''];

    $rate = (float)str_replace(',', '.', trim((string)$raw));
    if ($rate <= 0) {
        return [0.0, "Укажите курс: сколько {$code} за 1 доллар."];
    }

    // Порог намеренно узкий (±25% от справочного), а не «в разы». Для рубля и сума перевёрнутый ввод
    // отличается на порядки и был бы виден при любом пороге, а вот для ЕВРО курс близок к единице:
    // перевёрнутые 1.16 вместо 0.86 — это всего 1.35x, и широкий порог их пропускал (поймано тестом
    // 04.09.2026). При этом реальные дневные колебания EUR/RUB — доли процента, так что 25% не мешает.
    $ref = dolibarr_currency_rate($code);
    if ($ref !== null && $ref > 0) {
        $ratio = $rate / $ref;
        if ($ratio > 1.25 || $ratio < 0.8) {
            $refText = rtrim(rtrim(number_format($ref, 4, '.', ''), '0'), '.');
            $hint = '';
            // Если введённое похоже на «доллары за единицу валюты» — подсказываем правильное число.
            if ($rate > 0 && abs((1 / $rate) / $ref - 1) < 0.25) {
                $hint = ' Похоже, курс введён наоборот — тогда нужно ' . rtrim(rtrim(number_format(1 / $rate, 4, '.', ''), '0'), '.') . '.';
            }
            return [0.0, "Курс {$rate} слишком далёк от сегодняшнего: 1 доллар ≈ {$refText} {$code}."
                . $hint . ' Проверьте число — от него зависит себестоимость.'];
        }
    }
    return [$rate, ''];
}
