<?php
/**
 * Брак на складе (11.09.2026) — что делать с бракованным товаром, когда поставщик ответил.
 *
 * Брак приходит сюда из приёмки (receive.php): годное легло на основной склад, брак — на склад брака
 * направления, и Нодир/Абдурашид получили рекламацию. Решение принимает закупщик, а физически
 * товар отдаёт поставщику или выбрасывает кладовщик — поэтому этот экран в кассе, а не в NodirTool
 * (у закупщиков, к тому же, нет права на складские движения — только чтение склада).
 *
 * Три исхода, и у каждого своё движение:
 *   вернули поставщику — товар ушёл, списываем со склада брака;
 *   списать            — выбросили/утилизировали, списываем со склада брака;
 *   в продажу          — брак оказался мелким (уценка) или ошиблись — переводим на склад продаж.
 * Себестоимость при этом не меняется: за брак заплачено по той же цене, что за годное.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/defect_intake.php';
require_once __DIR__ . '/includes/named_lock.php';

$defectWh = (int)($cfg['defect_warehouse_id'] ?? 0);
$db = defect_db();

const DEFECT_ACTIONS = [
    'return'   => 'Вернули поставщику',
    'writeoff' => 'Списать (утилизация)',
    'tosale'   => 'Перевести в продажу (уценка / годен)',
];

/** Текущий остаток товара на складе брака — всегда из базы, не из формы. */
function defect_stock(mysqli $db, int $pid, int $wh): float
{
    $st = $db->prepare("SELECT COALESCE(SUM(reel),0) q FROM llx_product_stock WHERE fk_product=? AND fk_entrepot=?");
    $st->bind_param('ii', $pid, $wh); $st->execute();
    $q = (float)$st->get_result()->fetch_assoc()['q']; $st->close();
    return $q;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispose' && $defectWh) {
    $pid    = (int)($_POST['product_id'] ?? 0);
    $qty    = round((float)str_replace(',', '.', (string)($_POST['qty'] ?? 0)), 3);
    $act    = (string)($_POST['disposal'] ?? '');
    $toWh   = (int)($_POST['to_warehouse'] ?? $cfg['default_warehouse_id']);
    $claimN = (int)($_POST['claim_id'] ?? 0);

    if (!$pid || $qty <= 0 || !isset(DEFECT_ACTIONS[$act])) {
        flash_set('Укажите количество и что сделали с браком.', 'err');
    } elseif ($act === 'tosale' && !in_array($toWh, $cfg['warehouse_ids'], false)) {
        flash_set('Склад не относится к вашему направлению.', 'err');
    } else {
        // Под блокировкой: проверка остатка и движение — одна операция, иначе двойное нажатие
        // спишет брак дважды и уведёт склад в минус.
        $res = with_named_lock('defect_' . $defectWh . '_' . $pid, function () use ($db, $api, $cfg, $pid, $qty, $act, $toWh, $defectWh, $claimN) {
            $have = defect_stock($db, $pid, $defectWh);
            if ($qty > $have + 0.0001) {
                return ['ok' => false, 'error' => "На складе брака только {$have} шт — больше списать нельзя."];
            }
            $ref = $claimN ? " (рекламация №{$claimN})" : '';
            if ($act === 'tosale') {
                $ok = $api->transferStock($pid, $defectWh, $toWh, $qty, 'Брак → в продажу' . $ref);
                return $ok ? ['ok' => true, 'msg' => "{$qty} шт переведено на склад «" . ($cfg['warehouse_labels'][$toWh] ?? $toWh) . "» — теперь продаётся."]
                           : ['ok' => false, 'error' => 'Не удалось переместить: ' . $api->lastError];
            }
            $label = ($act === 'return' ? 'Брак: возвращён поставщику' : 'Брак: списан (утилизация)') . $ref;
            $out = $api->createStockMovement(['product_id' => $pid, 'warehouse_id' => $defectWh,
                                              'qty' => -1 * $qty, 'type' => 1, 'label' => $label]);
            return $out !== null ? ['ok' => true, 'msg' => "{$qty} шт: " . mb_strtolower(DEFECT_ACTIONS[$act]) . '.']
                                 : ['ok' => false, 'error' => 'Не удалось списать: ' . $api->lastError];
        });
        flash_set($res['ok'] ? $res['msg'] : $res['error'], $res['ok'] ? 'ok' : 'err');
    }
    header('Location: defects.php');
    exit;
}

