<?php
/**
 * Загрузка конфигурации текущего направления (по сессии) + проверка входа.
 * Подключать первой строкой в каждой странице, кроме login.php.
 */
require_once __DIR__ . '/session_boot.php';
session_start();

if (empty($_SESSION['direction'])) {
    header('Location: login.php');
    exit;
}

/**
 * CSRF-защита: один токен на сессию (сгенерирован при входе), проверяется централизованно здесь на
 * КАЖДЫЙ POST-запрос любой страницы, которая подключает auth.php — отдельным страницам ничего
 * добавлять не нужно, кроме самого поля csrf_field() в форме. login.php сюда не попадает (там ещё
 * нет аутентифицированной сессии, это отдельный менее ценный риск, сознательно не защищаем).
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_token(): string
{
    return $_SESSION['csrf_token'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['_csrf'] ?? '')) {
        http_response_code(403);
        die('Форма устарела или открыта в другой вкладке — обновите страницу (F5) и попробуйте ещё раз.');
    }
}

$configFile = __DIR__ . '/../config/config.' . $_SESSION['direction'] . '.php';
if (!file_exists($configFile)) {
    session_destroy();
    header('Location: login.php');
    exit;
}
$cfg = require $configFile;
// Общекомпанейские настройки (сумовый счёт, валютные счета) — одни на оба направления, не путать
// с настройками самого направления. Значения из config.<direction>.php имеют приоритет при совпадении.
$cfg = array_merge(require __DIR__ . '/../config/shared.php', $cfg);

require_once __DIR__ . '/dolibarr_api.php';
$api = new DolibarrApi($cfg['api_base_url'], $cfg['api_key']);

/**
 * Сбросить выбор (клиент/поставщик/заказ), если это обычный заход на страницу (не форма) — так
 * возврат через сайдбар снова показывает список/дашборд, а не "застревает" на прошлом выборе.
 * ИСКЛЮЧЕНИЕ: если страницу только что редиректнул client_form.php после создания/редактирования
 * (это тоже GET-запрос!) — выбор должен сохраниться один раз, отметка ставится там же перед редиректом.
 */
function reset_selection_unless_preserved(string $sessionKey): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return;
    }
    if (!empty($_SESSION['_preserve_once'][$sessionKey])) {
        unset($_SESSION['_preserve_once'][$sessionKey]);
        return;
    }
    $_SESSION[$sessionKey] = null;
}

/**
 * Клиент нашего направления? Код должен начинаться с J или T (см. $cfg['ref_prefix']). Общая функция
 * (раньше была только локально в client_form.php) — используется везде, где клиент принимается по ID
 * из запроса (select_client в sale/return/debt/advance/client_form/reports.php, экспорт документов),
 * чтобы нельзя было подставить клиента чужого направления, просто зная/подобрав его id.
 */
function client_belongs_to_direction($thirdparty, string $refPrefix): bool
{
    $code = is_array($thirdparty) ? ($thirdparty['code_client'] ?? '') : '';
    return $code !== '' && stripos($code, $refPrefix) === 0;
}

/**
 * Товар нашего направления? Направление товара определяется по extrafield kod_sap (исходный J.../T...
 * код SAP — НЕ по ref, см. DolibarrApi::searchProducts()). K-6 (внешний QA-аудит, раунд 2, 03.09.2026):
 * раньше sale.php принимал в корзину любой product_id без проверки направления — поиск в самом
 * интерфейсе и так уже фильтрует по направлению, но прямой POST (или гонка между вкладками) мог
 * добавить в корзину чужой товар и списать его с чужого же склада.
 */
function product_belongs_to_direction($product, string $refPrefix): bool
{
    $kodSap = is_array($product) ? ($product['array_options']['options_kod_sap'] ?? '') : '';
    return $kodSap !== '' && stripos($kodSap, $refPrefix) === 0;
}

/**
 * Проверить клиента по ID и направлению, положить в сессию под $sessionKey если всё ок. Возвращает
 * true/false — вызывающий код сам решает, что показать при отказе (сообщение, а не die(), т.к. это
 * используется в обычных POST-обработчиках форм, не как самостоятельная страница).
 */
function select_client_for_direction(DolibarrApi $api, array $cfg, string $sessionKey, int $clientId, string $fallbackName = ''): bool
{
    if (!$clientId) return false;
    $thirdparty = $api->getThirdparty($clientId);
    if (!is_array($thirdparty) || !client_belongs_to_direction($thirdparty, $cfg['ref_prefix'])) {
        return false;
    }
    $_SESSION[$sessionKey] = [
        'id' => $clientId,
        'name' => $thirdparty['name'] ?? $thirdparty['nom'] ?? $fallbackName,
    ];
    return true;
}

/**
 * "Флеш"-сообщение — переживает ОДИН редирект, показывается один раз и пропадает. Нужно для защиты
 * от повторного оформления при обновлении страницы (F5): раньше успешное действие (оформление продажи,
 * приём оплаты и т.п.) рисовало результат ПРЯМО в ответе на тот же POST — F5 после такого заново
 * отправлял браузером тот же POST и повторял действие (двойной счёт/платёж). Теперь сразу после
 * успеха — редирект (POST → GET), а F5 на уже отрисованной странице просто перезагружает GET, ничего
 * не отправляя повторно. flash_set() перед header('Location: ...'), flash_get() — в начале отрисовки.
 */
function flash_set(string $message, string $type, array $extra = []): void
{
    $_SESSION['_flash'] = ['message' => $message, 'type' => $type, 'extra' => $extra];
}

function flash_get(): ?array
{
    if (empty($_SESSION['_flash'])) return null;
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}
