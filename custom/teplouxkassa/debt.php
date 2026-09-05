<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_split.php';

if (empty($_SESSION['debt_client'])) $_SESSION['debt_client'] = null;

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — всегда сбрасывает
// выбранного клиента, чтобы снова видеть блоки должников, а не "застревать" на одном клиенте.
reset_selection_unless_preserved('debt_client');

$message = '';
$messageType = '';
$showReceiptLink = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!select_client_for_direction($api, $cfg, 'debt_client', $clientId, $_POST['client_name'] ?? '')) {
            $message = 'Клиент не найден или относится к другому направлению.';
            $messageType = 'err';
        }
    } elseif ($action === 'clear_client') {
        $_SESSION['debt_client'] = null;
    } elseif ($action === 'pay_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        // Сумму берём ДО оплаты (пришла из формы вместе со списком счетов) — надёжнее, чем
        // перезапрашивать счёт после оплаты (там уже может не быть remaintopay, если закрылся).
        $invRef = $_POST['invoice_ref'] ?? "#$invoiceId";
        $invAmount = (float)($_POST['invoice_amount'] ?? 0);

        // Оплата может быть разбита сразу по нескольким способам (часть наличными, часть картой и т.п.)
        $paySplitResult = resolvePaySplit($cfg, $_POST);
        $paySplit = $paySplitResult['amounts'];
        $payDetail = $paySplitResult['detail'];
        $paidSum = array_sum($paySplit);

        // K-5 (внешний QA-аудит, раунд 2, 03.09.2026): раньше invoice_id принимался из формы без
        // проверки, что счёт вообще принадлежит ВЫБРАННОМУ клиенту (и, тем самым, вашему направлению —
        // sale_client всегда одного направления, см. select_client_for_direction()). Прямым POST можно
        // было оплатить долг клиента ЧУЖОГО направления в свою кассу. Перепроверяем заново на сервере.
        $srcInvoiceForCheck = $invoiceId ? $api->getInvoice($invoiceId) : null;
        $invoiceOwnerOk = is_array($srcInvoiceForCheck)
            && (int)($srcInvoiceForCheck['socid'] ?? 0) === (int)($_SESSION['debt_client']['id'] ?? 0)
            && (int)($srcInvoiceForCheck['type'] ?? -1) === 0;

        if (!$invoiceOwnerOk) {
            $message = 'Этот счёт не найден или не принадлежит выбранному клиенту.';
            $messageType = 'err';
        } elseif ($paySplitResult['errors']) {
            $message = implode(' ', $paySplitResult['errors']);
            $messageType = 'err';
        } elseif (!$invoiceId || empty($paySplit)) {
            $message = 'Укажите сумму хотя бы по одному способу оплаты.';
            $messageType = 'err';
        } elseif (abs($paidSum - $invAmount) > 0.01) {
            $message = "Сумма по способам оплаты ({$paidSum}) не совпадает с суммой к оплате ({$invAmount}).";
            $messageType = 'err';
        } elseif (($netDebtNow = (float)(($api->getOutstandingInvoices((int)$_SESSION['debt_client']['id'])['opened']) ?? 0)) < $paidSum - 0.01) {
            // K-1 (внешняя приёмка, 03.09.2026): счёт может висеть с полной суммой, хотя клиент уже
            // рассчитался — возврат уменьшает ОБЩИЙ долг клиента, но не гасит конкретный счёт. Кассир,
            // закрывая такую строку, брал деньги за долг, которого нет, будучи уверенным, что просто
            // гасит счёт. Ограничиваем приём РЕАЛЬНЫМ долгом клиента (пересчитан заново на сервере).
            // Аванс при этом НЕ запрещён — просто оформляется осознанно, в разделе "Аванс".
            $message = $netDebtNow <= 0.01
                ? "У клиента нет долга (общий баланс: " . number_format($netDebtNow, 2) . " \$) — этот счёт уже перекрыт возвратами или авансом. Принимать оплату по нему не нужно. Если клиент оставляет деньги вперёд — оформите через раздел «Аванс»."
                : "Реальный долг клиента — " . number_format($netDebtNow, 2) . " \$, это меньше суммы по счёту (" . number_format($paidSum, 2) . " \$): часть уже перекрыта возвратами или авансом. Примите " . number_format($netDebtNow, 2) . " \$ через «Принять оплату» ниже; излишек, если клиент его оставляет, — через раздел «Аванс».";
            $messageType = 'err';
        } else {
            $payErrors = [];
            $receiptItems = [];
            foreach ($paySplit as $key => $amt) {
                $acc = $cfg['payment_accounts'][$key];
                $label = paySplitLabel($acc['label'], $payDetail[$key]);
                $res = $api->addPaymentDistributed([$invoiceId => number_format($amt, 2, '.', '')], $acc['paytype'], $acc['id'], 'Касса ' . $cfg['direction_label'] . ' — погашение долга — ' . $label);
                if ($res === null) {
                    $payErrors[] = "{$acc['label']}: {$api->lastError}";
                } else {
                    $receiptItems[] = ['ref' => $invRef, 'method' => $acc['label'], 'amount' => $amt, 'uzs' => $payDetail[$key]['uzs'], 'rate' => $payDetail[$key]['rate']];
                }
            }
            if ($payErrors) {
                $message = 'Ошибка приёма оплаты: ' . implode('; ', $payErrors);
                $messageType = 'err';
            } else {
                $uzsErrors = postUzsLedger($api, $cfg, $payDetail, 'Погашение долга по счёту #' . $invoiceId . ', касса ' . $cfg['direction_label']);
                $message = "Оплата по счёту #$invoiceId принята: " . implode(' + ', array_map(fn($i) => paySplitLabel($i['method'], ['usd' => $i['amount'], 'uzs' => $i['uzs'], 'rate' => $i['rate']]), $receiptItems)) . ".";
                if ($uzsErrors) $message .= "\nВНИМАНИЕ, сумовый счёт: " . implode('; ', $uzsErrors);
                $messageType = $uzsErrors ? 'err' : 'ok';
                $_SESSION['last_receipt'] = [
                    'client_name' => $_SESSION['debt_client']['name'] ?? '',
                    'items' => $receiptItems,
                    'total' => $paidSum,
                    'date' => time(),
                ];
                $showReceiptLink = true;
                // Оплата уже реально проведена — редирект (POST → GET), чтобы F5 не отправил эту же
                // форму повторно и не оплатил счёт дважды.
                flash_set($message, $messageType, ['show_receipt_link' => true]);
                header('Location: debt.php');
                exit;
            }
        }
    } elseif ($action === 'receive_payment') {
        // Оплата может быть разбита сразу по нескольким способам — берём все положительные суммы.
        $paySplitResult = resolvePaySplit($cfg, $_POST);
        $paySplit = $paySplitResult['amounts'];
        $payDetail = $paySplitResult['detail'];

        if ($paySplitResult['errors']) {
            $message = implode(' ', $paySplitResult['errors']);
            $messageType = 'err';
        } elseif (empty($_SESSION['debt_client'])) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif (empty($paySplit)) {
            $message = 'Введите сумму хотя бы по одному способу оплаты.';
            $messageType = 'err';
        } else {
            // свежий список неоплаченных счетов клиента — считаем FIFO прямо перед оплатой. Одним
            // запросом со всеми нужными полями, не getInvoice() на каждый счёт (см. отчёт ревью P0#5).
            $rows = $api->getUnpaidInvoicesForClient((int)$_SESSION['debt_client']['id']);
            $fifoList = [];
            if (is_array($rows)) {
                foreach ($rows as $inv) {
                    $fifoList[(int)$inv['id']] = [
                        'ref' => $inv['ref'] ?? '',
                        'date_raw' => (int)($inv['date'] ?? 0),
                        'remaintopay' => (float)($inv['remaintopay'] ?? $inv['total_ttc'] ?? 0),
                    ];
                }
            }
            uasort($fifoList, fn($a, $b) => $a['date_raw'] <=> $b['date_raw']);

            // K-1 (внешняя приёмка, 03.09.2026): суммарный остаток по СТРОКАМ счетов может быть больше
            // РЕАЛЬНОГО долга клиента — возврат/аванс уменьшают общий баланс, но не гасят конкретные
            // счета. Без ограничения FIFO разнёс бы всю введённую сумму по этим строкам и принял деньги
            // за уже несуществующий долг. Общий "бюджет применения" = реальный долг клиента; всё, что
            // сверх — не применяется к счетам (уходит в $leftover, кассир видит явное сообщение).
            $netDebtNow = (float)(($api->getOutstandingInvoices((int)$_SESSION['debt_client']['id'])['opened']) ?? 0);
            $applyBudget = max(0.0, $netDebtNow);

            // Каждый способ оплаты "тратится" по FIFO, продолжая с того места, где остановился
            // предыдущий способ (остатки по счетам уменьшаются последовательно во всех методах сразу).
            $payErrors = [];
            $receiptItems = [];
            $totalApplied = 0.0;
            $leftover = 0.0;
            // S-2: остаток по КАЖДОМУ доллар-способу отдельно (не общей суммой) — у разных способов
            // разные кассовые счета, излишек каждого должен лечь именно на СВОЙ счёт.
            $cashLeftoverByMethod = [];

            foreach ($paySplit as $key => $methodAmount) {
                $acc = $cfg['payment_accounts'][$key];
                $remaining = $methodAmount;
                $arrayofamounts = [];
                foreach ($fifoList as $invId => &$inv) {
                    if ($remaining <= 0.01 || $applyBudget <= 0.01) break; // K-1: не применяем сверх реального долга
                    if ($inv['remaintopay'] <= 0.01) continue;
                    $apply = min($remaining, $inv['remaintopay'], $applyBudget);
                    if ($apply <= 0.01) break;
                    $arrayofamounts[$invId] = number_format($apply, 2, '.', '');
                    $inv['remaintopay'] -= $apply;
                    $remaining -= $apply;
                    $applyBudget -= $apply;
                }
                unset($inv);
                $leftover += $remaining;
                if ($remaining > 0.01 && ($payDetail[$key]['uzs'] ?? null) === null) {
                    // Способ в USD (наличные) — сумовые уже полностью покрыты postUzsLedger() ниже
                    // независимо от применения, см. S-2.
                    $cashLeftoverByMethod[$key] = $remaining;
                }

                if (empty($arrayofamounts)) continue; // этому способу уже нечего было гасить

                $methodLabel = paySplitLabel($acc['label'], $payDetail[$key]);
                $res = $api->addPaymentDistributed($arrayofamounts, $acc['paytype'], $acc['id'], 'Касса ' . $cfg['direction_label'] . ' — приём оплаты (FIFO) — ' . $methodLabel);
                if ($res === null) {
                    $payErrors[] = "{$acc['label']}: {$api->lastError}";
                    continue;
                }
                foreach ($arrayofamounts as $invId => $amt) {
                    $receiptItems[] = [
                        'ref' => $fifoList[$invId]['ref'] ?? "#$invId", 'method' => $acc['label'], 'amount' => (float)$amt,
                        'uzs' => $payDetail[$key]['uzs'], 'rate' => $payDetail[$key]['rate'],
                    ];
                    $totalApplied += (float)$amt;
                }
            }

            if ($payErrors) {
                $message = 'Ошибка приёма оплаты: ' . implode('; ', $payErrors);
                $messageType = 'err';
            } elseif (empty($receiptItems)) {
                $message = 'У клиента нет неоплаченных счетов — оплату применить не к чему.';
                $messageType = 'err';
            } else {
                // Реальные суммы в сумах кладём на сумовый счёт независимо от того, как они легли на
                // счета клиента — деньги физически поступили в банк в любом случае, даже если часть
                // оказалась "лишней" сверх долга.
                $uzsErrors = postUzsLedger($api, $cfg, $payDetail, 'Приём оплаты (FIFO), касса ' . $cfg['direction_label'] . ' — ' . ($_SESSION['debt_client']['name'] ?? ''));
                // S-2 (внешний QA-аудит, раунд 2, 03.09.2026): излишек наличных (сверх долга) раньше
                // нигде не оседал — кассир реально принял деньги, а касса в Dolibarr их не видела.
                // Кладём напрямую на кассовый счёт направления, отдельной проводкой, как обычную
                // переплату (не привязана к конкретному счёту-долгу — просто честно лежит на кассе).
                $cashOverageErrors = postCashOverage($api, $cfg, $cashLeftoverByMethod, 'Излишек наличных сверх долга (FIFO), касса ' . $cfg['direction_label'] . ' — ' . ($_SESSION['debt_client']['name'] ?? ''));
                $message = "Оплата принята: " . number_format($totalApplied, 2) . " \$ распределено по " . count($receiptItems) . " платёж(ам) — сначала самые старые счета.";
                if ($leftover > 0.01) {
                    // K-1: излишек физически принят (деньги на руках у кассира) и зачислен в кассу, но
                    // к долгу НЕ применён — долга на эту сумму нет. Явно направляем оформить его авансом,
                    // чтобы у клиента этот остаток был виден как его деньги, а не просто лежал в кассе.
                    $message .= " Внесено больше реального долга — излишек " . number_format($leftover, 2) . " \$ зачислен в кассу, но к счетам не применён (долга на эту сумму нет). "
                        . "Если клиент оставляет эти деньги вперёд — оформите их в разделе «Аванс», тогда они будут числиться за ним.";
                }
                if ($uzsErrors) $message .= "\nВНИМАНИЕ, сумовый счёт: " . implode('; ', $uzsErrors);
                if ($cashOverageErrors) $message .= "\nВНИМАНИЕ, излишек наличных не зачислен в кассу: " . implode('; ', $cashOverageErrors);
                $messageType = ($uzsErrors || $cashOverageErrors) ? 'err' : 'ok';
                $_SESSION['last_receipt'] = [
                    'client_name' => $_SESSION['debt_client']['name'] ?? '',
                    'items' => $receiptItems,
                    'total' => $totalApplied,
                    'date' => time(),
                ];
                $showReceiptLink = true;
                // Оплата уже реально проведена (по FIFO) — редирект (POST → GET), чтобы F5 не отправил
                // эту же форму повторно и не разнёс сумму по счетам ещё раз.
                flash_set($message, $messageType, ['show_receipt_link' => true]);
                header('Location: debt.php');
                exit;
            }
        }
    } elseif ($action === 'handover_cash') {
        $cashAcc = $cfg['payment_accounts']['cash'] ?? null;
        if (!$cashAcc) {
            $message = 'Не настроен кассовый счёт наличных.';
            $messageType = 'err';
        } else {
            $balance = $api->getAccountBalance($cashAcc['id']);
            if ($balance === null) {
                $message = 'Не удалось получить остаток кассы: ' . $api->lastError;
                $messageType = 'err';
            } elseif ($balance <= 0.01) {
                $message = 'В кассе и так пусто — нечего передавать.';
                $messageType = 'err';
            } else {
                $recipient = trim($_POST['recipient'] ?? '') ?: ($cfg['cash_recipient_label'] ?? '');
                $recipientAccId = $cfg['cash_recipient_account_id'] ?? null;
                $label = 'Передача наличной кассы ' . $cfg['direction_label'] . ($recipient !== '' ? (' — принял: ' . $recipient) : '');
                // Списание со счёта кассира — обязательная часть, без неё ничего не делаем.
                $out = $api->addBankLine($cashAcc['id'], $label, -1 * $balance, 'LIQ');
                if ($out === null) {
                    $message = 'Ошибка при передаче кассы: ' . $api->lastError;
                    $messageType = 'err';
                } else {
                    // Настоящий перевод — деньги должны появиться на счёте того, кто их принял, а не
                    // просто исчезнуть. Если счёт получателя не настроен — списание уже произошло,
                    // явно предупреждаем, а не проваливаем всю операцию задним числом.
                    $inWarning = '';
                    if ($recipientAccId) {
                        $in = $api->addBankLine((int)$recipientAccId, $label, $balance, 'LIQ');
                        if ($in === null) {
                            $inWarning = ' ВНИМАНИЕ: списано со счёта кассира, но НЕ зачислено получателю: ' . $api->lastError . '. Поправьте вручную.';
                        }
                    } else {
                        $inWarning = ' ВНИМАНИЕ: счёт получателя не настроен — деньги списаны, но никуда не зачислены.';
                    }
                    $message = 'Касса передана: ' . number_format($balance, 2) . ' $' . ($recipient !== '' ? (' → ' . $recipient) : '') . '. Остаток обнулён.' . $inWarning;
                    $messageType = $inWarning ? 'err' : 'ok';
                }
            }
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
    $showReceiptLink = !empty($flash['extra']['show_receipt_link']);
}

// текущий остаток наличных — запрашивается ПОСЛЕ обработки действий выше, чтобы после
// "передачи кассы" сразу показать обнулённый остаток, не дожидаясь отдельной перезагрузки
$cashAcc = $cfg['payment_accounts']['cash'] ?? null;
$cashBalance = $cashAcc ? $api->getAccountBalance($cashAcc['id']) : null;

$debtors = [];
if (empty($_SESSION['debt_client'])) {
    $rows = $api->getUnpaidInvoicesForDirection($cfg['ref_prefix']);
    if (is_array($rows)) {
        $bySoc = [];
        foreach ($rows as $row) {
            $socid = (int)($row['socid'] ?? 0);
            if (!$socid) continue;
            $bySoc[$socid] = ($bySoc[$socid] ?? 0) + (float)($row['remaintopay'] ?? 0);
        }
        // отсекаем тех, кто в сумме не должен (возвраты перекрыли долг)
        $bySoc = array_filter($bySoc, fn($sum) => $sum > 0.01);
        arsort($bySoc);
        // Имена всех должников — ОДНИМ запросом, не по одному в цикле (см. отчёт ревью P0#5).
        $socNames = $api->getThirdpartiesByIds(array_keys($bySoc));
        foreach ($bySoc as $socid => $sum) {
            $soc = $socNames[$socid] ?? null;
            $debtors[] = [
                'id' => $socid,
                'name' => is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? "#{$socid}") : "#{$socid}",
                'debt' => $sum,
            ];
        }
    }
}

