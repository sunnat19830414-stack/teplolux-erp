<?php
/**
 * Зарплата и авансы (04.09.2026, задача Нодира). Доступ — только логин nodir (см. 'page_access' в
 * config.php, проверка стоит в auth.php и срабатывает даже по прямой ссылке).
 *
 * Логика и модель — includes/payroll.php (там же пояснение про налог при выплате на карту).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';

if (!array_key_exists('payroll_employee', $_SESSION)) $_SESSION['payroll_employee'] = null;
reset_selection_unless_preserved('payroll_employee');

$message = '';
$messageType = '';
$who = $_SESSION['user']['name'] ?? '';
$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

// Счета, с которых можно выдавать деньги — те же, что в "Оплате поставщикам", плюс касса шефа.
$moneyAccounts = [];
$myCash = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCash) $moneyAccounts['mycash'] = ['id' => $myCash['id'], 'label' => 'Моя касса (' . $myCash['label'] . ')', 'currency' => 'USD'];
if (!empty($cfg['boss_cash_account'])) {
    $moneyAccounts['boss'] = ['id' => $cfg['boss_cash_account']['id'], 'label' => $cfg['boss_cash_account']['label'], 'currency' => 'USD'];
}
$moneyAccounts['uzs'] = ['id' => $cfg['uzs_account_id'], 'label' => 'Сумовый счёт (для выплат на карту)', 'currency' => 'UZS'];
foreach ($cfg['currency_accounts'] as $code => $accId) {
    $moneyAccounts[strtolower($code)] = ['id' => $accId, 'label' => $code . '-MAIN', 'currency' => $code];
}

/** Разобрать поля формы выплаты → сколько получил сотрудник, сколько ушло со счёта, чем провести. */
function payroll_parse_payment(array $post, array $moneyAccounts): array
{
    $accKey = $post['account'] ?? '';
    $acc = $moneyAccounts[$accKey] ?? null;
    if (!$acc) return ['error' => 'Выберите счёт, с которого выдаются деньги.'];

    if (($acc['currency'] ?? 'USD') === 'USD') {
        // Наличные/валютный счёт: сколько выдали — столько и списалось, налога здесь нет.
        $usd = (float)($post['amount_usd'] ?? 0);
        if ($usd <= 0.001) return ['error' => 'Укажите сумму выдачи.'];
        return ['acc' => $acc, 'received_usd' => $usd, 'debited_usd' => $usd,
                'native' => ['amount' => $usd, 'currency' => 'USD', 'rate' => null], 'code' => 'LIQ'];
    }

    // Выплата на карту с сумового счёта: две ФАКТИЧЕСКИЕ суммы (см. докблок includes/payroll.php).
    $toCard = (float)($post['uzs_to_card'] ?? 0);
    $debited = (float)($post['uzs_debited'] ?? 0);
    $rate = (float)($post['rate'] ?? 0);
    if ($toCard <= 0.5) return ['error' => 'Укажите, сколько пришло сотруднику на карту (в сумах).'];
    if ($rate <= 0.01) return ['error' => 'Укажите курс (сум за 1 доллар).'];
    if ($debited <= 0.5) $debited = $toCard; // налог не указан — считаем, что его нет
    if ($debited + 0.5 < $toCard) return ['error' => 'Со счёта не может списаться меньше, чем пришло на карту — проверьте суммы.'];
    return [
        'acc' => $acc,
        'received_usd' => round($toCard / $rate, 2),
        'debited_usd' => round($debited / $rate, 2),
        'native' => ['amount' => $debited, 'currency' => 'UZS', 'rate' => $rate],
        'code' => 'VIR',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_employee') {
        $id = (int)($_POST['employee_id'] ?? 0);
        $emp = payroll_get_employee($id);
        $_SESSION['payroll_employee'] = $emp ? ['id' => $id, 'name' => $emp['name']] : null;
        if (!$emp) { $message = 'Сотрудник не найден.'; $messageType = 'err'; }
    } elseif ($action === 'clear_employee') {
        $_SESSION['payroll_employee'] = null;
    } elseif ($action === 'accrue') {
        $id = (int)($_POST['employee_id'] ?? 0);
        $custom = ($_POST['amount_usd'] ?? '') !== '' ? (float)$_POST['amount_usd'] : null;
        $r = payroll_accrue($id, $_POST['period'] ?? $period, $custom, $who, trim($_POST['comment'] ?? ''));
        if (!$r['ok']) { $message = $r['error']; $messageType = 'err'; }
        else {
            flash_set('Начислено ' . number_format($r['amount'], 2) . ' $ за ' . ($_POST['period'] ?? $period) . '.', 'ok');
            $_SESSION['_preserve_once']['payroll_employee'] = true;
            header('Location: payroll.php?period=' . urlencode($_POST['period'] ?? $period));
            exit;
        }
    } elseif ($action === 'accrue_all') {
        // Начислить всем активным сотрудникам разом — типовое действие в начале месяца.
        $p = $_POST['period'] ?? $period;
        $done = 0; $skipped = [];
        foreach (payroll_get_employees(true) as $emp) {
            $r = payroll_accrue((int)$emp['rowid'], $p, null, $who);
            if ($r['ok']) $done++; else $skipped[] = $emp['name'];
        }
        $msg = "Начислено {$done} сотрудник(ам) за {$p}.";
        if ($skipped) $msg .= ' Пропущены (уже начислено или нет оклада): ' . implode(', ', $skipped) . '.';
        flash_set($msg, $done ? 'ok' : 'err');
        header('Location: payroll.php?period=' . urlencode($p));
        exit;
    } elseif ($action === 'pay') {
        $id = (int)($_POST['employee_id'] ?? 0);
        $type = ($_POST['pay_type'] ?? 'advance') === 'payout' ? 'payout' : 'advance';
        $parsed = payroll_parse_payment($_POST, $moneyAccounts);
        if (isset($parsed['error'])) {
            $message = $parsed['error'];
            $messageType = 'err';
        } else {
            $r = payroll_pay($api, $id, $type, $parsed['received_usd'], $parsed['debited_usd'],
                (int)$parsed['acc']['id'], $parsed['native'], $parsed['code'], $who,
                trim($_POST['comment'] ?? ''), $type === 'payout' ? ($_POST['period'] ?? $period) : null);
            if (!$r['ok']) { $message = $r['error']; $messageType = 'err'; }
            else {
                $msg = ($type === 'advance' ? 'Аванс выдан' : 'Зарплата выдана') . ': сотрудник получил '
                    . number_format($r['received_usd'], 2) . ' $';
                if ($r['tax_usd'] > 0.01) {
                    $msg .= ', со счёта списано ' . number_format($r['debited_usd'], 2) . ' $ (налог '
                        . number_format($r['tax_usd'], 2) . ' $)';
                }
                $msg .= '.';
                // Предупреждаем, если счёт ушёл в минус — не блокируем (тот же принцип, что в
                // convert.php и логистике: это внутренний учётный счёт, а не настоящий банк, и
                // остаток может отставать от реальности).
                $balAfter = $api->getAccountBalance((int)$parsed['acc']['id']);
                if ($balAfter !== null && $balAfter < -0.01) {
                    $msg .= ' ВНИМАНИЕ: остаток счёта «' . $parsed['acc']['label'] . '» стал '
                        . number_format((float)$balAfter, 2) . ' $ — проверьте, всё ли поступление денег отмечено.';
                }
                flash_set($msg, 'ok');
                $_SESSION['_preserve_once']['payroll_employee'] = true;
                header('Location: payroll.php?period=' . urlencode($period));
                exit;
            }
        }
    } elseif ($action === 'add_department') {
        $r = payroll_add_department($_POST['department_name'] ?? '');
        flash_set($r['ok'] ? 'Отдел добавлен.' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: payroll.php?period=' . urlencode($period));
        exit;
    } elseif ($action === 'rename_department') {
        $r = payroll_rename_department((int)($_POST['department_id'] ?? 0), $_POST['department_name'] ?? '');
        flash_set($r['ok'] ? 'Название отдела изменено.' : $r['error'], $r['ok'] ? 'ok' : 'err');
        header('Location: payroll.php?period=' . urlencode($period));
        exit;
    } elseif ($action === 'toggle_department') {
        payroll_set_department_active((int)($_POST['department_id'] ?? 0), !empty($_POST['make_active']));
        flash_set('Отдел обновлён.', 'ok');
        header('Location: payroll.php?period=' . urlencode($period));
        exit;
    } elseif ($action === 'adjust') {
        $id = (int)($_POST['employee_id'] ?? 0);
        $amount = (float)($_POST['adjust_amount'] ?? 0);
        if (($_POST['adjust_sign'] ?? '+') === '-') $amount = -abs($amount); else $amount = abs($amount);
        $r = payroll_adjust($id, $amount, trim($_POST['adjust_comment'] ?? ''), $who);
        if (!$r['ok']) { $message = $r['error']; $messageType = 'err'; }
        else {
            flash_set('Правка записана: ' . ($amount > 0 ? '+' : '') . number_format($amount, 2) . ' $.', 'ok');
            $_SESSION['_preserve_once']['payroll_employee'] = true;
            header('Location: payroll.php?period=' . urlencode($period));
            exit;
        }
    }
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$employees = payroll_get_employees(false);
$balances = payroll_all_balances();
$summary = payroll_month_summary($period);
$byDepartment = payroll_month_by_department($period);
$allDepartments = payroll_get_departments(false);
$deptCounts = payroll_department_employee_counts();
$selected = null;
$selectedEntries = [];
$selectedBalance = 0.0;
$selectedHasAccrual = false;
if (!empty($_SESSION['payroll_employee']['id'])) {
    $selected = payroll_get_employee((int)$_SESSION['payroll_employee']['id']);
    if ($selected) {
        $selectedEntries = payroll_get_entries((int)$selected['rowid']);
        $selectedBalance = payroll_employee_balance((int)$selected['rowid']);
        $selectedHasAccrual = payroll_has_accrual((int)$selected['rowid'], $period);
    } else {
        $_SESSION['payroll_employee'] = null;
    }
}

$typeLabels = ['accrual' => 'Начислено', 'advance' => 'Аванс', 'payout' => 'Зарплата', 'adjust' => 'Правка'];

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Зарплата и авансы</h1>
<p class="muted">Оклад записан в карточке сотрудника, в долларах. Выдавать можно наличными или на карту
в сумах по курсу. Программа сама считает, сколько человек уже взял авансом и сколько осталось к выдаче.</p>
<?php if ($selected): ?>
  <form method="post" style="margin-bottom:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_employee">
    <button type="submit" class="secondary">← Все сотрудники</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <h2 style="margin:0">Месяц: <?= htmlspecialchars($period) ?></h2>
      <div class="muted">начислено <?= number_format($summary['accrued'], 2) ?> $ ·
        авансами выдано <?= number_format($summary['advances'], 2) ?> $ ·
        зарплатой выдано <?= number_format($summary['payouts'], 2) ?> $
        <?php if ($summary['tax'] > 0.01): ?>· налогов <?= number_format($summary['tax'], 2) ?> $<?php endif; ?>
      </div>
    </div>
    <form method="get" style="flex:0; display:flex; gap:8px; align-items:center">
      <input type="month" name="period" value="<?= htmlspecialchars($period) ?>" style="margin:0">
      <button type="submit" class="secondary">Показать</button>
    </form>
  </div>
  <div class="row" style="margin-top:12px">
    <div style="flex:0">
      <form method="post" onsubmit="return appConfirmSubmit(this, 'Начислить зарплату за <?= htmlspecialchars($period) ?> всем активным сотрудникам по их окладу?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="accrue_all">
        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
        <button type="submit">Начислить всем за <?= htmlspecialchars($period) ?></button>
      </form>
    </div>
    <div style="flex:0"><a href="payroll_excel.php?period=<?= urlencode($period) ?>" class="btn secondary">📄 Excel за месяц</a></div>
    <div style="flex:0"><a href="employee_form.php" class="btn secondary">+ Новый сотрудник</a></div>
  </div>

  <?php // Разбивка по отделам за месяц (04.09.2026) — сколько зарплаты приходится на каждый отдел. ?>
  <?php if (!empty($byDepartment)): ?>
    <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border)">
      <strong>По отделам за <?= htmlspecialchars($period) ?></strong>
      <table style="margin-top:8px">
        <tr><th>Отдел</th><th>Человек</th><th>Начислено</th><th>Выдано авансами</th><th>Выдано зарплатой</th><th>Налоги</th></tr>
        <?php foreach ($byDepartment as $d): ?>
          <tr>
            <td><strong><?= htmlspecialchars($d['dept']) ?></strong></td>
            <td class="muted"><?= (int)$d['people'] ?></td>
            <td><?= number_format((float)$d['accrued'], 2) ?> $</td>
            <td><?= number_format((float)$d['advances'], 2) ?> $</td>
            <td><?= number_format((float)$d['payouts'], 2) ?> $</td>
            <td class="muted"><?= (float)$d['tax'] > 0.01 ? number_format((float)$d['tax'], 2) . ' $' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (!$selected): ?>
<div class="card">
  <h2>Сотрудники</h2>
  <?php if (empty($employees)): ?>
    <p class="muted">Пока никого нет — добавьте первого сотрудника кнопкой выше.</p>
  <?php else: ?>
    <?php // Отделы (04.09.2026): список сгруппирован — сразу видно, кто где работает и сколько
          // приходится на каждый отдел. Сотрудники без отдела идут последней группой, не теряются.
          // Сортировка уже сделана в payroll_get_employees() (по отделу, потом по имени).
          $grouped = [];
          foreach ($employees as $e) {
              $key = $e['department_name'] ?? '';
              $grouped[$key][] = $e;
          }
    ?>
    <?php foreach ($grouped as $deptName => $list): ?>
      <?php
        $deptSalary = 0.0; $deptOwed = 0.0;
        foreach ($list as $e) {
            if ($e['active']) $deptSalary += (float)$e['salary_usd'];
            $deptOwed += $balances[(int)$e['rowid']] ?? 0.0;
        }
      ?>
      <div style="margin-bottom:18px">
        <div class="row" style="align-items:baseline; margin-bottom:6px">
          <div>
            <strong><?= $deptName !== '' ? htmlspecialchars($deptName) : 'Без отдела' ?></strong>
            <span class="muted">— <?= count($list) ?> чел., фонд окладов <?= number_format($deptSalary, 2) ?> $/мес</span>
          </div>
          <?php if (abs($deptOwed) > 0.01): ?>
            <div style="flex:0" class="muted">
              <?= $deptOwed > 0 ? 'к выдаче ' . number_format($deptOwed, 2) : 'взято вперёд ' . number_format(abs($deptOwed), 2) ?> $
            </div>
          <?php endif; ?>
        </div>
        <table>
          <tr><th>Сотрудник</th><th>Должность</th><th>Оклад</th><th>Числится за ним</th><th></th></tr>
          <?php foreach ($list as $e): ?>
            <?php $bal = $balances[(int)$e['rowid']] ?? 0.0; ?>
            <tr<?= $e['active'] ? '' : ' class="muted"' ?>>
              <td>
                <strong><?= htmlspecialchars($e['name']) ?></strong>
                <?php if (!$e['active']): ?><span class="badge badge-neutral">не работает</span><?php endif; ?>
                <?php if ($e['card_payment']): ?><span class="badge badge-neutral">на карту</span><?php endif; ?>
              </td>
              <td class="muted"><?= htmlspecialchars($e['position'] ?? '') ?></td>
              <td><?= number_format((float)$e['salary_usd'], 2) ?> $</td>
              <td>
                <?php if ($bal > 0.01): ?>
                  <span class="badge badge-warn">к выдаче <?= number_format($bal, 2) ?> $</span>
                <?php elseif ($bal < -0.01): ?>
                  <span class="badge badge-debt">взял вперёд <?= number_format(abs($bal), 2) ?> $</span>
                <?php else: ?>
                  <span class="muted">рассчитан</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="select_employee">
                  <input type="hidden" name="employee_id" value="<?= (int)$e['rowid'] ?>">
                  <button type="submit" class="secondary small">Открыть →</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php // Управление отделами (04.09.2026) — Нодир заводит и правит сам, как категории у хозрасходов. ?>
<div class="card">
  <h2>Отделы</h2>
  <form method="post" class="row" style="align-items:end; margin-bottom:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_department">
    <div><label>Новый отдел</label>
      <input type="text" name="department_name" placeholder="например: Склад Жоми · Склад Турк · Офис · Доставка"></div>
    <div style="flex:0"><button type="submit" class="secondary">Добавить</button></div>
  </form>
  <?php if (empty($allDepartments)): ?>
    <p class="muted">Пока нет ни одного отдела. Пока их нет, все сотрудники показываются одной группой
    «Без отдела» — это нормально, отделы можно завести в любой момент.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Сотрудников</th><th>Состояние</th><th></th></tr>
      <?php foreach ($allDepartments as $d): ?>
        <?php $cnt = $deptCounts[(int)$d['rowid']] ?? 0; ?>
        <tr<?= $d['active'] ? '' : ' class="muted"' ?>>
          <td>
            <form method="post" style="display:flex; gap:6px; align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rename_department">
              <input type="hidden" name="department_id" value="<?= (int)$d['rowid'] ?>">
              <input type="text" name="department_name" value="<?= htmlspecialchars($d['name']) ?>" style="margin:0; max-width:240px">
              <button type="submit" class="secondary small">Переименовать</button>
            </form>
          </td>
          <td class="muted"><?= $cnt ?></td>
          <td><?= $d['active'] ? '<span class="badge badge-ok">используется</span>' : '<span class="badge badge-neutral">скрыт</span>' ?></td>
          <td>
            <form method="post" style="display:inline"
                  <?= ($d['active'] && $cnt > 0) ? 'onsubmit="return appConfirmSubmit(this, \'В этом отделе ' . $cnt . ' сотрудник(ов). Скрыть отдел? Люди останутся привязаны к нему, отдел просто пропадёт из выбора при заведении новых.\');"' : '' ?>>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_department">
              <input type="hidden" name="department_id" value="<?= (int)$d['rowid'] ?>">
              <?php if ($d['active']): ?>
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
    <p class="muted" style="margin-top:8px">Отдел указывается в карточке сотрудника. Скрытый отдел
    пропадает из выбора для новых сотрудников, но у тех, кто уже в нём числится, привязка сохраняется —
    и в списке, и в отчёте по отделам он продолжает показываться.</p>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="card">
  <div class="row" style="align-items:center">
    <div>
      <h2 style="margin:0"><?= htmlspecialchars($selected['name']) ?></h2>
      <div class="muted">
        <?php if (!empty($selected['department_name'])): ?>
          <span class="badge badge-neutral"><?= htmlspecialchars($selected['department_name']) ?></span>
        <?php endif; ?>
        <?= htmlspecialchars($selected['position'] ?? '') ?> ·
        оклад <?= number_format((float)$selected['salary_usd'], 2) ?> $/мес
        <?= $selected['card_payment'] ? ' · зарплата на карту (официально)' : '' ?></div>
      <div><a href="employee_form.php?id=<?= (int)$selected['rowid'] ?>" class="muted">✏️ Редактировать карточку</a></div>
    </div>
    <div style="flex:0; text-align:right">
      <?php if ($selectedBalance > 0.01): ?>
        <div style="font-size:26px; font-weight:700; color:var(--warn)"><?= number_format($selectedBalance, 2) ?> $</div>
        <div class="muted">к выдаче</div>
      <?php elseif ($selectedBalance < -0.01): ?>
        <div style="font-size:26px; font-weight:700; color:var(--danger)"><?= number_format(abs($selectedBalance), 2) ?> $</div>
        <div class="muted">взял вперёд — вычтется из следующей зарплаты</div>
      <?php else: ?>
        <div style="font-size:26px; font-weight:700; color:var(--ok)">0.00 $</div>
        <div class="muted">рассчитан полностью</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$selectedHasAccrual): ?>
