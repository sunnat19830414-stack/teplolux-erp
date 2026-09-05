<?php
require_once __DIR__ . '/includes/session_boot.php';
session_start();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    $found = null;
    foreach (['zhomi', 'turk'] as $direction) {
        $cfg = require __DIR__ . '/config/config.' . $direction . '.php';
        if (hash_equals($cfg['app_login'], $login) && hash_equals($cfg['app_password'], $password)) {
            $found = $direction;
            break;
        }
    }

    if ($found) {
        session_regenerate_id(true);
        $_SESSION['direction'] = $found;
        header('Location: sale.php');
        exit;
    }
    $error = 'Неверный логин или пароль';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — Теплолюкс касса</title>
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
  <h1>Теплолюкс — касса</h1>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="text" name="login" placeholder="Логин" autofocus required>
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
  </form>
</div>
</body>
</html>