$unpaidInvoices = [];
$totalDebt = null;
if ($_SESSION['debt_client']) {
    $out = $api->getOutstandingInvoices((int)$_SESSION['debt_client']['id']);
    if (is_array($out)) {
        $totalDebt = (float)($out['opened'] ?? 0);
    }
    // Неоплаченные счета ЭТОГО клиента — одним запросом со всеми нужными полями (ref/дата/суммы),
    // раньше был getInvoice() на каждый счёт отдельно (см. отчёт ревью P0#5).
    $rows = $api->getUnpaidInvoicesForClient((int)$_SESSION['debt_client']['id']);
    // K-1 (внешняя приёмка, 03.09.2026) — сумма возвратов/авансов, которые уменьшают общий долг
    // клиента, но НЕ гасят конкретные счета (Dolibarr не переносит кредит-ноту на счёт автоматически).
    // Раньше эти строки просто скрывались (BUG-K3, 02.09.2026) — кнопки "Оплатить" на отрицательной
    // сумме больше не было, но и сумма таблицы перестала сходиться с бейджем "Долг" наверху: кассир
    // видел непогашенные строки на большую сумму, чем клиент реально должен, и, закрывая их по одной,
    // брал лишние деньги, будучи уверенным, что просто гасит долг. Теперь показываем их отдельной
    // строкой (без кнопки оплаты) — числа на экране сходятся и видно, почему.
    $creditsTotal = 0.0;
    if (is_array($rows)) {
        foreach ($rows as $inv) {
            $remaining = (float)($inv['remaintopay'] ?? $inv['total_ttc'] ?? 0);
            if ($remaining <= 0.01) { $creditsTotal += $remaining; continue; }
            $unpaidInvoices[] = [
                'id' => (int)$inv['id'],
                'ref' => $inv['ref'] ?? '',
                'date_raw' => (int)($inv['date'] ?? 0),
                'date' => !empty($inv['date']) ? date('d.m.Y', (int)$inv['date']) : '',
                'total_ttc' => (float)($inv['total_ttc'] ?? 0),
                'remaintopay' => $remaining,
            ];
        }
        // самые старые счета — сверху (тот же порядок, в котором их закроет FIFO-оплата)
        usort($unpaidInvoices, fn($a, $b) => $a['date_raw'] <=> $b['date_raw'] ?: ($a['id'] <=> $b['id']));
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Касса — приём оплаты по долгу</h1>
<p class="muted">Клиент раньше взял товар без оплаты ("в долг") — здесь можно принять оплату отдельно, без новой продажи.</p>
<?php if ($_SESSION['debt_client']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_client">
    <button type="submit" class="secondary">← Назад к списку должников</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>
<?php if ($showReceiptLink): ?>
  <p><a class="btn secondary" href="payment_receipt_excel.php">🧾 Распечатать квитанцию о приёме денег (Excel)</a></p>
<?php endif; ?>

<div class="card">
  <h2>Наличная касса — <?= htmlspecialchars($cfg['direction_label']) ?></h2>
  <?php if ($cashBalance === null): ?>
    <p class="err">Не удалось получить остаток наличных: <?= htmlspecialchars($api->lastError) ?></p>
  <?php else: ?>
    <div class="row" style="align-items:center">
      <div>
        <div style="font-size:26px; font-weight:700;"><?= number_format($cashBalance, 2) ?> $</div>
        <div class="muted">наличными в кассе сейчас</div>
      </div>
      <?php if ($cashBalance > 0.01): ?>
      <form method="post" style="flex:0; display:flex; gap:8px; align-items:center"
            onsubmit="return appConfirmSubmit(this, 'Передать кассу — обнулить остаток наличных (<?= number_format($cashBalance, 2) ?> $)?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="handover_cash">
        <input type="text" name="recipient" placeholder="Кому передано"
               value="<?= htmlspecialchars($cfg['cash_recipient_label'] ?? '') ?>" style="min-width:150px; margin:0">
        <button type="submit" class="danger">Передать кассу</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Клиент</h2>
  <?php if ($_SESSION['debt_client']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['debt_client']['name']) ?></strong>
        <?php if ($totalDebt !== null): ?>
          <div><span class="badge <?= $totalDebt > 0.01 ? 'badge-debt' : 'badge-ok' ?>">
            <?php if ($totalDebt > 0.01): ?>Долг: <?= number_format($totalDebt, 2) ?> $
            <?php elseif ($totalDebt < -0.01): ?>Аванс/переплата: <?= number_format(abs($totalDebt), 2) ?> $
            <?php else: ?>Долгов нет
            <?php endif; ?>
          </span></div>
        <?php endif; ?>
        <div><a href="client_form.php?ctx=debt&id=<?= (int)$_SESSION['debt_client']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_client">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
  <?php else: ?>
    <input type="text" id="clientSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать имя...">
    <div id="clientResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="client_form.php?ctx=debt" class="btn secondary small">+ Новый клиент</a></p>
  <?php endif; ?>
</div>

<?php if (!$_SESSION['debt_client']): ?>
<div class="card">
  <h2>Должники</h2>
  <?php if (empty($debtors)): ?>
    <p class="muted">Должников нет — все счета оплачены.</p>
  <?php else: ?>
    <div class="debtor-grid">
      <?php foreach ($debtors as $d): ?>
        <form method="post" class="debtor-block">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="select_client">
          <input type="hidden" name="client_id" value="<?= (int)$d['id'] ?>">
          <input type="hidden" name="client_name" value="<?= htmlspecialchars($d['name']) ?>">
          <button type="submit" class="debtor-block-btn">
            <span class="debtor-block-name"><?= htmlspecialchars($d['name']) ?></span>
            <span class="badge badge-debt"><?= number_format($d['debt'], 2) ?> $</span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($_SESSION['debt_client'] && $totalDebt !== null && $totalDebt > 0.01): ?>
<div class="card">
  <h2>Принять оплату</h2>
  <p class="muted">Можно разбить сразу на несколько способов (например часть наличными, часть картой). Сумма сама распределится по счетам — сначала самые старые (FIFO). Если внесли больше долга, лишнее не применится.</p>
  <form method="post" id="fifoPayForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="receive_payment">
    <div class="row">
      <?php foreach ($cfg['payment_accounts'] as $key => $acc): ?>
        <?php if (($acc['currency'] ?? 'USD') === 'UZS'): ?>
          <div class="pay-uzs-group">
            <label><?= htmlspecialchars($acc['label']) ?> — сумма в сумах</label>
            <input type="number" step="1" min="0" class="pay-uzs-input" data-key="<?= $key ?>" name="pay_uzs[<?= $key ?>]" placeholder="0">
            <label>Курс (сум за 1$)</label>
            <input type="number" step="0.01" min="0" class="pay-rate-input" data-key="<?= $key ?>" name="pay_rate[<?= $key ?>]" placeholder="напр. 12700">
            <div class="muted">≈ <span class="pay-uzs-preview" data-key="<?= $key ?>">0.00</span> $</div>
          </div>
        <?php else: ?>
          <div>
            <label><?= htmlspecialchars($acc['label']) ?></label>
            <input type="number" step="0.01" min="0" name="pay[<?= $key ?>]" placeholder="0.00">
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <button type="submit">Принять оплату</button>
  </form>
</div>
<?php endif; ?>

<?php if ($_SESSION['debt_client'] && $totalDebt !== null): ?>
<div class="card">
  <h2>Неоплаченные счета</h2>
  <?php if ($totalDebt < -0.01): ?>
    <p class="muted">У клиента переплата <?= number_format(abs($totalDebt), 2) ?> $ (кредит-ноты сюда не
    выводятся — тут только реальный долг). Чтобы выдать деньги клиенту — раздел
    <a href="payout.php">«Выдача денег»</a>.</p>
  <?php endif; ?>
  <?php if (empty($unpaidInvoices)): ?>
    <p class="muted">Неоплаченных счетов нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Счёт</th><th>Дата</th><th>Сумма</th><th></th><th>Способ оплаты</th><th></th></tr>
      <?php foreach ($unpaidInvoices as $inv): ?>
        <form method="post">
  <?= csrf_field() ?>
        <tr>
          <td><?= htmlspecialchars($inv['ref']) ?></td>
          <td><?= htmlspecialchars($inv['date']) ?></td>
          <td><?= number_format($inv['remaintopay'], 2) ?> $<?php if (abs($inv['remaintopay'] - $inv['total_ttc']) > 0.01): ?><span class="muted"> (из <?= number_format($inv['total_ttc'], 2) ?>)</span><?php endif; ?></td>
          <td><a href="invoice_excel.php?id=<?= (int)$inv['id'] ?>" title="Скачать накладную (Excel)">📄</a></td>
          <td>
            <input type="hidden" name="action" value="pay_invoice">
            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
            <input type="hidden" name="invoice_ref" value="<?= htmlspecialchars($inv['ref']) ?>">
            <input type="hidden" name="invoice_amount" value="<?= (float)$inv['remaintopay'] ?>">
            <div class="pay-split-inline">
              <?php foreach ($cfg['payment_accounts'] as $key => $acc): ?>
                <?php if (($acc['currency'] ?? 'USD') === 'UZS'): ?>
                  <span class="pay-uzs-group-inline">
                    <input type="number" step="1" min="0" class="pay-uzs-input" data-key="<?= $key ?>_<?= (int)$inv['id'] ?>" name="pay_uzs[<?= $key ?>]" placeholder="<?= htmlspecialchars($acc['label']) ?> сум" title="<?= htmlspecialchars($acc['label']) ?> — сумма в сумах">
                    <input type="number" step="0.01" min="0" class="pay-rate-input" data-key="<?= $key ?>_<?= (int)$inv['id'] ?>" name="pay_rate[<?= $key ?>]" placeholder="курс" title="<?= htmlspecialchars($acc['label']) ?> — курс">
                    <span class="pay-uzs-preview-inline" data-key="<?= $key ?>_<?= (int)$inv['id'] ?>">0.00 $</span>
                  </span>
                <?php else: ?>
                  <input type="number" step="0.01" min="0" name="pay[<?= $key ?>]" placeholder="<?= htmlspecialchars($acc['label']) ?>" title="<?= htmlspecialchars($acc['label']) ?>"
                         value="<?= $key === array_key_first($cfg['payment_accounts']) ? number_format($inv['remaintopay'], 2, '.', '') : '' ?>">
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </td>
          <td><button type="submit">Оплатить</button></td>
        </tr>
        </form>
      <?php endforeach; ?>
      <?php // K-1 (внешняя приёмка, 03.09.2026): без этой строки сумма таблицы не сходилась с бейджем
            // "Долг" наверху — возвраты/авансы уменьшают общий долг, но не гасят конкретные счета, и
            // кассир, закрывая строки по одной, брал деньги за уже несуществующий долг. Теперь видно,
            // почему числа отличаются, и итог совпадает с тем, что показано клиенту. ?>
      <?php if ($creditsTotal < -0.01): ?>
        <tr>
          <td colspan="4" style="padding-top:12px">
            <strong>Возвраты и авансы клиента</strong>
            <div class="muted">уже уменьшили общий долг, но не привязаны к конкретным счетам выше</div>
          </td>
          <td colspan="2" style="text-align:right; padding-top:12px"><strong><?= number_format($creditsTotal, 2) ?> $</strong></td>
        </tr>
        <tr>
          <td colspan="4" style="border-top:2px solid var(--border)"><strong>Итого к оплате</strong></td>
          <td colspan="2" style="text-align:right; border-top:2px solid var(--border)">
            <strong><?= number_format(max(0, $totalDebt), 2) ?> $</strong>
            <?php if ($totalDebt <= 0.01): ?><div class="muted">долг закрыт</div><?php endif; ?>
          </td>
        </tr>
      <?php endif; ?>
    </table>
    <?php if ($creditsTotal < -0.01 && $totalDebt <= 0.01): ?>
      <p class="muted" style="margin-top:10px">Счета выше перекрыты возвратами/авансом — принимать по ним
      оплату не нужно. Если клиент оставляет деньги вперёд, оформите их в разделе
      <a href="advance.php">«Аванс»</a>, тогда они будут числиться за ним.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
window.onClientPick = function (c) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_client">' +
    '<input type="hidden" name="client_id" value="' + c.id + '">' +
    '<input type="hidden" name="client_name" value="' + c.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
};

// Карта/QR/перевод — кассир вводит сумму в сумах + курс, показываем живой пересчёт в $
document.querySelectorAll('.pay-uzs-input').forEach(uzsInput => {
  const key = uzsInput.dataset.key;
  const rateInput = document.querySelector('.pay-rate-input[data-key="' + key + '"]');
  const preview = document.querySelector('.pay-uzs-preview[data-key="' + key + '"], .pay-uzs-preview-inline[data-key="' + key + '"]');
  if (!rateInput || !preview) return;
  const isInline = preview.classList.contains('pay-uzs-preview-inline');
  function update() {
    const uzs = parseFloat(uzsInput.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;
    const usd = rate > 0 ? uzs / rate : 0;
    preview.textContent = isInline ? (usd.toFixed(2) + ' $') : usd.toFixed(2);
  }
  uzsInput.addEventListener('input', update);
  rateInput.addEventListener('input', update);
});
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
