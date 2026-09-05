<?php
/**
 * «Склад: цены» (04.09.2026, по образцу SR Lux, который показал пользователь).
 *
 * В одной таблице всё, что нужно, чтобы решить по цене: заводская цена от поставщика (в валюте
 * договора И в долларах — по прямой просьбе), себестоимость, цена продажи и остаток. Всё правится
 * прямо здесь и сохраняется в Dolibarr — не нужно ходить по карточкам товаров.
 *
 * ⚠️ «Цена продажи» — это ровно та цена, по которой продаёт касса. Правка здесь сразу меняет цену
 * для Жамшида и MuhammadAli, поэтому на странице об этом сказано прямо.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stock_lookup.php';

$dirs = visible_directions($cfg);

$filters = [
    'ref'        => trim($_GET['ref'] ?? ''),
    'label'      => trim($_GET['label'] ?? ''),
    'supplier'   => (int)($_GET['supplier'] ?? 0),
    'stock_from' => trim($_GET['stock_from'] ?? ''),
    'stock_to'   => trim($_GET['stock_to'] ?? ''),
    'only_stock' => !empty($_GET['only_stock']),
];

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prices') {
    // Сохраняем только то, что реально изменилось: у каждого поля рядом лежит скрытое исходное
    // значение, сравниваем с ним. Иначе каждое нажатие кнопки переписывало бы все 400 строк.
    $factory = (array)($_POST['factory'] ?? []);
    $cost    = (array)($_POST['cost'] ?? []);
    $sale    = (array)($_POST['sale'] ?? []);
    $origF   = (array)($_POST['orig_factory'] ?? []);
    $origC   = (array)($_POST['orig_cost'] ?? []);
    $origS   = (array)($_POST['orig_sale'] ?? []);
    $supOf   = (array)($_POST['supplier_of'] ?? []);
    $curOf   = (array)($_POST['cur_of'] ?? []);
    $rateOf  = (array)($_POST['rate_of'] ?? []);

    $num = fn($v) => $v === '' || $v === null ? null : round((float)str_replace(',', '.', (string)$v), 4);
    $changed = ['factory' => 0, 'cost' => 0, 'sale' => 0];
    $errors = [];
    $who = $_SESSION['user']['name'] ?? '';

    $ids = array_unique(array_merge(array_keys($factory), array_keys($cost), array_keys($sale)));
    foreach ($ids as $pid) {
        $pid = (int)$pid;
        if ($pid <= 0) continue;

        // --- заводская цена (в валюте договора поставщика) ---
        $newF = $num($factory[$pid] ?? '');
        $oldF = $num($origF[$pid] ?? '');
        if ($newF !== null && $newF > 0 && ($oldF === null || abs($newF - $oldF) > 0.00005)) {
            $supId = (int)($supOf[$pid] ?? 0);
            if ($supId <= 0) {
                $errors[] = "#$pid: у товара нет поставщика — заводскую цену записать некуда";
            } else {
                $cur = strtoupper(trim((string)($curOf[$pid] ?? 'USD'))) ?: 'USD';
                $rate = (float)($rateOf[$pid] ?? 1) ?: 1.0;
                if ($api->savePurchasePrice($pid, $supId, $newF, $cur, $rate)) {
                    log_price_change($pid, $supId, $oldF, $newF, $who);
                    $changed['factory']++;
                } else {
                    $errors[] = "#$pid: " . $api->lastError;
                }
            }
        }

        // --- себестоимость ---
        $newC = $num($cost[$pid] ?? '');
        $oldC = $num($origC[$pid] ?? '');
        if ($newC !== null && ($oldC === null || abs($newC - $oldC) > 0.00005)) {
            if (save_product_cost($pid, $newC)) $changed['cost']++;
            else $errors[] = "#$pid: не удалось записать себестоимость";
        }

        // --- цена продажи ---
        $newS = $num($sale[$pid] ?? '');
        $oldS = $num($origS[$pid] ?? '');
        if ($newS !== null && ($oldS === null || abs($newS - $oldS) > 0.00005)) {
            if ($api->saveSalePrice($pid, $newS)) $changed['sale']++;
            else $errors[] = "#$pid: цена продажи — " . $api->lastError;
        }
    }

    $parts = [];
    if ($changed['factory']) $parts[] = 'заводская — ' . $changed['factory'];
    if ($changed['cost'])    $parts[] = 'себестоимость — ' . $changed['cost'];
    if ($changed['sale'])    $parts[] = 'цена продажи — ' . $changed['sale'];

    if (!$parts && !$errors) {
        flash_set('Ничего не изменилось — сохранять нечего.', 'warn');
    } else {
        $txt = $parts ? ('Сохранено: ' . implode(', ', $parts) . '.') : '';
        if ($errors) $txt .= ' Ошибки: ' . implode('; ', array_slice($errors, 0, 5))
            . (count($errors) > 5 ? ' и ещё ' . (count($errors) - 5) : '') . '.';
        flash_set(trim($txt), $errors ? 'warn' : 'ok');
    }
    // POST → Redirect → GET, чтобы F5 не сохранил всё повторно. Фильтры переносим в адрес.
    $q = array_filter([
        'ref' => $filters['ref'], 'label' => $filters['label'],
        'supplier' => $filters['supplier'] ?: null,
        'stock_from' => $filters['stock_from'], 'stock_to' => $filters['stock_to'],
        'only_stock' => $filters['only_stock'] ? 1 : null,
    ], fn($v) => $v !== '' && $v !== null);
    header('Location: stock_prices.php' . ($q ? '?' . http_build_query($q) : ''));
    exit;
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$rows = stock_price_rows($dirs, $filters, 400);
$totals = stock_price_totals($dirs, $filters);
$suppliers = $api->searchSuppliers('', 300);

$wideLayout = true;
require __DIR__ . '/includes/layout_top.php';
?>

<h1>Склад: цены и себестоимость</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <form method="get" class="filter-bar">
    <div><label>Артикул</label><input type="text" name="ref" value="<?= htmlspecialchars($filters['ref']) ?>" placeholder="содержит..."></div>
    <div><label>Наименование</label><input type="text" name="label" value="<?= htmlspecialchars($filters['label']) ?>" placeholder="содержит..."></div>
    <div><label>Поставщик</label>
      <select name="supplier">
        <option value="">— все —</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $filters['supplier'] ? 'selected' : '' ?>>
            <?= htmlspecialchars(mb_substr($s['name'] ?? $s['nom'] ?? '', 0, 34)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="range"><label>Остаток</label>
      <input type="number" name="stock_from" value="<?= htmlspecialchars($filters['stock_from']) ?>" placeholder="от" step="any">
      <input type="number" name="stock_to" value="<?= htmlspecialchars($filters['stock_to']) ?>" placeholder="до" step="any">
    </div>
    <div class="chk"><label><input type="checkbox" name="only_stock" value="1" <?= $filters['only_stock'] ? 'checked' : '' ?>> только то, что есть на складе</label></div>
    <div class="acts">
      <button type="submit">Найти</button>
      <a class="btn secondary" href="stock_prices_excel.php?<?= htmlspecialchars(http_build_query(array_filter($filters))) ?>">Экспорт в Excel</a>
    </div>
  </form>
</div>

<div class="tiles">
  <div class="tile"><div class="k">Товаров по фильтру</div><div class="v"><?= (int)$totals['count'] ?></div></div>
  <div class="tile"><div class="k">Стоимость остатков (по себест.)</div>
    <div class="v" style="color:var(--ok)"><?= number_format($totals['stock_value'], 2, '.', ' ') ?> $</div></div>
  <div class="tile"><div class="k">Показано строк</div><div class="v"><?= count($rows) ?></div>
    <?php if ($totals['count'] > count($rows)): ?><div class="k">уточните фильтр, чтобы увидеть остальные</div><?php endif; ?></div>
</div>

<div class="card">
  <p class="note" style="margin-bottom:12px">
    Все три цены редактируются прямо в таблице и сохраняются в Dolibarr одной кнопкой.
    <strong>«Цена продажи» — та самая, по которой продаёт касса</strong>: изменив её здесь, вы сразу
    меняете цену для продавцов. Заводская цена пишется поставщику, указанному в строке, в валюте его
    договора; долларовый столбец рядом — пересчёт, его не правят.
  </p>

  <?php if (empty($rows)): ?>
    <p class="muted">По этому фильтру ничего не нашлось.</p>
  <?php else: ?>
    <form method="post">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_prices">
      <div class="table-wrap">
        <table class="dense" id="priceTable">
          <colgroup>
            <col style="width:130px"><col><col style="width:150px">
            <col style="width:128px"><col style="width:88px">
            <col style="width:100px"><col style="width:100px"><col style="width:74px">
          </colgroup>
          <thead>
            <tr>
              <th>Артикул</th>
              <th>Наименование</th>
              <th>Поставщик</th>
              <th class="num">Заводская</th>
              <th class="num">Заводская, $</th>
              <th class="num">Себестоимость</th>
              <th class="num">Цена продажи</th>
              <th class="num">Остаток</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $fNative = $r['factory_native'] > 0 ? rtrim(rtrim(number_format($r['factory_native'], 4, '.', ''), '0'), '.') : '';
              $cost    = $r['cost'] > 0 ? rtrim(rtrim(number_format($r['cost'], 4, '.', ''), '0'), '.') : '';
              $sale    = $r['sale'] > 0 ? rtrim(rtrim(number_format($r['sale'], 4, '.', ''), '0'), '.') : '';
              $curLbl  = $r['factory_cur'] === 'USD' ? '$' : $r['factory_cur'];
            ?>
            <tr>
              <td class="cell-ref"><?= htmlspecialchars($r['ref']) ?></td>
              <td class="cell-name"><?= htmlspecialchars($r['label']) ?></td>
              <td class="muted tiny-sup"><?= htmlspecialchars($r['supplier'] ?: '—') ?></td>
              <td class="num nowrap">
                <input type="number" class="price-inp edit-cell" name="factory[<?= $r['id'] ?>]"
                       value="<?= htmlspecialchars($fNative) ?>" step="any" min="0" placeholder="нет"
                       data-original="<?= htmlspecialchars($fNative) ?>"
                       data-rate="<?= htmlspecialchars((string)$r['factory_rate']) ?>"
                       data-cur="<?= htmlspecialchars($r['factory_cur']) ?>"
                       <?= $r['supplier_id'] ? '' : 'disabled title="У товара нет поставщика"' ?>><?php
                  // Валюта — маленькой подписью В СТРОКУ, а не под полем: иначе строка вырастает вдвое.
                  if ($r['factory_cur'] !== 'USD'): ?><span class="cur-tag"><?= htmlspecialchars($curLbl) ?></span><?php endif; ?>
                <input type="hidden" name="orig_factory[<?= $r['id'] ?>]" value="<?= htmlspecialchars($fNative) ?>">
                <input type="hidden" name="supplier_of[<?= $r['id'] ?>]" value="<?= $r['supplier_id'] ?>">
                <input type="hidden" name="cur_of[<?= $r['id'] ?>]" value="<?= htmlspecialchars($r['factory_cur']) ?>">
                <input type="hidden" name="rate_of[<?= $r['id'] ?>]" value="<?= htmlspecialchars((string)$r['factory_rate']) ?>">
              </td>
              <td class="num muted factory-usd">
                <?= $r['factory_usd'] > 0 ? number_format($r['factory_usd'], 2, '.', ' ') : '—' ?>
              </td>
              <td class="num">
                <input type="number" class="price-inp edit-cell" name="cost[<?= $r['id'] ?>]"
                       value="<?= htmlspecialchars($cost) ?>" step="any" min="0" placeholder="нет"
                       data-original="<?= htmlspecialchars($cost) ?>">
                <input type="hidden" name="orig_cost[<?= $r['id'] ?>]" value="<?= htmlspecialchars($cost) ?>">
              </td>
              <td class="num">
                <input type="number" class="price-inp edit-cell" name="sale[<?= $r['id'] ?>]"
                       value="<?= htmlspecialchars($sale) ?>" step="any" min="0" placeholder="нет"
                       data-original="<?= htmlspecialchars($sale) ?>">
                <input type="hidden" name="orig_sale[<?= $r['id'] ?>]" value="<?= htmlspecialchars($sale) ?>">
              </td>
              <td class="num<?= $r['stock'] > 0 ? ' has-stock' : ' muted' ?>">
                <?= rtrim(rtrim(number_format($r['stock'], 2, '.', ' '), '0'), '.') ?: '0' ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="sugg-foot">
        <button type="submit">Сохранить изменения</button>
        <div class="muted" id="priceChanged">Изменений нет</div>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
// Подсветка изменённых полей + счётчик, чтобы было видно, что именно уйдёт в сохранение.
// Долларовая колонка пересчитывается на лету из введённой цены и курса записи.
(function () {
  const t = document.getElementById('priceTable');
  const out = document.getElementById('priceChanged');
  if (!t) return;

  function recount() {
    let n = 0;
    t.querySelectorAll('input.edit-cell').forEach(function (inp) {
      const changed = inp.value.trim() !== (inp.dataset.original || '').trim();
      inp.classList.toggle('changed', changed);
      if (changed) n++;
    });
    if (out) out.textContent = n ? ('Изменено полей: ' + n + ' — не забудьте сохранить') : 'Изменений нет';
  }

  t.addEventListener('input', function (e) {
    const inp = e.target;
    if (!inp.classList || !inp.classList.contains('edit-cell')) return;

    if (inp.name.indexOf('factory[') === 0) {
      const row = inp.closest('tr');
      const cell = row.querySelector('.factory-usd');
      const rate = parseFloat(inp.dataset.rate) || 1;
      const val = parseFloat(inp.value) || 0;
      if (cell) {
        const usd = (inp.dataset.cur === 'USD') ? val : (rate > 0 ? val / rate : 0);
        cell.textContent = usd > 0 ? usd.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—';
      }
    }
    recount();
  });
  recount();
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
