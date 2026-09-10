<?php
/**
 * Категории хозрасходов — справочник (06.09.2026).
 *
 * Раньше категории заводились прямо на странице «Хозрасходы», отдельной страницы не было, и в меню
 * справочник не значился. Теперь все справочники собраны в одном разделе меню — по той же схеме, что
 * и «Виды расходов» (expense_types.php).
 *
 * Скрытие НЕ удаляет категорию: уже внесённые расходы продолжают показываться со своим названием,
 * категория просто перестаёт предлагаться при вводе нового расхода. Переименование тоже безопасно —
 * расход хранит ссылку на категорию, а не её название.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $r = household_add_category((string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Категория добавлена.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'rename') {
        $r = household_rename_category((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Название изменено.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'toggle') {
        $on = !empty($_POST['activate']);
        household_set_category_active((int)($_POST['id'] ?? 0), $on);
        flash_set($on ? 'Категория снова доступна при вводе.' : 'Категория скрыта — на прошлые расходы это не влияет.', 'ok');
    }
    header('Location: expense_categories.php');
    exit;
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$categories = household_get_categories(false);

// Сколько расходов уже внесено по каждой категории — видно, что реально используется.
$usage = [];
$res = payroll_db()->query("SELECT fk_category, COUNT(*) n FROM llx_nt_household_expense GROUP BY fk_category");
if ($res) while ($r = $res->fetch_assoc()) $usage[(int)$r['fk_category']] = (int)$r['n'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Категории хозрасходов</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Добавить категорию</h2>
  <p class="muted">Категории — это на что тратим: аренда, коммунальные, связь и так далее.
  Заводите как вам удобно, список ничем не ограничен.</p>
  <form method="post" class="row" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div style="flex:1"><label>Название</label>
      <input type="text" name="name" placeholder="например: Охрана склада" required></div>
    <div style="flex:0"><button type="submit">Добавить</button></div>
  </form>
</div>

<div class="card">
  <h2>Справочник</h2>
  <?php if (empty($categories)): ?>
    <p class="muted">Пока пусто — добавьте первую категорию выше.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Внесено расходов</th><th>В списке при вводе</th><th></th></tr>
      <?php foreach ($categories as $c): ?>
        <?php $id = (int)$c['rowid']; $isActive = (int)$c['active'] === 1; ?>
        <tr>
          <td>
            <form method="post" class="row" style="align-items:end; gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="id" value="<?= $id ?>">
              <div style="flex:1"><input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>" style="margin:0"></div>
              <div style="flex:0"><button type="submit" class="small secondary">Сохранить</button></div>
            </form>
          </td>
          <td class="muted"><?= (int)($usage[$id] ?? 0) ?></td>
          <td>
            <span class="badge <?= $isActive ? 'badge-ok' : 'badge-neutral' ?>"><?= $isActive ? 'показывается' : 'скрыта' ?></span>
          </td>
          <td>
            <form method="post">
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
    <p class="muted">Скрытая категория остаётся в уже внесённых расходах и в отчёте — она просто не
    предлагается при вводе нового расхода.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
