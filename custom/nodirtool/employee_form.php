<?php
/**
 * Карточка сотрудника (04.09.2026) — создание/редактирование. Доступ только Нодиру (см. page_access
 * в config.php, проверка в auth.php). Свой список сотрудников, отдельно от клиентов/поставщиков
 * Dolibarr — это не контрагенты, и смешивать их было бы путаницей.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$message = '';
$messageType = '';
$fields = ['name' => '', 'position' => '', 'fk_department' => 0, 'salary_usd' => '', 'card_payment' => 0, 'active' => 1, 'note' => ''];

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $emp = payroll_get_employee($id);
    if (!$emp) { http_response_code(404); die('Сотрудник не найден.'); }
    $fields = [
        'name' => $emp['name'], 'position' => $emp['position'] ?? '',
        'fk_department' => (int)($emp['fk_department'] ?? 0),
        'salary_usd' => $emp['salary_usd'], 'card_payment' => (int)$emp['card_payment'],
        'active' => (int)$emp['active'], 'note' => $emp['note'] ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'name' => trim($_POST['name'] ?? ''),
        'position' => trim($_POST['position'] ?? ''),
        'fk_department' => (int)($_POST['fk_department'] ?? 0),
        'salary_usd' => (float)($_POST['salary_usd'] ?? 0),
        'card_payment' => !empty($_POST['card_payment']) ? 1 : 0,
        'active' => !empty($_POST['active']) ? 1 : 0,
        'note' => trim($_POST['note'] ?? ''),
    ];
    $r = payroll_save_employee($id, $fields['name'], $fields['position'], (int)$fields['fk_department'],
        (float)$fields['salary_usd'], (bool)$fields['card_payment'], (bool)$fields['active'], $fields['note']);
    if (!$r['ok']) {
        $message = $r['error'];
        $messageType = 'err';
    } else {
        // Возвращаемся в раздел зарплаты с этим сотрудником уже выбранным — тот же одноразовый
        // маркер _preserve_once, что и в остальных формах проекта (иначе GET сбросит выбор).
        $_SESSION['payroll_employee'] = ['id' => (int)$r['id'], 'name' => $fields['name']];
        $_SESSION['_preserve_once']['payroll_employee'] = true;
        flash_set($isEdit ? 'Карточка сохранена.' : 'Сотрудник добавлен.', 'ok');
        header('Location: payroll.php');
        exit;
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1><?= $isEdit ? 'Карточка сотрудника' : 'Новый сотрудник' ?></h1>
<p><a href="payroll.php" class="btn secondary">← К зарплате</a></p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:560px">
  <form method="post">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <label>Имя</label>
    <input type="text" name="name" value="<?= htmlspecialchars($fields['name']) ?>" required autofocus>
    <label>Должность</label>
    <input type="text" name="position" value="<?= htmlspecialchars($fields['position']) ?>" placeholder="например: зав.склад, грузчик, водитель">

    <?php // Отделы (04.09.2026) — список ведётся на самой странице «Зарплата». Уже привязанный, но
          // скрытый отдел остаётся в выборе для ЭТОГО сотрудника, чтобы правка карточки не сбрасывала
          // привязку молча. ?>
    <?php
      $departments = payroll_get_departments(true);
      $currentDeptInList = false;
      foreach ($departments as $d) { if ((int)$d['rowid'] === (int)$fields['fk_department']) $currentDeptInList = true; }
      if (!$currentDeptInList && (int)$fields['fk_department'] > 0) {
          foreach (payroll_get_departments(false) as $d) {
              if ((int)$d['rowid'] === (int)$fields['fk_department']) { $departments[] = $d + ['_hidden' => true]; }
          }
      }
    ?>
    <label>Отдел</label>
    <select name="fk_department">
      <option value="0">— без отдела —</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= (int)$d['rowid'] ?>" <?= (int)$d['rowid'] === (int)$fields['fk_department'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($d['name']) ?><?= !empty($d['_hidden']) ? ' (скрыт)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if (empty(payroll_get_departments(true))): ?>
      <p class="muted" style="margin:-4px 0 10px">Отделов пока нет — завести можно на странице
      «<a href="payroll.php">Зарплата и авансы</a>», внизу.</p>
    <?php endif; ?>

    <label>Оклад в месяц, $</label>
    <input type="number" step="0.01" min="0" name="salary_usd" value="<?= htmlspecialchars((string)$fields['salary_usd']) ?>">
    <p class="muted" style="margin:-4px 0 10px">Подставляется при начислении зарплаты. При начислении
    сумму можно поправить разово (премия, неполный месяц) — оклад в карточке при этом не меняется.</p>

    <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer">
      <input type="checkbox" name="card_payment" value="1" style="width:auto" <?= $fields['card_payment'] ? 'checked' : '' ?>>
      Зарплата на карту (официально)
    </label>
    <p class="muted" style="margin:-4px 0 10px">Только пометка для вас — при выплате на карту всё равно
    вводятся фактические суммы (сколько пришло на карту и сколько списалось со счёта).</p>

    <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer">
      <input type="checkbox" name="active" value="1" style="width:auto" <?= $fields['active'] ? 'checked' : '' ?>>
      Работает
    </label>
    <p class="muted" style="margin:-4px 0 10px">Снимите галочку, если человек уволился — он пропадёт из
    начисления «всем сразу», но история и остаток долга сохранятся.</p>

    <label>Заметка</label>
    <input type="text" name="note" value="<?= htmlspecialchars($fields['note']) ?>">

    <div class="row" style="margin-top:6px">
      <div style="flex:0"><button type="submit"><?= $isEdit ? 'Сохранить' : 'Добавить' ?></button></div>
      <div style="flex:0"><a class="btn secondary" href="payroll.php">Отмена</a></div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