// ── список брака направления с последней рекламацией по товару
$rows = [];
if ($defectWh) {
    $r = $db->query("SELECT ps.fk_product, ps.reel, p.ref, p.label
                     FROM llx_product_stock ps JOIN llx_product p ON p.rowid = ps.fk_product
                     WHERE ps.fk_entrepot = $defectWh AND ps.reel > 0 ORDER BY p.label");
    while ($x = $r->fetch_assoc()) {
        $c = $db->query("SELECT c.rowid, c.status, c.resolution, c.datec, s.nom
                         FROM llx_nt_claim c LEFT JOIN llx_societe s ON s.rowid = c.fk_party
                         WHERE c.fk_product = " . (int)$x['fk_product'] . " AND c.target_type = 'supplier'
                         ORDER BY c.rowid DESC LIMIT 1")->fetch_assoc();
        $x['claim'] = $c;
        $rows[] = $x;
    }
}
$resolutions = ['replace' => 'довезут товаром', 'discount' => 'скинут со следующего счёта',
                'refund' => 'вернули деньги', 'rejected' => 'отказали'];
$flash = flash_get();

$__page = 'defects.php';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="card">
  <h1>Брак на складе <span class="muted">— <?= htmlspecialchars($cfg['defect_warehouse_label']) ?></span></h1>
  <p class="muted">Сюда попадает брак из приёмки. Он не продаётся. Когда <?= htmlspecialchars($cfg['purchaser_label']) ?>
     решит с поставщиком, что делать, — отметьте здесь, куда делся товар.</p>

  <?php if ($flash): ?>
    <p class="<?= $flash['type'] === 'err' ? 'err' : 'ok' ?>"><?= htmlspecialchars($flash['message']) ?></p>
  <?php endif; ?>

  <?php if (!$defectWh): ?>
    <p class="err">Склад брака не настроен — сообщите администратору.</p>
  <?php elseif (!$rows): ?>
    <p class="muted">Брака на складе нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Товар</th><th style="text-align:right">На складе брака</th><th>Рекламация</th><th>Что сделали</th></tr>
      <?php foreach ($rows as $x): $c = $x['claim']; ?>
        <tr>
          <td><?= htmlspecialchars($x['label']) ?><div class="muted"><?= htmlspecialchars($x['ref']) ?></div></td>
          <td style="text-align:right"><strong><?= rtrim(rtrim(number_format((float)$x['reel'], 3, '.', ''), '0'), '.') ?></strong> шт</td>
          <td>
            <?php if ($c): ?>
              №<?= (int)$c['rowid'] ?> · <?= htmlspecialchars((string)$c['nom']) ?><br>
              <?php if ($c['status'] === 'open'): ?>
                <span class="badge badge-neutral">ждём ответа поставщика</span>
              <?php else: ?>
                <span class="badge badge-ok"><?= htmlspecialchars($resolutions[$c['resolution']] ?? 'закрыта') ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">нет</span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="row" style="gap:6px; flex-wrap:wrap; align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="dispose">
              <input type="hidden" name="product_id" value="<?= (int)$x['fk_product'] ?>">
              <input type="hidden" name="claim_id" value="<?= (int)($c['rowid'] ?? 0) ?>">
              <input type="number" name="qty" step="any" min="0" max="<?= (float)$x['reel'] ?>" value="<?= (float)$x['reel'] ?>" style="width:80px; margin:0">
              <select name="disposal" style="margin:0" required>
                <option value="">— что сделали —</option>
                <?php foreach (DEFECT_ACTIONS as $k => $v): ?><option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
              </select>
              <select name="to_warehouse" style="margin:0" title="Куда перевести, если в продажу">
                <?php foreach ($cfg['warehouse_ids'] as $w): ?>
                  <option value="<?= $w ?>" <?= $w == $cfg['default_warehouse_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cfg['warehouse_labels'][$w] ?? $w) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="secondary">Отметить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted">Склад в выпадающем списке нужен только для «Перевести в продажу». Пока рекламация открыта,
       лучше ничего не трогать — поставщик может попросить товар назад.</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
