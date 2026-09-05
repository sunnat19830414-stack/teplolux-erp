<?php
/**
 * "Сводка" — топ-5 пункт 5, 02.09.2026 (переделана целиком, была просто счётчиками). Теперь это
 * конкретный список действий: каждый пункт — либо прямая ссылка на что-то одно (заказ), либо блок с
 * прямыми ссылками на КОНКРЕТНЫЕ заказы/поставщиков/перевозчиков (не просто число). См. CLAUDE.md.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/logistics.php';
require_once __DIR__ . '/includes/mycash.php';

// --- 1. Моя касса — неподтверждённые поступления (только СВОЙ счёт текущего логина) ---
$myCashUnconfirmed = [];
$myCashAcc = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCashAcc) {
    $lines = $api->getBankLines((int)$myCashAcc['id']);
    $ackMap = mycash_get_ack_map((int)$myCashAcc['id']);
    foreach ($lines as $l) {
        $amount = (float)($l['amount'] ?? 0);
        $lineId = (int)($l['id'] ?? 0);
        if ($amount > 0 && !isset($ackMap[$lineId])) {
            $myCashUnconfirmed[] = ['id' => $lineId, 'amount' => $amount, 'label' => $l['label'] ?? '', 'date' => $l['dateo'] ?? null];
        }
    }
}

// --- 2. Заказы-черновики / проведены / утверждены — ещё НЕ отправлены поставщику ---
// N-3 (внешняя приёмка, 03.09.2026): раньше все три статуса шли ОДНИМ списком вперемешку — а это три
// разных состояния, требующих разных действий (черновик надо провести, проведённый — утвердить,
// утверждённый — отправить поставщику). Теперь сгруппированы по статусу, каждая группа со своей
// подсказкой "что с этим делать".
$draftLikeGroups = [
    'draft'     => ['statut' => 0, 'title' => 'Черновики',  'todo' => 'собраны, но ещё не проведены', 'orders' => []],
    'validated' => ['statut' => 1, 'title' => 'Проведены',  'todo' => 'ждут вашего утверждения',      'orders' => []],
    'approved'  => ['statut' => 2, 'title' => 'Утверждены', 'todo' => 'осталось отправить поставщику', 'orders' => []],
];
$draftLikeTotal = 0;
foreach ($draftLikeGroups as $st => &$grp) {
    $rows = $api->getSupplierOrdersByStatus($st, 'id,ref,socid,statut,total_ttc,date_commande');
    if (is_array($rows)) {
        foreach ($rows as $row) { $grp['orders'][] = $row; $draftLikeTotal++; }
    }
}
unset($grp);

// --- 3. Заказы в пути — только с близкой (≤3 дня) или просроченной датой доставки ---
$deliveryWarnings = [];
$runningRows = $api->getSupplierOrdersByStatus('running', 'id,ref,socid,total_ttc,delivery_date');
if (is_array($runningRows)) {
    $todayTs = strtotime(date('Y-m-d'));
    foreach ($runningRows as $row) {
        $dTs = !empty($row['delivery_date']) ? strtotime(date('Y-m-d', (int)$row['delivery_date'])) : null;
        if ($dTs === null) continue; // дата не указана — нечего сравнивать, не показываем
        $daysLeft = (int)round(($dTs - $todayTs) / 86400);
        if ($daysLeft <= 3) { // просрочено или осталось ≤3 дней
            $row['days_left'] = $daysLeft;
            $deliveryWarnings[] = $row;
        }
    }
}
usort($deliveryWarnings, fn($a, $b) => $a['days_left'] <=> $b['days_left']);

// --- 4. Полученные заказы без счёта ---
// BUG-N1 (внешний отчёт, 02.09.2026): раньше сюда попадали ВСЕ полученные заказы, даже те, по которым
// счёт поставщику уже реально создан — жёлтый бейдж на этой карточке был завышен. Теперь исключаем
// заказы, чей номер уже встречается как ref_supplier существующего счёта (та же проверка, что
// payments.php использует и при самом создании счёта, и в своём дашборде "кому должны").
$receivedNoInvoice = [];
$invoicedRefs = $api->getInvoicedSupplierOrderRefs();
foreach (['received_start', 'received_end'] as $st) {
    $rows = $api->getSupplierOrdersByStatus($st, 'id,ref,socid,total_ttc');
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!empty($invoicedRefs[$row['ref'] ?? ''])) continue;
            $receivedNoInvoice[] = $row;
        }
    }
}

// --- 5. Неоплаченные счета поставщикам — поимённо ---
$unpaidInvoices = [];
$rawInvoices = $api->getUnpaidSupplierInvoices();
if (is_array($rawInvoices)) {
    foreach ($rawInvoices as $inv) {
        $paid = 0;
        foreach ($api->getSupplierInvoicePayments((int)$inv['id']) as $p) { $paid += (float)($p['amount'] ?? 0); }
        $remaining = (float)($inv['total_ttc'] ?? 0) - $paid;
        if ($remaining > 0.01) {
            $inv['remaining'] = $remaining;
            $unpaidInvoices[] = $inv;
        }
    }
}
usort($unpaidInvoices, fn($a, $b) => $b['remaining'] <=> $a['remaining']);

// --- 6. Долги перевозчикам ---
$carrierDebts = logistics_get_all_carrier_debts();
$owedCarriers = [];
foreach ($carrierDebts as $cid => $d) {
    if ($d['debt'] > 0.01) $owedCarriers[$cid] = $d;
}
uasort($owedCarriers, fn($a, $b) => $b['debt'] <=> $a['debt']);

// --- Имена поставщиков/перевозчиков — batch-запросами (не в цикле, см. отчёт аудита P0#5) ---
// N-3: заказы теперь сгруппированы по статусу — собираем socid из всех групп сразу (batch-запрос имён
// по-прежнему один на всю страницу, группировка на это не влияет).
$draftLikeSocIds = [];
foreach ($draftLikeGroups as $grp) {
    foreach ($grp['orders'] as $o) { $draftLikeSocIds[] = (int)$o['socid']; }
}
$allSocIds = array_unique(array_merge(
    $draftLikeSocIds,
    array_map(fn($r) => (int)$r['socid'], $deliveryWarnings),
    array_map(fn($r) => (int)$r['socid'], $receivedNoInvoice),
    array_map(fn($r) => (int)$r['socid'], $unpaidInvoices),
    array_keys($owedCarriers)
));
$namesById = $allSocIds ? $api->getThirdpartiesByIds($allSocIds) : [];
$nameOf = fn($id) => is_array($namesById[$id] ?? null) ? ($namesById[$id]['name'] ?? $namesById[$id]['nom'] ?? "#$id") : "#$id";

// --- Контракты, близкие к лимиту (без изменений) ---
$contractWarnings = [];
$suppliers = $api->getAllSuppliers();
if (is_array($suppliers)) {
    foreach ($suppliers as $s) {
        $opts = $s['array_options'] ?? [];
        $amount = (float)($opts['options_contract_amount'] ?? 0);
        $startTs = !empty($opts['options_contract_start']) ? (int)$opts['options_contract_start'] : null;
        if ($amount <= 0 || !$startTs) continue;

        $orders = $api->getSupplierOrdersForSupplier((int)$s['id']);
        $spent = 0;
        $currencies = [];
        if (is_array($orders)) {
            foreach ($orders as $o) {
                $statut = (int)($o['statut'] ?? 0);
                $date = (int)($o['date_commande'] ?? 0);
                if ($statut >= 2 && $statut <= 5 && $date >= $startTs) {
                    $spent += (float)($o['total_ttc'] ?? 0);
                    $currencies[$o['multicurrency_code'] ?: 'USD'] = true;
                }
            }
        }
        $ratio = $amount > 0 ? $spent / $amount : 0;
        if ($ratio >= 0.8) {
            $contractWarnings[] = ['name' => $s['name'] ?? $s['nom'] ?? '', 'spent' => $spent, 'amount' => $amount, 'ratio' => $ratio, 'mixed_currency' => count($currencies) > 1];
        }
    }
}
usort($contractWarnings, fn($a, $b) => $b['ratio'] <=> $a['ratio']);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Сводка — требует действия</h1>

<div class="card">
  <h2>💰 Моя касса — неподтверждённые поступления</h2>
  <?php if (empty($myCashUnconfirmed)): ?>
    <p class="muted">Всё подтверждено.</p>
  <?php else: ?>
    <table>
      <tr><th>Дата</th><th>Сумма</th><th>Описание</th><th></th></tr>
      <?php foreach ($myCashUnconfirmed as $l): ?>
        <tr>
          <td class="muted"><?= $l['date'] ? date('d.m.Y', (int)$l['date']) : '' ?></td>
          <td>+<?= number_format($l['amount'], 2) ?> $</td>
          <td class="muted"><?= htmlspecialchars($l['label']) ?></td>
          <td><a href="mycash.php" class="btn secondary small">Подтвердить →</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>📝 Заказы, ещё не отправленные поставщику</h2>
  <?php if ($draftLikeTotal === 0): ?>
    <p class="muted">Таких нет.</p>
  <?php else: ?>
    <?php // N-3: три статуса — три разных действия, поэтому три отдельные группы, а не общий список. ?>
    <?php foreach ($draftLikeGroups as $grp): ?>
      <?php if (empty($grp['orders'])) continue; ?>
      <div style="margin-bottom:18px">
        <div class="row" style="align-items:baseline; margin-bottom:6px">
          <div><strong><?= htmlspecialchars($grp['title']) ?></strong>
            <span class="muted">— <?= htmlspecialchars($grp['todo']) ?></span></div>
          <div style="flex:0" class="muted"><?= count($grp['orders']) ?></div>
        </div>
        <table>
          <tr><th>Заказ</th><th>Поставщик</th><th>Сумма</th><th></th></tr>
          <?php foreach ($grp['orders'] as $o): ?>
            <tr>
              <?php // BUG-N2: единственное место "Сводки", где показываются ЧЕРНОВИКИ — здесь сырой
                    // "(PROV..)" и вылезал, даже после фикса в orders.php/order_view.php (раунд 2 это
                    // и зафиксировал). Общая функция — includes/auth.php. ?>
              <td><?= htmlspecialchars(nt_order_display_ref($o['ref'] ?? '', $o['statut'] ?? 0, (int)$o['id']) ?: "#{$o['id']}") ?></td>
              <td><?= htmlspecialchars($nameOf((int)$o['socid'])) ?></td>
              <td><?= number_format((float)($o['total_ttc'] ?? 0), 2) ?> $</td>
              <td><a href="order_view.php?id=<?= (int)$o['id'] ?>" class="btn secondary small">Открыть →</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>🚚 Заказы в пути — скоро или уже просрочена доставка</h2>
  <p class="muted">Показаны только с ожидаемой датой ≤3 дней или уже прошедшей.</p>
  <?php if (empty($deliveryWarnings)): ?>
    <p class="muted">Ничего срочного.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Поставщик</th><th>Ожидаемая дата</th><th></th></tr>
      <?php foreach ($deliveryWarnings as $o): ?>
        <tr>
          <td><?= htmlspecialchars($o['ref'] ?? "#{$o['id']}") ?></td>
          <td><?= htmlspecialchars($nameOf((int)$o['socid'])) ?></td>
          <td>
            <span class="badge <?= $o['days_left'] < 0 ? 'badge-debt' : 'badge-warn' ?>">
              <?= $o['days_left'] < 0 ? 'Просрочено на ' . abs($o['days_left']) . ' дн.' : ($o['days_left'] == 0 ? 'Сегодня' : 'Через ' . $o['days_left'] . ' дн.') ?>
            </span>
          </td>
          <td><a href="logistics.php" class="btn secondary small">Логистика →</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>📦 Получены на склад, но счёт не оформлен</h2>
  <?php if (empty($receivedNoInvoice)): ?>
    <p class="muted">Таких нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Поставщик</th><th>Сумма</th><th></th></tr>
      <?php foreach ($receivedNoInvoice as $o): ?>
        <tr>
          <td><?= htmlspecialchars($o['ref'] ?? "#{$o['id']}") ?></td>
          <td><?= htmlspecialchars($nameOf((int)$o['socid'])) ?></td>
          <td><?= number_format((float)($o['total_ttc'] ?? 0), 2) ?> $</td>
          <td><a href="payments.php?supplier_id=<?= (int)$o['socid'] ?>" class="btn secondary small">Оформить счёт →</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>💳 Неоплаченные счета поставщикам</h2>
  <?php if (empty($unpaidInvoices)): ?>
    <p class="muted">Долгов нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Счёт</th><th>Поставщик</th><th>Остаток</th><th></th></tr>
      <?php foreach ($unpaidInvoices as $inv): ?>
        <tr>
          <td><?= htmlspecialchars($inv['ref'] ?? '') ?></td>
          <td><?= htmlspecialchars($nameOf((int)$inv['socid'])) ?></td>
          <td class="err"><?= number_format($inv['remaining'], 2) ?> $</td>
          <td><a href="payments.php?supplier_id=<?= (int)$inv['socid'] ?>" class="btn secondary small">Оплатить →</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>🚛 Долги перевозчикам</h2>
  <?php if (empty($owedCarriers)): ?>
    <p class="muted">Долгов нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Перевозчик</th><th>Долг</th><th></th></tr>
      <?php foreach ($owedCarriers as $cid => $d): ?>
        <tr>
          <td><?= htmlspecialchars($nameOf($cid)) ?></td>
          <td class="err"><?= number_format($d['debt'], 2) ?> $</td>
          <td><a href="carriers.php?carrier_id=<?= (int)$cid ?>" class="btn secondary small">Оплатить →</a></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>📊 Контракты, близкие к лимиту (≥80%)</h2>
  <?php if (empty($contractWarnings)): ?>
    <p class="muted">Таких нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Поставщик</th><th>Закуплено</th><th>Контракт</th><th>%</th></tr>
      <?php foreach ($contractWarnings as $w): ?>
        <tr>
          <td><?= htmlspecialchars($w['name']) ?><?= $w['mixed_currency'] ? ' <span class="warn" style="padding:2px 6px">разные валюты</span>' : '' ?></td>
          <td><?= number_format($w['spent'], 2) ?> $</td>
          <td><?= number_format($w['amount'], 2) ?> $</td>
          <td><span class="badge <?= $w['ratio'] >= 1 ? 'badge-debt' : 'badge-warn' ?>"><?= round($w['ratio'] * 100) ?>%</span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <p><a href="suppliers.php">Перейти к поставщикам →</a></p>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
