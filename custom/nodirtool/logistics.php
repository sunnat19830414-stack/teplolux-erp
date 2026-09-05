<?php
require_once __DIR__ . '/includes/auth.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_logistics') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $carrier = trim($_POST['carrier_name'] ?? '');
    $tracking = trim($_POST['tracking_number'] ?? '');
    $deliveryDateStr = trim($_POST['delivery_date'] ?? '');
    $deliveryDateTs = $deliveryDateStr !== '' ? strtotime($deliveryDateStr) : null;

    $ok = $api->updateSupplierOrderDetails($orderId, $carrier, $tracking, $deliveryDateTs);
    if (!$ok) {
        $message = "Ошибка сохранения по заказу #$orderId: " . $api->lastError;
        $messageType = 'err';
    } else {
        $message = "Данные по заказу #$orderId сохранены.";
        $messageType = 'ok';
    }
}

// Заказы "в пути" — отправлены поставщику, ещё не получены (склад видит их же в TeplouxKassa)
$rows = $api->getSupplierOrdersByStatus('running', 'id,ref,socid,statut,date_commande,delivery_date');
$supplierNameCache = [];
$orders = [];
if (is_array($rows)) {
    foreach ($rows as $row) {
        $socid = (int)($row['socid'] ?? 0);
        if (!array_key_exists($socid, $supplierNameCache)) {
            $soc = $api->getThirdparty($socid);
            $supplierNameCache[$socid] = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? "#$socid") : "#$socid";
        }
        // доп.поля (перевозчик/трек) — только полным запросом заказа
        $full = $api->getSupplierOrder((int)$row['id']);
        $opts = is_array($full) ? ($full['array_options'] ?? []) : [];
        $orders[] = [
            'id' => (int)$row['id'],
            'ref' => $row['ref'] ?? '',
            'supplier' => $supplierNameCache[$socid],
            'date' => !empty($row['date_commande']) ? date('d.m.Y', (int)$row['date_commande']) : '',
            'delivery_date' => !empty($full['delivery_date']) ? date('Y-m-d', (int)$full['delivery_date']) : '',
            'carrier_name' => $opts['options_carrier_name'] ?? '',
            'tracking_number' => $opts['options_tracking_number'] ?? '',
        ];
    }
}
usort($orders, fn($a, $b) => $b['id'] <=> $a['id']);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Логистика — заказы в пути</h1>
<p class="muted">Заказы, отправленные поставщику, но ещё не полученные на склад (приёмку делают на складе, через мини-кассу).</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if (empty($orders)): ?>
  <div class="card"><p class="muted">Сейчас ничего не в пути.</p></div>
<?php else: ?>
  <?php foreach ($orders as $o): ?>
    <div class="card">
      <div class="row" style="align-items:center; margin-bottom:8px">
        <div><strong><?= htmlspecialchars($o['ref']) ?></strong> <span class="muted">· <?= htmlspecialchars($o['supplier']) ?> · заказан <?= htmlspecialchars($o['date']) ?></span></div>
      </div>
      <form method="post" class="row" style="align-items:end">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_logistics">
        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
        <div>
          <label>Ожидаемая дата доставки</label>
          <input type="date" name="delivery_date" value="<?= htmlspecialchars($o['delivery_date']) ?>">
        </div>
        <div>
          <label>Перевозчик</label>
          <input type="text" name="carrier_name" value="<?= htmlspecialchars($o['carrier_name']) ?>" placeholder="Транспортная компания">
        </div>
        <div>
          <label>Номер накладной / трек-номер</label>
          <input type="text" name="tracking_number" value="<?= htmlspecialchars($o['tracking_number']) ?>">
        </div>
        <div style="flex:0"><button type="submit">Сохранить</button></div>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
