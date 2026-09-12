<?php
/**
 * Защита входа от перебора паролей (12.09.2026, риск R7 из CLAUDE.md).
 *
 * ОДИНАКОВАЯ копия в TeplouxKassa, NodirTool и BossTool — правки вносить во все три.
 *
 * Зачем. Пароли у инструментов короткие, а ограничения на число попыток не было вообще: пока всё
 * стояло в офисной сети, это терпели. Пользователь решил открыть программы в интернет по HTTPS
 * (12.09.2026) — без такой защиты пароль вроде `606c2ca38c32` перебирается автоматом за считаные часы.
 *
 * Правила за последние 15 минут:
 *   по ЛОГИНУ (с любого адреса): 5 неудач → пауза 15 минут, 10 и больше → 60 минут;
 *   по АДРЕСУ (любые логины):   15 неудач → пауза 15 минут, 30 и больше → 60 минут.
 * Успешный вход стирает неудачи этого логина с этого адреса. Каждая неудача задерживает ответ на
 * секунду: живому человеку незаметно, перебору мешает.
 *
 * ⚠️ Счётчики РАЗНЫЕ намеренно. Весь офис выходит в интернет через один адрес: считай мы только по
 * адресу с порогом 5, три опечатки Жамшида закрыли бы вход и Нодиру, и MuhammadAli. Поэтому свой
 * логин человек блокирует только себе, а по адресу порог втрое выше — он ловит уже настоящий перебор.
 */

const LOGIN_GUARD_WINDOW_MIN = 15;    // за сколько минут считаем неудачи
const LOGIN_GUARD_SOFT_TRIES = 5;     // по логину: после скольких — пауза 15 минут
const LOGIN_GUARD_HARD_TRIES = 10;    // по логину: после скольких — пауза 60 минут
const LOGIN_GUARD_IP_FACTOR = 3;      // по адресу пороги во столько раз выше (офис за одним IP)

function login_guard_db(): ?mysqli
{
    static $conn = false;
    if ($conn !== false) return $conn;
    $file = __DIR__ . '/../config/db.local.php';
    if (!is_file($file)) return $conn = null;
    try {
        $db = require $file;
        $c = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $c->set_charset('utf8mb4');
        $c->query("CREATE TABLE IF NOT EXISTS llx_nt_login_attempt (
            rowid INT AUTO_INCREMENT PRIMARY KEY,
            tool VARCHAR(16) NOT NULL,
            login VARCHAR(64) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            ok TINYINT NOT NULL DEFAULT 0,
            datec DATETIME NOT NULL,
            INDEX idx_lookup (tool, login, datec),
            INDEX idx_ip (tool, ip, datec)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $conn = $c;
    } catch (Throwable $e) {
        // База недоступна — вход не ломаем, просто остаёмся без защиты (и это видно в журнале PHP).
        error_log('login_guard: ' . $e->getMessage());
        return $conn = null;
    }
}

/** Адрес посетителя. За nginx берём X-Forwarded-For, но только когда запрос пришёл с самого сервера. */
function login_guard_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $local = in_array($remote, ['127.0.0.1', '::1'], true);
    if ($local && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $first = trim($parts[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return substr($first, 0, 45);
    }
    return substr($remote, 0, 45) ?: 'unknown';
}

/**
 * Можно ли сейчас пробовать войти. Возвращает ['blocked'=>bool, 'minutes'=>int, 'left'=>int].
 * 'left' — сколько попыток осталось до паузы (для подсказки человеку).
 */
function login_guard_check(string $tool, string $login): array
{
    $db = login_guard_db();
    if (!$db) return ['blocked' => false, 'minutes' => 0, 'left' => LOGIN_GUARD_SOFT_TRIES];
    $ip = login_guard_ip();
    $win = LOGIN_GUARD_WINDOW_MIN;
    $count = function (string $field, string $value) use ($db, $tool, $win) {
        $st = $db->prepare("SELECT COUNT(*) n, MAX(datec) last FROM llx_nt_login_attempt
                            WHERE tool = ? AND ok = 0 AND datec > (NOW() - INTERVAL ? MINUTE) AND `$field` = ?");
        $st->bind_param('sis', $tool, $win, $value);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return [(int)($row['n'] ?? 0), (string)($row['last'] ?? '')];
    };
    [$nLogin, $lastLogin] = $count('login', $login);
    [$nIp, $lastIp] = $count('ip', $ip);

    $blocked = 0; $last = '';
    if ($nLogin >= LOGIN_GUARD_SOFT_TRIES) {
        $blocked = $nLogin >= LOGIN_GUARD_HARD_TRIES ? 60 : LOGIN_GUARD_WINDOW_MIN;
        $last = $lastLogin;
    }
    if ($nIp >= LOGIN_GUARD_SOFT_TRIES * LOGIN_GUARD_IP_FACTOR) {
        $pauseIp = $nIp >= LOGIN_GUARD_HARD_TRIES * LOGIN_GUARD_IP_FACTOR ? 60 : LOGIN_GUARD_WINDOW_MIN;
        if ($pauseIp > $blocked || $blocked === 0) { $blocked = $pauseIp; $last = $lastIp; }
    }
    $left = max(0, LOGIN_GUARD_SOFT_TRIES - $nLogin);
    if (!$blocked) return ['blocked' => false, 'minutes' => 0, 'left' => $left];

    $wait = (int)ceil((strtotime($last) + $blocked * 60 - time()) / 60);
    if ($wait <= 0) return ['blocked' => false, 'minutes' => 0, 'left' => $left ?: LOGIN_GUARD_SOFT_TRIES];
    return ['blocked' => true, 'minutes' => $wait, 'left' => 0];
}

/** Записать попытку. $ok = true — вход удался (тогда неудачи этого логина с этого адреса стираются). */
function login_guard_record(string $tool, string $login, bool $ok): void
{
    $db = login_guard_db();
    if (!$db) return;
    $ip = login_guard_ip();
    $login = substr($login, 0, 64);
    if ($ok) {
        $st = $db->prepare("DELETE FROM llx_nt_login_attempt WHERE tool = ? AND login = ? AND ip = ? AND ok = 0");
        $st->bind_param('sss', $tool, $login, $ip); $st->execute(); $st->close();
    }
    $st = $db->prepare("INSERT INTO llx_nt_login_attempt (tool, login, ip, ok, datec) VALUES (?, ?, ?, ?, NOW())");
    $okI = $ok ? 1 : 0;
    $st->bind_param('sssi', $tool, $login, $ip, $okI); $st->execute(); $st->close();
    // Старые записи не нужны: чистим редко, чтобы не дёргать базу на каждом входе.
    if (random_int(1, 50) === 1) $db->query("DELETE FROM llx_nt_login_attempt WHERE datec < (NOW() - INTERVAL 30 DAY)");
    if (!$ok) sleep(1);   // задержка ответа — перебору мешает, человеку незаметна
}

/** Текст для экрана входа. */
function login_guard_message(int $minutes): string
{
    return 'Слишком много неудачных попыток входа. Попробуйте через ' . max(1, $minutes) . ' мин. '
         . 'Если забыли пароль — обратитесь к Суннату, перебирать бесполезно.';
}
