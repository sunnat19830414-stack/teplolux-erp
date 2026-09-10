<?php
/**
 * Источники доходов — справочник (06.09.2026).
 *
 * Раньше источники заводились прямо на странице «Доходы», отдельной страницы не было, и в меню
 * справочник не значился. Теперь все справочники собраны в одном разделе меню — по той же схеме, что
 * и «Виды расходов» (expense_types.php).
 *
 * Скрытие НЕ удаляет источник: уже внесённые поступления продолжают показываться со своим названием,
 * источник просто перестаёт предлагаться при вводе нового. Переименование тоже безопасно —
 * поступление хранит ссылку на источник, а не его название.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $r = income_add_source((string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Источник дохода добавлен.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'rename') {
        $r = income_rename_source((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Название изменено.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'toggle') {
        $on = !empty($_POST['activate']);
        income_set_source_active((int)($_POST['id'] ?? 0), $on);
        flash_set($on ? 'Источник снова доступен при вводе.' : 'Источник скрыт — на прошлые поступления это не влияет.', 'ok');
    }
    header('Location: income_sources.php');
    exit;
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$sources = income_get_sources(false);

// Сколько расходов уже внесено по каждой категории — видно, что реально используется.
$usage = [];
$res = payroll_db()->query("SELECT fk_source, COUNT(*) n FROM llx_nt_income GROUP BY fk_source");
if ($res) while ($r = $res->fetch_assoc()) $usage[(int)$r['fk_source']] = (int)$r['n'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Источники доходов</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Добавить источник</h2>
  <p class="muted">Источники — откуда приходят деньги мимо продажи товара: электричество государству с солнечных
  батарей, аренда, услуги и работы. Заводите как вам удобно.</p>
  <form method="post" class="row" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div style="flex:1"><label>Название</label>
      <input type="text" name="name" placeholder="например: Сдача металлолома" required></div>
    <div style="flex:0"><button type="submit">Добавить</button></div>
  </form>
</div>

<div class="card">
  <h2>Справочник</h2>
  <?php if (empty($sources)): ?>
    <p class="muted">Пока пусто — добавьте первый источник выше.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Поступлений</th><th>В списке при вводе</th><th></th></tr>
      <?php foreach ($sources as $c): ?>
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
            <span class="badge <?= $isActive ? 'badge-ok' : 'badge-neutral' ?>"><?= $isActive ? 'показывается' : 'скрыт' ?></span>
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
    <p class="muted">Скрытый источник остаётся в уже внесённых поступлениях и в отчёте — он просто не
    предлагается при вводе нового.</p>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
