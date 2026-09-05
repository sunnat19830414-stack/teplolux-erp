<?php
/**
 * Проверка входа + конфиг/API-клиент. Подключать первой строкой на каждой странице, кроме login.php.
 *
 * Отличие от NodirTool: у пользователя есть НАПРАВЛЕНИЕ. Суннатилла видит только Турк (товары,
 * заявки, продажи, долги), Умид — оба. Ограничение живёт здесь, чтобы его нельзя было обойти
 * прямой ссылкой, а не только скрытым пунктом меню.
 */
require_once __DIR__ . '/session_boot.php';
session_start();

$cfg = require __DIR__ . '/../config.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/** Направление текущего пользователя: 'J' | 'T' | null (оба). */
function user_direction(): ?string
{
    return $_SESSION['user']['direction'] ?? null;
}

/** Направления, которые пользователю разрешено видеть — для фильтров и заголовков. */
function visible_directions(array $cfg): array
{
    $d = user_direction();
    return $d === null ? array_keys($cfg['directions']) : [$d];
}

/** Можно ли пользователю смотреть это направление. */
function can_see_direction(?string $direction): bool
{
    $mine = user_direction();
    if ($mine === null) return true;            // шеф видит всё
    if ($direction === null) return false;      // «общее» для ограниченного пользователя закрыто
    return $direction === $mine;
}

/** Префикс кода клиента/товара по направлению ('J'/'T') — для фильтров Dolibarr. */
function direction_prefixes(array $cfg): array
{
    return visible_directions($cfg);
}

/**
 * CSRF: один токен на сессию, проверяется здесь на каждый POST любой страницы, подключившей auth.php.
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
 * Сбросить выбор при обычном (не POST) заходе — тот же приём, что в кассе и закупках: возврат через
 * меню снова показывает список, а не «застревает» на прошлом выборе. Одноразовый флаг
 * `_preserve_once` ставится перед редиректом там, где выбор нужно сохранить ровно один раз.
 */
function reset_selection_unless_preserved(string $sessionKey): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') return;
    if (!empty($_SESSION['_preserve_once'][$sessionKey])) {
        unset($_SESSION['_preserve_once'][$sessionKey]);
        return;
    }
    $_SESSION[$sessionKey] = null;
}

/** Разовое сообщение, переживающее один редирект (защита от повторной отправки формы по F5). */
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

/** Деньги одинаково во всём инструменте. */
function money(float $v, string $currency = '$'): string
{
    return number_format($v, 2, '.', ' ') . ' ' . $currency;
}

/** Название месяца по-русски (в родительном падеже — «за сентябрь»). PHP-локали на этом сервере ненадёжны. */
function month_name_ru(int $month): string
{
    $names = [1 => 'январь', 'февраль', 'март', 'апрель', 'май', 'июнь',
              'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
    return $names[$month] ?? '';
}
