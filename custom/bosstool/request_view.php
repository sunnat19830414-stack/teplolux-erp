<?php
/**
 * Одна заявка: выбор поставщика, набор позиций, отправка закупщику. Пока заявка черновик — её можно
 * менять; после отправки она только для чтения (её уже видит закупщик, и менять список под ним, не
 * сказав, было бы хуже, чем создать новую).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/requests.php';
require_once __DIR__ . '/includes/order_suggest.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$req = $id ? request_get($id) : null;

if (!$req) {
    http_response_code(404);
    die('Заявка не найдена.');
}
// Суннатилла не должен открывать заявки чужого направления даже по прямой ссылке.
if (!can_see_direction($req['direction'])) {
    http_response_code(403);
    die('Эта заявка относится к другому направлению.');
}

$isDraft = $req['status'] === 'draft';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$isDraft && in_array($action, ['add_lines', 'update_line', 'delete_line', 'save_head', 'send'], true)) {
        $message = 'Заявка уже отправлена закупщику — менять её нельзя. Создайте новую.';
        $messageType = 'err';
    } elseif ($action === 'save_head') {
        request_update_head(
            $id,
            (int)($_POST['supplier_id'] ?? 0) ?: null,
            trim($_POST['supplier_name'] ?? '') ?: null,
            trim($_POST['label'] ?? ''),
            trim($_POST['note'] ?? '')
        );
        flash_set('Сохранено.', 'ok');
        header('Location: request_view.php?id=' . $id);
        exit;
    } elseif ($action === 'add_lines') {
        // Количества вписаны прямо в таблице рекомендаций — сохраняем все ненулевые строки разом.
        // Товары перепроверяем по базе (направление, название), а не доверяем форме.
        $qtys = (array)($_POST['qty'] ?? []);
        $prices = (array)($_POST['price'] ?? []);
        $supId = (int)($req['fk_supplier'] ?? 0);
        $allowed = supplier_order_suggestions($supId, visible_directions($cfg));
        $byId = [];
        foreach ($allowed['rows'] as $s) $byId[$s['id']] = $s;
        $suppCurSave = (string)$allowed['currency'];

        $existing = [];
        foreach ($req['lines'] as $l) $existing[(int)$l['fk_product']] = $l;

        // --- цены: сохраняем ВСЕ изменённые, независимо от количества ---
        // По просьбе пользователя цену можно править прямо в таблице, и новая цена пишется в
        // Dolibarr как заводская (закупочная) для этого поставщика. Сравниваем с тем, что сейчас в
        // справочнике: пишем только реально изменившиеся, чтобы не плодить лишние записи в журнале.
        $priceSaved = 0; $priceErrors = [];
        if ($supId > 0) {
            $rate = currency_rate($suppCurSave);
            foreach ($prices as $pid => $raw) {
                $pid = (int)$pid;
                if (!isset($byId[$pid])) continue;
                $raw = trim((string)$raw);
                if ($raw === '') continue;
                $newPrice = round((float)str_replace(',', '.', $raw), 4);
                if ($newPrice <= 0) continue;

                $old = $byId[$pid]['price'];
                if ($old !== null && abs($old - $newPrice) < 0.00005) continue;   // не менялась

                if ($api->savePurchasePrice($pid, $supId, $newPrice, $suppCurSave, $rate)) {
                    log_price_change($pid, $supId, $old, $newPrice, $_SESSION['user']['name'] ?? '');
                    $priceSaved++;
                } else {
                    $priceErrors[] = $byId[$pid]['ref'] . ': ' . $api->lastError;
                }
            }
        }

        $added = 0; $updated = 0;
        foreach ($qtys as $pid => $raw) {
            $pid = (int)$pid;
            $qty = (float)str_replace(',', '.', (string)$raw);
            if ($qty <= 0 || !isset($byId[$pid])) continue;
            if (isset($existing[$pid])) {
                request_update_line((int)$existing[$pid]['rowid'], $qty, (string)($existing[$pid]['comment'] ?? ''));
                $updated++;
            } else {
                request_add_line($id, $pid, $byId[$pid]['ref'], $byId[$pid]['label'], $qty, '');
                $added++;
            }
        }

        $priceNote = '';
        if ($priceSaved > 0) {
            $priceNote = " Цена обновлена у $priceSaved " . ($priceSaved === 1 ? 'позиции' : 'позиций') . '.';
        }
        if ($priceErrors) {
            $priceNote .= ' Не удалось сохранить цену: ' . implode('; ', $priceErrors) . '.';
        }

        if ($added === 0 && $updated === 0 && $priceSaved === 0) {
            $message = 'Ни в одной строке не проставлено количество и не изменена цена.'
                . ($priceErrors ? ' ' . implode('; ', $priceErrors) : '');
            $messageType = 'err';
        } elseif ($added === 0 && $updated === 0) {
            // Количества не трогали — сохранили только цены.
            flash_set(trim($priceNote), $priceErrors ? 'warn' : 'ok');
            header('Location: request_view.php?id=' . $id);
            exit;
        } else {
            flash_set("В заявку добавлено позиций: $added" . ($updated ? ", изменено: $updated" : '') . '.'
                . $priceNote, $priceErrors ? 'warn' : 'ok');
            header('Location: request_view.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'update_line') {
        $qty = (float)str_replace(',', '.', $_POST['qty'] ?? '0');
        if ($qty > 0) request_update_line((int)($_POST['line_id'] ?? 0), $qty, trim($_POST['comment'] ?? ''));
        header('Location: request_view.php?id=' . $id);
        exit;
    } elseif ($action === 'delete_line') {
        request_delete_line((int)($_POST['line_id'] ?? 0));
        header('Location: request_view.php?id=' . $id);
        exit;
    } elseif ($action === 'send') {
        if (empty($req['lines'])) {
            $message = 'В заявке нет ни одной позиции.';
            $messageType = 'err';
        } else {
            request_set_status($id, 'sent', ['sent_at' => true]);
            flash_set('Заявка отправлена закупщику — она появилась у Нодира и Абдурашида.', 'ok');
            header('Location: request_view.php?id=' . $id);
            exit;
        }
    } elseif ($action === 'cancel') {
        request_set_status($id, 'cancelled', ['closed_at' => true]);
        flash_set('Заявка отменена.', 'ok');
        header('Location: requests.php');
        exit;
    }
}

// Флеш подхватываем ТОЛЬКО если в этом запросе ничего не сказали сами: иначе сообщение об отказе
// («заявку уже отправили, менять нельзя») затиралось бы флешем от предыдущего действия, и человек
// видел бы «Заявка отправлена» вместо объяснения, почему его правка не применилась.
$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }
$req = request_get($id);   // перечитываем — состав мог измениться выше
$isDraft = $req['status'] === 'draft';

// Таблица рекомендаций широкая (10 колонок) — разворачиваем страницу на всю ширину экрана.
$wideLayout = true;
require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заявка №<?= (int)$req['rowid'] ?>
  <span class="badge <?= request_status_badge($req['status']) ?>" style="font-size:13px; vertical-align:middle">
    <?= htmlspecialchars(request_status_label($req['status'])) ?></span>
</h1>
<p><a class="btn secondary small" href="requests.php">← Все заявки</a></p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if ($req['status'] === 'declined' && $req['decline_reason']): ?>
  <p class="warn">Закупщик отклонил заявку: <?= htmlspecialchars($req['decline_reason']) ?></p>
<?php endif; ?>
<?php if ($req['status'] === 'ordered' && $req['fk_order']): ?>
  <p class="ok">По этой заявке уже оформлен заказ поставщику (№<?= (int)$req['fk_order'] ?>).</p>
<?php endif; ?>

<?php $lineCount = count($req['lines']); ?>

<div class="card req-head">
  <?php if ($isDraft): ?>
    <form method="post" id="headForm" class="req-head-grid">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_head">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="supplier_id" id="supId" value="<?= (int)($req['fk_supplier'] ?? 0) ?>">
      <input type="hidden" name="supplier_name" id="supName" value="<?= htmlspecialchars($req['supplier_name'] ?? '') ?>">

      <div>
        <label>Поставщик <span class="muted">— необязательно</span></label>
        <div id="supChosen" class="sup-chosen" style="<?= $req['supplier_name'] ? '' : 'display:none' ?>">
          <strong id="supChosenName"><?= htmlspecialchars($req['supplier_name'] ?? '') ?></strong>
          <button type="button" class="secondary small" onclick="clearSupplier()">Сменить</button>
        </div>
        <div id="supPick" style="<?= $req['supplier_name'] ? 'display:none' : '' ?>">
          <input type="text" id="supplierSearch" placeholder="начните печатать название..." style="margin:0">
          <div id="supplierResults" class="result-list" style="margin-top:6px"></div>
        </div>
      </div>

      <div>
        <label>Пометка</label>
        <input type="text" name="label" value="<?= htmlspecialchars($req['label'] ?? '') ?>" placeholder="например: на октябрь" style="margin:0">
      </div>

      <div>
        <label>Примечание для закупщика</label>
        <input type="text" name="note" value="<?= htmlspecialchars($req['note'] ?? '') ?>"
               placeholder="сроки, замены, условия" style="margin:0">
      </div>

      <div class="req-head-save"><button type="submit" class="secondary">Сохранить</button></div>
    </form>
  <?php else: ?>
    <div class="req-head-grid">
      <div><label>Поставщик</label><strong><?= htmlspecialchars($req['supplier_name'] ?: 'на усмотрение закупщика') ?></strong></div>
      <div><label>Пометка</label><?= htmlspecialchars($req['label'] ?: '—') ?></div>
      <div><label>Примечание</label><?= htmlspecialchars($req['note'] ?: '—') ?></div>
    </div>
  <?php endif; ?>

  <div class="req-head-foot">
    <span class="muted">
      Направление: <?= htmlspecialchars($cfg['directions'][$req['direction']] ?? $req['direction']) ?>
      · создана <?= date('d.m.Y', strtotime($req['created_at'])) ?> (<?= htmlspecialchars($req['created_by']) ?>)
      · в заявке позиций: <strong><?= $lineCount ?></strong>
    </span>
    <?php if ($isDraft): ?>
      <span class="req-head-actions">
        <form method="post" onsubmit="return appConfirmSubmit(this, 'Отправить заявку закупщику? После отправки менять её будет нельзя.');">
        <?= csrf_field() ?>
          <input type="hidden" name="action" value="send">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" <?= $lineCount === 0 ? 'disabled' : '' ?>>Отправить закупщику</button>
        </form>
        <form method="post" onsubmit="return appConfirmSubmit(this, 'Отменить эту заявку?');">
        <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="secondary">Отменить заявку</button>
        </form>
      </span>
    <?php endif; ?>
  </div>
</div>

<?php if ($isDraft): ?>
  <?php if (empty($req['fk_supplier'])): ?>
    <div class="card">
      <h2>Что заказать</h2>
      <p class="muted">Выберите поставщика выше — и здесь появится вся его номенклатура: что на складе,
      что уже едет, сколько продали за год и сколько стоит заказать.</p>
    </div>
  <?php else: ?>
    <?php
      $monthsTarget = (float)($_GET['months'] ?? 3);
      if ($monthsTarget < 0.5) $monthsTarget = 0.5;
      if ($monthsTarget > 24) $monthsTarget = 24;
      $suggData = supplier_order_suggestions((int)$req['fk_supplier'], visible_directions($cfg), $monthsTarget);
      $sugg = $suggData['rows'];
      $window = (float)$suggData['window_months'];
      $suppCur = (string)$suggData['currency'];          // валюта договора поставщика
      $curLabel = $suppCur === 'USD' ? '$' : $suppCur;
      // Честная подпись колонки: пока истории мало, это НЕ «за год».
      $soldColumn = $window >= 11.5
          ? 'Прод./год'
          : 'Продано за ' . rtrim(rtrim(number_format($window, 1, '.', ''), '0'), '.') . ' мес.';
      $alreadyQty = [];
      foreach ($req['lines'] as $l) $alreadyQty[(int)$l['fk_product']] = (float)$l['qty'];
    ?>
    <div class="card">
      <div class="sec-head">
        <h2>Рекомендации к заказу — <?= htmlspecialchars($req['supplier_name']) ?>
          <span class="muted" style="font-weight:400">· <?= count($sugg) ?> позиций</span></h2>
        <div class="legend">
          <span><i style="background:#dc2626"></i>меньше 1 мес.</span>
          <span><i style="background:#d97706"></i>1–2 мес.</span>
          <span><i style="background:#16a34a"></i>больше 2 мес.</span>
        </div>
      </div>

      <?php if (empty($sugg)): ?>
        <p class="muted">У этого поставщика нет товаров в каталоге<?php
          if (count(visible_directions($cfg)) === 1) echo ' по вашему направлению'; ?>.</p>
      <?php else: ?>
        <?php
          // Пока в базе нет истории продаж, колонки «Прод./год», «Запас, мес.» и «Рек.» будут пустыми.
          // Объясняем это прямо, иначе выглядит как поломка расчёта.
          $withSales = 0;
          foreach ($sugg as $s) if ($s['sold_total'] > 0) $withSales++;
        ?>
        <?php if ($window < SUGGEST_MIN_HISTORY_MONTHS): ?>
          <p class="note">Продажи копятся всего <?= rtrim(rtrim(number_format($window, 1, '.', ''), '0'), '.') ?> мес. —
          для среднего расхода этого мало, поэтому «Запас» и «Рек.» пустые. Склад и «в пути» верны:
          пока ориентируйтесь на них. Расчёт включится сам через месяц работы.</p>
        <?php elseif ($withSales === 0): ?>
          <p class="note">По этим товарам продаж ещё не было — «Запас» и «Рек.» считать не из чего.
          Склад и «в пути» показаны верно.</p>
        <?php elseif ($withSales < count($sugg) / 3): ?>
          <p class="note">Рекомендация посчитана по <?= $withSales ?> из <?= count($sugg) ?> позиций —
          у остальных продаж пока нет.</p>
        <?php endif; ?>
        <?php
          $needCount = 0; $stockZero = 0;
          foreach ($sugg as $s) {
            if ($s['suggest'] > 0) $needCount++;
            if ($s['stock'] <= 0) $stockZero++;
          }
        ?>
        <div class="tiles">
          <div class="tile"><div class="k">Позиций у поставщика</div><div class="v"><?= count($sugg) ?></div></div>
          <div class="tile"><div class="k">Нечего отгружать</div><div class="v <?= $stockZero ? 'warn-v' : '' ?>"><?= $stockZero ?></div>
            <div class="k">склад пуст</div></div>
          <div class="tile"><div class="k">Рекомендовано к заказу</div><div class="v"><?= $needCount ?></div>
            <div class="k">позиций</div></div>
          <div class="tile"><div class="k">В заявке сейчас</div><div class="v"><?= $lineCount ?></div>
            <div class="k" id="tileSum">—</div></div>
        </div>

        <div class="sugg-controls">
          <input type="text" id="suggFilter" placeholder="Артикул или наименование — начните печатать">
          <form method="get">
            <input type="hidden" name="id" value="<?= $id ?>">
            <label for="monthsInp">Запас на</label>
            <input type="number" id="monthsInp" name="months" value="<?= rtrim(rtrim(number_format($monthsTarget, 1, '.', ''), '0'), '.') ?>"
                   step="0.5" min="0.5" max="24">
            <span class="muted">мес.</span>
            <button type="submit" class="secondary small">Пересчитать</button>
          </form>
          <span class="muted" id="suggCount"></span>
        </div>

        <form method="post">
        <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_lines">
          <input type="hidden" name="id" value="<?= $id ?>">
          <div class="table-wrap">
          <table id="suggTable" class="dense">
            <colgroup>
              <col style="width:140px"><col>
              <col style="width:64px"><col style="width:88px"><col style="width:88px">
              <col style="width:74px"><col style="width:64px"><col style="width:92px">
              <col style="width:92px"><col style="width:92px">
            </colgroup>
            <thead>
            <tr>
              <th>Артикул</th>
              <th>Наименование</th>
              <th class="num">Склад</th>
              <th class="num">В пути</th>
              <th class="num"><?= htmlspecialchars($soldColumn) ?></th>
              <th class="num">Запас, мес.</th>
              <th class="num">Рек.</th>
              <th class="num">Цена, <?= htmlspecialchars($curLabel) ?></th>
              <th class="num">Заказать</th>
              <th class="num">Сумма</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sugg as $s): ?>
              <?php
                $bg = ['red' => 'red', 'yellow' => 'amber', 'green' => '', 'none' => ''][$s['level']];
                $dot = ['red' => '#dc2626', 'yellow' => '#d97706', 'green' => '#16a34a', 'none' => '#9ca3af'][$s['level']];
                $prefill = $alreadyQty[$s['id']] ?? ($s['suggest'] > 0 ? $s['suggest'] : 0);
              ?>
              <tr data-search="<?= htmlspecialchars(mb_strtolower($s['ref'] . ' ' . $s['label'])) ?>"<?= $bg ? ' class="' . $bg . '"' : '' ?>>
                <td class="cell-ref"><?= htmlspecialchars($s['ref']) ?></td>
                <td class="cell-name"><?= htmlspecialchars($s['label']) ?></td>
                <td class="num"><?= rtrim(rtrim(number_format($s['stock'], 2, '.', ' '), '0'), '.') ?: '0' ?></td>
                <td class="num">
                  <?php if ($s['incoming'] > 0): ?>
                    <?= rtrim(rtrim(number_format($s['incoming'], 2, '.', ' '), '0'), '.') ?>
                    <div class="muted tiny" title="<?= htmlspecialchars(implode(', ', $s['incoming_refs'])) ?>"><?= htmlspecialchars(implode(', ', $s['incoming_refs'])) ?></div>
                  <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td class="num"><?= $s['sold_total'] > 0 ? rtrim(rtrim(number_format($s['sold_total'], 2, '.', ' '), '0'), '.') : '<span class="muted">—</span>' ?></td>
                <td class="num">
                  <?php if ($s['months_left'] === null): ?>
                    <span class="muted">—</span>
                  <?php else: ?>
                    <?= number_format($s['months_left'], 1) ?>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <i class="dot" style="background:<?= $dot ?>"></i><?= $s['suggest'] > 0 ? number_format($s['suggest'], 0, '.', ' ') : '' ?>
                </td>
                <td class="num">
                  <?php // Цену можно править прямо здесь — новая записывается в Dolibarr как
                        // заводская для этого поставщика (по просьбе пользователя 04.09.2026). ?>
                  <input type="number" class="price-inp" name="price[<?= (int)$s['id'] ?>]"
                         value="<?= $s['price'] !== null && $s['price'] > 0 ? rtrim(rtrim(number_format($s['price'], 4, '.', ''), '0'), '.') : '' ?>"
                         data-original="<?= $s['price'] !== null && $s['price'] > 0 ? rtrim(rtrim(number_format($s['price'], 4, '.', ''), '0'), '.') : '' ?>"
                         step="any" min="0" placeholder="нет">
                </td>
                <td class="num">
                  <input type="number" class="qty-inp" name="qty[<?= (int)$s['id'] ?>]"
                         value="<?= $prefill > 0 ? rtrim(rtrim(number_format($prefill, 2, '.', ''), '0'), '.') : '0' ?>"
                         step="any" min="0"
                         data-price="<?= $s['price'] !== null ? htmlspecialchars((string)$s['price']) : '' ?>">
                </td>
                <td class="num row-sum">
                  <?php $rowSum = ($s['price'] !== null && $prefill > 0) ? $prefill * $s['price'] : 0; ?>
                  <?= $rowSum > 0 ? number_format($rowSum, 2, '.', ' ') : '' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <div class="sugg-foot">
            <button type="submit">Сохранить: количества и цены</button>
            <div id="suggTotal" style="font-weight:700; white-space:nowrap"></div>
            <div class="muted">
              <strong>Цену можно править прямо в таблице</strong> — новая запишется в Dolibarr как
              заводская для «<?= htmlspecialchars($req['supplier_name']) ?>» и сохранится по кнопке
              «Сохранить» вместе с количествами.<br>
              «Рек.» — запас на <?= rtrim(rtrim(number_format($monthsTarget, 1, '.', ''), '0'), '.') ?> мес. минус склад минус то, что уже в пути.
              Средний расход считается по продажам за
              <?= rtrim(rtrim(number_format($window, 1, '.', ''), '0'), '.') ?> мес. — столько истории
              пока накоплено в системе<?= $window >= 11.5 ? '' : ', дальше цифра будет точнее' ?>.
              «Заказать» — ваше количество, его и увидит закупщик. Строки с нулём не добавляются.
            </div>
          </div>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card">
  <div class="sec-head">
    <h2>Что купить <span class="muted" style="font-weight:400">· <?= $lineCount ?> позиций</span></h2>
  </div>
  <?php if ($isDraft): ?>
    <p style="margin:-4px 0 12px">
      <a class="btn secondary small" href="product_form.php?request_id=<?= $id ?>">+ Новый товар</a>
      <span class="muted">— если поставщик прислал новинку, которой ещё нет в каталоге</span>
    </p>
  <?php endif; ?>
  <?php if ($lineCount === 0): ?>
    <p class="muted">Пока пусто — проставьте количества в таблице выше и нажмите «Сохранить».</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="dense">
      <colgroup><col style="width:150px"><col><col style="width:120px"><?php if ($isDraft): ?><col style="width:60px"><?php endif; ?></colgroup>
      <thead><tr><th>Артикул</th><th>Наименование</th><th class="num">Кол-во</th><?php if ($isDraft): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($req['lines'] as $l): ?>
        <tr>
          <td class="cell-ref"><?= htmlspecialchars($l['ref']) ?></td>
          <td class="cell-name"><?= htmlspecialchars($l['label']) ?><?= $l['comment'] ? ' <span class="muted">· ' . htmlspecialchars($l['comment']) . '</span>' : '' ?></td>
          <td class="num">
            <?php if ($isDraft): ?>
              <form method="post" style="display:flex; justify-content:flex-end">
              <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_line">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="line_id" value="<?= (int)$l['rowid'] ?>">
                <input type="hidden" name="comment" value="<?= htmlspecialchars($l['comment'] ?? '') ?>">
                <input type="number" class="qty-inp" name="qty" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$l['qty'], 3, '.', ''), '0'), '.')) ?>"
                       step="any" min="0.001" onchange="this.form.submit()">
              </form>
            <?php else: ?>
              <?= htmlspecialchars(rtrim(rtrim(number_format((float)$l['qty'], 3, '.', ''), '0'), '.')) ?>
            <?php endif; ?>
          </td>
          <?php if ($isDraft): ?>
          <td class="num">
            <form method="post" onsubmit="return appConfirmSubmit(this, 'Убрать эту позицию из заявки?');">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_line">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="line_id" value="<?= (int)$l['rowid'] ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script src="assets/picker.js"></script>
<script>
// Живой фильтр по таблице рекомендаций — у крупных поставщиков позиций несколько сотен.
(function () {
  const f = document.getElementById('suggFilter');
  const t = document.getElementById('suggTable');
  const c = document.getElementById('suggCount');
  if (!f || !t) return;
  const rows = t.querySelectorAll('tr[data-search]');
  f.addEventListener('input', function () {
    const q = f.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(function (row) {
      const hit = !q || row.dataset.search.indexOf(q) !== -1;
      row.style.display = hit ? '' : 'none';
      if (hit) shown++;
    });
    if (c) c.textContent = q ? ('показано ' + shown + ' из ' + rows.length) : '';
  });
})();

// Итог по заявке считается на месте, пока шеф проставляет количества: главный его вопрос —
// «на сколько я заказываю». Позиции без цены в справочнике в сумму не входят — о них говорим отдельно,
// чтобы итог не выглядел полным, когда часть цен неизвестна.
(function () {
  const t = document.getElementById('suggTable');
  const out = document.getElementById('suggTotal');
  if (!t || !out) return;
  const cur = <?= json_encode($curLabel ?? '$') ?>;

  function recalc() {
    let total = 0, noPrice = 0, changed = 0;
    t.querySelectorAll('tr[data-search]').forEach(function (row) {
      const qtyInp = row.querySelector('.qty-inp');
      const priceInp = row.querySelector('.price-inp');
      if (!qtyInp) return;
      const qty = parseFloat(qtyInp.value) || 0;
      // Считаем по ТОМУ, что сейчас в поле цены: шеф правит цену прямо здесь, и сумма должна
      // отзываться сразу, а не показывать старое значение из справочника.
      const price = priceInp ? (parseFloat(priceInp.value) || 0) : 0;
      const cell = row.querySelector('.row-sum');

      if (priceInp) {
        const orig = priceInp.dataset.original || '';
        const isChanged = priceInp.value.trim() !== orig.trim();
        priceInp.classList.toggle('changed', isChanged);
        if (isChanged && priceInp.value.trim() !== '') changed++;
      }

      if (qty > 0 && price > 0) {
        const sum = qty * price;
        total += sum;
        if (cell) cell.textContent = sum.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
      } else {
        if (cell) cell.textContent = '';
        if (qty > 0) noPrice++;
      }
    });
    const money = total.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + cur;
    let text = 'Итого: ' + money;
    if (noPrice > 0) text += ' + ' + noPrice + ' поз. без цены';
    if (changed > 0) text += ' · изменено цен: ' + changed;
    out.textContent = text;

    // Та же сумма — в плашке над таблицей, чтобы итог был виден и без прокрутки вниз.
    const tile = document.getElementById('tileSum');
    if (tile) tile.textContent = total > 0 ? ('на ' + money) : '—';
  }

  t.addEventListener('input', function (e) {
    if (e.target && (e.target.classList.contains('qty-inp') || e.target.classList.contains('price-inp'))) recalc();
  });
  recalc();
})();
function clearSupplier() {
  document.getElementById('supId').value = '';
  document.getElementById('supName').value = '';
  document.getElementById('headForm').submit();
}
window.wireSupplierSearch && window.wireSupplierSearch('supplierSearch', 'supplierResults', function (s) {
  document.getElementById('supId').value = s.id;
  document.getElementById('supName').value = s.name;
  document.getElementById('headForm').submit();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
