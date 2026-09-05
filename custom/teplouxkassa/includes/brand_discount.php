<?php
/**
 * Скидка по брендам (02.09.2026, доработано 02.09.2026) — Caleffi/Madas/Sitem/Fantini Cosmi/Mut:
 * 10% по умолчанию, 14,5% (ЗАМЕНА, не добавка) как только сумма ПО КАТАЛОЖНОЙ цене этих 5 брендов в
 * текущей корзине достигает $10 000 (см. $cfg['brand_discount'] в config/shared.php). Клиент с
 * отметкой `monthly_brand_discount` в карточке — СРАЗУ получает 14,5% на эти бренды, без порога
 * (по решению пользователя 02.09.2026 — раньше было "накопительно за месяц", это убрано).
 *
 * ⚠️ 02.09.2026: кассир теперь может РЕДАКТИРОВАТЬ скидку на любую строку корзины вручную — и на
 * товары этих 5 брендов (может отказаться от автоматических 14,5%, даже если порог достигнут — "кассир
 * решает"), и на ЛЮБОЙ другой товар (для остальных брендов автоскидки нет вообще, только вручную).
 * Модель: у строки корзины есть `discount_rate` — `null`, пока кассир её не трогал (тогда при
 * рендере/чекауте используется ПРЕДЛОЖЕННАЯ системой ставка — 0% для обычных товаров, авто-ставка для
 * брендовых), или число (кассир явно ввёл — используется КАК ЕСТЬ, больше не пересчитывается).
 *
 * Скидка НИКОГДА не мутирует `$item['price']` в самой корзине (это каталожная цена, используется как
 * есть везде в проекте) — `bd_apply_discounts()` только добавляет `discount_rate`/`discounted_price`
 * при рендере/чекауте.
 */

/** ID товаров всех 5 брендов сразу — кэш в сессии на час (состав категорий меняется редко). */
function bd_get_eligible_product_ids(DolibarrApi $api, array $cfg): array
{
    $catIds = $cfg['brand_discount']['category_ids'] ?? [];
    if (!$catIds) return [];

    $cacheKey = 'bd_eligible_ids';
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && ($cached['at'] ?? 0) > time() - 3600 && ($cached['cat_ids'] ?? null) === $catIds) {
        return $cached['ids'];
    }

    $ids = [];
    foreach ($catIds as $catId) {
        $r = $api->get("categories/{$catId}/objects?" . http_build_query(['type' => 'product', 'onlyids' => 1]));
        if (is_array($r)) {
            foreach ($r as $pid) { $ids[(int)$pid] = true; }
        }
    }
    $ids = array_keys($ids);
    $_SESSION[$cacheKey] = ['at' => time(), 'cat_ids' => $catIds, 'ids' => $ids];
    return $ids;
}

/** Товар исключён из автоскидки вручную (Mut и т.п.) — extrafield ставится прямо в Dolibarr. */
function bd_is_product_excluded(DolibarrApi $api, int $productId): bool
{
    static $cache = [];
    if (!array_key_exists($productId, $cache)) {
        $p = $api->getProduct($productId, false);
        $opts = is_array($p) ? ($p['array_options'] ?? []) : [];
        $cache[$productId] = !empty($opts['options_no_brand_discount']);
    }
    return $cache[$productId];
}

/** Клиент с отметкой в карточке — сразу получает 14,5% на эти 5 брендов, без порога $10 000. */
function bd_client_gets_high_rate(?array $thirdparty): bool
{
    if (!is_array($thirdparty)) return false;
    $opts = $thirdparty['array_options'] ?? [];
    return !empty($opts['options_monthly_brand_discount']);
}

/**
 * Применить скидку к корзине. Каждая строка корзины может иметь `discount_rate` = null (кассир не
 * трогал — используется ПРЕДЛОЖЕННАЯ ставка) или число (кассир ввёл вручную — используется как есть).
 * Возвращает:
 *  - 'cart' — та же корзина (те же индексы), каждый элемент + 'discount_rate' (ЭФФЕКТИВНАЯ ставка,
 *    ручная или предложенная) и 'discounted_price' (то, что реально пойдёт в счёт вместо item['price']),
 *  - 'suggested_rate' — ставка, которую система предложила бы для брендовых строк (0, если в корзине
 *    вообще нет товаров этих 5 брендов) — для подсказки в интерфейсе, не обязательно совпадает с тем,
 *    что реально применено к каждой строке (кассир мог поправить).
 *  - 'discount_base' — сумма (по каталожной цене) товаров этих 5 брендов в корзине, от которой считался
 *    порог (используется, только если клиент НЕ отмечен галочкой — см. bd_client_gets_high_rate()).
 */
