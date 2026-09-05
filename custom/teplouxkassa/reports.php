<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client_history.php';

if (!array_key_exists('report_client', $_SESSION)) $_SESSION['report_client'] = null;
if (!array_key_exists('report_filters', $_SESSION)) $_SESSION['report_filters'] = default_report_filters();

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — сбрасывает
// выбранного клиента, чтобы не "застревать" на нём.
reset_selection_unless_preserved('report_client');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'select_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (select_client_for_direction($api, $cfg, 'report_client', $clientId, $_POST['client_name'] ?? '')) {
            $_SESSION['report_filters'] = default_report_filters(); // новый клиент — фильтры с чистого листа
        } else {
            $message = 'Клиент не найден или относится к другому направлению.';
            $messageType = 'err';
        }
    } elseif ($action === 'clear_client') {
        $_SESSION['report_client'] = null;
        $_SESSION['report_filters'] = default_report_filters();
    } elseif ($action === 'apply_filters') {
        $_SESSION['report_filters'] = report_filters_from_request($_POST);
    } elseif ($action === 'reset_filters') {
        $_SESSION['report_filters'] = default_report_filters();
    }
}

$filters = $_SESSION['report_filters'];
$history = null;
$filterQuery = '';
if ($_SESSION['report_client']) {
    $clientId = (int)$_SESSION['report_client']['id'];
    $history = buildClientHistory($api, $clientId, $filters);
    $filterQuery = report_filters_to_query($filters, $clientId);
}

