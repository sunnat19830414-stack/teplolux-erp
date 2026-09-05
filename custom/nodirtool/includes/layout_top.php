<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($cfg['app_title']) ?></title>
<style>
  :root {
    --accent: #2563eb; --accent-dark: #1d4ed8; --bg: #f4f5f7;
    --sidebar-bg: #111827; --sidebar-text: #cbd5e1; --sidebar-text-active: #ffffff;
    --card: #ffffff; --text: #1f2430; --muted: #6b7280; --border: #e2e5ea;
    --danger: #dc2626; --danger-bg: #fee2e2; --ok: #16a34a; --warn: #b45309; --warn-bg: #fef3c7;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); }

  .app-shell { display: flex; min-height: 100vh; }
  .sidebar { width: 230px; flex-shrink: 0; background: var(--sidebar-bg); color: var(--sidebar-text); display: flex; flex-direction: column; padding: 18px 14px; }
  .sidebar-brand { color: #fff; font-weight: 700; font-size: 16px; padding: 0 8px 18px; }
  .sidebar-brand span { display: block; font-weight: 400; font-size: 13px; color: #93a3b8; margin-top: 2px; }
  .nav-group-title { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; padding: 14px 8px 6px; }
  .sidebar nav a { display: block; padding: 9px 10px; border-radius: 8px; color: var(--sidebar-text); text-decoration: none; font-size: 14.5px; margin-bottom: 2px; }
  .sidebar nav a:hover { background: #1f2937; color: #fff; }
  .sidebar nav a.active { background: var(--accent); color: #fff; }
  .sidebar-footer { margin-top: auto; padding-top: 14px; border-top: 1px solid #1f2937; }
  .sidebar-footer a, .sidebar-footer div { color: #93a3b8; text-decoration: none; font-size: 13.5px; padding: 8px; display: block; }
  .sidebar-footer a:hover { color: #fff; }

  .content { flex: 1; min-width: 0; padding: 28px 32px; }
  .content-inner { max-width: 1180px; margin: 0 auto; }

  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
  /* UX-K4 (02.09.2026): модальное подтверждение вместо нативного confirm() — assets/confirm-modal.js */
  .confirm-overlay { display: none; position: fixed; inset: 0; background: rgba(17,24,39,.45); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
  .confirm-dialog { background: var(--card); border-radius: 12px; padding: 22px 24px; max-width: 420px; width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
  .confirm-message { font-size: 15px; line-height: 1.5; margin-bottom: 18px; white-space: pre-line; }
  .confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
  h1 { font-size: 21px; margin: 0 0 16px; }
  h2 { font-size: 15.5px; margin: 0 0 12px; color: #374151; }
  label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
  input[type=text], input[type=number], input[type=password], input[type=date], select, textarea {
    width: 100%; padding: 10px 12px; font-size: 15px; border: 1px solid var(--border); border-radius: 8px;
    margin-bottom: 10px; background: #fff; color: var(--text); font-family: inherit;
  }
  input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
  button, .btn { display: inline-block; padding: 10px 18px; font-size: 14.5px; font-weight: 500; border: none; border-radius: 8px; background: var(--accent); color: #fff; cursor: pointer; text-decoration: none; }
  button:hover { background: var(--accent-dark); }
  button.secondary, .btn.secondary { background: #eef0f3; color: var(--text); }
  button.secondary:hover { background: #e2e5ea; }
  button.danger { background: var(--danger); }
  button.small { padding: 6px 12px; font-size: 13px; }
  .row { display: flex; gap: 10px; flex-wrap: wrap; }
  .row > * { flex: 1; min-width: 140px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 6px; border-bottom: 1px solid var(--border); }
  th { color: var(--muted); font-weight: 500; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; }
  .muted { color: var(--muted); font-size: 13px; }
  .ok { color: var(--ok); font-weight: 600; }
  .err { color: var(--danger); font-weight: 600; }
  .warn { color: var(--warn); font-weight: 600; background: var(--warn-bg); padding: 8px 12px; border-radius: 8px; display: inline-block; }

  .search-result { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px; cursor: pointer; background: #fff; }
  .search-result:hover { border-color: var(--accent); box-shadow: 0 1px 4px rgba(0,0,0,.06); }
  .result-list { max-height: 420px; overflow-y: auto; padding-right: 2px; }

  .badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 12.5px; font-weight: 600; }
  .badge-debt { background: var(--danger-bg); color: var(--danger); }
  .badge-ok { background: #dcfce7; color: var(--ok); }
  .badge-neutral { background: #eef0f3; color: var(--text); }
  .badge-warn { background: #fef3c7; color: #92400e; }

  .grid-2col { display: grid; grid-template-columns: 1.3fr 1fr; gap: 20px; align-items: start; }
  @media (max-width: 900px) { .grid-2col { grid-template-columns: 1fr; } }

  .stage-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .contract-bar { height: 8px; border-radius: 4px; background: #eef0f3; overflow: hidden; margin: 6px 0; }
  .contract-bar-fill { height: 100%; background: var(--accent); }
  .contract-bar-fill.over { background: var(--danger); }

  .debtor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
  .debtor-block { margin: 0; }
  .debtor-block-btn {
    width: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;
    padding: 14px 16px; background: #fff; border: 1px solid var(--border); border-radius: 10px;
    color: var(--text); font-weight: 500; cursor: pointer; text-align: left;
  }
  .debtor-block-btn:hover { border-color: var(--danger); box-shadow: 0 1px 5px rgba(220,38,38,.12); background: var(--danger-bg); }
  .debtor-block-name { font-size: 14.5px; line-height: 1.35; }
</style>
</head>
<body>
<?php $__page = basename($_SERVER['PHP_SELF']); ?>
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">Теплолюкс<span>Закупки</span></div>
    <nav>
      <div class="nav-group-title">Главная</div>
      <a href="index.php" class="<?= $__page === 'index.php' ? 'active' : '' ?>">Сводка</a>
      <div class="nav-group-title">Закупки</div>
      <?php
        // Заявки от руководства (04.09.2026): счётчик прямо в меню, чтобы новый список от шефа не
        // пролежал незамеченным — раньше он приходил в чат и терялся там.
        require_once __DIR__ . '/requests.php';
        $__waiting = requests_waiting_count();
      ?>
      <a href="requests_in.php" class="<?= $__page === 'requests_in.php' ? 'active' : '' ?>">Заявки от руководства<?php
        if ($__waiting > 0) echo ' <span class="badge badge-warn">' . $__waiting . '</span>'; ?></a>
      <a href="orders.php" class="<?= $__page === 'orders.php' ? 'active' : '' ?>">Заказы поставщику</a>
      <a href="logistics.php" class="<?= $__page === 'logistics.php' ? 'active' : '' ?>">Логистика</a>
      <a href="suppliers.php" class="<?= $__page === 'suppliers.php' ? 'active' : '' ?>">Поставщики / контракты</a>
      <a href="carriers.php" class="<?= $__page === 'carriers.php' ? 'active' : '' ?>">Перевозчики</a>
      <a href="batches.php" class="<?= $__page === 'batches.php' ? 'active' : '' ?>">Партии / Логистика</a>
      <a href="cost_report.php" class="<?= $__page === 'cost_report.php' ? 'active' : '' ?>">Себестоимость по товарам</a>
      <div class="nav-group-title">Финансы</div>
      <a href="mycash.php" class="<?= $__page === 'mycash.php' ? 'active' : '' ?>">Моя касса</a>
      <a href="payments.php" class="<?= $__page === 'payments.php' ? 'active' : '' ?>">Оплата поставщикам</a>
      <a href="convert.php" class="<?= $__page === 'convert.php' ? 'active' : '' ?>">Конвертация валют</a>
      <?php // 04.09.2026: зарплата — только у Нодира (см. page_access в config.php). Пункт меню скрыт
            // у остальных, но настоящая защита стоит в auth.php — по прямой ссылке тоже не зайти. ?>
      <?php if (nt_page_allowed($cfg, 'payroll.php')): ?>
        <a href="payroll.php" class="<?= $__page === 'payroll.php' || $__page === 'employee_form.php' ? 'active' : '' ?>">Зарплата и авансы</a>
      <?php endif; ?>
      <a href="household.php" class="<?= $__page === 'household.php' ? 'active' : '' ?>">Хозрасходы</a>
      <a href="income.php" class="<?= $__page === 'income.php' ? 'active' : '' ?>">Доходы</a>
      <div class="nav-group-title">Настройки</div>
      <a href="mail_setup.php" class="<?= $__page === 'mail_setup.php' ? 'active' : '' ?>">Настройка почты</a>
    </nav>
    <div class="sidebar-footer">
      <div>Вы: <?= htmlspecialchars($_SESSION['user']['name'] ?? '') ?></div>
      <a href="logout.php">Выход</a>
    </div>
  </aside>
  <main class="content"><div class="content-inner">
