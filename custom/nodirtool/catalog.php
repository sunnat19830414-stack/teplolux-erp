<?php
/**
 * Каталог товаров (10.09.2026). Поиск, фильтры, переход в карточку.
 *
 * Направления фильтруются по `kod_sap`, а не по `ref` и не по категории
 * (см. правила проекта: `ref` у Жоми и Турк пересекается).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/catalog.php';

$caps = [
    'directions' => ['J', 'T'],          // закупщики работают по обоим направлениям (см. CLAUDE.md)
    'view'       => ['phys', 'customs', 'purchase', 'stock', 'dealer'],
    'edit'       => ['phys', 'customs', 'price', 'dealer'],
];

$db = catalog_db();

$f = [
    'q'        => trim((string)($_GET['q'] ?? '')),
    'category' => (int)($_GET['category'] ?? 0),
    'missing'  => (string)($_GET['missing'] ?? ''),
    'instock'  => !empty($_GET['instock']),
    'sort'     => (string)($_GET['sort'] ?? 'sold'),
    'page'     => (int)($_GET['page'] ?? 1),
];
$res  = catalog_search($db, $caps, $f);
$cats = catalog_categories($db, $caps);
$flash = flash_get();

$missingLabels = [
    ''            => 'все товары',
    'weight'      => 'без веса',
    'dims'        => 'без габаритов',
    'description' => 'без описания',
    'box'         => 'без упаковки',
    'customcode'  => 'без ТНВЭД',
    'price'       => 'без цены',
];
$qs = function (array $over = []) use ($f) {
    $p = array_filter([
        'q' => $f['q'], 'category' => $f['category'] ?: '', 'missing' => $f['missing'],
        'instock' => $f['instock'] ? 1 : '', 'sort' => $f['sort'],
    ], fn($v) => $v !== '' && $v !== 0);
    return http_build_query(array_merge($p, $over));
};

$__page = 'catalog.php';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="card">
  <h1>Каталог товаров</h1>

  <?php if ($flash): ?>
    <p class="<?= $flash['type'] === 'err' ? 'err' : 'ok' ?>"><?= htmlspecialchars($flash['message']) ?></p>
  <?php endif; ?>

  <form method="get" class="row" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1 1 240px">
      <label class="muted">Поиск по названию, артикулу или коду</label>
      <input type="text" name="q" value="<?= htmlspecialchars($f['q']) ?>" placeholder="например: кран 1/2 или 502640" autofocus>
    </div>
    <div style="flex:0 1 200px">
      <label class="muted">Категория</label>
      <select name="category">
        <option value="">все категории</option>
        <?php foreach (['type' => 'По типу товара', 'brand' => 'По бренду'] as $bucket => $title): ?>
          <?php if (empty($cats[$bucket])) continue; ?>
          <optgroup label="<?= $title ?>">
            <?php foreach ($cats[$bucket] as $c): ?>
              <option value="<?= (int)$c['rowid'] ?>" <?= $f['category'] === (int)$c['rowid'] ? 'selected' : '' ?>>
                <?= ($c['depth'] ?? 0) > 0 ? str_repeat("\u{00A0}", 4) : '' ?><?= htmlspecialchars($c['label']) ?> (<?= (int)$c['n'] ?>)
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 1 170px">
      <label class="muted">Чего не хватает</label>
      <select name="missing">
        <?php foreach ($missingLabels as $k => $v): ?>
          <option value="<?= $k ?>" <?= $f['missing'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:0 1 150px">
      <label class="muted">Сортировка</label>
      <select name="sort">
        <option value="sold"  <?= $f['sort'] === 'sold'  ? 'selected' : '' ?>>по продажам</option>
        <option value="stock" <?= $f['sort'] === 'stock' ? 'selected' : '' ?>>по остатку</option>
        <option value="ref"   <?= $f['sort'] === 'ref'   ? 'selected' : '' ?>>по артикулу</option>
        <option value="label" <?= $f['sort'] === 'label' ? 'selected' : '' ?>>по названию</option>
      </select>
    </div>
    <label class="checkbox-inline" style="flex:0 0 auto">
      <input type="checkbox" name="instock" value="1" <?= $f['instock'] ? 'checked' : '' ?>> только в наличии
    </label>
    <button type="submit">Найти</button>
    <a class="btn secondary" href="catalog.php">Сбросить</a>
  </form>

  <p class="muted" style="margin-top:12px">
    Найдено: <strong><?= (int)$res['total'] ?></strong>
    <?php if ($f['missing'] !== ''): ?> · показаны только <?= $missingLabels[$f['missing']] ?><?php endif; ?>
    <?php if ($res['pages'] > 1): ?> · страница <?= $res['page'] ?> из <?= $res['pages'] ?><?php endif; ?>
  </p>

  <?php if (!$res['rows']): ?>
    <p class="muted">Ничего не найдено. Попробуйте изменить фильтры.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th style="width:120px">Артикул</th>
        <th>Наименование</th>
        <th style="width:80px;text-align:right">Цена</th>
        <th style="width:80px;text-align:right">Остаток</th>
        <th style="width:150px">Заполнено</th>
        <th style="width:70px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($res['rows'] as $r):
        $filled = [
            'вес'      => (float)$r['weight'] > 0,
            'габариты' => ((float)$r['length'] > 0 || (float)$r['width'] > 0 || (float)$r['height'] > 0),
            'упак.'    => (int)$r['pcs_per_box'] > 0,
            'опис.'    => trim((string)$r['description']) !== '',
        ];
    ?>
      <tr>
        <td><code><?= htmlspecialchars((string)$r['ref']) ?></code></td>
        <td>
          <?= htmlspecialchars((string)$r['label']) ?>
          <?php if ((float)$r['sold'] > 0): ?>
            <span class="muted">· продано <?= number_format((float)$r['sold'], 0, '.', ' ') ?> шт</span>
          <?php endif; ?>
        </td>
        <td style="text-align:right"><?= (float)$r['price'] > 0 ? number_format((float)$r['price'], 2) : '<span class="muted">—</span>' ?></td>
        <td style="text-align:right"><?= number_format((float)$r['stock'], 0, '.', ' ') ?></td>
        <td>
          <?php foreach ($filled as $name => $ok): ?>
            <span class="badge <?= $ok ? 'badge-ok' : 'badge-neutral' ?>" title="<?= $ok ? 'заполнено' : 'не заполнено' ?>"><?= $name ?></span>
          <?php endforeach; ?>
        </td>
        <td><a class="btn secondary small" href="product_card.php?id=<?= (int)$r['rowid'] ?>&amp;back=<?= urlencode('catalog.php?' . $qs()) ?>">Открыть</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($res['pages'] > 1): ?>
    <div class="row" style="gap:8px;margin-top:12px;align-items:center">
      <?php if ($res['page'] > 1): ?>
        <a class="btn secondary" href="?<?= $qs(['page' => $res['page'] - 1]) ?>">← Назад</a>
      <?php endif; ?>
      <span class="muted">страница <?= $res['page'] ?> из <?= $res['pages'] ?></span>
      <?php if ($res['page'] < $res['pages']): ?>
        <a class="btn secondary" href="?<?= $qs(['page' => $res['page'] + 1]) ?>">Вперёд →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
