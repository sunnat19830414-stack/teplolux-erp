<?php
/**
 * Рекламации (B8, 05.09.2026) — брак, недостача, бой. Логика и денежные последствия исходов —
 * в includes/claims.php, здесь только экран.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/claims.php';
require_once __DIR__ . '/includes/currency.php';

if (!array_key_exists('selected_claim', $_SESSION)) $_SESSION['selected_claim'] = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['id'])) {
    $_SESSION['selected_claim'] = (int)$_GET['id'];
    $_SESSION['_preserve_once']['selected_claim'] = true;
}
reset_selection_unless_preserved('selected_claim');

$message = '';
$messageType = '';
$who = $_SESSION['user']['name'] ?? '';

// Счета, куда может прийти возврат денег
$moneyAccounts = [];
$myCashAcc = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCashAcc) $moneyAccounts[(int)$myCashAcc['id']] = 'Моя касса (' . $myCashAcc['label'] . ')';
$moneyAccounts[(int)$cfg['uzs_account_id']] = 'Сумовый счёт (UZS-MAIN)';
foreach ($cfg['currency_accounts'] as $curCode => $accId) $moneyAccounts[(int)$accId] = $curCode . '-MAIN';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_claim') {
        $_SESSION['selected_claim'] = (int)($_POST['claim_id'] ?? 0);
    } elseif ($action === 'clear_claim') {
        $_SESSION['selected_claim'] = null;
    } elseif ($action === 'create') {
        $r = claim_create($_POST, $who);
        if (empty($r['ok'])) {
            $message = $r['error'];
            $messageType = 'err';
        } else {
            $_SESSION['selected_claim'] = (int)$r['id'];
            $_SESSION['_preserve_once']['selected_claim'] = true;
            flash_set('Рекламация открыта. Приложите фото и переписку, чтобы всё было в одном месте.', 'ok');
            header('Location: claims.php');
            exit;
        }
    } elseif ($action === 'resolve') {
        $r = claim_resolve((int)($_POST['claim_id'] ?? 0), (string)($_POST['resolution'] ?? ''), $_POST, $who, $api);
        if (empty($r['ok'])) {
            $message = $r['error'];
            $messageType = 'err';
        } else {
            $_SESSION['_preserve_once']['selected_claim'] = true;
            flash_set('Рекламация закрыта. ' . $r['money_note'], 'ok');
            header('Location: claims.php');
            exit;
        }
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$selected = $_SESSION['selected_claim'] ? claim_get((int)$_SESSION['selected_claim']) : null;
$claims = $selected ? [] : claims_list();

// Имена контрагентов — одним запросом
$partyNames = [];
$idsForNames = $selected ? [(int)$selected['fk_party']] : array_map(fn($c) => (int)$c['fk_party'], $claims);
if ($idsForNames) $partyNames = $api->getThirdpartiesByIds($idsForNames);
$nameOf = fn($id) => is_array($partyNames[$id] ?? null)
    ? ($partyNames[$id]['name'] ?? $partyNames[$id]['nom'] ?? "#$id") : "#$id";

// Документы по рекламации кладём в карточку контрагента — отдельного хранилища у претензии нет,
// а к контрагенту загрузка уже работает (modulepart=societe).
$claimDocs = $selected ? ($api->getPartyDocuments((int)$selected['fk_party']) ?: []) : [];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Рекламации</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<?php if ($selected): ?>
  <form method="post" style="margin-bottom:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_claim">
    <button type="submit" class="secondary small">← Все рекламации</button>
  </form>
<?php endif; ?>

<?php if (!$selected): ?>

  <div class="card">
    <h2>Новая рекламация</h2>
    <p class="muted">Пишется, когда пришёл брак, недостача или бой. Пока ответа нет, претензия висит
    открытой и видна на Сводке — чтобы не потерялась в переписке.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="fk_party" id="partyId" value="">

      <label>Кому претензия</label>
      <select name="target_type" id="targetType">
        <?php foreach (CLAIM_TARGETS as $k => $lbl): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
      </select>

      <label id="partyLabel">Поставщик</label>
      <input type="text" id="partySearch" placeholder="начните вводить название" autocomplete="off">
      <div id="partyResults" class="search-results"></div>
      <div id="partyChosen" class="muted" style="margin-bottom:8px"></div>

      <label>Что именно <span class="muted">— необязательно</span></label>
      <input type="text" id="productSearch" placeholder="найти товар" autocomplete="off">
      <div id="productResults" class="search-results"></div>
      <input type="hidden" name="fk_product" id="productId" value="">
      <input type="hidden" name="product_label" id="productLabel" value="">
      <div id="productChosen" class="muted" style="margin-bottom:8px"></div>

      <div class="row">
        <div style="flex:0 0 150px"><label>Количество</label>
          <input type="number" step="0.001" min="0" name="qty" value="0"></div>
        <div style="flex:1"><label>Сумма претензии</label>
          <input type="number" step="0.01" min="0.01" name="amount" required></div>
        <div style="flex:0 0 130px"><label>Валюта</label>
          <select name="currency">
            <?php foreach (['USD'=>'USD — $','EUR'=>'EUR — €','RUB'=>'RUB — ₽','UZS'=>'UZS — сум'] as $k=>$lbl): ?>
              <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <label>Что не так</label>
      <textarea name="description" rows="3" placeholder="например: 12 радиаторов пришли с вмятинами, фото приложены" required></textarea>

      <button type="submit">Открыть рекламацию</button>
    </form>
  </div>

  <div class="card">
    <h2>Список</h2>
    <?php if (empty($claims)): ?>
      <p class="muted">Рекламаций пока не было.</p>
    <?php else: ?>
      <table>
        <tr><th>№</th><th>Кому</th><th>Что</th><th>Сумма</th><th>Открыта</th><th>Состояние</th><th></th></tr>
        <?php foreach ($claims as $c): ?>
          <tr>
            <td>#<?= (int)$c['rowid'] ?></td>
            <td><?= htmlspecialchars($nameOf((int)$c['fk_party'])) ?>
              <span class="muted">· <?= htmlspecialchars(CLAIM_TARGETS[$c['target_type']] ?? '') ?></span></td>
            <td class="muted"><?= htmlspecialchars(mb_substr((string)$c['product_label'] ?: (string)$c['description'], 0, 40)) ?></td>
            <td><?= htmlspecialchars(money((float)$c['amount'], $c['currency'])) ?></td>
            <td class="muted"><?= htmlspecialchars(date('d.m.Y', strtotime($c['datec']))) ?></td>
            <td>
              <?php if ($c['status'] === 'open'): ?>
                <span class="badge badge-warn">Ждём ответа</span>
              <?php else: ?>
                <span class="badge badge-ok"><?= htmlspecialchars(CLAIM_RESOLUTIONS[$c['resolution']] ?? 'Закрыта') ?></span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="select_claim">
                <input type="hidden" name="claim_id" value="<?= (int)$c['rowid'] ?>">
                <button type="submit" class="small secondary">Открыть</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

<?php else: ?>
  <div class="card">
    <h2>Рекламация #<?= (int)$selected['rowid'] ?> — <?= htmlspecialchars($nameOf((int)$selected['fk_party'])) ?></h2>
    <p class="muted"><?= htmlspecialchars(CLAIM_TARGETS[$selected['target_type']] ?? '') ?>
      · открыта <?= htmlspecialchars(date('d.m.Y', strtotime($selected['datec']))) ?>
      <?= $selected['created_by'] ? '· ' . htmlspecialchars($selected['created_by']) : '' ?></p>
    <div style="font-size:22px; font-weight:700"><?= htmlspecialchars(money((float)$selected['amount'], $selected['currency'])) ?></div>
    <?php if ($selected['product_label']): ?>
      <p><?= htmlspecialchars($selected['product_label']) ?>
        <?php if ((float)$selected['qty'] > 0): ?>
          · <?= htmlspecialchars(rtrim(rtrim(number_format((float)$selected['qty'], 3, '.', ''), '0'), '.')) ?> шт.
        <?php endif; ?></p>
    <?php endif; ?>
    <p style="white-space:pre-line"><?= htmlspecialchars((string)$selected['description']) ?></p>

    <?php
    // Фото брака (11.09.2026): касса прикрепляет их к заказу поставщику с именем brak_<id>_<n>.jpg.
    // Ищем по префиксу среди документов заказа — отдельного хранилища у рекламаций нет, и не нужно:
    // те же файлы видны на странице заказа и в самом Dolibarr.
    $claimPhotos = [];
    if ((int)$selected['fk_order'] > 0) {
        $ordForPhotos = $api->getSupplierOrder((int)$selected['fk_order']);
        if (is_array($ordForPhotos) && !empty($ordForPhotos['ref'])) {
            foreach ($api->getOrderDocuments($ordForPhotos['ref']) as $doc) {
                $fn = (string)($doc['name'] ?? $doc['filename'] ?? '');
                if (str_starts_with($fn, 'brak_' . (int)$selected['rowid'] . '_')) $claimPhotos[] = $fn;
            }
        }
    }
    ?>
    <?php if ($claimPhotos): ?>
      <p><strong>Фото брака:</strong>
        <?php foreach ($claimPhotos as $n => $fn): ?>
          <a class="btn secondary small" target="_blank"
             href="document_download.php?order_id=<?= (int)$selected['fk_order'] ?>&amp;filename=<?= urlencode($fn) ?>">фото <?= $n + 1 ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <?php if ((int)$selected['fk_order'] > 0): ?>
      <p class="muted"><a href="order_view.php?id=<?= (int)$selected['fk_order'] ?>">Открыть заказ</a></p>
    <?php endif; ?>

    <?php if ($selected['status'] === 'closed'): ?>
      <p class="ok" style="display:inline-block">Закрыта: <?= htmlspecialchars(CLAIM_RESOLUTIONS[$selected['resolution']] ?? '') ?></p>
      <?php if ($selected['resolution_note']): ?>
        <p class="muted"><?= htmlspecialchars($selected['resolution_note']) ?></p>
      <?php endif; ?>
      <p class="muted">Закрыта <?= htmlspecialchars(date('d.m.Y', strtotime((string)$selected['resolved_at']))) ?>.</p>
    <?php endif; ?>
  </div>

  <?php if ($selected['status'] === 'open'): ?>
    <div class="card">
      <h2>Чем закончилось</h2>
      <p class="muted">Выберите, о чём договорились. От этого зависит, что программа сделает с деньгами.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resolve">
        <input type="hidden" name="claim_id" value="<?= (int)$selected['rowid'] ?>">

        <label>Исход</label>
        <select name="resolution" id="resolution">
          <?php foreach (CLAIM_RESOLUTIONS as $k => $lbl): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="muted" id="resolutionHint"></p>

        <div id="accountBox" style="display:none">
          <label>Куда пришли деньги</label>
          <select name="account_id">
            <?php foreach ($moneyAccounts as $aid => $lbl): ?>
              <option value="<?= (int)$aid ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="muted">Счёт должен быть в той же валюте, что и претензия
            (<?= htmlspecialchars(cur_symbol($selected['currency'])) ?>).</p>
        </div>

        <label>Комментарий <span class="muted">— необязательно</span></label>
        <input type="text" name="resolution_note" placeholder="например: обещали довезти следующей машиной">

        <button type="submit">Закрыть рекламацию</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Документы <span class="muted">— фото брака, письма, ответы</span></h2>
    <p class="muted">Файлы лежат в карточке
      <a href="<?= $selected['target_type'] === 'carrier' ? 'carriers.php?carrier_id=' : 'suppliers.php?supplier_id=' ?><?= (int)$selected['fk_party'] ?>">
        <?= htmlspecialchars($nameOf((int)$selected['fk_party'])) ?></a> — там же их можно загрузить.</p>
    <?php if (empty($claimDocs)): ?>
      <p class="muted">Файлов пока нет.</p>
    <?php else: ?>
      <table>
        <tr><th>Файл</th><th>Размер</th></tr>
        <?php foreach ($claimDocs as $d): ?>
          <tr>
            <td><a href="party_document_download.php?party_id=<?= (int)$selected['fk_party'] ?>&file=<?= urlencode($d['name'] ?? '') ?>">
              <?= htmlspecialchars($d['name'] ?? '') ?></a></td>
            <td class="muted"><?= isset($d['size']) ? number_format((int)$d['size'] / 1024, 0, '.', ' ') . ' КБ' : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
// Кому претензия → какой поиск контрагента подключаем
(function () {
  const type = document.getElementById('targetType');
  if (!type) return;
  const label = document.getElementById('partyLabel');
  const chosen = document.getElementById('partyChosen');
  const idEl = document.getElementById('partyId');

  function pick(p) {
    idEl.value = p.id;
    chosen.textContent = 'Выбран: ' + (p.name || ('#' + p.id));
  }
  function rewire() {
    const isCarrier = type.value === 'carrier';
    label.textContent = isCarrier ? 'Перевозчик' : 'Поставщик';
    idEl.value = '';
    chosen.textContent = '';
    document.getElementById('partySearch').value = '';
    document.getElementById('partyResults').innerHTML = '';
    if (isCarrier) window.wireCarrierSearch && window.wireCarrierSearch('partySearch', 'partyResults', pick);
    else window.wireSupplierSearch && window.wireSupplierSearch('partySearch', 'partyResults', pick);
  }
  type.addEventListener('change', rewire);
  rewire();
})();

// Товар — необязательно
window.wireProductSearch && window.wireProductSearch('productSearch', 'productResults', function (p) {
  const idEl = document.getElementById('productId');
  if (!idEl) return;
  idEl.value = p.id;
  document.getElementById('productLabel').value = p.label || p.ref || '';
  document.getElementById('productChosen').textContent = 'Выбран: ' + (p.label || p.ref || ('#' + p.id));
});

// Исход → нужен ли счёт для поступления денег + пояснение, что произойдёт
(function () {
  const sel = document.getElementById('resolution');
  if (!sel) return;
  const box = document.getElementById('accountBox');
  const hint = document.getElementById('resolutionHint');
  const hints = {
    replace:  'Деньги не двигаются. Долг остаётся за ними товаром.',
    discount: 'Сумма запишется как их долг — зачтётся следующим счётом. Деньги сейчас не двигаются.',
    refund:   'Деньги реально придут на выбранный счёт — проводка создастся сразу.',
    rejected: 'Ничего с деньгами не делаем, убыток остаётся на нас.'
  };
  function sync() {
    box.style.display = sel.value === 'refund' ? '' : 'none';
    hint.textContent = hints[sel.value] || '';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
