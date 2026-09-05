<?php
/**
 * Проверка входа + загрузка конфига/API-клиента. Подключать первой строкой в каждой странице,
 * кроме login.php. В отличие от TeplouxKassa — конфиг один общий (без направлений).
 */
require_once __DIR__ . '/session_boot.php';
session_start();

$cfg = require __DIR__ . '/../config.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/**
 * Доступ к разделу по логину (04.09.2026). Пока нужен ровно для одного случая — зарплата видна
 * только Нодиру (решение пользователя: оклады всех сотрудников — чувствительные данные). Проверка
 * ЗДЕСЬ, в auth.php, значит срабатывает на КАЖДОЙ странице автоматически — и по прямой ссылке тоже,
 * не только при скрытом пункте меню. Разделы, которых нет в 'page_access', доступны всем логинам.
 */
$__currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__allowedLogins = $cfg['page_access'][$__currentPage] ?? null;
if ($__allowedLogins !== null && !in_array($_SESSION['user']['login'] ?? '', $__allowedLogins, true)) {
    http_response_code(403);
    die('Этот раздел вам недоступен.');
}

/** Виден ли раздел текущему логину — для скрытия пунктов меню (см. includes/layout_top.php). */
function nt_page_allowed(array $cfg, string $page): bool
{
    $allowed = $cfg['page_access'][$page] ?? null;
    return $allowed === null || in_array($_SESSION['user']['login'] ?? '', $allowed, true);
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

require_once __DIR__ . '/dolibarr_api.php';
$api = new DolibarrApi($cfg['api_base_url'], $cfg['api_key']);

/**
 * Сбросить выбор (поставщик), если это обычный заход на страницу (не форма) — так возврат через
 * сайдбар снова показывает список/дашборд, а не "застревает" на прошлом выборе. ИСКЛЮЧЕНИЕ: если
 * страницу только что редиректнул supplier_form.php после создания/редактирования (тоже GET!) —
 * выбор должен сохраниться один раз, отметка ставится там же перед редиректом.
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
 * "Флеш"-сообщение — переживает ОДИН редирект, показывается один раз и пропадает. Нужно для защиты
 * от повторного оформления при обновлении страницы (F5): раньше успешное действие (создание счёта,
 * оплата и т.п.) рисовало результат ПРЯМО в ответе на тот же POST — F5 после такого заново отправлял
 * браузером тот же POST и повторял действие (двойной счёт/платёж). Теперь сразу после успеха —
 * редирект (POST → GET), а F5 на уже отрисованной странице просто перезагружает GET, ничего не
 * отправляя повторно. flash_set() перед header('Location: ...'), flash_get() — в начале отрисовки.
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

/**
 * BUG-N2 (внешний отчёт 02.09.2026, подтверждён снова в раунде 2 03.09.2026) — как ПОКАЗЫВАТЬ номер
 * заказа поставщику. У черновика Dolibarr ещё нет настоящего номера и REST отдаёт сырой служебный
 * "(PROV28)" (внутренний id в скобках) — сбивает с толку рядом с диалогом, который уже сказал
 * "Заказ #28 создан". Показываем "черновик #28".
 *
 * ⚠️ ТОЛЬКО для отображения. Функциональный `$order['ref']` (для документов, API-вызовов, сверки
 * `ref_supplier` со счетами) НИКОГДА не подменять — там нужен настоящий Dolibarr-ref, каким бы он ни был.
 *
 * Общая функция (раньше эта же логика была скопирована отдельно в orders.php и order_view.php, а на
 * "Сводке" (index.php) её вообще не было — из-за чего "(PROV..)" продолжал вылезать там, что и
 * зафиксировал раунд 2).
 */
function nt_order_display_ref(?string $rawRef, $statut, int $id): string
{
    $rawRef = (string)$rawRef;
    return ((int)$statut === 0 && str_starts_with($rawRef, '(PROV')) ? ('черновик #' . $id) : $rawRef;
}
