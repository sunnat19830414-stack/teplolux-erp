<?php
/**
 * Создание нового клиента / редактирование существующего. Вызывается со страниц, где выбирается
 * клиент (sale/debt/advance/reports/return) — параметр ctx определяет, куда и в какую сессионную
 * переменную вернуться после сохранения (белый список ниже — не принимаем произвольные значения
 * из запроса, чтобы нельзя было подсунуть чужое имя сессионной переменной).
 */
require_once __DIR__ . '/includes/auth.php';

const CLIENT_FORM_CONTEXTS = [
    'sale'     => ['page' => 'sale.php',     'field' => 'sale_client'],
    'debt'     => ['page' => 'debt.php',     'field' => 'debt_client'],
    'advance'  => ['page' => 'advance.php',  'field' => 'advance_client'],
    'reports'  => ['page' => 'reports.php',  'field' => 'report_client'],
    'return'   => ['page' => 'return.php',   'field' => 'return_client'],
    'payout'   => ['page' => 'payout.php',   'field' => 'payout_client'],
];

$ctxKey = $_GET['ctx'] ?? ($_POST['ctx'] ?? '');
$ctx = CLIENT_FORM_CONTEXTS[$ctxKey] ?? null;

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

$message = '';
$messageType = '';
$fields = ['name' => '', 'phone' => '', 'town' => '', 'address' => ''];
$monthlyBrandDiscount = false;

// client_belongs_to_direction() теперь общая функция — includes/auth.php.

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $existing = $api->getThirdparty($id);
    if (!is_array($existing) || !client_belongs_to_direction($existing, $cfg['ref_prefix'])) {
        http_response_code(403);
        die('Этот клиент не найден или относится к другому направлению.');
    }
    $fields['name'] = $existing['name'] ?? $existing['nom'] ?? '';
    $fields['phone'] = $existing['phone'] ?? '';
    $fields['town'] = $existing['town'] ?? '';
    $fields['address'] = $existing['address'] ?? '';
    $monthlyBrandDiscount = !empty(($existing['array_options'] ?? [])['options_monthly_brand_discount']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields['name'] = trim($_POST['name'] ?? '');
    $fields['phone'] = trim($_POST['phone'] ?? '');
    $fields['town'] = trim($_POST['town'] ?? '');
    $fields['address'] = trim($_POST['address'] ?? '');
    $monthlyBrandDiscount = !empty($_POST['monthly_brand_discount']);
    $fields['array_options'] = ['options_monthly_brand_discount' => $monthlyBrandDiscount ? 1 : 0];

    if ($fields['name'] === '') {
        $message = 'Укажите название/имя клиента.';
        $messageType = 'err';
    } elseif ($isEdit) {
        $existing = $api->getThirdparty($id);
        if (!is_array($existing) || !client_belongs_to_direction($existing, $cfg['ref_prefix'])) {
            http_response_code(403);
            die('Этот клиент не найден или относится к другому направлению.');
        }
        $ok = $api->updateClient($id, $fields);
        if ($ok === null) {
            $message = 'Ошибка сохранения: ' . $api->lastError;
            $messageType = 'err';
        } elseif ($ctx) {
            $_SESSION[$ctx['field']] = ['id' => $id, 'name' => $fields['name']];
            $_SESSION['_preserve_once'][$ctx['field']] = true;
            header('Location: ' . $ctx['page']);
            exit;
        } else {
            $message = 'Данные клиента сохранены.';
            $messageType = 'ok';
        }
    } else {
        $code = $api->getNextClientCode($cfg['ref_prefix']);
        $newId = $api->createClient($fields + ['code_client' => $code, 'country_id' => 230]);
        if (!$newId) {
            $message = 'Ошибка создания: ' . $api->lastError;
            $messageType = 'err';
        } elseif ($ctx) {
            $_SESSION[$ctx['field']] = ['id' => (int)$newId, 'name' => $fields['name']];
            $_SESSION['_preserve_once'][$ctx['field']] = true;
            header('Location: ' . $ctx['page']);
            exit;
        } else {
            $id = (int)$newId;
            $isEdit = true;
            $message = "Клиент создан (код {$code}).";
            $messageType = 'ok';
        }
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1><?= $isEdit ? 'Редактировать клиента' : 'Новый клиент' ?></h1>
<?php if ($ctx): ?>
  <p class="muted">После сохранения вы вернётесь в раздел «<?= htmlspecialchars(basename($ctx['page'], '.php')) ?>» с этим клиентом уже выбранным.</p>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:520px">
  <form method="post">
  <?= csrf_field() ?>
    <?php if ($ctxKey): ?><input type="hidden" name="ctx" value="<?= htmlspecialchars($ctxKey) ?>"><?php endif; ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <label>Название / ФИО</label>
    <input type="text" name="name" value="<?= htmlspecialchars($fields['name']) ?>" required autofocus>
    <label>Телефон</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($fields['phone']) ?>">
    <label>Город</label>
    <input type="text" name="town" value="<?= htmlspecialchars($fields['town']) ?>">
    <label>Адрес</label>
    <input type="text" name="address" value="<?= htmlspecialchars($fields['address']) ?>">
    <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer">
      <input type="checkbox" name="monthly_brand_discount" value="1" style="width:auto" <?= $monthlyBrandDiscount ? 'checked' : '' ?>>
      Повышенная скидка по брендам сразу, без порога (устная договорённость)
    </label>
    <p class="muted" style="margin-top:-6px">Caleffi/Madas/Sitem/Fantini Cosmi/Mut — обычно 14,5% вместо 10% только после набора на 10 000 $ в одном чеке; этому клиенту 14,5% будет предлагаться СРАЗУ, на любую сумму (кассир всё равно может поправить скидку вручную в корзине).</p>
    <div class="row">
      <div style="flex:0"><button type="submit"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button></div>
      <div style="flex:0"><a class="btn secondary" href="<?= htmlspecialchars($ctx['page'] ?? 'sale.php') ?>">Отмена</a></div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
