<?php
/**
 * Новый товар в каталоге прямо из оформления заказа (04.09.2026, пункт B9 отчёта «Пробелы
 * NodirTool»). Раньше в заказ можно было поставить только уже заведённый товар — новинку от
 * поставщика приходилось идти заводить в сам Dolibarr или просить Сунната, и оформление заказа
 * вставало до чужого участия.
 *
 * Направление обязательно: TeplouxKassa (кассы Жоми/Турк) различает товары ИМЕННО по доп.полю
 * `kod_sap` — товар без него не увидит ни один продавец, то есть «создали и потеряли». Поэтому
 * J/T-код выдаётся здесь автоматически, а не оставляется на потом.
 *
 * После создания возвращаемся туда, откуда пришли (ctx), с товаром, уже добавленным в корзину
 * заказа — тем же приёмом одноразового флага `_preserve_once`, что и supplier_form.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_lookup.php';

const PRODUCT_FORM_CONTEXTS = [
    'orders' => ['page' => 'orders.php'],
];

// Направление → префикс кода и склад по умолчанию (те же значения, что в конфигах TeplouxKassa).
const PRODUCT_DIRECTIONS = [
    'zhomi' => ['label' => 'Жоми', 'prefix' => 'J', 'warehouse' => 4],
    'turk'  => ['label' => 'Турк', 'prefix' => 'T', 'warehouse' => 6],
];

$ctxKey = $_GET['ctx'] ?? ($_POST['ctx'] ?? 'orders');
$ctx = PRODUCT_FORM_CONTEXTS[$ctxKey] ?? PRODUCT_FORM_CONTEXTS['orders'];

$message = '';
$messageType = '';
$fields = ['ref' => '', 'label' => '', 'direction' => 'zhomi', 'price' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields['ref'] = trim($_POST['ref'] ?? '');
    $fields['label'] = trim($_POST['label'] ?? '');
    $fields['direction'] = $_POST['direction'] ?? '';
    $fields['price'] = trim($_POST['price'] ?? '');

    $dir = PRODUCT_DIRECTIONS[$fields['direction']] ?? null;
    $price = $fields['price'] === '' ? 0.0 : (float)str_replace(',', '.', $fields['price']);

    if ($fields['ref'] === '' || $fields['label'] === '') {
        $message = 'Заполните артикул и название.';
        $messageType = 'err';
    } elseif (!$dir) {
        $message = 'Выберите направление — без него товар не увидят на кассе.';
        $messageType = 'err';
    } elseif (product_ref_exists($fields['ref'])) {
        $message = 'Товар с таким артикулом уже есть в каталоге — найдите его обычным поиском.';
        $messageType = 'err';
    } else {
        $newId = $api->createProduct($fields['ref'], $fields['label'], $price, $dir['warehouse']);
        if (!$newId) {
            $message = 'Ошибка создания товара: ' . $api->lastError;
            $messageType = 'err';
        } else {
            $newId = (int)$newId;
            $kodSap = next_kod_sap($dir['prefix']);
            $efOk = $api->updateProductExtrafields($newId, ['kod_sap' => $kodSap, 'artikul' => $fields['ref']]);

            if ($efOk === null) {
                // Товар создан, но без кода направления его не увидит касса — говорим об этом прямо,
                // а не заканчиваем молчаливым "успехом".
                flash_set("Товар «{$fields['label']}» создан, но код направления НЕ проставлен: "
                    . $api->lastError . ' — товар не будет виден на кассе, сообщите Суннату.', 'err');
            } else {
                flash_set("Товар «{$fields['label']}» создан (артикул {$fields['ref']}, код {$kodSap}) "
                    . 'и добавлен в заказ.', 'ok');
            }

            // Сразу положить в корзину заказа — ради этого форма и открывалась.
            $_SESSION['po_new_product'] = [
                'id' => $newId,
                'ref' => $fields['ref'],
                'label' => $fields['label'],
                'price' => $price,
            ];
            header('Location: ' . $ctx['page']);
            exit;
        }
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Новый товар</h1>
<p class="muted">Заводите здесь только то, чего действительно ещё нет в каталоге — сначала поищите
обычным поиском. Товар сразу попадёт в корзину заказа.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:560px">
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="ctx" value="<?= htmlspecialchars($ctxKey) ?>">

    <label>Артикул поставщика</label>
    <input type="text" name="ref" value="<?= htmlspecialchars($fields['ref']) ?>" required autofocus
           placeholder="как в прайсе поставщика, например 545910">

    <label>Название</label>
    <input type="text" name="label" value="<?= htmlspecialchars($fields['label']) ?>" required
           placeholder="как в спецификации">

    <label>Направление</label>
    <select name="direction">
      <?php foreach (PRODUCT_DIRECTIONS as $key => $d): ?>
        <option value="<?= $key ?>" <?= $fields['direction'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($d['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="muted" style="margin:-4px 0 12px">Определяет, на чьей кассе появится товар и на какой
    склад он придёт по умолчанию. Код (J/T) система выдаст сама.</p>

    <label>Цена продажи, $ <span class="muted">(необязательно, можно проставить позже)</span></label>
    <input type="text" name="price" value="<?= htmlspecialchars($fields['price']) ?>" placeholder="0">

    <div class="row">
      <div style="flex:0"><button type="submit">Создать и добавить в заказ</button></div>
      <div style="flex:0"><a class="btn secondary" href="<?= htmlspecialchars($ctx['page']) ?>">Отмена</a></div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
