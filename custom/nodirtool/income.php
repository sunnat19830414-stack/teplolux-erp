<?php
/**
 * Прочие доходы (04.09.2026) — деньги, которые приходят МИМО продажи товара: электричество с
 * солнечных батарей государству, аренда, услуги и работы и т.п. Отдельный раздел от хозрасходов
 * (решение пользователя). Источники заводит сам Абдурашид, готового списка нет.
 *
 * Зеркало household.php: то же устройство, только деньги ЗАЧИСЛЯЮТСЯ на счёт. Логика — includes/payroll.php.
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

// Счета, куда могут прийти деньги — те же, что везде в разделе финансов.
$moneyAccounts = [];
$moneyAccounts['uzs'] = ['id' => $cfg['uzs_account_id'], 'label' => 'Сумовый счёт (р/с компании)', 'currency' => 'UZS'];
foreach ($cfg['currency_accounts'] as $code => $accId) {
    $moneyAccounts[strtolower($code)] = ['id' => $accId, 'label' => $code . '-MAIN', 'currency' => $code];
}
$myCash = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCash) $moneyAccounts['mycash'] = ['id' => $myCash['id'], 'label' => 'Моя касса (' . $myCash['label'] . ')', 'currency' => 'USD'];
if (!empty($cfg['boss_cash_account'])) {
    $moneyAccounts['boss'] = ['id' => $cfg['boss_cash_account']['id'], 'label' => $cfg['boss_cash_account']['label'], 'currency' => 'USD'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_source') {
        $r = income_add_source($_POST['source_name'] ?? '');
        flash_set($r['ok'] ? 'Источник дохода добавлен.' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: income.php?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit;
    } elseif ($action === 'toggle_source') {
        income_set_source_active((int)($_POST['source_id'] ?? 0), !empty($_POST['make_active']));
        flash_set('Источник обновлён.', 'ok');
        header('Location: income.php?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit;
    } elseif ($action === 'add_income') {
        $accKey = $_POST['account'] ?? '';
        $acc = $moneyAccounts[$accKey] ?? null;
        $incomeDate = $_POST['income_date'] ?? date('Y-m-d');
        $comment = trim($_POST['comment'] ?? '');
        $srcId = (int)($_POST['source_id'] ?? 0);

        if (!$acc) {
            $message = 'Выберите счёт, на который пришли деньги.';
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
                $r = income_record($api, $srcId, $incomeDate, $usd, (int)$acc['id'], $native, $code, $who, $comment);
                if (!$r['ok']) { $message = $r['error']; $messageType = 'err'; }
                else {
                    flash_set('Доход записан: ' . $r['source'] . ' — ' . number_format($usd, 2) . ' $ зачислено на «' . $acc['label'] . '».', 'ok');
                    header('Location: income.php?from=' . urlencode($from) . '&to=' . urlencode($to));
                    exit;
                }
            }
        }
    } elseif ($action === 'delete_income') {
        $r = income_delete($api, (int)($_POST['income_id'] ?? 0));
        flash_set($r['ok'] ? ('Запись удалена.' . (!empty($r['note']) ? ' ' . $r['note'] : '')) : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: income.php?from=' . urlencode($from) . '&to=' . urlencode($to));
        exit;
    }
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$sources = income_get_sources(true);
$allSources = income_get_sources(false);
$report = income_report($from, $to);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Доходы</h1>
<p class="muted">Деньги, которые приходят не от продажи товара: электричество с солнечных батарей,
аренда, услуги и работы и всё остальное. Источники заводите сами — как вам удобно.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <h2>Записать доход</h2>
  <?php if (empty($sources)): ?>
    <p class="muted">Сначала заведите хотя бы один источник — форма ниже.</p>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_income">
      <div class="row">
        <div>
          <label>Источник</label>
          <select name="source_id">
            <?php foreach ($sources as $s): ?>
              <option value="<?= (int)$s['rowid'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Дата поступления</label>
          <input type="date" name="income_date" value="<?= date('Y-m-d') ?>">
        </div>
        <div>
          <label>Куда пришли деньги</label>
          <select name="account" id="incAccount">
            <?php foreach ($moneyAccounts as $k => $acc): ?>
              <option value="<?= $k ?>" data-currency="<?= htmlspecialchars($acc['currency']) ?>"><?= htmlspecialchars($acc['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="incUsdBlock" style="display:none">
        <label>Сумма, $</label>
        <input type="number" step="0.01" min="0" name="amount_usd">
      </div>
      <div id="incNativeBlock">
        <div class="row">
          <div><label>Сумма в валюте счёта (<span id="incCurrLabel">UZS</span>)</label>
            <input type="number" step="1" min="0" name="native_amount" id="incNativeAmount"></div>
          <div><label>Курс (за 1 $)</label>
            <input type="number" step="0.01" min="0" name="rate" id="incRate" placeholder="например 12700"></div>
          <div><label>Получится</label><div class="muted" id="incPreview" style="padding-top:8px">0.00 $</div></div>
        </div>
      </div>

      <label>Комментарий</label>
      <input type="text" name="comment" placeholder="например: электричество за август, показания 4520">
      <button type="submit">Записать доход</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Доходы за период</h2>
  <form method="get" class="row" style="align-items:end; margin-bottom:12px">
    <div style="flex:0"><label>С</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
    <div style="flex:0"><label>По</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
    <div style="flex:0"><button type="submit" class="secondary">Показать</button></div>
    <div style="flex:0"><a class="btn secondary" href="income_excel.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">📄 Excel</a></div>
  </form>

  <?php if (empty($report['by_source'])): ?>
    <p class="muted">За этот период доходов нет.</p>
  <?php else: ?>
    <div class="row" style="align-items:baseline; margin-bottom:10px">
      <div><strong>Всего за период</strong></div>
      <div style="flex:0; font-size:24px; font-weight:700; color:var(--ok)"><?= number_format($report['total'], 2) ?> $</div>
    </div>
    <table>
      <tr><th>Источник</th><th>Поступлений</th><th>Сумма</th><th>Доля</th></tr>
      <?php foreach ($report['by_source'] as $s): ?>
        <?php $share = $report['total'] > 0 ? ((float)$s['total'] / $report['total'] * 100) : 0; ?>
        <tr>
          <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
          <td class="muted"><?= (int)$s['cnt'] ?></td>
          <td><?= number_format((float)$s['total'], 2) ?> $</td>
          <td class="muted"><?= number_format($share, 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h2 style="margin-top:20px">Все поступления за период</h2>
    <table>
      <tr><th>Дата</th><th>Источник</th><th>Сумма</th><th>Комментарий</th><th>Кто</th><th></th></tr>
      <?php foreach ($report['rows'] as $i): ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(date('d.m.Y', strtotime($i['income_date']))) ?></td>
          <td><?= htmlspecialchars($i['source_name']) ?></td>
          <td style="white-space:nowrap">
            <?= number_format((float)$i['amount_usd'], 2) ?> $
            <?php if (!empty($i['native_currency']) && $i['native_currency'] !== 'USD'): ?>
              <div class="muted" style="font-size:12px"><?= number_format((float)$i['native_amount'], 0, '.', ' ') ?> <?= htmlspecialchars($i['native_currency']) ?> по курсу <?= rtrim(rtrim(number_format((float)$i['rate'], 2, '.', ''), '0'), '.') ?></div>
            <?php endif; ?>
          </td>
          <td class="muted"><?= htmlspecialchars($i['comment'] ?? '') ?></td>
          <td class="muted"><?= htmlspecialchars($i['who'] ?? '') ?></td>
          <td>
            <form method="post" style="display:inline"
                  onsubmit="return appConfirmSubmit(this, 'Удалить эту запись? Деньги будут сняты со счёта обратно.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_income">
              <input type="hidden" name="income_id" value="<?= (int)$i['rowid'] ?>">
              <button type="submit" class="secondary small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Источники дохода</h2>
  <form method="post" class="row" style="align-items:end; margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_source">
    <div><label>Новый источник</label>
      <input type="text" name="source_name" placeholder="например: Солнечные батареи · Аренда склада · Услуги и работы"></div>
    <div style="flex:0"><button type="submit" class="secondary">Добавить</button></div>
  </form>
  <?php if (empty($allSources)): ?>
    <p class="muted">Пока нет ни одного источника.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Состояние</th><th></th></tr>
      <?php foreach ($allSources as $s): ?>
        <tr<?= $s['active'] ? '' : ' class="muted"' ?>>
          <td><?= htmlspecialchars($s['name']) ?></td>
          <td><?= $s['active'] ? '<span class="badge badge-ok">используется</span>' : '<span class="badge badge-neutral">скрыт</span>' ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_source">
              <input type="hidden" name="source_id" value="<?= (int)$s['rowid'] ?>">
              <?php if ($s['active']): ?>
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
    <p class="muted" style="margin-top:8px">Скрытый источник пропадает из выбора при записи дохода,
    но прошлые поступления по нему остаются в отчёте.</p>
  <?php endif; ?>
</div>

<script>
(function () {
  const sel = document.getElementById('incAccount');
  if (!sel) return;
  const usdBlock = document.getElementById('incUsdBlock');
  const nativeBlock = document.getElementById('incNativeBlock');
  const currLabel = document.getElementById('incCurrLabel');
  const amount = document.getElementById('incNativeAmount');
  const rate = document.getElementById('incRate');
  const preview = document.getElementById('incPreview');
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
