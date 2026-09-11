<?php
/**
 * Заявки на закупку закупщиков (11.09.2026, просьба пользователя: «сделать заявки на закупку для Нодира
 * и Абдурашида, и оно должно работать так же, как у шефа»). Копия BossTool/requests.php: выбрать
 * поставщика, увидеть всю его номенклатуру с рекомендацией, набрать список и отправить. Отправленная
 * заявка попадает в общий список «Заявки к оформлению» (requests_in.php) — к обоим закупщикам, как и
 * заявки шефа, и оттуда её берут в работу и собирают заказ. Шеф видит эти заявки у себя в BossTool.
 * Здесь — только заявки, составленные закупщиками; заявки руководства — в «Заявки к оформлению».
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/requests.php';

requests_ensure_tables();

$me = $_SESSION['user'];
$directions = ['J' => 'Жоми', 'T' => 'Турк'];   // закупщики видят оба направления
$myDirection = null;
$dirs = array_keys($directions);
$purchasers = array_keys($cfg['users']);
$userName = fn($login) => $cfg['users'][$login]['display_name'] ?? $login;

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $direction = $_POST['direction'] ?? '';
        if (!isset($directions[$direction])) {
            $message = 'Выберите направление.';
            $messageType = 'err';
        } else {
            $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
            $supplierName = trim($_POST['supplier_name'] ?? '') ?: null;
            $label = trim($_POST['label'] ?? '');
            $newId = request_create($direction, $me['login'], $supplierId, $supplierName, $label);
            if (!$newId) {
                $message = 'Не удалось создать заявку.';
                $messageType = 'err';
            } else {
                flash_set('Заявка создана — выберите поставщика, проставьте количества и отправьте в работу.', 'ok');
                header('Location: request_view.php?id=' . $newId);
                exit;
            }
        }
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$mine = fn($rows) => array_values(array_filter($rows, fn($r) => in_array($r['created_by'], $purchasers, true)));
$open = $mine(request_list($dirs, ['draft', 'sent', 'taken']));
$closed = $mine(request_list($dirs, ['ordered', 'declined', 'cancelled'], 40));
$showHistory = !empty($_GET['history']);

require __DIR__ . '/includes/layout_top.php';
require __DIR__ . '/includes/requests_css.php';
?>

<h1>Заявки на закупку</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Новая заявка</h2>
  <p class="muted">Составьте список закупки так же, как это делает шеф: выберите поставщика, проставьте
  количества и отправьте. Заявка появится в «<a href="requests_in.php">Заявки к оформлению</a>» у вас и у
  коллеги — оттуда её берут в работу и собирают заказ поставщику. Шеф тоже видит её у себя.</p>
  <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="supplier_id" id="reqSupplierId" value="">
    <input type="hidden" name="supplier_name" id="reqSupplierName" value="">
    <?php if ($myDirection === null): ?>
      <div style="max-width:180px">
        <label>Направление</label>
        <select name="direction">
          <?php foreach ($directions as $code => $name): ?>
            <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label>Пометка <span class="muted">(необязательно)</span></label>
      <input type="text" name="label" placeholder="например: на октябрь, срочно">
    </div>
    <div style="flex:0"><button type="submit">Создать заявку</button></div>
  </form>
</div>

<div class="card">
  <h2>В работе</h2>
  <?php if (empty($open)): ?>
    <p class="muted">Открытых заявок нет.</p>
  <?php else: ?>
    <div class="block-grid">
      <?php foreach ($open as $r): ?>
        <a class="block-btn" href="request_view.php?id=<?= (int)$r['rowid'] ?>">
          <span class="badge <?= request_status_badge($r['status']) ?>"><?= htmlspecialchars(request_status_label($r['status'])) ?></span>
          <span style="font-size:15px">
            Заявка №<?= (int)$r['rowid'] ?>
            <?= $r['label'] ? ' — ' . htmlspecialchars($r['label']) : '' ?>
          </span>
          <span class="muted">
            <?= htmlspecialchars($directions[$r['direction']] ?? $r['direction']) ?>
            <?= $r['supplier_name'] ? ' · ' . htmlspecialchars($r['supplier_name']) : '' ?><br>
            позиций: <?= (int)$r['line_count'] ?> · <?= htmlspecialchars($userName($r['created_by'])) ?>, <?= date('d.m.Y', strtotime($r['created_at'])) ?>
            <?php if ($r['taken_by']): ?><br>в работе у: <?= htmlspecialchars($r['taken_by']) ?><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>История</h2>
  <?php if (!$showHistory): ?>
    <p><a class="btn secondary small" href="requests.php?history=1">Показать закрытые заявки</a></p>
  <?php elseif (empty($closed)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <table>
      <tr><th>Заявка</th><th>Направление</th><th>Поставщик</th><th>Позиций</th><th>Статус</th><th>Дата</th></tr>
      <?php foreach ($closed as $r): ?>
        <tr>
          <td><a href="request_view.php?id=<?= (int)$r['rowid'] ?>">№<?= (int)$r['rowid'] ?></a>
            <?= $r['label'] ? '<div class="muted">' . htmlspecialchars($r['label']) . '</div>' : '' ?></td>
          <td><?= htmlspecialchars($directions[$r['direction']] ?? $r['direction']) ?></td>
          <td><?= htmlspecialchars($r['supplier_name'] ?? '—') ?></td>
          <td class="num"><?= (int)$r['line_count'] ?></td>
          <td><span class="badge <?= request_status_badge($r['status']) ?>"><?= htmlspecialchars(request_status_label($r['status'])) ?></span>
            <?= $r['decline_reason'] ? '<div class="muted">' . htmlspecialchars($r['decline_reason']) . '</div>' : '' ?></td>
          <td><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
