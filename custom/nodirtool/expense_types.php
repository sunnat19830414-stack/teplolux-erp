<?php
/**
 * Виды логистических расходов — справочник (Ж7 отчёта «Пробелы NodirTool», 05.09.2026).
 *
 * Раньше семь видов были зашиты в код: всё нетипичное валили в «Прочее», и разобраться потом было
 * невозможно. Категории хозрасходов Абдурашид заводит сам — здесь такой возможности не было.
 *
 * Скрытие НЕ удаляет вид: уже внесённые расходы продолжают показываться со своим названием, вид
 * просто пропадает из списка при вводе нового расхода. Переименование тоже безопасно — код
 * не меняется, старые записи читаются как прежде.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logistics.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $r = logistics_add_expense_type((string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Вид расхода добавлен.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'rename') {
        $r = logistics_rename_expense_type((string)($_POST['code'] ?? ''), (string)($_POST['name'] ?? ''));
        flash_set($r['ok'] ? 'Название изменено.' : $r['error'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'toggle') {
        $code = (string)($_POST['code'] ?? '');
        $on = !empty($_POST['activate']);
        logistics_set_expense_type_active($code, $on);
        flash_set($on ? 'Вид расхода снова доступен при вводе.' : 'Вид расхода скрыт — на прошлые записи это не влияет.', 'ok');
    }
    header('Location: expense_types.php');
    exit;
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$types = logistics_expense_types(false);
unset($types['fx_diff']);   // служебный: курсовую разницу ведёт программа (includes/shipments.php)
$active = logistics_expense_types(true);

// Сколько расходов уже внесено по каждому виду — чтобы было видно, что реально используется.
$db = logistics_db();
$usage = [];
$res = $db->query("SELECT expense_type, COUNT(*) n FROM llx_supplier_logistics_expense GROUP BY expense_type");
if ($res) while ($r = $res->fetch_assoc()) $usage[$r['expense_type']] = (int)$r['n'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Виды логистических расходов</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Добавить свой вид</h2>
  <p class="muted">Чтобы не сваливать нетипичное в «Прочее» — потом не разобрать, за что платили.</p>
  <form method="post" class="row" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <div style="flex:1"><label>Название</label>
      <input type="text" name="name" placeholder="например: Хранение в порту" required></div>
    <div style="flex:0"><button type="submit">Добавить</button></div>
  </form>
</div>

<div class="card">
  <h2>Справочник</h2>
  <table>
    <tr><th>Название</th><th>Внесено расходов</th><th>В списке при вводе</th><th></th></tr>
    <?php foreach ($types as $code => $name): ?>
      <?php $isActive = isset($active[$code]); ?>
      <tr>
        <td>
          <form method="post" class="row" style="align-items:end; gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
            <div style="flex:1"><input type="text" name="name" value="<?= htmlspecialchars($name) ?>" style="margin:0"></div>
            <div style="flex:0"><button type="submit" class="small secondary">Сохранить</button></div>
          </form>
        </td>
        <td class="muted"><?= (int)($usage[$code] ?? 0) ?></td>
        <td>
          <?php if ($isActive): ?>
            <span class="badge badge-ok">показывается</span>
          <?php else: ?>
            <span class="badge badge-neutral">скрыт</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
            <input type="hidden" name="activate" value="<?= $isActive ? '' : '1' ?>">
            <button type="submit" class="small secondary"><?= $isActive ? 'Скрыть' : 'Показывать' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted">Скрытый вид остаётся в уже внесённых расходах и в отчёте себестоимости — он просто
  не предлагается при вводе нового.</p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
