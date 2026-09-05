<?php
/**
 * Новый товар прямо из заявки на закупку (04.09.2026, по просьбе пользователя). Поставщик прислал
 * новинку — заказать её нельзя, пока её нет в каталоге, а идти за этим в Dolibarr или к Суннату
 * означает остановить работу над заявкой.
 *
 * Созданный товар СРАЗУ добавляется позицией в заявку. В таблицу «Рекомендации к заказу» он при этом
 * не попадёт: та показывает товары, у которых уже есть закупочная цена от этого поставщика, а у
 * новинки её ещё нет. Связь появится сама, когда закупщик оформит первый заказ — цена запишется
 * в справочник, и дальше товар будет в таблице как все остальные.
 *
 * Направление обязательно и выдаётся кодом `kod_sap`: без него товар не увидит ни один продавец на
 * кассе. У Суннатиллы направление своё и не выбирается.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/requests.php';
require_once __DIR__ . '/includes/stock_lookup.php';

// Направление → префикс кода и склад по умолчанию (те же значения, что в конфигах TeplouxKassa).
const PRODUCT_DIRECTIONS = [
    'J' => ['prefix' => 'J', 'warehouse' => 4],
    'T' => ['prefix' => 'T', 'warehouse' => 6],
];

$requestId = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$req = $requestId ? request_get($requestId) : null;

if (!$req) {
    http_response_code(404);
    die('Заявка не найдена.');
}
if (!can_see_direction($req['direction'])) {
    http_response_code(403);
    die('Эта заявка относится к другому направлению.');
}
if ($req['status'] !== 'draft') {
    http_response_code(403);
    die('Заявка уже отправлена закупщику — добавлять в неё товары нельзя.');
}

$supplierId = (int)($req['fk_supplier'] ?? 0);
$suppCur = supplier_contract_currency($supplierId);
$curLabel = $suppCur === 'USD' ? '$' : $suppCur;

$message = '';
$messageType = '';
$fields = ['ref' => '', 'label' => '', 'qty' => '1', 'price' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields['ref'] = trim($_POST['ref'] ?? '');
    $fields['label'] = trim($_POST['label'] ?? '');
    $fields['qty'] = trim($_POST['qty'] ?? '1');
    $fields['price'] = trim($_POST['price'] ?? '');
    $qty = (float)str_replace(',', '.', $fields['qty']);
    if ($qty <= 0) $qty = 1;
    $price = $fields['price'] === '' ? 0.0 : (float)str_replace(',', '.', $fields['price']);

    // Направление берём у САМОЙ заявки — оно уже проверено на доступность выше.
    $dir = PRODUCT_DIRECTIONS[$req['direction']] ?? null;

    if ($fields['ref'] === '' || $fields['label'] === '') {
        $message = 'Заполните артикул и название.';
        $messageType = 'err';
    } elseif (!$dir) {
        $message = 'У заявки не указано направление — сообщите Суннату.';
        $messageType = 'err';
    } elseif (product_ref_exists($fields['ref'])) {
        $message = 'Товар с таким артикулом уже есть в каталоге — найдите его в таблице ниже.';
        $messageType = 'err';
    } else {
        $newId = $api->createProduct($fields['ref'], $fields['label'], $dir['warehouse']);
        if (!$newId) {
            $message = 'Не удалось создать товар: ' . $api->lastError;
            $messageType = 'err';
        } else {
            $newId = (int)$newId;
            $kodSap = next_kod_sap($dir['prefix']);
            $efOk = $api->updateProductExtrafields($newId, ['kod_sap' => $kodSap, 'artikul' => $fields['ref']]);

            request_add_line($requestId, $newId, $fields['ref'], $fields['label'], $qty, '');

            // Заводская цена, если её указали: записываем как закупочную цену ИМЕННО этого
            // поставщика. Побочный полезный эффект — товар сразу появляется в таблице
            // «Рекомендации к заказу» (она показывает то, у чего есть цена от поставщика).
            $priceNote = '';
            if ($price > 0 && $supplierId > 0) {
                $saved = $api->savePurchasePrice($newId, $supplierId, $price, $suppCur, currency_rate($suppCur));
                $priceNote = $saved
                    ? " Цена {$price} {$curLabel} записана как заводская."
                    : ' Цену записать НЕ удалось: ' . $api->lastError . ' — впишет закупщик при заказе.';
            }

            if ($efOk === null) {
                // Товар создан, но без кода направления его не увидят на кассе — говорим прямо,
                // а не заканчиваем молчаливым «готово».
                flash_set("Товар «{$fields['label']}» создан и добавлен в заявку, но код направления "
                    . 'НЕ проставлен: ' . $api->lastError . ' — сообщите Суннату.', 'err');
            } else {
                flash_set("Товар «{$fields['label']}» создан (артикул {$fields['ref']}, код {$kodSap}) "
                    . 'и добавлен в заявку.' . $priceNote, 'ok');
            }
            $_SESSION['_preserve_once']['selected_request'] = true;
            header('Location: request_view.php?id=' . $requestId);
            exit;
        }
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Новый товар</h1>
<p><a class="btn secondary small" href="request_view.php?id=<?= $requestId ?>">← Назад в заявку №<?= $requestId ?></a></p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:620px">
  <p class="muted">Заводите здесь только то, чего действительно ещё нет в каталоге — сначала поищите
  фильтром в таблице заявки. Товар сразу попадёт в заявку.</p>

  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="request_id" value="<?= $requestId ?>">

    <label>Артикул поставщика</label>
    <input type="text" name="ref" value="<?= htmlspecialchars($fields['ref']) ?>" required autofocus
           placeholder="как в прайсе поставщика, например 545910">

    <label>Название</label>
    <input type="text" name="label" value="<?= htmlspecialchars($fields['label']) ?>" required
           placeholder="как в прайсе или спецификации">

    <label>Сколько нужно</label>
    <input type="number" name="qty" value="<?= htmlspecialchars($fields['qty']) ?>" step="any" min="0.001">

    <label>Заводская цена, <?= htmlspecialchars($curLabel) ?>
      <span class="muted">— необязательно, если есть в прайсе поставщика</span></label>
    <input type="number" name="price" value="<?= htmlspecialchars($fields['price']) ?>" step="0.0001" min="0"
           placeholder="как в прайсе">

    <p class="muted">Направление — <strong><?= htmlspecialchars($cfg['directions'][$req['direction']] ?? $req['direction']) ?></strong>,
    как у самой заявки. Код товара система выдаст сама.
    <?php if ($supplierId > 0): ?>
      Цена запишется как заводская для поставщика «<?= htmlspecialchars($req['supplier_name']) ?>»
      <?php if ($suppCur !== 'USD'): ?>в <?= htmlspecialchars($suppCur) ?>, как в договоре<?php endif; ?>.
      Если не заполнить — впишет закупщик при оформлении заказа.
    <?php else: ?>
      Поставщик в заявке не указан, поэтому цену записать не к кому — её проставит закупщик.
    <?php endif; ?>
    </p>

    <div class="row">
      <div style="flex:0"><button type="submit">Создать и добавить в заявку</button></div>
      <div style="flex:0"><a class="btn secondary" href="request_view.php?id=<?= $requestId ?>">Отмена</a></div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
