<?php
/**
 * Создание нового перевозчика / редактирование существующего — зеркало supplier_form.php, но для
 * контрагентов Dolibarr, помеченных extrafield is_carrier=1 (топ-5 пункт 3, 02.09.2026). Отдельный
 * файл (не общий с supplier_form.php) — у перевозчиков нет ни contract_amount/contract_start, ни
 * fournisseur=1, поля/создание отличаются, а смешивать ctx двух разных сущностей в одном файле было
 * бы путаницей, не экономией.
 */
require_once __DIR__ . '/includes/auth.php';

const CARRIER_FORM_CONTEXTS = [
    'carriers' => ['page' => 'carriers.php', 'field' => 'selected_carrier'],
    // UX-N2 (внешний отчёт, 02.09.2026): "+ Новый перевозчик" прямо из формы расхода — не полем выбора
    // клиента/поставщика в сессии, а ОДНОРАЗОВЫМ маркером "только что создан, подставь в пикер" (см.
    // batches.php/order_view.php, читают new_carrier_for_expense и чистят его сами при чтении).
    // 'also_preserve' — batches.php само хранит выбранную партию в $_SESSION['selected_batch'] и
    // сбрасывает её на любой обычный GET (reset_selection_unless_preserved) — без этого редирект назад
    // сюда потерял бы выбранную партию, вернув на пустой дашборд партий вместо той, что редактировали.
    'batch_expense' => ['page' => 'batches.php', 'field' => 'new_carrier_for_expense', 'also_preserve' => 'selected_batch'],
    // order_view.php адресуется по id заказа в URL (не в сессии, как batch) — need_id=true просит
    // дописать ?id=<return_id> к странице возврата, return_id передаётся скрытым полем формы.
    'order_expense' => ['page' => 'order_view.php', 'field' => 'new_carrier_for_expense', 'need_id' => true],
];

$ctxKey = $_GET['ctx'] ?? ($_POST['ctx'] ?? '');
$ctx = CARRIER_FORM_CONTEXTS[$ctxKey] ?? null;
$returnId = (int)($_GET['return_id'] ?? $_POST['return_id'] ?? 0);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

$message = '';
$messageType = '';
$fields = ['name' => '', 'phone' => '', 'town' => '', 'address' => ''];

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $existing = $api->getThirdparty($id);
    if (!is_array($existing)) {
        http_response_code(404);
        die('Перевозчик не найден.');
    }
    $fields['name'] = $existing['name'] ?? $existing['nom'] ?? '';
    $fields['phone'] = $existing['phone'] ?? '';
    $fields['town'] = $existing['town'] ?? '';
    $fields['address'] = $existing['address'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields['name'] = trim($_POST['name'] ?? '');
    $fields['phone'] = trim($_POST['phone'] ?? '');
    $fields['town'] = trim($_POST['town'] ?? '');
    $fields['address'] = trim($_POST['address'] ?? '');

    if ($fields['name'] === '') {
        $message = 'Укажите название перевозчика.';
        $messageType = 'err';
    } elseif ($isEdit) {
        $ok = $api->updateSupplier($id, $fields); // обычный PUT thirdparties — тот же метод годится
        if ($ok === null) {
            $message = 'Ошибка сохранения: ' . $api->lastError;
            $messageType = 'err';
        } elseif ($ctx) {
            $_SESSION[$ctx['field']] = ['id' => $id, 'name' => $fields['name']];
            $_SESSION['_preserve_once'][$ctx['field']] = true;
            if (!empty($ctx['also_preserve'])) $_SESSION['_preserve_once'][$ctx['also_preserve']] = true;
            $loc = $ctx['page'] . ((!empty($ctx['need_id']) && $returnId) ? '?id=' . $returnId : '');
            header('Location: ' . $loc);
            exit;
        } else {
            $message = 'Данные перевозчика сохранены.';
            $messageType = 'ok';
        }
    } else {
        $newId = $api->createCarrier($fields + ['country_id' => 230]);
        if (!$newId) {
            $message = 'Ошибка создания: ' . $api->lastError;
            $messageType = 'err';
        } elseif ($ctx) {
            $_SESSION[$ctx['field']] = ['id' => (int)$newId, 'name' => $fields['name']];
            $_SESSION['_preserve_once'][$ctx['field']] = true;
            if (!empty($ctx['also_preserve'])) $_SESSION['_preserve_once'][$ctx['also_preserve']] = true;
            $loc = $ctx['page'] . ((!empty($ctx['need_id']) && $returnId) ? '?id=' . $returnId : '');
            header('Location: ' . $loc);
            exit;
        } else {
            $id = (int)$newId;
            $isEdit = true;
            $message = 'Перевозчик создан.';
            $messageType = 'ok';
        }
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1><?= $isEdit ? 'Редактировать перевозчика' : 'Новый перевозчик' ?></h1>
<?php if ($ctx): ?>
  <p class="muted">После сохранения вы вернётесь в раздел «Перевозчики» с этим перевозчиком уже выбранным.</p>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:520px">
  <form method="post">
  <?= csrf_field() ?>
    <?php if ($ctxKey): ?><input type="hidden" name="ctx" value="<?= htmlspecialchars($ctxKey) ?>"><?php endif; ?>
    <?php if (!empty($ctx['need_id']) && $returnId): ?><input type="hidden" name="return_id" value="<?= $returnId ?>"><?php endif; ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <label>Название</label>
    <input type="text" name="name" value="<?= htmlspecialchars($fields['name']) ?>" required autofocus>
    <label>Телефон</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($fields['phone']) ?>">
    <label>Город</label>
    <input type="text" name="town" value="<?= htmlspecialchars($fields['town']) ?>">
    <label>Адрес</label>
    <input type="text" name="address" value="<?= htmlspecialchars($fields['address']) ?>">
    <div class="row">
      <div style="flex:0"><button type="submit"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button></div>
      <?php $cancelHref = ($ctx['page'] ?? 'carriers.php') . ((!empty($ctx['need_id']) && $returnId) ? '?id=' . $returnId : ''); ?>
      <div style="flex:0"><a class="btn secondary" href="<?= htmlspecialchars($cancelHref) ?>">Отмена</a></div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
