<?php
/**
 * Заявки на закупку — список и создание. Шеф (или Суннатилла по своей линии) выбирает поставщика,
 * видит его товары, набирает список и отправляет закупщику. Дальше заявка появляется у Нодира и
 * Абдурашида в их инструменте, и заказ поставщику оформляют уже они — именно ради этого заявка и
 * заводится: чтобы список не уходил поставщику напрямую из чата, минуя закупщиков.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/requests.php';

requests_ensure_tables();

$me = $_SESSION['user'];
$myDirection = user_direction();
$dirs = visible_directions($cfg);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Направление: у Суннатиллы оно жёстко своё, шеф выбирает.
        $direction = $myDirection ?? ($_POST['direction'] ?? '');
        if (!isset($cfg['directions'][$direction])) {
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
                flash_set('Заявка создана — добавьте товары и отправьте закупщику.', 'ok');
                header('Location: request_view.php?id=' . $newId);
                exit;
            }
        }
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$open = request_list($dirs, ['draft', 'sent', 'taken']);
$closed = request_list($dirs, ['ordered', 'declined', 'cancelled'], 40);
$showHistory = !empty($_GET['history']);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заявки на закупку</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Новая заявка</h2>
  <p class="muted">Список того, что нужно купить. Закупщик получит его целиком и оформит заказ
  поставщику сам — отправлять список поставщику напрямую больше не нужно.</p>
  <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="supplier_id" id="reqSupplierId" value="">
    <input type="hidden" name="supplier_name" id="reqSupplierName" value="">
    <?php if ($myDirection === null): ?>
      <div style="max-width:180px">
        <label>Направление</label>
        <select name="direction">
          <?php foreach ($cfg['directions'] as $code => $name): ?>
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
            <?= htmlspecialchars($cfg['directions'][$r['direction']] ?? $r['direction']) ?>
            <?= $r['supplier_name'] ? ' · ' . htmlspecialchars($r['supplier_name']) : '' ?><br>
            позиций: <?= (int)$r['line_count'] ?> · от <?= date('d.m.Y', strtotime($r['created_at'])) ?>
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
          <td><?= htmlspecialchars($cfg['directions'][$r['direction']] ?? $r['direction']) ?></td>
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
