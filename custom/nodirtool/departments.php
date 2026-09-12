<?php
/**
 * Отделы — справочник (06.09.2026). Раньше управлялись прямо на странице «Зарплата и авансы».
 *
 * Отделы нужны ТОЛЬКО зарплате: группировка сотрудников в списке и разбивка по отделам в сводке за
 * месяц. К хозрасходам они не привязываются (решение пользователя, 04.09.2026).
 *
 * Доступ тот же, что и у зарплаты — только Нодир (см. `page_access` в config.php): по отделам видно
 * структуру штата, а зарплата закрыта не просто так.
 *
 * Скрытие НЕ рвёт привязку: сотрудники остаются в отделе и в списке, и в отчёте, а в карточке
 * сотрудника отдел показывается как «(скрыт)» и не сбрасывается.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $r = payroll_add_department((string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Отдел добавлен.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'rename') {
        $r = payroll_rename_department((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Название изменено.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'toggle') {
        $on = !empty($_POST['activate']);
        payroll_set_department_active((int)($_POST['id'] ?? 0), $on);
        flash_set($on ? 'Отдел снова доступен при выборе.' : 'Отдел скрыт — сотрудники в нём остались.', 'ok');
    }
    header('Location: departments.php');
    exit;
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$departments = payroll_get_departments(false);
$counts = payroll_department_employee_counts();

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Отделы</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Добавить отдел</h2>
  <p class="muted">Отдел указывается в карточке сотрудника. По отделам группируется список людей и
  считается сводка за месяц.</p>
  <form method="post" class="row" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div style="flex:1"><label>Название</label>
      <input type="text" name="name" placeholder="например: Доставка" required></div>
    <div style="flex:0"><button type="submit">Добавить</button></div>
  </form>
</div>

<div class="card">
  <h2>Справочник</h2>
  <?php if (empty($departments)): ?>
    <p class="muted">Пока пусто — добавьте первый отдел выше.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Сотрудников</th><th>В списке при выборе</th><th></th></tr>
      <?php foreach ($departments as $d): ?>
        <?php $id = (int)$d['rowid']; $isActive = (int)$d['active'] === 1; ?>
        <tr>
          <td>
            <form method="post" class="row" style="align-items:end; gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="id" value="<?= $id ?>">
              <div style="flex:1"><input type="text" name="name" value="<?= htmlspecialchars($d['name']) ?>" style="margin:0"></div>
              <div style="flex:0"><button type="submit" class="small secondary">Сохранить</button></div>
            </form>
          </td>
          <td class="muted"><?= (int)($counts[$id] ?? 0) ?></td>
          <td>
            <span class="badge <?= $isActive ? 'badge-ok' : 'badge-neutral' ?>"><?= $isActive ? 'показывается' : 'скрыт' ?></span>
          </td>
          <td>
            <?php $cnt = (int)($counts[$id] ?? 0); ?>
            <form method="post"
                  <?= ($isActive && $cnt > 0)
                      ? 'onsubmit="return appConfirmSubmit(this, \'В этом отделе ' . $cnt
                        . ' сотрудник(ов). Скрыть отдел? Люди останутся привязаны к нему, отдел просто'
                        . ' пропадёт из выбора при заведении новых.\');"'
                      : '' ?>>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="activate" value="<?= $isActive ? '' : '1' ?>">
              <button type="submit" class="small secondary"><?= $isActive ? 'Скрыть' : 'Показывать' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted">Скрытый отдел не предлагается при выборе, но сотрудники в нём остаются — и в
    списке, и в отчёте по отделам.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
