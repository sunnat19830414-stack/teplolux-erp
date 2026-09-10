<?php
/**
 * Карточка товара: просмотр и заполнение недостающих данных (10.09.2026).
 *
 * После сохранения — редирект (PRG), иначе F5 повторит запись.
 * Пустое поле означает «не трогать», а не «очистить»: иначе продавец, заполняя вес, случайно
 * стёр бы описание, которого он не видел на своём экране.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/catalog.php';

$caps = [
    'directions' => [$cfg['ref_prefix']],
    // Цену продажи продавец ВИДИТ (он по ней продаёт), но не правит — см. ниже.
    'view'       => ['phys', 'customs', 'stock', 'price'],
    // Правку цены сюда сознательно НЕ дали (решение пользователя 10.09.2026): случаи, ради которых
    // её просили — испорченный товарный вид, скидка знакомым, скидка по поручению руководства —
    // закрываются построчной скидкой в sale.php, действующей на ОДНУ продажу. Правка карточки
    // меняла бы цену навсегда и для всех. Управляется флагом `catalog_edit_price` в конфиге
    // направления (сейчас false); проверка стоит на сервере, подделанный POST с ценой отбивается.
    'edit'       => ($cfg['catalog_edit_price'] ?? false) ? ['phys', 'price'] : ['phys'],
];

$db  = catalog_db();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back = (string)($_GET['back'] ?? $_POST['back'] ?? 'catalog.php');
if (!preg_match('~^catalog\.php~', $back)) $back = 'catalog.php';   // не пускаем чужой адрес в редирект

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who = $_SESSION['user_name'] ?? $_SESSION['direction'] ?? 'касса';
    $res = catalog_save($db, $api, $caps, $id, $_POST, (string)$who, 'kassa');

    if (!$res['ok'] && !$res['changed']) {
        flash_set(implode('; ', $res['errors']) ?: 'Не удалось сохранить', 'err');
    } elseif (!$res['changed']) {
        flash_set('Изменений не было', 'ok');
    } else {
        $names = [];
        foreach ($res['changed'] as $k => [$o, $n]) $names[] = catalog_fields()[$k]['title'];
        $msg = 'Сохранено: ' . implode(', ', $names);
        if ($res['errors']) $msg .= '. НО: ' . implode('; ', $res['errors']);
        flash_set($msg, $res['errors'] ? 'err' : 'ok');
    }
    header('Location: product_card.php?id=' . $id . '&back=' . urlencode($back));
    exit;
}

$p = catalog_load($db, $caps, $id);
if (!$p) {
    flash_set('Товар не найден или относится к другому направлению', 'err');
    header('Location: ' . $back);
    exit;
}
$flash  = flash_get();
$fields = catalog_fields();

/** Значение поля для показа: пусто → прочерк. */
$val = function (string $key) use ($p, $fields) {
    $def = $fields[$key];
    $v = $p[$def['col'] ?? $def['ef']] ?? null;
    if ($v === null || $v === '' || (in_array($def['type'], ['num','int','money'], true) && (float)$v == 0)) return null;
    return $v;
};

$__page = 'catalog.php';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="card">
  <p><a class="btn secondary" href="<?= htmlspecialchars($back) ?>">← К списку</a></p>

  <h1><?= htmlspecialchars((string)$p['label']) ?></h1>
  <p class="muted">
    Артикул <code><?= htmlspecialchars((string)$p['ref']) ?></code>
    · код <?= htmlspecialchars((string)$p['kod_sap']) ?>
    <?php if ($p['sold'] > 0): ?> · продано за 21 месяц <?= number_format($p['sold'], 0, '.', ' ') ?> шт<?php endif; ?>
  </p>

  <?php if ($flash): ?>
    <p class="<?= $flash['type'] === 'err' ? 'err' : 'ok' ?>"><?= htmlspecialchars($flash['message']) ?></p>
  <?php endif; ?>

  <div class="grid" style="display:flex;gap:24px;flex-wrap:wrap">
    <div style="flex:1 1 320px">
      <h2 style="font-size:15px">Остатки</h2>
      <?php if (!$p['stock_rows']): ?>
        <p class="muted">На складах нет</p>
      <?php else: ?>
        <table>
          <?php foreach ($p['stock_rows'] as $s): ?>
            <tr>
              <td><?= htmlspecialchars((string)($s['lieu'] ?: $s['ref'])) ?></td>
              <td style="text-align:right"><strong><?= number_format((float)$s['reel'], 0, '.', ' ') ?></strong> шт</td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div style="flex:1 1 320px">
      <h2 style="font-size:15px">Последние правки</h2>
      <?php if (!$p['log']): ?>
        <p class="muted">Карточку ещё не правили через инструменты</p>
      <?php else: ?>
        <table>
          <?php foreach ($p['log'] as $l): ?>
            <tr>
              <td><?= htmlspecialchars($l['field']) ?></td>
              <td class="muted"><?= htmlspecialchars(($l['old_value'] ?: '—')) ?> → <strong><?= htmlspecialchars((string)$l['new_value']) ?></strong></td>
              <td class="muted"><?= htmlspecialchars($l['who']) ?>, <?= date('d.m.Y', strtotime($l['datec'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" style="margin-top:20px">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$p['rowid'] ?>">
    <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">

    <h2 style="font-size:15px">Данные товара</h2>
    <p class="muted">Пустое поле не изменит того, что уже записано. Заполняйте только то, что знаете точно.</p>

    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <?php foreach ($fields as $key => $def):
          $canEdit = catalog_can($caps, 'edit', $def['group']);
          $canView = $def['group'] === 'base' || catalog_can($caps, 'view', $def['group']) || $canEdit;
          if (!$canView) continue;
          $v = $val($key);
          $wide = in_array($def['type'], ['area', 'text'], true);
      ?>
        <div style="flex:<?= $wide ? '1 1 100%' : '0 1 165px' ?>">
          <label class="muted">
            <?= htmlspecialchars($def['title']) ?><?= isset($def['unit']) ? ', ' . $def['unit'] : '' ?>
            <?php if ($v === null): ?><span style="color:var(--warn)">· пусто</span><?php endif; ?>
          </label>
          <?php if (!$canEdit): ?>
            <div style="padding:8px 0"><?= $v === null ? '<span class="muted">—</span>' : htmlspecialchars((string)$v) ?></div>
          <?php elseif ($def['type'] === 'area'): ?>
            <textarea name="<?= $key ?>" rows="3" style="width:100%"><?= htmlspecialchars((string)($v ?? '')) ?></textarea>
          <?php else: ?>
            <input type="<?= in_array($def['type'], ['num','int','money'], true) ? 'number' : 'text' ?>"
                   name="<?= $key ?>"
                   <?= isset($def['step']) ? 'step="' . $def['step'] . '"' : ($def['type'] === 'money' ? 'step="0.01"' : '') ?>
                   <?= in_array($def['type'], ['num','int','money'], true) ? 'min="0"' : '' ?>
                   value="<?= htmlspecialchars((string)($v ?? '')) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (catalog_can($caps, 'edit', 'price')): ?>
      <p class="warn" style="margin-top:12px">
        ⚠️ Цена продажи меняется <strong>навсегда и для всех покупателей</strong>.
        Для скидки на одну продажу пользуйтесь полем скидки в разделе «Продажа».
      </p>
    <?php endif; ?>

    <p style="margin-top:14px">
      <button type="submit">Сохранить</button>
      <a class="btn secondary" href="<?= htmlspecialchars($back) ?>">Отмена</a>
    </p>
  </form>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
