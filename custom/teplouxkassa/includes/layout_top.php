<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Касса <?= htmlspecialchars($cfg['direction_label']) ?> — Теплолюкс</title>
<style>
  :root {
    --accent: #2563eb;
    --accent-dark: #1d4ed8;
    --bg: #f4f5f7;
    --sidebar-bg: #111827;
    --sidebar-text: #cbd5e1;
    --sidebar-text-active: #ffffff;
    --card: #ffffff;
    --text: #1f2430;
    --muted: #6b7280;
    --border: #e2e5ea;
    --danger: #dc2626;
    --danger-bg: #fee2e2;
    --ok: #16a34a;
    --warn: #b45309;
    --warn-bg: #fef3c7;
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    margin: 0; font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    background: var(--bg); color: var(--text);
  }

  .app-shell { display: flex; min-height: 100vh; }

  .sidebar {
    width: 230px; flex-shrink: 0; background: var(--sidebar-bg); color: var(--sidebar-text);
    display: flex; flex-direction: column; padding: 18px 14px;
  }
  .sidebar-brand { color: #fff; font-weight: 700; font-size: 16px; padding: 0 8px 18px; }
  .sidebar-brand span { display: block; font-weight: 400; font-size: 13px; color: #93a3b8; margin-top: 2px; }
  .sidebar-cash {
    display: block; background: #1f2937; border-radius: 10px; padding: 10px 12px; margin: 0 4px 16px;
    text-decoration: none;
  }
  .sidebar-cash:hover { background: #263349; }
  .sidebar-cash-label { font-size: 11.5px; color: #93a3b8; }
  .sidebar-cash-amount { font-size: 19px; font-weight: 700; color: #4ade80; margin-top: 2px; }
  .nav-group-title { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; padding: 14px 8px 6px; }
  .sidebar nav a {
    display: block; padding: 9px 10px; border-radius: 8px; color: var(--sidebar-text);
    text-decoration: none; font-size: 14.5px; margin-bottom: 2px;
  }
  .sidebar nav a:hover { background: #1f2937; color: #fff; }
  .sidebar nav a.active { background: var(--accent); color: #fff; }
  .sidebar-footer { margin-top: auto; padding-top: 14px; border-top: 1px solid #1f2937; }
  .sidebar-footer a { color: #93a3b8; text-decoration: none; font-size: 13.5px; padding: 8px; display: block; }
  .sidebar-footer a:hover { color: #fff; }

  .content { flex: 1; min-width: 0; padding: 28px 32px; }
  .content-inner { max-width: 1180px; margin: 0 auto; }

  .card {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 18px 20px; margin-bottom: 18px;
  }
  /* UX-K4 (02.09.2026): модальное подтверждение вместо нативного confirm() — assets/confirm-modal.js */
  .confirm-overlay {
    display: none; position: fixed; inset: 0; background: rgba(17,24,39,.45); z-index: 1000;
    align-items: center; justify-content: center; padding: 20px;
  }
  .confirm-dialog {
    background: var(--card); border-radius: 12px; padding: 22px 24px; max-width: 420px; width: 100%;
    box-shadow: 0 10px 40px rgba(0,0,0,.25);
  }
  .confirm-message { font-size: 15px; line-height: 1.5; margin-bottom: 18px; white-space: pre-line; }
  .confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
  h1 { font-size: 21px; margin: 0 0 16px; }
  h2 { font-size: 15.5px; margin: 0 0 12px; color: #374151; }
  label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
  input[type=text], input[type=number], input[type=password], select {
    width: 100%; padding: 10px 12px; font-size: 15px; border: 1px solid var(--border);
    border-radius: 8px; margin-bottom: 10px; background: #fff; color: var(--text);
  }
  input:focus, select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
  button, .btn {
    display: inline-block; padding: 10px 18px; font-size: 14.5px; font-weight: 500; border: none;
    border-radius: 8px; background: var(--accent); color: #fff; cursor: pointer; text-decoration: none;
  }
  button:hover { background: var(--accent-dark); }
  button.secondary, .btn.secondary { background: #eef0f3; color: var(--text); }
  button.secondary:hover { background: #e2e5ea; }
  button.danger { background: var(--danger); }
  .row { display: flex; gap: 10px; flex-wrap: wrap; }
  .row > * { flex: 1; min-width: 140px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 9px 6px; border-bottom: 1px solid var(--border); }
  th { color: var(--muted); font-weight: 500; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; }
  .muted { color: var(--muted); font-size: 13px; }
  .ok { color: var(--ok); font-weight: 600; }
  .err { color: var(--danger); font-weight: 600; }
  .warn { color: var(--warn); font-weight: 600; background: var(--warn-bg); padding: 8px 12px; border-radius: 8px; display: inline-block; }

  .grid-2col { display: grid; grid-template-columns: 1.3fr 1fr; gap: 20px; align-items: start; }
  @media (max-width: 900px) { .grid-2col { grid-template-columns: 1fr; } }

  .search-result { padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px; cursor: pointer; background: #fff; }
  .search-result:hover { border-color: var(--accent); box-shadow: 0 1px 4px rgba(0,0,0,.06); }
  .result-list { max-height: 420px; overflow-y: auto; padding-right: 2px; }

  .cat-tiles { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 12px; }
  .cat-tile {
    padding: 8px 13px; border-radius: 20px; border: 1px solid var(--border); background: #fff;
    font-size: 13.5px; cursor: pointer; color: var(--text);
  }
  .cat-tile:hover { border-color: var(--accent); color: var(--accent); }
  #categoryBackRow { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }

  .total { font-size: 20px; font-weight: 700; text-align: right; margin-top: 10px; }

  .cart-table { display: flex; flex-direction: column; gap: 8px; }
  .cart-row {
    padding: 10px 12px; background: #fafbfc; border: 1px solid var(--border); border-radius: 10px;
    position: relative;
  }
  .cart-row-main { display: flex; justify-content: space-between; align-items: start; gap: 10px; }
  .cart-row-name { font-size: 14px; line-height: 1.4; padding-right: 4px; }
  .cart-row-name .muted { display: block; font-size: 12px; margin-top: 1px; }
  .cart-remove {
    border: none; background: transparent; color: #b0b6c0; font-size: 15px; line-height: 1;
    cursor: pointer; padding: 3px 7px; flex-shrink: 0; border-radius: 6px; margin: -3px -5px 0 0;
  }
  .cart-remove:hover { background: var(--danger-bg); color: var(--danger); }
  .cart-row-controls {
    display: grid; grid-template-columns: 60px minmax(0, 1fr) 62px auto; gap: 8px; align-items: center;
    margin-top: 9px;
  }
  .cart-qty, .cart-warehouse, .cart-discount {
    width: 100%; min-width: 0; padding: 6px 8px; font-size: 13px; border: 1px solid var(--border);
    border-radius: 7px; margin: 0; background: #fff; color: var(--text);
  }
  .cart-qty { text-align: center; font-size: 14px; }
  .cart-warehouse { text-overflow: ellipsis; }
  .cart-discount { text-align: center; font-size: 14px; }
  .cart-discount.is-manual { border-color: var(--accent); background: #eff6ff; }
  .cart-subtotal { font-weight: 600; white-space: nowrap; text-align: right; font-size: 14px; color: var(--accent-dark); }

  .badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: 12.5px; font-weight: 600; }
  .badge-debt { background: var(--danger-bg); color: var(--danger); }
  .badge-ok { background: #dcfce7; color: var(--ok); }
  .badge-advance { background: #dbeafe; color: var(--accent-dark); }
  .badge-neutral { background: #eef0f3; color: var(--muted); }

  .filter-presets { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
  .filter-types { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 10px; }
  .checkbox-inline { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: var(--text); margin: 0; }
  .checkbox-inline input { width: auto; margin: 0; }
  .filter-chip {
    display: inline-flex; align-items: center; gap: 8px; background: #eef0f3; border-radius: 8px;
    padding: 9px 12px; margin-bottom: 10px; font-size: 14px;
  }
  .filter-chip-x { border: none; background: transparent; color: var(--muted); cursor: pointer; font-size: 13px; padding: 0 2px; }
  .filter-chip-x:hover { color: var(--danger); }

  .pay-split-inline { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
  .pay-split-inline input { width: 72px; margin: 0; padding: 6px 7px; font-size: 12.5px; }
  .pay-split-inline .pay-uzs-group-inline { display: flex; gap: 3px; align-items: center; }
  .pay-split-inline .pay-uzs-preview-inline { font-size: 11px; color: var(--muted); min-width: 40px; }

  .pay-uzs-group { background: #fafbfc; border: 1px solid var(--border); border-radius: 8px; padding: 10px 12px; }
  .pay-uzs-group label { margin-top: 4px; }
  .pay-uzs-group label:first-child { margin-top: 0; }

  .stage-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .doc-block { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; overflow: hidden; }
  .doc-block-header {
    display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #fafbfc;
    border-bottom: 1px solid var(--border); flex-wrap: wrap;
  }
  .doc-block-total { margin-left: auto; font-weight: 700; }
  .doc-block table { margin: 0; }
  .doc-block th, .doc-block td { padding: 7px 14px; }
  .doc-block tr:first-child th { border-top: none; }

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
<?php
$__page = basename($_SERVER['PHP_SELF']);
$__cashAcc = $cfg['payment_accounts']['cash'] ?? null;
// Плашка баланса кассы в сайдбаре — на КАЖДОЙ странице (см. layout_top.php), кэшируем в сессии на 30
// сек вместо запроса на каждую загрузку любой страницы (см. отчёт ревью P0#5) — это только
// информационная плашка, не критично, если отстанет на пол минуты; сам раздел "Касса/Долги" считает
// свой остаток отдельно и всегда живьём, эта кэш-задержка его не касается.
$__cashBalance = null;
if ($__cashAcc) {
    $__cacheKey = 'cash_balance_cache_' . $__cashAcc['id'];
    $__cached = $_SESSION[$__cacheKey] ?? null;
    if (is_array($__cached) && (time() - $__cached['at']) < 30) {
        $__cashBalance = $__cached['value'];
    } else {
        $__cashBalance = $api->getAccountBalance($__cashAcc['id']);
        $_SESSION[$__cacheKey] = ['value' => $__cashBalance, 'at' => time()];
    }
}
?>
<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-brand">Теплолюкс<span><?= htmlspecialchars($cfg['direction_label']) ?></span></div>
    <?php if ($__cashBalance !== null): ?>
    <a href="debt.php" class="sidebar-cash">
      <div class="sidebar-cash-label">Наличными в кассе</div>
      <div class="sidebar-cash-amount"><?= number_format($__cashBalance, 2) ?> $</div>
    </a>
    <?php endif; ?>
    <nav>
      <div class="nav-group-title">Продажи</div>
      <a href="sale.php" class="<?= $__page === 'sale.php' ? 'active' : '' ?>">Продажа</a>
      <a href="return.php" class="<?= $__page === 'return.php' ? 'active' : '' ?>">Возврат</a>
      <a href="debt.php" class="<?= $__page === 'debt.php' ? 'active' : '' ?>">Касса / Долги</a>
      <a href="advance.php" class="<?= $__page === 'advance.php' ? 'active' : '' ?>">Аванс / предоплата</a>
      <a href="payout.php" class="<?= $__page === 'payout.php' ? 'active' : '' ?>">Выдача денег клиенту</a>
      <a href="drafts.php" class="<?= $__page === 'drafts.php' ? 'active' : '' ?>">Черновики</a>
      <div class="nav-group-title">Отчёты</div>
      <a href="reports.php" class="<?= $__page === 'reports.php' ? 'active' : '' ?>">История клиента</a>
      <a href="shift.php" class="<?= $__page === 'shift.php' ? 'active' : '' ?>">Сменный отчёт</a>
      <div class="nav-group-title">Склад</div>
      <a href="receive.php" class="<?= $__page === 'receive.php' ? 'active' : '' ?>">Приём товара</a>
      <a href="transfer.php" class="<?= $__page === 'transfer.php' ? 'active' : '' ?>">Перемещение</a>
      <a href="inventory.php" class="<?= $__page === 'inventory.php' ? 'active' : '' ?>">Инвентаризация</a>
      <a href="stock.php" class="<?= $__page === 'stock.php' ? 'active' : '' ?>">Остатки</a>
    </nav>
    <div class="sidebar-footer"><a href="logout.php">Выход</a></div>
  </aside>
  <main class="content"><div class="content-inner">