function bd_apply_discounts(DolibarrApi $api, array $cfg, array $cart, ?array $client): array
{
    $bd = $cfg['brand_discount'] ?? null;
    if (!$bd || empty($cart)) {
        return ['cart' => $cart, 'suggested_rate' => 0.0, 'discount_base' => 0.0];
    }

    $eligibleIds = bd_get_eligible_product_ids($api, $cfg);
    $eligibleSet = array_flip($eligibleIds);

    $cartBase = 0.0;
    $eligibleIdxs = [];
    foreach ($cart as $idx => $item) {
        $pid = (int)($item['product_id'] ?? 0);
        if (isset($eligibleSet[$pid]) && !bd_is_product_excluded($api, $pid)) {
            $cartBase += (float)$item['price'] * (float)$item['qty'];
            $eligibleIdxs[] = $idx;
        }
    }

    $suggestedRate = 0.0;
    if ($eligibleIdxs) {
        $suggestedRate = bd_client_gets_high_rate($client)
            ? (float)$bd['rate_high']
            : ($cartBase >= (float)$bd['threshold'] ? (float)$bd['rate_high'] : (float)$bd['rate_normal']);
    }

    foreach ($cart as $idx => &$item) {
        $manual = array_key_exists('discount_rate', $item) && $item['discount_rate'] !== null;
        if ($manual) {
            $rate = max(0.0, min(100.0, (float)$item['discount_rate']));
        } else {
            $rate = in_array($idx, $eligibleIdxs, true) ? $suggestedRate : 0.0;
        }
        $item['discount_rate'] = $rate;
        $item['discount_is_manual'] = $manual;
        $item['discounted_price'] = round((float)$item['price'] * (1 - $rate / 100), 4);
    }
    unset($item);

    return ['cart' => $cart, 'suggested_rate' => $suggestedRate, 'discount_base' => $cartBase];
}

// --- Журнал вручную поставленных скидок (02.09.2026) — "кто/когда/какая скидка", см. интервью ---

function bd_log_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function bd_ensure_log_table(): void
{
    static $done = false;
    if ($done) return;
    bd_log_db()->query("CREATE TABLE IF NOT EXISTS llx_brand_discount_log (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_invoice INT NOT NULL,
        fk_product INT NOT NULL,
        product_label VARCHAR(255) NOT NULL,
        discount_rate DECIMAL(5,2) NOT NULL,
        is_manual TINYINT NOT NULL DEFAULT 0,
        direction VARCHAR(10) NOT NULL,
        who VARCHAR(50) DEFAULT NULL,
        datec DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Записать в журнал все строки счёта, к которым была применена скидка (rate > 0) — и автоматическая
 * (брендовая), и вручную поставленная кассиром, чтобы потом можно было посмотреть "кто/когда/какую
 * скидку дал". Вызывать ПОСЛЕ успешного оформления счёта (checkout), $invoiceId уже реальный.
 */
function bd_log_checkout_discounts(int $invoiceId, array $discountedCart, string $direction, string $who): void
{
    bd_ensure_log_table();
    $db = bd_log_db();
    $now = date('Y-m-d H:i:s');
    foreach ($discountedCart as $item) {
        $rate = (float)($item['discount_rate'] ?? 0);
        if ($rate <= 0.01) continue;
        $stmt = $db->prepare("INSERT INTO llx_brand_discount_log
            (fk_invoice, fk_product, product_label, discount_rate, is_manual, direction, who, datec)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $productId = (int)($item['product_id'] ?? 0);
        $label = (string)($item['label'] ?? '');
        $isManual = !empty($item['discount_is_manual']) ? 1 : 0;
        $stmt->bind_param('iisdisss', $invoiceId, $productId, $label, $rate, $isManual, $direction, $who, $now);
        $stmt->execute();
    }
}
