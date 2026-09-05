<?php
require_once __DIR__ . '/includes/session_boot.php';
session_start();
$cfg = require __DIR__ . '/config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $u = $cfg['users'][$login] ?? null;
    if ($u && hash_equals($u['password'], $password)) {
        // S-1 (внешний QA-аудит, раунд 2, 03.09.2026): раньше здесь НЕ было session_regenerate_id() —
        // если кто-то заранее "подсунул" идентификатор сессии (например, через открытую вкладку до
        // входа), он оставался действительным и ПОСЛЕ входа. TeplouxKassa это уже делала у себя;
        // NodirTool — нет. Заодно новый id создаётся уже под НОВЫМ именем cookie (см. session_boot.php).
        session_regenerate_id(true);
        $_SESSION['user'] = ['login' => $login, 'name' => $u['display_name']];
        header('Location: index.php');
        exit;
    }
    $error = 'Неверный логин или пароль.';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Закупки Теплолюкс</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f4f5f7; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
  .box { background:#fff; padding:28px; border-radius:12px; width:320px; max-width:90vw; box-shadow:0 2px 12px rgba(0,0,0,.08); }
  h1 { font-size:20px; margin:0 0 16px; text-align:center; }
  input { width:100%; padding:12px; font-size:16px; border:1px solid #e2e5ea; border-radius:8px; margin-bottom:10px; box-sizing:border-box; }
  button { width:100%; padding:12px; font-size:16px; border:none; border-radius:8px; background:#2563eb; color:#fff; cursor:pointer; }
  .err { color:#dc2626; font-size:14px; margin-bottom:10px; text-align:center; }
</style>
</head>
<body>
<div class="box">
  <h1>Закупки — Теплолюкс</h1>
  <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="post">
    <input type="text" name="login" placeholder="Логин" autofocus required>
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
  </form>
</div>
</body>
</html>
