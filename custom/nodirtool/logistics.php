<?php
/**
 * Логистика — заказы в пути.
 *
 * 05.09.2026 (R3 отчёта «Пробелы NodirTool»): перевозчик здесь БОЛЬШЕ НЕ вписывается текстом.
 * Раньше на заказе было своё свободное поле «Перевозчик» (доп.поле `carrier_name`), никак не
 * связанное с разделом «Перевозчики», где тот же перевозчик — полноценный контрагент с долгом и
 * документами. Одна компания записывалась двумя способами, и связать их было нечем. Не проявилось
 * только потому, что текстовое поле не заполнил ни один заказ.
 *
 * Теперь перевозчик берётся из РЕЙСА (раздел «Перевозки»): он же и начисляет долг, и попадает в
 * себестоимость. Если рейс ещё не оформлен — прямая ссылка его записать.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/shipments.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_logistics') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $tracking = trim($_POST['tracking_number'] ?? '');
    $deliveryDateStr = trim($_POST['delivery_date'] ?? '');
    $deliveryDateTs = $deliveryDateStr !== '' ? strtotime($deliveryDateStr) : null;

    // Перевозчик больше не передаётся — null означает «не трогать это поле» (см. докблок).
    $ok = $api->updateSupplierOrderDetails($orderId, null, $tracking, $deliveryDateTs);
    if (!$ok) {
        $message = "Ошибка сохранения по заказу #$orderId: " . $api->lastError;
        $messageType = 'err';
    } else {
        flash_set("Данные по заказу #$orderId сохранены.", 'ok');
        header('Location: logistics.php');
        exit;
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

// Заказы "в пути" — отправлены поставщику, ещё не получены (склад видит их же в TeplouxKassa)
$rows = $api->getSupplierOrdersByStatus('running', 'id,ref,socid,statut,date_commande,delivery_date');
$rows = is_array($rows) ? $rows : [];

// Имена поставщиков — одним запросом, а не getThirdparty() на каждый заказ.
$supplierNames = $rows ? $api->getThirdpartiesByIds(array_map(fn($r) => (int)$r['socid'], $rows)) : [];
$shipments = shipments_for_orders(array_map(fn($r) => (int)$r['id'], $rows));
$carrierNames = [];
if ($shipments) $carrierNames = $api->getThirdpartiesByIds(array_map(fn($s) => (int)$s['fk_carrier'], $shipments));

$orders = [];
foreach ($rows as $row) {
    $oid = (int)$row['id'];
    $socid = (int)($row['socid'] ?? 0);
    $soc = $supplierNames[$socid] ?? null;
    // Трек-номер живёт в доп.полях — их отдаёт только полный запрос заказа.
    $full = $api->getSupplierOrder($oid);
    $opts = is_array($full) ? ($full['array_options'] ?? []) : [];
    $sh = $shipments[$oid] ?? null;
    $carrierSoc = $sh ? ($carrierNames[(int)$sh['fk_carrier']] ?? null) : null;

    $orders[] = [
        'id' => $oid,
        'ref' => nt_order_display_ref($row['ref'] ?? '', $row['statut'] ?? 0, $oid),
        'supplier' => is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? "#$socid") : "#$socid",
        'date' => !empty($row['date_commande']) ? date('d.m.Y', (int)$row['date_commande']) : '',
        'delivery_date' => !empty($full['delivery_date']) ? date('Y-m-d', (int)$full['delivery_date']) : '',
        'tracking_number' => $opts['options_tracking_number'] ?? '',
        'shipment' => $sh,
        'carrier_name' => is_array($carrierSoc) ? ($carrierSoc['name'] ?? $carrierSoc['nom'] ?? '') : '',
    ];
}
usort($orders, fn($a, $b) => $b['id'] <=> $a['id']);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заказы в пути</h1>
<p class="muted">Заказы, отправленные поставщику, но ещё не полученные на склад (приёмку делают на складе, через мини-кассу).</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if (empty($orders)): ?>
  <div class="card"><p class="muted">Сейчас ничего не в пути.</p></div>
<?php else: ?>
  <?php foreach ($orders as $o): ?>
    <div class="card">
      <div class="row" style="align-items:center; margin-bottom:8px">
        <div><strong><?= htmlspecialchars($o['ref']) ?></strong>
          <span class="muted">· <?= htmlspecialchars($o['supplier']) ?> · заказан <?= htmlspecialchars($o['date']) ?></span></div>
      </div>

      <div style="margin-bottom:10px">
        <span class="muted">Везёт:</span>
        <?php if ($o['shipment']): ?>
          <strong><?= htmlspecialchars($o['carrier_name']) ?></strong>
          <span class="muted">·
            <?= htmlspecialchars(trim($o['shipment']['route_from'] . ' → ' . $o['shipment']['route_to'], ' →')) ?>
            · <?= htmlspecialchars(money((float)($o['shipment']['invoice_amount'] ?? $o['shipment']['agreed_amount']), $o['shipment']['currency'])) ?>
          </span>
          <a class="btn secondary small" href="shipments.php?id=<?= (int)$o['shipment']['rowid'] ?>">Рейс →</a>
        <?php else: ?>
          <span class="muted">рейс не оформлен — перевозчик и фрахт не учтены</span>
          <a class="btn secondary small" href="shipments.php">Записать рейс →</a>
        <?php endif; ?>
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
          <label>Номер накладной / трек-номер</label>
          <input type="text" name="tracking_number" value="<?= htmlspecialchars($o['tracking_number']) ?>">
        </div>
        <div style="flex:0"><button type="submit">Сохранить</button></div>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