<div class="card">
  <h2>Начислить зарплату за <?= htmlspecialchars($period) ?></h2>
  <p class="muted">За этот месяц ещё не начислено. По умолчанию берётся оклад из карточки —
  можно указать другую сумму (премия, неполный месяц).</p>
  <form method="post" class="row" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="accrue">
    <input type="hidden" name="employee_id" value="<?= (int)$selected['rowid'] ?>">
    <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
    <div><label>Сумма, $ (пусто = оклад <?= number_format((float)$selected['salary_usd'], 2) ?>)</label>
      <input type="number" step="0.01" min="0" name="amount_usd" placeholder="<?= number_format((float)$selected['salary_usd'], 2, '.', '') ?>"></div>
    <div><label>Комментарий</label><input type="text" name="comment" placeholder="например: премия за август"></div>
    <div style="flex:0"><button type="submit">Начислить</button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2>Выдать деньги</h2>
  <form method="post" id="payForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="pay">
    <input type="hidden" name="employee_id" value="<?= (int)$selected['rowid'] ?>">
    <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
    <div class="row">
      <div>
        <label>Что выдаём</label>
        <select name="pay_type">
          <option value="advance">Аванс (до зарплаты)</option>
          <option value="payout">Зарплату</option>
        </select>
      </div>
      <div>
        <label>С какого счёта</label>
        <select name="account" id="payAccount">
          <?php foreach ($moneyAccounts as $k => $acc): ?>
            <option value="<?= $k ?>" data-currency="<?= htmlspecialchars($acc['currency']) ?>"><?= htmlspecialchars($acc['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div id="usdBlock">
      <label>Сумма к выдаче, $</label>
      <input type="number" step="0.01" min="0" name="amount_usd"
             value="<?= $selectedBalance > 0.01 ? number_format($selectedBalance, 2, '.', '') : '' ?>">
      <p class="muted" style="margin:-4px 0 10px">Сколько сотрудник получает на руки. Со счёта спишется столько же.</p>
    </div>

    <?php // Выплата на карту: две ФАКТИЧЕСКИЕ суммы — что пришло сотруднику и что ушло со счёта.
          // Процент налога пока неизвестен (уточняется), поэтому программа его не считает сама. ?>
    <div id="uzsBlock" style="display:none">
      <div class="row">
        <div>
          <label>Пришло сотруднику на карту, сум</label>
          <input type="number" step="1" min="0" name="uzs_to_card" id="uzsToCard" placeholder="например 6 150 000">
        </div>
        <div>
          <label>Курс (сум за 1 $)</label>
          <input type="number" step="0.01" min="0" name="rate" id="uzsRate" placeholder="например 12300">
        </div>
      </div>
      <div class="row">
        <div>
          <label>Списалось со счёта, сум</label>
          <input type="number" step="1" min="0" name="uzs_debited" id="uzsDebited" placeholder="если больше — разница это налог">
          <p class="muted" style="margin:-4px 0 0">Оставьте пустым, если налога нет и списалось столько же.</p>
        </div>
        <div>
          <label>Расчёт</label>
          <div class="muted" id="uzsPreview" style="padding-top:8px">
            Получит: <strong>0.00 $</strong> · спишется: <strong>0.00 $</strong> · налог: <strong>0.00 $</strong>
          </div>
        </div>
      </div>
    </div>

    <label>Комментарий (необязательно)</label>
    <input type="text" name="comment" placeholder="например: аванс до 15-го">
    <button type="submit">Выдать</button>
  </form>
</div>

<div class="card">
  <h2>Правка вручную</h2>
  <p class="muted">Премия, штраф или исправление ошибки — без движения денег, только меняет то, что за
  сотрудником числится.</p>
  <form method="post" class="row" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="adjust">
    <input type="hidden" name="employee_id" value="<?= (int)$selected['rowid'] ?>">
    <div style="flex:0">
      <label>Знак</label>
      <select name="adjust_sign">
        <option value="+">+ добавить к выдаче</option>
        <option value="-">− уменьшить</option>
      </select>
    </div>
    <div style="flex:0"><label>Сумма, $</label><input type="number" step="0.01" min="0" name="adjust_amount"></div>
    <div><label>Причина (обязательно)</label><input type="text" name="adjust_comment" placeholder="например: премия за перевыполнение"></div>
    <div style="flex:0"><button type="submit" class="secondary">Записать</button></div>
  </form>
</div>

<div class="card">
  <h2>История</h2>
  <?php if (empty($selectedEntries)): ?>
    <p class="muted">Пока пусто.</p>
  <?php else: ?>
    <table>
      <tr><th>Когда</th><th>Что</th><th>Период</th><th>Сумма</th><th>Налог</th><th>Комментарий</th><th>Кто</th></tr>
      <?php foreach ($selectedEntries as $en): ?>
        <?php $amt = (float)$en['amount_usd']; ?>
        <tr>
          <td class="muted"><?= htmlspecialchars(substr((string)$en['datec'], 0, 16)) ?></td>
          <td><?= htmlspecialchars($typeLabels[$en['entry_type']] ?? $en['entry_type']) ?></td>
          <td class="muted"><?= htmlspecialchars($en['period'] ?? '') ?></td>
          <td style="white-space:nowrap; color:<?= $amt > 0 ? 'var(--ok)' : 'var(--danger)' ?>">
            <?= ($amt > 0 ? '+' : '') . number_format($amt, 2) ?> $
            <?php if (!empty($en['native_currency']) && $en['native_currency'] === 'UZS'): ?>
              <div class="muted" style="font-size:12px"><?= number_format((float)$en['native_amount'], 0, '.', ' ') ?> сум по курсу <?= rtrim(rtrim(number_format((float)$en['rate'], 2, '.', ''), '0'), '.') ?></div>
            <?php endif; ?>
          </td>
          <td class="muted"><?= (float)$en['tax_usd'] > 0.01 ? number_format((float)$en['tax_usd'], 2) . ' $' : '—' ?></td>
          <td class="muted"><?= htmlspecialchars($en['comment'] ?? '') ?></td>
          <td class="muted"><?= htmlspecialchars($en['who'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
// Переключение "наличные / на карту" — от валюты выбранного счёта, как в других разделах проекта.
(function () {
  const sel = document.getElementById('payAccount');
  if (!sel) return;
  const usdBlock = document.getElementById('usdBlock');
  const uzsBlock = document.getElementById('uzsBlock');
  function toggle() {
    const isUzs = sel.options[sel.selectedIndex].dataset.currency === 'UZS';
    usdBlock.style.display = isUzs ? 'none' : '';
    uzsBlock.style.display = isUzs ? '' : 'none';
  }
  sel.addEventListener('change', toggle);
  toggle();

  // Живой пересчёт: сколько получит сотрудник, сколько спишется, сколько из этого налог.
  const toCard = document.getElementById('uzsToCard');
  const rate = document.getElementById('uzsRate');
  const debited = document.getElementById('uzsDebited');
  const preview = document.getElementById('uzsPreview');
  function recalc() {
    const c = parseFloat(toCard.value) || 0;
    const r = parseFloat(rate.value) || 0;
    let d = parseFloat(debited.value) || 0;
    if (d <= 0) d = c;
    const receivedUsd = r > 0 ? c / r : 0;
    const debitedUsd = r > 0 ? d / r : 0;
    preview.innerHTML = 'Получит: <strong>' + receivedUsd.toFixed(2) + ' $</strong> · спишется: <strong>' +
      debitedUsd.toFixed(2) + ' $</strong> · налог: <strong>' + Math.max(0, debitedUsd - receivedUsd).toFixed(2) + ' $</strong>';
  }
  [toCard, rate, debited].forEach(i => i && i.addEventListener('input', recalc));
  recalc();
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
