<?php
/**
 * Хозрасходы и коммуналка (04.09.2026, задача Абдурашида). Видят оба закупщика — они подменяют друг
 * друга; закрыт по решению пользователя только раздел зарплаты. Категории заводит сам Абдурашид,
 * готового списка нет (тоже решение пользователя).
 *
 * Логика — includes/payroll.php (household_*). Деньги списываются настоящей проводкой по счёту.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';

$message = '';
$messageType = '';
$who = $_SESSION['user']['name'] ?? '';

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-t');

// Те же счета, что и в остальных разделах, плюс касса шефа.
$moneyAccounts = [];
$myCash = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCash) $moneyAccounts['mycash'] = ['id' => $myCash['id'], 'label' => 'Моя касса (' . $myCash['label'] . ')', 'currency' => 'USD'];
if (!empty($cfg['boss_cash_account'])) {
    $moneyAccounts['boss'] = ['id' => $cfg['boss_cash_account']['id'], 'label' => $cfg['boss_cash_account']['label'], 'currency' => 'USD'];
}
$moneyAccounts['uzs'] = ['id' => $cfg['uzs_account_id'], 'label' => 'Сумовый счёт (UZS-MAIN)', 'currency' => 'UZS'];
foreach ($cfg['currency_accounts'] as $code => $accId) {
    $moneyAccounts[strtolower($code)] = ['id' => $accId, 'label' => $code . '-MAIN', 'currency' => $code];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $r = household_add_category($_POST['category_name'] ?? '');
        flash_set($r['ok'] ? 'Категория добавлена.' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: household.php?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit;
    } elseif ($action === 'toggle_category') {
        household_set_category_active((int)($_POST['category_id'] ?? 0), !empty($_POST['make_active']));
        flash_set('Категория обновлена.', 'ok');
        header('Location: household.php?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit;
    } elseif ($action === 'add_expense') {
        $accKey = $_POST['account'] ?? '';
        $acc = $moneyAccounts[$accKey] ?? null;
        $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');
        $comment = trim($_POST['comment'] ?? '');
        $catId = (int)($_POST['category_id'] ?? 0);

        if (!$acc) {
            $message = 'Выберите счёт, с которого платим.';
            $messageType = 'err';
        } else {
            // Сумма: в долларах напрямую, либо в валюте счёта + курс (тот же принцип, что везде).
            if (($acc['currency'] ?? 'USD') === 'USD') {
                $usd = (float)($_POST['amount_usd'] ?? 0);
                $native = ['amount' => $usd, 'currency' => 'USD', 'rate' => null];
                $code = 'LIQ';
            } else {
                $nativeAmount = (float)($_POST['native_amount'] ?? 0);
                $rate = (float)($_POST['rate'] ?? 0);
                if ($rate <= 0.01) {
                    $message = 'Укажите курс (' . $acc['currency'] . ' за 1 доллар).';
                    $messageType = 'err';
                }
                $usd = $rate > 0 ? round($nativeAmount / $rate, 2) : 0;
                $native = ['amount' => $nativeAmount, 'currency' => $acc['currency'], 'rate' => $rate];
                $code = 'VIR';
            }
            if ($messageType !== 'err') {
                $r = household_record_expense($api, $catId, $expenseDate, $usd, (int)$acc['id'], $native, $code, $who, $comment);
                if (!$r['ok']) { $message = $r['error']; $messageType = 'err'; }
                else {
                    $msg = 'Расход записан: ' . $r['category'] . ' — ' . number_format($usd, 2) . ' $.';
                    // Предупреждение об уходе счёта в минус — как в convert.php и логистике (не блокируем).
                    $balAfter = $api->getAccountBalance((int)$acc['id']);
                    if ($balAfter !== null && $balAfter < -0.01) {
                        $msg .= ' ВНИМАНИЕ: остаток счёта «' . $acc['label'] . '» стал '
                            . number_format((float)$balAfter, 2) . ' $ — проверьте, всё ли поступление денег отмечено.';
                    }
                    flash_set($msg, 'ok');
                    header('Location: household.php?from=' . urlencode($from) . '&to=' . urlencode($to));
                    exit;
                }
            }
        }
    } elseif ($action === 'delete_expense') {
        $r = household_delete_expense($api, (int)($_POST['expense_id'] ?? 0));
        flash_set($r['ok'] ? ('Расход удалён.' . (!empty($r['note']) ? ' ' . $r['note'] : '')) : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: household.php?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit;
    }
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$categories = household_get_categories(true);
$allCategories = household_get_categories(false);
$report = household_report($from, $to);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Хозрасходы и коммуналка</h1>
<p class="muted">Свет, вода, аренда, канцелярия и всё остальное, что не относится к закупке товара.
Категории заводите сами — как вам удобно.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <h2>Записать расход</h2>
  <?php if (empty($categories)): ?>
    <p class="muted">Сначала заведите хотя бы одну категорию — форма ниже.</p>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_expense">
      <div class="row">
        <div>
          <label>Категория</label>
          <select name="category_id">
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['rowid'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Дата расхода</label>
          <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>">
        </div>
        <div>
          <label>С какого счёта</label>
          <select name="account" id="hhAccount">
            <?php foreach ($moneyAccounts as $k => $acc): ?>
              <option value="<?= $k ?>" data-currency="<?= htmlspecialchars($acc['currency']) ?>"><?= htmlspecialchars($acc['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="hhUsdBlock">
        <label>Сумма, $</label>
        <input type="number" step="0.01" min="0" name="amount_usd">
      </div>
      <div id="hhNativeBlock" style="display:none">
        <div class="row">
          <div><label>Сумма в валюте счёта (<span id="hhCurrLabel">UZS</span>)</label>
            <input type="number" step="1" min="0" name="native_amount" id="hhNativeAmount"></div>
          <div><label>Курс (за 1 $)</label>
            <input type="number" step="0.01" min="0" name="rate" id="hhRate" placeholder="например 12700"></div>
          <div><label>Получится</label><div class="muted" id="hhPreview" style="padding-top:8px">0.00 $</div></div>
        </div>
      </div>

      <label>Комментарий</label>
      <input type="text" name="comment" placeholder="например: свет за август, счётчик 12345">
      <button type="submit">Записать расход</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Расходы за период</h2>
  <form method="get" class="row" style="align-items:end; margin-bottom:12px">
    <div style="flex:0"><label>С</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
    <div style="flex:0"><label>По</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
    <div style="flex:0"><button type="submit" class="secondary">Показать</button></div>
    <div style="flex:0"><a class="btn secondary" href="household_excel.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">📄 Excel</a></div>
  </form>

  <?php if (empty($report['by_category'])): ?>
    <p class="muted">За этот период расходов нет.</p>
  <?php else: ?>
    <div class="row" style="align-items:baseline; margin-bottom:10px">
      <div><strong>Всего за период</strong></div>
      <div style="flex:0; font-size:24px; font-weight:700"><?= number_format($report['total'], 2) ?> $</div>
    </div>
    <table>
      <tr><th>Категория</th><th>Расходов</th><th>Сумма</th><th>Доля</th></tr>
      <?php foreach ($report['by_category'] as $c): ?>
        <?php $share = $report['total'] > 0 ? ((float)$c['total'] / $report['total'] * 100) : 0; ?>
        <tr>
          <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
          <td class="muted"><?= (int)$c['cnt'] ?></td>
          <td><?= number_format((float)$c['total'], 2) ?> $</td>
          <td class="muted"><?= number_format($share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h2 style="margin-top:20px">Все расходы за период</h2>
    <table>
      <tr><th>Дата</th><th>Категория</th><th>Сумма</th><th>Комментарий</th><th>Кто</th><th></th></tr>
      <?php foreach ($report['rows'] as $e): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(date('d.m.Y', strtotime($e['expense_date']))) ?></td>
          <td><?= htmlspecialchars($e['category_name']) ?></td>
          <td style="white-space:nowrap">
            <?= number_format((float)$e['amount_usd'], 2) ?> $
            <?php if (!empty($e['native_currency']) && $e['native_currency'] !== 'USD'): ?>
              <div class="muted" style="font-size:12px"><?= number_format((float)$e['native_amount'], 0, '.', ' ') ?> <?= htmlspecialchars($e['native_currency']) ?> по курсу <?= rtrim(rtrim(number_format((float)$e['rate'], 2, '.', ''), '0'), '.') ?></div>
            <?php endif; ?>
          </td>
          <td class="muted"><?= htmlspecialchars($e['comment'] ?? '') ?></td>
          <td class="muted"><?= htmlspecialchars($e['who'] ?? '') ?></td>
          <td>
            <form method="post" style="display:inline"
                  onsubmit="return appConfirmSubmit(this, 'Удалить этот расход? Деньги вернутся на счёт, с которого были списаны.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_expense">
              <input type="hidden" name="expense_id" value="<?= (int)$e['rowid'] ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Категории</h2>
  <form method="post" class="row" style="align-items:end; margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_category">
    <div><label>Новая категория</label>
      <input type="text" name="category_name" placeholder="например: Свет · Вода · Аренда склада · Канцелярия"></div>
    <div style="flex:0"><button type="submit" class="secondary">Добавить</button></div>
  </form>
  <?php if (empty($allCategories)): ?>
    <p class="muted">Пока нет ни одной категории.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Состояние</th><th></th></tr>
      <?php foreach ($allCategories as $c): ?>
        <tr<?= $c['active'] ? '' : ' class="muted"' ?>>
          <td><?= htmlspecialchars($c['name']) ?></td>
          <td><?= $c['active'] ? '<span class="badge badge-ok">используется</span>' : '<span class="badge badge-neutral">скрыта</span>' ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_category">
              <input type="hidden" name="category_id" value="<?= (int)$c['rowid'] ?>">
              <?php if ($c['active']): ?>
                <button type="submit" class="secondary small">Скрыть</button>
              <?php else: ?>
                <input type="hidden" name="make_active" value="1">
                <button type="submit" class="secondary small">Вернуть</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="margin-top:8px">Скрытая категория пропадает из выбора при записи расхода,
    но прошлые расходы по ней остаются в отчёте.</p>
  <?php endif; ?>
</div>

<script>
(function () {
  const sel = document.getElementById('hhAccount');
  if (!sel) return;
  const usdBlock = document.getElementById('hhUsdBlock');
  const nativeBlock = document.getElementById('hhNativeBlock');
  const currLabel = document.getElementById('hhCurrLabel');
  const amount = document.getElementById('hhNativeAmount');
  const rate = document.getElementById('hhRate');
  const preview = document.getElementById('hhPreview');
  function toggle() {
    const curr = sel.options[sel.selectedIndex].dataset.currency;
    const isForeign = curr !== 'USD';
    usdBlock.style.display = isForeign ? 'none' : '';
    nativeBlock.style.display = isForeign ? '' : 'none';
    if (isForeign) currLabel.textContent = curr;
  }
  function recalc() {
    const a = parseFloat(amount.value) || 0;
    const r = parseFloat(rate.value) || 0;
    preview.textContent = (r > 0 ? (a / r) : 0).toFixed(2) + ' $';
  }
  sel.addEventListener('change', toggle);
  [amount, rate].forEach(i => i && i.addEventListener('input', recalc));
  toggle(); recalc();
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
