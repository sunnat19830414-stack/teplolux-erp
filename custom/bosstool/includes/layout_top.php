<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($cfg['app_title']) ?></title>
<style>
  :root {
    --accent: #0f766e; --accent-dark: #115e59; --bg: #f4f6f5;
    --sidebar-bg: #10241f; --sidebar-text: #b9cbc5; --sidebar-text-active: #ffffff;
    --card: #ffffff; --text: #1f2430; --muted: #6b7280; --border: #e0e5e3;
    --danger: #b91c1c; --danger-bg: #fee2e2; --ok: #15803d; --ok-bg: #dcfce7;
    --warn: #b45309; --warn-bg: #fef3c7;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body { margin: 0; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); }

  .app-shell { display: flex; min-height: 100vh; }
  .sidebar { width: 236px; flex-shrink: 0; background: var(--sidebar-bg); color: var(--sidebar-text); display: flex; flex-direction: column; padding: 18px 14px; }
  .sidebar-brand { color: #fff; font-weight: 700; font-size: 16px; padding: 0 8px 6px; }
  .sidebar-brand span { display: block; font-weight: 400; font-size: 13px; color: #7f9a92; margin-top: 2px; }
  .cash-chip { display: block; margin: 10px 8px 4px; padding: 9px 11px; border-radius: 9px;
               background: #14332c; color: #d7f0e8; text-decoration: none; font-size: 13px; line-height: 1.4; }
  .cash-chip strong { display: block; font-size: 16px; color: #fff; margin-top: 2px; }
  .cash-chip:hover { background: #1a4239; }
  .nav-group-title { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #5d7a72; padding: 14px 8px 6px; }
  .sidebar nav a { display: block; padding: 9px 10px; border-radius: 8px; color: var(--sidebar-text); text-decoration: none; font-size: 14.5px; margin-bottom: 2px; }
  .sidebar nav a:hover { background: #1a3b32; color: #fff; }
  .sidebar nav a.active { background: var(--accent); color: #fff; }
  .sidebar-footer { margin-top: auto; padding-top: 14px; border-top: 1px solid #1d3a33; }
  .sidebar-footer a, .sidebar-footer div { color: #7f9a92; text-decoration: none; font-size: 13.5px; padding: 8px; display: block; }
  .sidebar-footer a:hover { color: #fff; }

  .content { flex: 1; min-width: 0; padding: 28px 32px; }
  .content-inner { max-width: 1180px; margin: 0 auto; }
  /* Страницы с большой таблицей (заявка на закупку) — во всю ширину экрана: иначе наименование
     товара переносится на 3 строки, а справа остаётся пустое место. */
  .content-inner.wide { max-width: 1720px; }

  .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
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
  input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
  button, .btn { display: inline-block; padding: 10px 18px; font-size: 14.5px; font-weight: 500; border: none; border-radius: 8px; background: var(--accent); color: #fff; cursor: pointer; text-decoration: none; }
  button:hover { background: var(--accent-dark); }
  button.secondary, .btn.secondary { background: #eaeeec; color: var(--text); }
  button.secondary:hover { background: #dde3e1; }
  button.danger { background: var(--danger); }
  button.small, .btn.small { padding: 6px 12px; font-size: 13px; }
  .row { display: flex; gap: 10px; flex-wrap: wrap; }
  .row > * { flex: 1; min-width: 140px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 6px; border-bottom: 1px solid var(--border); vertical-align: top; }
  th { color: var(--muted); font-weight: 500; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .muted { color: var(--muted); font-size: 13px; }
  .ok { color: var(--ok); font-weight: 600; }
  .err { color: var(--danger); font-weight: 600; }
  .warn { color: var(--warn); font-weight: 600; background: var(--warn-bg); padding: 8px 12px; border-radius: 8px; display: inline-block; }

  .search-result { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px; cursor: pointer; background: #fff; }
  .search-result:hover { border-color: var(--accent); box-shadow: 0 1px 4px rgba(0,0,0,.06); }
  .result-list { max-height: 420px; overflow-y: auto; padding-right: 2px; }

  .badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 12.5px; font-weight: 600; }
  .badge-debt { background: var(--danger-bg); color: var(--danger); }
  .badge-ok { background: var(--ok-bg); color: var(--ok); }
  .badge-neutral { background: #eaeeec; color: var(--text); }
  .badge-warn { background: var(--warn-bg); color: #92400e; }

  .grid-2col { display: grid; grid-template-columns: 1.25fr 1fr; gap: 20px; align-items: start; }
  @media (max-width: 900px) { .grid-2col { grid-template-columns: 1fr; } }

  .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
  .kpi { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
  .kpi .k { font-size: 12.5px; color: var(--muted); }
  .kpi .v { font-size: 23px; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
  .kpi .v.pos { color: var(--ok); }
  .kpi .v.neg { color: var(--danger); }

  .block-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
  .block-btn { width: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;
    padding: 14px 16px; background: #fff; border: 1px solid var(--border); border-radius: 10px;
    color: var(--text); font-weight: 500; cursor: pointer; text-align: left; }
  .block-btn:hover { border-color: var(--accent); box-shadow: 0 1px 5px rgba(15,118,110,.12); background: #f0faf7; }
  .bar { height: 8px; border-radius: 4px; background: #eaeeec; overflow: hidden; margin-top: 5px; }
  .bar > i { display: block; height: 100%; background: var(--accent); }

  /* --- шапка раздела: заголовок слева, легенда/действия справа --- */
  .sec-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; flex-wrap: wrap; }
  .sec-head h2 { margin: 0; }
  .legend { display: flex; gap: 14px; font-size: 12.5px; color: var(--muted); white-space: nowrap; }
  .legend i { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }

  /* --- компактная шапка заявки: всё в одну полосу вместо колонки полей --- */
  .req-head-grid { display: grid; grid-template-columns: 1.1fr 1fr 1.4fr auto; gap: 14px; align-items: start; }
  .req-head-grid label { margin-bottom: 5px; }
  .req-head-save { align-self: end; }
  .sup-chosen { display: flex; align-items: center; gap: 10px; min-height: 40px; }
  .req-head-foot { display: flex; align-items: center; justify-content: space-between; gap: 16px;
                   flex-wrap: wrap; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border); }
  .req-head-actions { display: flex; gap: 8px; }
  @media (max-width: 1000px) { .req-head-grid { grid-template-columns: 1fr; } }

  /* --- плотная таблица: главное на экране, строк много --- */
  .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; }
  table.dense { font-size: 13px; }
  table.dense th, table.dense td { padding: 6px 9px; vertical-align: middle; }
  table.dense thead th { position: sticky; top: 0; background: #f5f7f6; z-index: 1;
    border-bottom: 1px solid var(--border); font-size: 11.5px; text-transform: none;
    font-weight: 500; color: var(--muted); letter-spacing: 0; white-space: nowrap; }
  table.dense tbody tr:hover { background: #f4faf8; }
  table.dense tr.red  { background: #fef4f3; }
  table.dense tr.amber { background: #fffaef; }
  table.dense tr.red:hover, table.dense tr.amber:hover { filter: brightness(.985); }
  /* Артикул — как ссылка-опознаватель строки; наименование — обычным текстом рядом, а не под ним:
     так строка остаётся в одну линию и таблица не разъезжается по высоте. */
  .cell-ref { font-weight: 600; color: var(--accent); white-space: nowrap; }
  .cell-name { color: var(--ink, var(--text)); line-height: 1.35; }
  .line-name { line-height: 1.3; margin-top: 2px; }
  .tiny { font-size: 10.5px; line-height: 1.2; max-width: 96px; overflow: hidden;
          text-overflow: ellipsis; white-space: nowrap; margin-left: auto; }
  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }

  /* --- плашки-итоги над таблицей --- */
  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
  .tile { border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; background: #fbfcfc; }
  .tile .k { font-size: 11.5px; color: var(--muted); line-height: 1.3; }
  .tile .v { font-size: 22px; font-weight: 700; line-height: 1.15; margin: 2px 0; font-variant-numeric: tabular-nums; }
  .tile .v.warn-v { color: var(--warn); }

  /* --- полоса фильтров над таблицей (по образцу SR Lux) --- */
  .filter-bar { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
  .filter-bar > div { display: flex; flex-direction: column; }
  .filter-bar label { margin-bottom: 4px; white-space: nowrap; }
  .filter-bar input[type=text], .filter-bar input[type=number], .filter-bar select {
    margin: 0; padding: 7px 10px; font-size: 13.5px; min-width: 130px; }
  .filter-bar .range { flex-direction: column; }
  .filter-bar .range > label { margin-bottom: 4px; }
  .filter-bar .range input { min-width: 78px; width: 78px; display: inline-block; }
  .filter-bar .chk { justify-content: flex-end; }
  .filter-bar .chk label { display: flex; align-items: center; gap: 6px; margin: 0 0 8px; font-size: 13.5px; color: var(--text); }
  .filter-bar .chk input { width: auto; margin: 0; }
  .filter-bar .acts { flex-direction: row; gap: 8px; align-items: center; }
  .tiny-sup { font-size: 12px; line-height: 1.3; }
  td.has-stock { color: var(--accent); font-weight: 600; }
  td.nowrap { white-space: nowrap; }
  .cur-tag { font-size: 10.5px; color: var(--muted); margin-left: 4px; vertical-align: middle; }
  .qty-inp { width: 78px; margin: 0; padding: 4px 7px; text-align: right; font-size: 13px; }
  /* Поле заводской цены: выглядит как текст, пока его не тронули — чтобы таблица не пестрила
     рамками, но было понятно, что значение редактируемое. Изменённое подсвечивается. */
  .price-inp { width: 84px; margin: 0; padding: 4px 7px; text-align: right; font-size: 13px;
               border-color: transparent; background: transparent; }
  .price-inp:hover { border-color: var(--border); background: #fff; }
  .price-inp:focus { border-color: var(--accent); background: #fff; }
  .price-inp.changed { border-color: var(--accent); background: #eefaf6; font-weight: 600; }

  /* --- строка управления над таблицей --- */
  .sugg-controls { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
  .sugg-controls form { display: flex; align-items: center; gap: 8px; }
  .sugg-controls label { margin: 0; white-space: nowrap; }
  .sugg-controls input[type=number] { width: 70px; margin: 0; padding: 6px 9px; font-size: 13.5px; }
  .sugg-controls #suggFilter { width: 340px; margin: 0; padding: 7px 11px; font-size: 13.5px; }
  .sugg-foot { display: flex; align-items: center; gap: 16px; margin-top: 14px; flex-wrap: wrap; }
  .sugg-foot .muted { flex: 1; min-width: 260px; line-height: 1.45; }

  /* Пояснение под заголовком — заметно, но не кричит на пол-экрана. */
  .note { background: #fffaef; border-left: 3px solid var(--warn); color: #6b4b12;
          padding: 9px 13px; border-radius: 0 8px 8px 0; font-size: 13.5px; line-height: 1.5; margin: 0 0 14px; }
</style>
</head>
<body>
<?php
  $__page = basename($_SERVER['PHP_SELF']);
  $__me = $_SESSION['user'] ?? [];
  $__cashAcc = $__me['cash_account'] ?? null;
  $__cashBalance = $__cashAcc ? $api->getAccountBalance((int)$__cashAcc['id']) : null;
?>
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">Теплолюкс<span>Руководство<?= user_direction() ? ' · ' . htmlspecialchars($cfg['directions'][user_direction()]) : '' ?></span></div>
    <?php if ($__cashAcc): ?>
      <a class="cash-chip" href="cash.php">
        <?= htmlspecialchars($__cashAcc['label']) ?>
        <strong><?= $__cashBalance === null ? '—' : money((float)$__cashBalance) ?></strong>
      </a>
    <?php endif; ?>
    <nav>
      <div class="nav-group-title">Главная</div>
      <a href="index.php" class="<?= $__page === 'index.php' ? 'active' : '' ?>">Сводка</a>
      <div class="nav-group-title">Закупки</div>
      <a href="requests.php" class="<?= in_array($__page, ['requests.php', 'request_view.php'], true) ? 'active' : '' ?>">Заявки на закупку</a>
      <div class="nav-group-title">Деньги</div>
      <a href="cash.php" class="<?= $__page === 'cash.php' ? 'active' : '' ?>">Моя касса</a>
      <div class="nav-group-title">Отчёты</div>
      <a href="stock_prices.php" class="<?= $__page === 'stock_prices.php' ? 'active' : '' ?>">Склад: цены</a>
      <a href="report_money.php" class="<?= $__page === 'report_money.php' ? 'active' : '' ?>">Пришло / ушло</a>
      <a href="report_sales.php" class="<?= $__page === 'report_sales.php' ? 'active' : '' ?>">Продажи и долги</a>
      <a href="report_purchases.php" class="<?= $__page === 'report_purchases.php' ? 'active' : '' ?>">Закупки и поставщики</a>
      <a href="report_costs.php" class="<?= $__page === 'report_costs.php' ? 'active' : '' ?>">Зарплата и расходы</a>
      <a href="report_demand.php" class="<?= $__page === 'report_demand.php' ? 'active' : '' ?>">Что пора закупать</a>
    </nav>
    <div class="sidebar-footer">
      <div>Вы: <?= htmlspecialchars($__me['name'] ?? '') ?></div>
      <a href="logout.php">Выход</a>
    </div>
  </aside>
  <main class="content"><div class="content-inner<?= !empty($wideLayout) ? ' wide' : '' ?>">
