<?php
/**
 * Передача денег между людьми — с подтверждением получателя (11.09.2026, решение пользователя:
 * «все передачи денег между сотрудниками и боссом подтверждались о принятии денег»).
 *
 * ОДИНАКОВАЯ копия лежит в TeplouxKassa, NodirTool и BossTool — правки вносить во все три.
 *
 * Как устроено. Отправитель создаёт передачу — деньги ПОКА НЕ ДВИГАЮТСЯ, в llx_bank ничего нет, есть
 * только строка llx_nt_cash_handover со статусом pending. Сумма резервируется: второй раз передать или
 * потратить её через передачу нельзя (handover_available). Получатель в своём инструменте видит
 * «Ждёт вашего подтверждения» и жмёт «Принял» — только тогда обе проводки (списание у отправителя,
 * зачисление получателю) пишутся одной транзакцией. «Не принимаю» (с причиной) или «Отменить» у
 * отправителя — деньги остаются у отправителя, в учёте ничего не было.
 *
 * Почему деньги не уходят «в путь» сразу: ответственность за наличные переходит в момент подтверждения.
 * Пока получатель не подтвердил, деньги числятся у отправителя — если они потерялись, это видно у него.
 *
 * При подтверждении остаток отправителя НЕ проверяется: наличные уже физически у получателя. Если
 * отправитель тем временем успел записать трату тех же денег, его касса уйдёт в минус — это видно и
 * говорится в сообщении, а не молча.
 *
 * Источник «Мои личные деньги» (только у руководителя): при подтверждении пишется вложение собственника
 * прямо на кассу получателя (llx_nt_owner_move, та же схема, что BossTool/includes/owner_moves.php).
 *
 * Передачи по счетам компании (пополнение банка руководителем) сюда не относятся — там подтверждать некому.
 */

