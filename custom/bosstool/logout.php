<?php
// session_boot.php ОБЯЗАТЕЛЬНО до session_start(): без него сессия стартует под именем cookie по
// умолчанию, и уничтожена будет не та — «Выход» тихо перестал бы работать (эта ошибка уже была
// поймана в кассе и закупках 03.09.2026, здесь учтена сразу).
require_once __DIR__ . '/includes/session_boot.php';
session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
