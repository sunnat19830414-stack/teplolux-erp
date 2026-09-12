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
  /* Широкая раскладка для страниц-таблиц (заполнение карточек) — как в BossTool. */
  .content-inner.wide { max-width: 1720px; }

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
  /* Таблица позиций заказа (assets/line_table.js, 11.09.2026) */
  .nt-sortable { cursor: pointer; user-select: none; white-space: nowrap; }
  .nt-sortable::after { content: ' ↕'; color: var(--muted); font-size: 11px; }
  .nt-sortable[data-dir="asc"]::after { content: ' ▲'; color: var(--accent); }
  .nt-sortable[data-dir="desc"]::after { content: ' ▼'; color: var(--accent); }
  table.nt-lines input.nt-edit { width: 96px; margin: 0; padding: 6px 8px; font-size: 14px; }
  table.nt-lines td { vertical-align: middle; }
  tr.nt-noprice td { background: var(--danger-bg); }
  tr.nt-saved td { background: #dcfce7; }
  tr.nt-failed td { background: var(--danger-bg); }
  .nt-toast { position: fixed; left: 50%; bottom: 22px; transform: translateX(-50%); display: none; gap: 12px;
    align-items: center; background: #1f2430; color: #fff; padding: 10px 16px; border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,.25); z-index: 1000; font-size: 14px; max-width: 90vw; }
  .nt-toast .err { color: #fca5a5; }
  .nt-toast button { padding: 6px 12px; }
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
      <?php
        // Счётчики в меню: новая заявка от шефа и открытая рекламация не должны пролежать
        // незамеченными — раньше и то и другое приходило в переписку и терялось там.
        require_once __DIR__ . '/requests.php';
        require_once __DIR__ . '/claims.php';
        $__waiting = requests_waiting_count();
        $__openClaims = count(claims_list(true));
        // Активный пункт: вторым аргументом — вспомогательные страницы того же раздела.
        $__nav = function (string $file, string $title, array $also = [], string $badge = '') use ($__page) {
            $active = $__page === $file || in_array($__page, $also, true);
            echo '<a href="' . $file . '"' . ($active ? ' class="active"' : '') . '>'
               . htmlspecialchars($title) . $badge . '</a>';
        };
        $__badge = fn(int $n) => $n > 0 ? ' <span class="badge badge-warn">' . $n . '</span>' : '';
      ?>

      <div class="nav-group-title">Главная</div>
      <?php $__nav('index.php', 'Сводка'); ?>

      <div class="nav-group-title">Закупки</div>
      <?php
        $__nav('requests.php', 'Заявки на закупку', ['request_view.php']);
        $__nav('requests_in.php', 'Заявки к оформлению', [], $__badge($__waiting));
        $__nav('orders.php', 'Заказы поставщику', ['order_view.php', 'product_form.php', 'price_history_view.php']);
        $__nav('suppliers.php', 'Поставщики / контракты', ['supplier_form.php']);
        $__nav('catalog.php', 'Каталог товаров', ['product_card.php']);
        $__nav('claims.php', 'Рекламации', [], $__badge($__openClaims));
      ?>

      <div class="nav-group-title">Логистика</div>
      <?php
        $__nav('logistics.php', 'Заказы в пути');
        $__nav('shipments.php', 'Перевозки');
        $__nav('carriers.php', 'Перевозчики', ['carrier_form.php']);
        $__nav('batches.php', 'Партии и расходы');
        $__nav('cost_report.php', 'Себестоимость по товарам');
      ?>

      <div class="nav-group-title">Финансы</div>
      <?php
        $__nav('mycash.php', 'Моя касса');
        $__nav('payments.php', 'Оплата поставщикам');
        $__nav('convert.php', 'Конвертация валют');
        // Зарплата — только у Нодира (page_access в config.php). Пункт скрыт у остальных, но
        // настоящая защита стоит в auth.php: по прямой ссылке тоже не зайти.
        if (nt_page_allowed($cfg, 'payroll.php')) $__nav('payroll.php', 'Зарплата и авансы', ['employee_form.php']);
        $__nav('household.php', 'Хозрасходы');
        $__nav('income.php', 'Доходы');
      ?>

      <div class="nav-group-title">Справочники</div>
      <?php
        $__nav('expense_types.php', 'Виды логистических расходов');
        $__nav('expense_categories.php', 'Категории хозрасходов');
        $__nav('income_sources.php', 'Источники доходов');
        if (nt_page_allowed($cfg, 'departments.php')) $__nav('departments.php', 'Отделы');
      ?>

      <div class="nav-group-title">Настройки</div>
      <?php
        $__nav('mail_setup.php', 'Настройка почты');
        $__nav('suppliers_bulk.php', 'Заполнить карточки поставщиков');
      ?>
    </nav>
    <div class="sidebar-footer">
      <div>Вы: <?= htmlspecialchars($_SESSION['user']['name'] ?? '') ?></div>
      <a href="logout.php">Выход</a>
    </div>
  </aside>
  <main class="content"><div class="content-inner<?= !empty($wideLayout) ? ' wide' : '' ?>">
