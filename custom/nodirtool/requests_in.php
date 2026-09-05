<?php
/**
 * Заявки от руководства (04.09.2026). Список закупки составляет шеф (Умид), по турецкой линии —
 * Суннатилла; Нодир и Абдурашид берут заявку и делают по ней всю остальную работу. Раньше такой
 * список приходил в чат или на почту — иногда шеф отправлял его прямо поставщику, минуя закупщиков.
 * Отсюда заявка одной кнопкой превращается в корзину заказа поставщику.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/requests.php';

requests_ensure_tables();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reqId = (int)($_POST['request_id'] ?? 0);
    $req = $reqId ? request_get($reqId) : null;

    if (!$req) {
        $message = 'Заявка не найдена.';
        $messageType = 'err';
    } elseif ($action === 'take') {
        if (empty($req['lines'])) {
            $message = 'В заявке нет позиций.';
            $messageType = 'err';
        } else {
            // Кладём позиции в корзину заказа и уводим в раздел «Заказы поставщику» — дальше обычный
            // цикл оформления. Сама заявка цен не несёт (шеф указывает только что и сколько), но
            // известную закупочную цену этого поставщика подставляем сразу — её всё равно
            // пришлось бы вбивать вручную по каждой строке.
            require_once __DIR__ . '/includes/product_lookup.php';
            $supId = (int)($req['fk_supplier'] ?? 0);

            $curr = 'USD';
            $rate = 1.0;
            if ($supId) {
                $soc = $api->getThirdparty($supId);
                if (is_array($soc)) {
                    require_once __DIR__ . '/includes/currency.php';
                    $curr = supplier_currency($soc);
                    $rate = dolibarr_currency_rate($curr) ?? 1.0;
                }
            }

            $prices = $supId
                ? get_purchase_prices_bulk(array_map(fn($l) => (int)$l['fk_product'], $req['lines']), $supId)
                : [];

            $_SESSION['po_cart'] = [];
            $withPrice = 0;
            foreach ($req['lines'] as $l) {
                $pid = (int)$l['fk_product'];
                $price = purchase_price_for_order($prices[$pid] ?? null, $curr, $rate);
                if ($price > 0) $withPrice++;
                $_SESSION['po_cart'][] = [
                    'product_id' => $pid,
                    'ref' => (string)$l['ref'],
                    'label' => (string)$l['label'],
                    'price' => $price,
                    'qty' => (float)$l['qty'],
                ];
            }

            if ($supId) {
                $_SESSION['po_supplier'] = [
                    'id' => $supId,
                    'name' => (string)$req['supplier_name'],
                    'currency' => $curr,
                    'rate' => $rate,
                ];
                $_SESSION['_preserve_once']['po_supplier'] = true;
            }
            // Помним, из какой заявки собрана корзина — чтобы отметить её выполненной после
            // создания заказа (см. orders.php::create_order).
            $_SESSION['po_from_request'] = $reqId;
            request_set_status($reqId, 'taken', ['taken_at' => true, 'taken_by' => $_SESSION['user']['name'] ?? '']);

            $total = count($req['lines']);
            $priceNote = $withPrice === 0
                ? 'Цен от этого поставщика в базе пока нет — проставьте вручную.'
                : ($withPrice === $total
                    ? 'Цены подставлены из справочника закупочных цен — проверьте по прайсу.'
                    : "Цены подставлены у $withPrice из $total позиций, остальные впишите вручную.");

            flash_set('Заявка №' . $reqId . ' взята в работу — позиции уже в корзине заказа. '
                . (empty($req['fk_supplier']) ? 'Поставщика выберите сами: руководство его не указало. ' : '')
                . $priceNote, 'ok');
            header('Location: orders.php');
            exit;
        }
    } elseif ($action === 'decline') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $message = 'Напишите причину — её увидит руководство.';
            $messageType = 'err';
        } else {
            request_set_status($reqId, 'declined', ['closed_at' => true, 'decline_reason' => $reason,
                'taken_by' => $_SESSION['user']['name'] ?? '']);
            flash_set('Заявка отклонена, причина отправлена руководству.', 'ok');
            header('Location: requests_in.php');
            exit;
        }
    } elseif ($action === 'release') {
        request_set_status($reqId, 'sent', ['taken_by' => null]);
        flash_set('Заявка возвращена в общий список.', 'ok');
        header('Location: requests_in.php');
        exit;
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$open = request_list([], ['sent', 'taken']);
$recent = request_list([], ['ordered', 'declined'], 25);
$showHistory = !empty($_GET['history']);

$directionNames = ['J' => 'Жоми', 'T' => 'Турк'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заявки от руководства</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Ждут работы</h2>
  <?php if (empty($open)): ?>
    <p class="muted">Новых заявок нет. Здесь появится список закупки, как только его пришлёт Умид или Суннатилла.</p>
  <?php else: ?>
    <?php foreach ($open as $r): ?>
      <?php $lines = request_lines((int)$r['rowid']); ?>
      <div style="border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin-bottom:12px">
        <div class="row" style="align-items:flex-start">
          <div>
            <strong style="font-size:15px">Заявка №<?= (int)$r['rowid'] ?><?= $r['label'] ? ' — ' . htmlspecialchars($r['label']) : '' ?></strong>
            <span class="badge <?= request_status_badge($r['status']) ?>"><?= htmlspecialchars(request_status_label($r['status'])) ?></span>
            <div class="muted">
              <?= htmlspecialchars($directionNames[$r['direction']] ?? $r['direction']) ?>
              · от <?= htmlspecialchars($r['created_by']) ?>, <?= date('d.m.Y', strtotime($r['created_at'])) ?>
              · поставщик: <?= htmlspecialchars($r['supplier_name'] ?: 'на ваше усмотрение') ?>
              <?php if ($r['taken_by']): ?> · в работе у: <?= htmlspecialchars($r['taken_by']) ?><?php endif; ?>
            </div>
            <?php if ($r['note']): ?>
              <div style="margin-top:6px"><em><?= nl2br(htmlspecialchars($r['note'])) ?></em></div>
            <?php endif; ?>
          </div>
        </div>

        <table style="margin-top:10px">
          <tr><th>Товар</th><th>Артикул</th><th style="text-align:right">Нужно</th></tr>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td><?= htmlspecialchars($l['label']) ?><?= $l['comment'] ? '<div class="muted">' . htmlspecialchars($l['comment']) . '</div>' : '' ?></td>
              <td class="muted"><?= htmlspecialchars($l['ref']) ?></td>
              <td style="text-align:right"><?= htmlspecialchars(rtrim(rtrim(number_format((float)$l['qty'], 3, '.', ''), '0'), '.')) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>

        <div class="row" style="margin-top:10px">
          <form method="post" style="flex:0">
          <?= csrf_field() ?>
            <input type="hidden" name="action" value="take">
            <input type="hidden" name="request_id" value="<?= (int)$r['rowid'] ?>">
            <button type="submit">Взять в работу — собрать заказ</button>
          </form>
          <?php if ($r['status'] === 'taken'): ?>
            <form method="post" style="flex:0">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="release">
              <input type="hidden" name="request_id" value="<?= (int)$r['rowid'] ?>">
              <button type="submit" class="secondary">Вернуть в общий список</button>
            </form>
          <?php endif; ?>
          <form method="post" style="flex:1; display:flex; gap:6px; align-items:center">
          <?= csrf_field() ?>
            <input type="hidden" name="action" value="decline">
            <input type="hidden" name="request_id" value="<?= (int)$r['rowid'] ?>">
            <input type="text" name="reason" placeholder="причина отклонения" style="margin:0">
            <button type="submit" class="secondary">Отклонить</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Закрытые заявки</h2>
  <?php if (!$showHistory): ?>
    <p><a class="btn secondary small" href="requests_in.php?history=1">Показать</a></p>
  <?php elseif (empty($recent)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <table>
      <tr><th>Заявка</th><th>Направление</th><th>Поставщик</th><th>Статус</th><th>Заказ</th></tr>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td>№<?= (int)$r['rowid'] ?><?= $r['label'] ? '<div class="muted">' . htmlspecialchars($r['label']) . '</div>' : '' ?></td>
          <td><?= htmlspecialchars($directionNames[$r['direction']] ?? $r['direction']) ?></td>
          <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
          <td><span class="badge <?= request_status_badge($r['status']) ?>"><?= htmlspecialchars(request_status_label($r['status'])) ?></span>
            <?= $r['decline_reason'] ? '<div class="muted">' . htmlspecialchars($r['decline_reason']) . '</div>' : '' ?></td>
          <td><?= $r['fk_order'] ? '<a href="order_view.php?id=' . (int)$r['fk_order'] . '">открыть</a>' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