function handover_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function handover_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    handover_db()->query("CREATE TABLE IF NOT EXISTS llx_nt_cash_handover (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        from_account INT NOT NULL,             -- 0, если источник — личные деньги руководителя
        to_account INT NOT NULL,
        amount DECIMAL(18,2) NOT NULL,
        currency VARCHAR(3) NOT NULL DEFAULT 'USD',
        source VARCHAR(8) NOT NULL DEFAULT 'cash',   -- cash | own (вложение собственника)
        from_who VARCHAR(100) NOT NULL,
        from_label VARCHAR(120) NOT NULL,
        to_label VARCHAR(120) NOT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',  -- pending | confirmed | rejected | cancelled
        datec DATETIME NOT NULL,
        decided_at DATETIME DEFAULT NULL,
        decided_by VARCHAR(100) DEFAULT NULL,
        reason VARCHAR(255) DEFAULT NULL,
        INDEX idx_to (to_account, status),
        INDEX idx_from (from_account, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function handover_balance(int $accountId): float
{
    $st = handover_db()->prepare("SELECT COALESCE(SUM(amount), 0) s FROM llx_bank WHERE fk_account = ? AND dateo <= NOW()");
    $st->bind_param('i', $accountId); $st->execute();
    $v = (float)$st->get_result()->fetch_assoc()['s']; $st->close();
    return round($v, 2);
}

/** Сколько с этого счёта уже передано и ждёт подтверждения. */
function handover_pending_out(int $accountId): float
{
    handover_ensure_table();
    $st = handover_db()->prepare("SELECT COALESCE(SUM(amount), 0) s FROM llx_nt_cash_handover WHERE from_account = ? AND status = 'pending' AND source = 'cash'");
    $st->bind_param('i', $accountId); $st->execute();
    $v = (float)$st->get_result()->fetch_assoc()['s']; $st->close();
    return round($v, 2);
}

/** Остаток минус то, что уже передано и ждёт подтверждения. */
function handover_available(int $accountId): float
{
    return round(handover_balance($accountId) - handover_pending_out($accountId), 2);
}

function handover_account_currency(int $accountId): string
{
    $x = handover_db()->query("SELECT currency_code FROM llx_bank_account WHERE rowid = " . (int)$accountId)->fetch_assoc();
    return strtoupper((string)($x['currency_code'] ?? '')) ?: 'USD';
}

/**
 * Создать передачу. $amount = null — «всё, что доступно» (так передаёт кассу продавец).
 * Возвращает ['ok'=>true,'id'=>..,'amount'=>..] или ['ok'=>false,'error'=>..].
 */
function handover_create(int $fromAccount, string $fromLabel, int $toAccount, string $toLabel, ?float $amount,
                         string $who, string $comment = '', string $source = 'cash'): array
{
    handover_ensure_table();
    if ($toAccount <= 0) return ['ok' => false, 'error' => 'Касса получателя не настроена — обратитесь к администратору.'];
    if ($source === 'cash' && $fromAccount === $toAccount) return ['ok' => false, 'error' => 'Нельзя передать самому себе.'];
    $cur = handover_account_currency($toAccount);
    if ($source === 'cash' && handover_account_currency($fromAccount) !== $cur) {
        return ['ok' => false, 'error' => 'Кассы в разных валютах — такая передача здесь не предусмотрена.'];
    }
    require_once __DIR__ . '/named_lock.php';
    if ($source === 'cash' && !acquire_named_lock('bank_transfer_acc_' . $fromAccount)) {
        return ['ok' => false, 'error' => 'С этой кассой сейчас идёт другая операция — подождите несколько секунд и повторите.'];
    }
    if ($source === 'cash') {
        $avail = handover_available($fromAccount);
        if ($amount === null) $amount = $avail;
        $amount = round((float)$amount, 2);
        if ($amount <= 0.001) {
            $pend = handover_pending_out($fromAccount);
            return ['ok' => false, 'error' => $pend > 0 ? 'Всё, что было в кассе, уже передано и ждёт подтверждения.' : 'Передавать нечего — укажите сумму больше нуля.'];
        }
        if ($amount > $avail + 0.001) {
            $pend = handover_pending_out($fromAccount);
            return ['ok' => false, 'error' => 'Можно передать не больше ' . number_format($avail, 2, '.', ' ') . ' ' . $cur
                . ($pend > 0 ? ' (ещё ' . number_format($pend, 2, '.', ' ') . ' уже передано и ждёт подтверждения)' : '') . '.'];
        }
    } else {
        $amount = round((float)$amount, 2);
        if ($amount <= 0.001) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
        $fromAccount = 0;
    }
    $now = date('Y-m-d H:i:s');
    $st = handover_db()->prepare("INSERT INTO llx_nt_cash_handover (from_account, to_account, amount, currency, source, from_who, from_label, to_label, comment, status, datec)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)");
    $st->bind_param('iidsssssss', $fromAccount, $toAccount, $amount, $cur, $source, $who, $fromLabel, $toLabel, $comment, $now);
    $st->execute(); $id = (int)handover_db()->insert_id; $st->close();
    return ['ok' => true, 'id' => $id, 'amount' => $amount, 'currency' => $cur];
}

/** Передачи: входящие на эти счета / исходящие с этих счетов. $statuses — какие показать. */
function handover_list(array $toAccounts = [], array $fromAccounts = [], array $statuses = ['pending'], int $limit = 50, ?string $fromWho = null): array
{
    handover_ensure_table();
    $w = [];
    if ($toAccounts) $w[] = 'to_account IN (' . implode(',', array_map('intval', $toAccounts)) . ')';
    if ($fromAccounts) $w[] = 'from_account IN (' . implode(',', array_map('intval', $fromAccounts)) . ')';
    if ($fromWho !== null) $w[] = "from_who = '" . handover_db()->real_escape_string($fromWho) . "'";
    if (!$w) return [];
    $s = implode(',', array_map(fn($x) => "'" . handover_db()->real_escape_string($x) . "'", $statuses));
    return handover_db()->query("SELECT * FROM llx_nt_cash_handover WHERE (" . implode(' OR ', $w) . ") AND status IN ($s)
                                 ORDER BY rowid DESC LIMIT " . (int)$limit)->fetch_all(MYSQLI_ASSOC);
}

/**
 * Получатель подтверждает. $myAccounts — счета, за которые отвечает вошедший (проверка, что это ЕГО
 * передача). $userId — служебный пользователь Dolibarr инструмента (автор проводок).
 */
function handover_confirm(int $id, array $myAccounts, string $who, int $userId): array
{
    handover_ensure_table();
    $db = handover_db();
    require_once __DIR__ . '/named_lock.php';
    if (!acquire_named_lock('handover_' . $id)) return ['ok' => false, 'error' => 'Эта передача сейчас обрабатывается — повторите через несколько секунд.'];
    $h = $db->query("SELECT * FROM llx_nt_cash_handover WHERE rowid = " . (int)$id)->fetch_assoc();
    if (!$h || !in_array((int)$h['to_account'], array_map('intval', $myAccounts), true)) return ['ok' => false, 'error' => 'Передача не найдена.'];
    if ($h['status'] !== 'pending') return ['ok' => false, 'error' => 'Эта передача уже ' . handover_status_label($h['status']) . '.'];

    $amount = (float)$h['amount']; $now = date('Y-m-d H:i:s'); $today = date('Y-m-d');
    $suffix = ($h['comment'] ? ' — ' . $h['comment'] : '') . ' [подтвердил ' . $who . ']';
    $warn = '';
    try {
        $db->begin_transaction();
        $ins = $db->prepare("INSERT INTO llx_bank (datec, dateo, datev, amount, label, fk_account, fk_type, fk_user_author, rappro, numero_compte)
                             VALUES (?, ?, ?, ?, ?, ?, 'LIQ', ?, 0, '')");
        if ($h['source'] === 'own') {
            // личные деньги руководителя → сразу на кассу получателя, как вложение собственника
            $lbl = 'Собственник: вложение (' . $h['from_who'] . ') — передано: ' . $h['to_label'] . $suffix;
            $to = (int)$h['to_account'];
            $ins->bind_param('sssdsii', $now, $today, $today, $amount, $lbl, $to, $userId); $ins->execute();
            $bankId = (int)$db->insert_id;
            $db->query("CREATE TABLE IF NOT EXISTS llx_nt_owner_move (rowid INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(3) NOT NULL,
                amount DECIMAL(18,2) NOT NULL, currency VARCHAR(3) NOT NULL, rate DECIMAL(18,6) DEFAULT NULL, amount_usd DECIMAL(18,2) NOT NULL,
                fk_account INT NOT NULL, fk_bank INT NOT NULL, comment VARCHAR(255) DEFAULT NULL, who VARCHAR(100) NOT NULL, datec DATETIME NOT NULL,
                INDEX idx_kind (kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $om = $db->prepare("INSERT INTO llx_nt_owner_move (kind, amount, currency, rate, amount_usd, fk_account, fk_bank, comment, who, datec)
                                VALUES ('in', ?, ?, NULL, ?, ?, ?, ?, ?, ?)");
            $c = 'передано: ' . $h['to_label']; $cur = $h['currency']; $fw = $h['from_who'];
            $om->bind_param('dsdiisss', $amount, $cur, $amount, $to, $bankId, $c, $fw, $now); $om->execute(); $om->close();
        } else {
            $from = (int)$h['from_account']; $to = (int)$h['to_account'];
            $bal = handover_balance($from);
            if ($bal + 0.001 < $amount) $warn = 'ВНИМАНИЕ: у отправителя в кассе сейчас только ' . number_format($bal, 2, '.', ' ')
                . ' — после подтверждения его касса ушла в минус (похоже, эти деньги у него записаны ещё и как трата). ';
            $out = -$amount;
            $lo = 'Передача: ' . $h['to_label'] . ' (' . $h['from_who'] . ')' . $suffix;
            $li = 'Получено от: ' . $h['from_who'] . ' (' . $h['from_label'] . ')' . $suffix;
            $ins->bind_param('sssdsii', $now, $today, $today, $out, $lo, $from, $userId); $ins->execute();
            $ins->bind_param('sssdsii', $now, $today, $today, $amount, $li, $to, $userId); $ins->execute();
            $bankId = (int)$db->insert_id;
        }
        $ins->close();
        $st = $db->prepare("UPDATE llx_nt_cash_handover SET status = 'confirmed', decided_at = ?, decided_by = ? WHERE rowid = ? AND status = 'pending'");
        $st->bind_param('ssi', $now, $who, $id); $st->execute(); $st->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'error' => 'Не подтверждено, деньги на месте: ' . $e->getMessage()];
    }
    return ['ok' => true, 'warning' => $warn, 'amount' => $amount, 'currency' => $h['currency'], 'from_who' => $h['from_who'], 'bank_id' => $bankId];
}

/** Получатель не принимает (с причиной) — деньги остаются у отправителя. */
function handover_reject(int $id, array $myAccounts, string $who, string $reason): array
{
    handover_ensure_table();
    if (trim($reason) === '') return ['ok' => false, 'error' => 'Напишите причину — её увидит отправитель.'];
    $db = handover_db();
    $h = $db->query("SELECT * FROM llx_nt_cash_handover WHERE rowid = " . (int)$id)->fetch_assoc();
    if (!$h || !in_array((int)$h['to_account'], array_map('intval', $myAccounts), true)) return ['ok' => false, 'error' => 'Передача не найдена.'];
    if ($h['status'] !== 'pending') return ['ok' => false, 'error' => 'Эта передача уже ' . handover_status_label($h['status']) . '.'];
    $st = $db->prepare("UPDATE llx_nt_cash_handover SET status = 'rejected', decided_at = NOW(), decided_by = ?, reason = ? WHERE rowid = ? AND status = 'pending'");
    $st->bind_param('ssi', $who, $reason, $id); $st->execute(); $ok = $st->affected_rows > 0; $st->close();
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Не удалось — обновите страницу.'];
}

/** Отправитель отменяет свою ещё не подтверждённую передачу. */
function handover_cancel(int $id, array $myFromAccounts, string $who, ?string $fromWho = null): array
{
    handover_ensure_table();
    $db = handover_db();
    $h = $db->query("SELECT * FROM llx_nt_cash_handover WHERE rowid = " . (int)$id)->fetch_assoc();
    $mine = $h && (in_array((int)$h['from_account'], array_map('intval', $myFromAccounts), true)
                   || ($h['source'] === 'own' && $fromWho !== null && $h['from_who'] === $fromWho));
    if (!$mine) return ['ok' => false, 'error' => 'Передача не найдена.'];
    if ($h['status'] !== 'pending') return ['ok' => false, 'error' => 'Эта передача уже ' . handover_status_label($h['status']) . ' — отменить нельзя.'];
    $st = $db->prepare("UPDATE llx_nt_cash_handover SET status = 'cancelled', decided_at = NOW(), decided_by = ? WHERE rowid = ? AND status = 'pending'");
    $st->bind_param('si', $who, $id); $st->execute(); $ok = $st->affected_rows > 0; $st->close();
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Не удалось — обновите страницу.'];
}

function handover_status_label(string $s): string
{
    return ['pending' => 'ждёт подтверждения', 'confirmed' => 'подтверждена', 'rejected' => 'не принята', 'cancelled' => 'отменена'][$s] ?? $s;
}

/**
 * Готовый блок для страницы: входящие (Принял / Не принимаю) и исходящие (ждёт / отменить / отказ).
 * $in, $out — строки handover_list(); $csrf — csrf_field(); $moneyFn — функция форматирования денег.
 * Формы шлют action=handover_confirm|handover_reject|handover_cancel и handover_id на текущую страницу.
 */
function handover_render(array $in, array $out, string $csrf, callable $moneyFn): string
{
    if (!$in && !$out) return '';
    ob_start(); ?>
    <?php if ($in): ?>
    <div class="card" style="border:2px solid #f59e0b">
      <h2>Ждут вашего подтверждения</h2>
      <p class="muted" style="margin-top:0">Вам передали деньги. Пересчитайте и нажмите «Принял» — только тогда сумма
        зачислится в вашу кассу. Если денег нет или сумма другая — «Не принимаю» и причина, отправитель её увидит.</p>
      <table>
        <tr><th>Когда</th><th>От кого</th><th class="num">Сумма</th><th>Комментарий</th><th></th></tr>
        <?php foreach ($in as $h): ?>
          <tr>
            <td class="muted"><?= date('d.m.Y H:i', strtotime($h['datec'])) ?></td>
            <td><?= htmlspecialchars($h['from_who']) ?><?= $h['source'] === 'own' ? ' <span class="muted">(личные деньги)</span>' : '' ?></td>
            <td class="num"><strong><?= htmlspecialchars($moneyFn((float)$h['amount'], $h['currency'])) ?></strong></td>
            <td class="muted"><?= htmlspecialchars((string)$h['comment']) ?></td>
            <td style="white-space:nowrap">
              <form method="post" style="display:inline" onsubmit="return appConfirmSubmit(this, 'Подтвердить, что вы получили <?= htmlspecialchars($moneyFn((float)$h['amount'], $h['currency']), ENT_QUOTES) ?> от <?= htmlspecialchars(addslashes($h['from_who']), ENT_QUOTES) ?>?');">
                <?= $csrf ?><input type="hidden" name="action" value="handover_confirm"><input type="hidden" name="handover_id" value="<?= (int)$h['rowid'] ?>">
                <button type="submit" class="small">Принял</button>
              </form>
              <details style="display:inline-block"><summary class="muted" style="cursor:pointer; display:inline">Не принимаю</summary>
                <form method="post" style="margin-top:6px">
                  <?= $csrf ?><input type="hidden" name="action" value="handover_reject"><input type="hidden" name="handover_id" value="<?= (int)$h['rowid'] ?>">
                  <input type="text" name="reason" placeholder="причина: денег не получил, сумма другая…" required style="min-width:240px">
                  <button type="submit" class="secondary small">Отказать</button>
                </form></details>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($out): ?>
    <div class="card">
      <h2>Переданные мной деньги</h2>
      <table>
        <tr><th>Когда</th><th>Кому</th><th class="num">Сумма</th><th>Положение</th><th></th></tr>
        <?php foreach ($out as $h): ?>
          <tr>
            <td class="muted"><?= date('d.m.Y H:i', strtotime($h['datec'])) ?></td>
            <td><?= htmlspecialchars($h['to_label']) ?></td>
            <td class="num"><?= htmlspecialchars($moneyFn((float)$h['amount'], $h['currency'])) ?></td>
            <td><?php if ($h['status'] === 'pending'): ?><span class="badge badge-warn">ждёт подтверждения</span>
                <?php elseif ($h['status'] === 'rejected'): ?><span class="badge badge-debt">не принята</span>
                  <div class="muted" style="font-size:12px"><?= htmlspecialchars((string)$h['decided_by']) ?>: <?= htmlspecialchars((string)$h['reason']) ?> — деньги остались у вас</div>
                <?php elseif ($h['status'] === 'confirmed'): ?><span class="badge badge-ok">принята</span>
                  <div class="muted" style="font-size:12px"><?= htmlspecialchars((string)$h['decided_by']) ?>, <?= date('d.m H:i', strtotime((string)$h['decided_at'])) ?></div>
                <?php else: ?><span class="muted">отменена</span><?php endif; ?></td>
            <td><?php if ($h['status'] === 'pending'): ?>
              <form method="post" style="display:inline" onsubmit="return appConfirmSubmit(this, 'Отменить передачу? Деньги остаются у вас.');">
                <?= $csrf ?><input type="hidden" name="action" value="handover_cancel"><input type="hidden" name="handover_id" value="<?= (int)$h['rowid'] ?>">
                <button type="submit" class="secondary small">Отменить</button>
              </form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
    <?php return ob_get_clean();
}

/**
 * Обработка трёх действий формы из handover_render(). Возвращает [message, type] или null, если действие
 * не про передачи. $myAccounts — счета вошедшего; $fromWho — его имя (для отмены «личных» передач руководителя).
 */
function handover_handle_post(array $post, array $myAccounts, string $who, int $userId, callable $moneyFn): ?array
{
    $a = $post['action'] ?? '';
    $id = (int)($post['handover_id'] ?? 0);
    if ($a === 'handover_confirm') {
        $r = handover_confirm($id, $myAccounts, $who, $userId);
        return $r['ok'] ? [($r['warning'] ?? '') . 'Принято: ' . $moneyFn($r['amount'], $r['currency']) . ' от ' . $r['from_who'] . ' — зачислено в вашу кассу.', !empty($r['warning']) ? 'warn' : 'ok']
                        : [$r['error'], 'err'];
    }
    if ($a === 'handover_reject') {
        $r = handover_reject($id, $myAccounts, $who, trim((string)($post['reason'] ?? '')));
        return $r['ok'] ? ['Передача не принята — отправитель увидит причину, деньги остаются у него.', 'ok'] : [$r['error'], 'err'];
    }
    if ($a === 'handover_cancel') {
        $r = handover_cancel($id, $myAccounts, $who, $who);
        return $r['ok'] ? ['Передача отменена — деньги остаются у вас.', 'ok'] : [$r['error'], 'err'];
    }
    return null;
}