$badgeClass = ['sale' => 'badge-ok', 'return' => 'badge-debt', 'advance' => 'badge-advance'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Отчёты — история клиента</h1>
<p class="muted">Что клиент покупал, когда возвращал товар, вносил аванс и когда платил — с фильтрами по дате, товару, категории и складу.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($_SESSION['report_client']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_client">
    <button type="submit" class="secondary">← Сменить клиента</button>
  </form>
<?php endif; ?>

<div class="card">
  <h2>Клиент</h2>
  <?php if ($_SESSION['report_client']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['report_client']['name']) ?></strong>
        <div><a href="client_form.php?ctx=reports&id=<?= (int)$_SESSION['report_client']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_client">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
  <?php else: ?>
    <input type="text" id="clientSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать имя...">
    <div id="clientResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="client_form.php?ctx=reports" class="btn secondary small">+ Новый клиент</a></p>
  <?php endif; ?>
</div>

<?php if ($_SESSION['report_client']): ?>

<div class="card">
  <h2>Фильтры</h2>
  <form method="post" id="filterForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="apply_filters">

    <label>Период</label>
    <div class="filter-presets">
      <button type="button" class="secondary small" data-preset="today">Сегодня</button>
      <button type="button" class="secondary small" data-preset="week">Эта неделя</button>
      <button type="button" class="secondary small" data-preset="month">Этот месяц</button>
      <button type="button" class="secondary small" data-preset="prevmonth">Прошлый месяц</button>
      <button type="button" class="secondary small" data-preset="year">Этот год</button>
    </div>
    <div class="row">
      <div>
        <label>С даты</label>
        <input type="date" name="date_from" id="dateFrom" value="<?= htmlspecialchars($filters['date_from']) ?>">
      </div>
      <div>
        <label>По дату</label>
        <input type="date" name="date_to" id="dateTo" value="<?= htmlspecialchars($filters['date_to']) ?>">
      </div>
    </div>

    <label>Тип документа</label>
    <div class="filter-types">
      <label class="checkbox-inline"><input type="checkbox" name="types[]" value="sale" <?= in_array('sale', $filters['types'], true) ? 'checked' : '' ?>> Продажа</label>
      <label class="checkbox-inline"><input type="checkbox" name="types[]" value="return" <?= in_array('return', $filters['types'], true) ? 'checked' : '' ?>> Возврат</label>
      <label class="checkbox-inline"><input type="checkbox" name="types[]" value="advance" <?= in_array('advance', $filters['types'], true) ? 'checked' : '' ?>> Аванс</label>
    </div>

    <div class="row">
      <div>
        <label>Товар</label>
        <div id="productFilterChip" style="<?= $filters['product_id'] ? '' : 'display:none' ?>" class="filter-chip">
          <span id="productFilterLabel"><?= htmlspecialchars($filters['product_label']) ?></span>
          <button type="button" id="productFilterClear" class="filter-chip-x">✕</button>
        </div>
        <input type="text" id="productFilterSearch" placeholder="Начните печатать название/артикул..." style="<?= $filters['product_id'] ? 'display:none' : '' ?>">
        <div id="productFilterResults" class="result-list" style="max-height:220px"></div>
        <input type="hidden" name="product_id" id="productFilterId" value="<?= (int)$filters['product_id'] ?>">
        <input type="hidden" name="product_label" id="productFilterHidden" value="<?= htmlspecialchars($filters['product_label']) ?>">
      </div>
      <div>
        <label>Категория</label>
        <select name="category_id">
          <option value="0">Все категории</option>
          <?php foreach ($cfg['categories'] as $catId => $catLabel): ?>
            <option value="<?= (int)$catId ?>" <?= $filters['category_id'] == $catId ? 'selected' : '' ?>><?= htmlspecialchars($catLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Склад</label>
        <select name="warehouse_id">
          <option value="0">Все склады</option>
          <?php foreach ($cfg['warehouse_labels'] as $whId => $whLabel): ?>
            <option value="<?= (int)$whId ?>" <?= $filters['warehouse_id'] == $whId ? 'selected' : '' ?>><?= htmlspecialchars($whLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <button type="submit">Применить фильтр</button>
  </form>
  <form method="post" style="margin-top:8px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_filters">
    <button type="submit" class="secondary small">Сбросить все фильтры</button>
  </form>
</div>

<?php if ($history): ?>

<div class="card">
  <h2>Сводная по фильтру</h2>
  <div class="row">
    <div><div class="muted">Куплено</div><div style="font-size:20px; font-weight:700"><?= number_format($history['summary']['purchased'], 2) ?> $</div></div>
    <div><div class="muted">Возвращено</div><div style="font-size:20px; font-weight:700"><?= number_format($history['summary']['returned'], 2) ?> $</div></div>
    <div><div class="muted">Оплачено</div><div style="font-size:20px; font-weight:700"><?= number_format($history['summary']['paid'], 2) ?> $</div></div>
    <div>
      <div class="muted">Долг на сегодня <span title="Не зависит от фильтра по дате — это текущее состояние счёта клиента.">ⓘ</span></div>
      <?php $debt = $history['summary']['debt']; ?>
      <div style="font-size:20px; font-weight:700"><span class="badge <?= ($debt !== null && $debt > 0.01) ? 'badge-debt' : 'badge-ok' ?>"><?= $debt !== null ? number_format($debt, 2) . ' $' : '?' ?></span></div>
    </div>
  </div>
  <p style="margin-top:14px">
    <a class="btn secondary" href="report_excel.php?<?= $filterQuery ?>">📄 Скачать в Excel</a>
    <a class="btn secondary" href="report_print.php?<?= $filterQuery ?>" target="_blank">🖨️ Печатная версия</a>
  </p>
</div>

<?php if (!empty($history['by_product'])): ?>
<div class="card">
  <h2>Сводная таблица по товарам</h2>
  <table>
    <tr><th>Товар</th><th>Артикул</th><th>Куплено, шт</th><th>Куплено, $</th><th>Возвращено, шт</th><th>Возвращено, $</th></tr>
    <?php foreach ($history['by_product'] as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['product']) ?></td>
        <td><?= htmlspecialchars($row['article']) ?></td>
        <td><?= $row['qty_sale'] > 0 ? rtrim(rtrim(number_format($row['qty_sale'], 3, '.', ''), '0'), '.') : '—' ?></td>
        <td><?= $row['qty_sale'] > 0 ? number_format($row['total_sale'], 2) . ' $' : '—' ?></td>
        <td><?= $row['qty_return'] > 0 ? rtrim(rtrim(number_format($row['qty_return'], 3, '.', ''), '0'), '.') : '—' ?></td>
        <td><?= $row['qty_return'] > 0 ? number_format($row['total_return'], 2) . ' $' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2>Документы</h2>
  <?php if (empty($history['documents'])): ?>
    <p class="muted">Ничего не найдено по этому фильтру.</p>
  <?php else: ?>
    <?php foreach ($history['documents'] as $doc): ?>
      <div class="doc-block">
        <div class="doc-block-header">
          <span class="badge <?= $badgeClass[$doc['doc_type']] ?? 'badge-ok' ?>"><?= htmlspecialchars($doc['type_label']) ?></span>
          <strong><?= htmlspecialchars($doc['doc_ref']) ?></strong>
          <span class="muted"><?= htmlspecialchars($doc['date']) ?></span>
          <span class="doc-block-total"><?= number_format($doc['total'], 2) ?> $</span>
        </div>
        <?php if (!empty($doc['lines'])): ?>
        <table>
          <tr><th>Товар</th><th>Артикул</th><th>Кол-во</th><th>Сумма</th></tr>
          <?php foreach ($doc['lines'] as $line): ?>
            <tr>
              <td><?= htmlspecialchars($line['product']) ?></td>
              <td><?= htmlspecialchars($line['article']) ?></td>
              <td><?= rtrim(rtrim(number_format($line['qty'], 3, '.', ''), '0'), '.') ?></td>
              <td><?= number_format($line['total'], 2) ?> $</td>
            </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Оплаты</h2>
  <?php if (empty($history['payments'])): ?>
    <p class="muted">Оплат не было (или тип "Продажа" не выбран в фильтре).</p>
  <?php else: ?>
    <table>
      <tr><th>Дата</th><th>Счёт</th><th>Способ оплаты</th><th>Сумма</th></tr>
      <?php foreach ($history['payments'] as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['date']) ?></td>
          <td><?= htmlspecialchars($row['doc_ref']) ?></td>
          <td><?= htmlspecialchars($row['method']) ?></td>
          <td><?= number_format($row['amount'], 2) ?> $</td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php endif; ?>

<script>
window.onClientPick = function (c) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_client">' +
    '<input type="hidden" name="client_id" value="' + c.id + '">' +
    '<input type="hidden" name="client_name" value="' + c.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
};
</script>
<script src="assets/picker.js"></script>

<script>
// --- Быстрые кнопки-периоды ---
(function () {
  function iso(d) { return d.toISOString().slice(0, 10); }
  function setRange(from, to) {
    document.getElementById('dateFrom').value = iso(from);
    document.getElementById('dateTo').value = iso(to);
    document.getElementById('filterForm').submit();
  }
  document.querySelectorAll('[data-preset]').forEach(btn => {
    btn.addEventListener('click', () => {
      const now = new Date();
      const preset = btn.dataset.preset;
      if (preset === 'today') {
        setRange(now, now);
      } else if (preset === 'week') {
        const day = (now.getDay() + 6) % 7; // понедельник = 0
        const monday = new Date(now); monday.setDate(now.getDate() - day);
        setRange(monday, now);
      } else if (preset === 'month') {
        setRange(new Date(now.getFullYear(), now.getMonth(), 1), now);
      } else if (preset === 'prevmonth') {
        const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const last = new Date(now.getFullYear(), now.getMonth(), 0);
        setRange(first, last);
      } else if (preset === 'year') {
        setRange(new Date(now.getFullYear(), 0, 1), now);
      }
    });
  });
})();

// --- Фильтр по товару: простой автокомплит (без плиток категорий, в отличие от picker.js) ---
(function () {
  const input = document.getElementById('productFilterSearch');
  const results = document.getElementById('productFilterResults');
  const chip = document.getElementById('productFilterChip');
  const chipLabel = document.getElementById('productFilterLabel');
  const clearBtn = document.getElementById('productFilterClear');
  const hiddenId = document.getElementById('productFilterId');
  const hiddenLabel = document.getElementById('productFilterHidden');
  if (!input) return;

  let t;
  input.addEventListener('input', () => {
    clearTimeout(t);
    const term = input.value.trim();
    if (term === '') { results.innerHTML = ''; return; }
    t = setTimeout(async () => {
      results.innerHTML = '<p class="muted">Ищу...</p>';
      const res = await fetch('ajax_search_product.php?q=' + encodeURIComponent(term));
      const items = await res.json();
      results.innerHTML = '';
      if (!items.length) { results.innerHTML = '<p class="muted">Ничего не найдено</p>'; return; }
      items.forEach(p => {
        const div = document.createElement('div');
        div.className = 'search-result';
        div.innerHTML = '<strong>' + p.label + '</strong><br><span class="muted">' + p.ref + '</span>';
        div.onclick = () => {
          hiddenId.value = p.id;
          hiddenLabel.value = p.label + ' (' + p.ref + ')';
          chipLabel.textContent = hiddenLabel.value;
          chip.style.display = '';
          input.style.display = 'none';
          results.innerHTML = '';
        };
        results.appendChild(div);
      });
    }, 350);
  });

  clearBtn.addEventListener('click', () => {
    hiddenId.value = '0';
    hiddenLabel.value = '';
    chip.style.display = 'none';
    input.style.display = '';
    input.value = '';
  });
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
