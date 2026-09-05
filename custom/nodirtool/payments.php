<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/invoice_lock.php'; // N-2 — блокировка от двойной оплаты (см. файл)
require_once __DIR__ . '/includes/currency.php';

if (!array_key_exists('pay_supplier', $_SESSION)) $_SESSION['pay_supplier'] = null;

// Прямая ссылка с главной ("Сводка") — ?supplier_id=X сразу открывает карточку этого поставщика,
// минуя дашборд. Тот же приём "_preserve_once", что уже используется после редиректов form'ов.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['supplier_id'])) {
    $jumpId = (int)$_GET['supplier_id'];
    $jumpSoc = $api->getThirdparty($jumpId);
    if (is_array($jumpSoc)) {
        $_SESSION['pay_supplier'] = ['id' => $jumpId, 'name' => $jumpSoc['name'] ?? $jumpSoc['nom'] ?? ''];
        $_SESSION['_preserve_once']['pay_supplier'] = true;
    }
}

// Обычный (не форма) заход в раздел — например, вернулись сюда через сайдбар из другого раздела —
// всегда показываем дашборд "кому должны", а не то, на каком поставщике остановились в прошлый раз.
// Иначе непонятно, как вернуться к списку блоков, кроме как нажать "Сменить" внутри самой карточки.
reset_selection_unless_preserved('pay_supplier');

$message = '';
$messageType = '';

// Счета денег, откуда можно платить поставщику
$moneyAccounts = [];
// Личная касса ТЕКУЩЕГО закупщика — первым пунктом, это самый частый случай "заплатил наличными из
// своего кармана" (топ-5 пункт 2, 02.09.2026). У каждого логина свой счёт, чужой сюда не подставляется.
$myCashAcc = $cfg['personal_cash_accounts'][$_SESSION['user']['login']] ?? null;
if ($myCashAcc) {
    $moneyAccounts['mycash'] = ['id' => $myCashAcc['id'], 'label' => 'Моя касса (' . $myCashAcc['label'] . ')'];
}
$moneyAccounts['uzs'] = ['id' => $cfg['uzs_account_id'], 'label' => 'Сумовый счёт (UZS-MAIN)'];
foreach ($cfg['currency_accounts'] as $curCode => $accId) {
    $moneyAccounts[strtolower($curCode)] = ['id' => $accId, 'label' => $curCode . '-MAIN'];
}
// Валюта каждого счёта — берётся из самой карточки счёта в Dolibarr, а не угадывается по названию.
// Списание всегда идёт в валюте счёта: с EUR-MAIN уходят евро, с сумового — сумы.
foreach ($moneyAccounts as $k => $a) {
    $moneyAccounts[$k]['currency'] = account_currency((int)$a['id']);
}
$payMethods = ['transfer' => ['code' => 2, 'label' => 'Банк.перевод'], 'cash' => ['code' => 4, 'label' => 'Наличные']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'select_supplier') {
        $_SESSION['pay_supplier'] = ['id' => (int)($_POST['supplier_id'] ?? 0), 'name' => $_POST['supplier_name'] ?? ''];
    } elseif ($action === 'clear_supplier') {
        $_SESSION['pay_supplier'] = null;
    } elseif ($action === 'create_invoice_from_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $order = $api->getSupplierOrder($orderId);
        if (!is_array($order)) {
            $message = 'Не удалось прочитать заказ: ' . $api->lastError;
            $messageType = 'err';
        } else {
            // Проверка на задвоение — ДО создания, не после (раньше только подсвечивали жёлтым уже
            // ПОСЛЕ того, как второй счёт был создан — см. отчёт ревью P0#7). Ищем существующий счёт
            // поставщику с тем же номером заказа в ref_supplier — если уже есть, не создаём второй.
            $existingInvoice = null;
            if (!empty($order['ref'])) {
                $existingForSupplier = $api->getSupplierInvoicesForSupplier((int)$order['socid']);
                if (is_array($existingForSupplier)) {
                    foreach ($existingForSupplier as $exInv) {
                        if (($exInv['ref_supplier'] ?? '') === $order['ref']) {
                            $existingInvoice = $exInv;
                            break;
                        }
                    }
                }
            }

            if ($existingInvoice) {
                $message = "По заказу {$order['ref']} уже создан счёт поставщику {$existingInvoice['ref']} — второй счёт на ту же поставку не создан. Найдите его в списке «Счета к оплате» ниже.";
                $messageType = 'err';
            } else {
                // N-3 / условия оплаты (03.09.2026, решение пользователя после разговора с Нодиром):
                // у поставщиков ПРЕДОПЛАТЫ счёт оформляется на ЗАКАЗАННОЕ количество — деньги ушли до
                // отгрузки, счёт фиксирует именно оплаченное (недопоставка отражается отдельно, как долг
                // поставщика). У поставщиков ПОСТОПЛАТЫ платим по факту привезённого — счёт должен быть
                // на РЕАЛЬНО ПРИНЯТОЕ количество, иначе переплатим за непривезённое (это и есть N-3 из
                // отчёта — верно, но, как выяснилось, не для всех поставщиков).
                $supplierSoc = $api->getThirdparty((int)$order['socid']);
                $paymentTerms = is_array($supplierSoc) ? ($supplierSoc['array_options']['options_payment_terms'] ?? '') : '';
                $byReceived = ($paymentTerms === 'postpay');
                $receivedByLine = [];
                if ($byReceived) {
                    require_once __DIR__ . '/includes/order_receipts.php';
                    foreach (get_order_receipts($orderId) as $rc) {
                        $receivedByLine[$rc['line_id']] = ($receivedByLine[$rc['line_id']] ?? 0) + $rc['qty'];
                    }
                }

                // Валюта счёта = валюта заказа (04.09.2026): европейский заказ должен давать
                // европейский счёт, иначе закупщик видит доллары там, где у поставщика евро.
                $ordCur = strtoupper(trim((string)($order['multicurrency_code'] ?? ''))) ?: 'USD';
                $ordRate = (float)($order['multicurrency_tx'] ?? 1) ?: 1.0;

                $invId = $api->createSupplierInvoice((int)$order['socid'], $order['ref'] ?? '', 0, $ordCur, $ordRate);
                if (!$invId) {
                    $message = 'Ошибка создания счёта: ' . $api->lastError;
                    $messageType = 'err';
                } else {
                    $lineErrors = [];
                    $shortfalls = [];
                    $anyLineAdded = false;
                    foreach (($order['lines'] ?? []) as $line) {
                        $orderedQty = (float)$line['qty'];
                        $qtyForInvoice = $orderedQty;
                        if ($byReceived) {
                            $qtyForInvoice = (float)($receivedByLine[(int)($line['id'] ?? 0)] ?? 0);
                            if ($qtyForInvoice + 0.0001 < $orderedQty) {
                                $shortfalls[] = ($line['product_label'] ?? $line['desc'] ?? '?') .
                                    ': заказано ' . rtrim(rtrim(number_format($orderedQty, 3, '.', ''), '0'), '.') .
                                    ', принято ' . rtrim(rtrim(number_format($qtyForInvoice, 3, '.', ''), '0'), '.');
                            }
                            if ($qtyForInvoice <= 0.0001) continue; // ничего не привезли по этой позиции — в счёт не включаем
                        }
                        // Цена строки — в валюте заказа: для валютного заказа это multicurrency_subprice,
                        // а `subprice` там долларовый (Dolibarr выводит его сам по курсу).
                        $linePrice = $ordCur !== 'USD'
                            ? (float)($line['multicurrency_subprice'] ?? 0)
                            : (float)$line['subprice'];
                        $r = $api->addSupplierInvoiceLine($invId, (int)$line['fk_product'], $line['product_label'] ?? $line['desc'] ?? '', $qtyForInvoice, $linePrice, 0, $ordCur);
                        if ($r === null) { $lineErrors[] = $api->lastError; } else { $anyLineAdded = true; }
                    }
                    if ($byReceived && !$anyLineAdded && empty($lineErrors)) {
                        $lineErrors[] = 'по этому заказу ещё ничего не принято на склад — счёт по факту приёмки оформлять нечем (поставщик работает по постоплате).';
                    }
                    if ($lineErrors) {
                        $message = "Счёт #$invId создан, но не все позиции скопировались: " . implode('; ', $lineErrors);
                        $messageType = 'err';
                    } else {
                        $val = $api->validateSupplierInvoice($invId);
                        if ($val === null) {
                            $message = "Счёт #$invId создан с позициями, но не проведён: " . $api->lastError;
                            $messageType = 'err';
                        } else {
                            $message = "Счёт поставщика #$invId создан из заказа {$order['ref']} и проведён.";
                            if ($byReceived) {
                                $message .= " Поставщик работает по ПОСТОПЛАТЕ — счёт оформлен по РЕАЛЬНО ПРИНЯТОМУ количеству.";
                                if ($shortfalls) {
                                    $message .= " Недопоставка: " . implode('; ', $shortfalls) . ". За непривезённое счёт не выставлен.";
                                }
                            }
                            $messageType = 'ok';
                            // Счёт уже реально создан — редирект (POST → GET), чтобы F5 не отправил
                            // эту же форму повторно и не создал второй счёт по тому же заказу.
                            flash_set($message, $messageType);
                            header('Location: payments.php');
                            exit;
                        }
                    }
                }
            }
        }
    } elseif ($action === 'pay_invoice') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $accKey = $_POST['account'] ?? '';
        $methodKey = $_POST['method'] ?? '';
        $acc = $moneyAccounts[$accKey] ?? null;
        $method = $payMethods[$methodKey] ?? null;

        // Сумма вводится В ВАЛЮТЕ СЧЁТА — именно столько реально уходит со счёта. Если счёт не
        // долларовый, нужен курс, чтобы понять, на сколько закрывается сам счёт-фактура (он ведётся
        // в базовой валюте компании). Раньше курса не было вовсе: введённое число списывалось со
        // счёта И закрывало счёт-фактуру одним и тем же числом — оплата 1000 с евро-счёта уносила
        // 1000 евро, а долг гасила на 1000 долларов (найдено пользователем 04.09.2026).
        $accCur = $acc['currency'] ?? 'USD';
        [$payRate, $rateErr] = validate_currency_rate($accCur, $accCur === 'USD' ? 1 : ($_POST['rate'] ?? ''));
        $amountUsd = $accCur === 'USD' ? $amount : round($amount / max($payRate, 1e-9), 2);

        if (!$invId || $amount <= 0 || !$acc || !$method) {
            $message = 'Заполните сумму, счёт списания и способ оплаты.';
            $messageType = 'err';
        } elseif ($rateErr !== '') {
            $message = $rateErr;
            $messageType = 'err';
        } else {
            // N-2 (внешний QA-аудит, раунд 2, 03.09.2026): проверка остатка И сама запись платежа —
            // ОБЕ внутри именованной блокировки MySQL на этот конкретный счёт (см.
            // includes/invoice_lock.php) — раньше между чтением остатка и записью платежа не было
            // ничего, что помешало бы второму одновременному запросу прочитать тот же "старый" остаток
            // и тоже пройти. Теперь второй запрос либо ждёт своей очереди (и видит уже обновлённый
            // остаток), либо, если первый долго не отпускает лок, получает явную ошибку вместо
            // молчаливого повторного списания.
            $result = nt_with_supplier_invoice_lock($invId, function () use ($api, $invId, $amount, $amountUsd, $accCur, $acc, $method) {
                $freshInv = $api->getSupplierInvoice($invId);
                if (!is_array($freshInv)) {
                    return ['ok' => false, 'error' => 'Не удалось проверить остаток по счёту: ' . $api->lastError];
                }
                $invCur = strtoupper(trim((string)($freshInv['multicurrency_code'] ?? ''))) ?: 'USD';
                $invRate = (float)($freshInv['multicurrency_tx'] ?? 1) ?: 1.0;

                $paidSoFar = 0;
                foreach ($api->getSupplierInvoicePayments($invId) as $p) { $paidSoFar += (float)($p['amount'] ?? 0); }
                // Остаток и сверка — в БАЗОВОЙ валюте (в ней Dolibarr ведёт сам счёт-фактуру),
                // а пользователю показываем его в валюте счёта-фактуры, как он привык её видеть.
                $freshRemaining = (float)($freshInv['total_ttc'] ?? 0) - $paidSoFar;
                if ($amountUsd > $freshRemaining + 0.01) {
                    $remShow = $invCur === 'USD' ? $freshRemaining : $freshRemaining * $invRate;
                    return ['ok' => false, 'error' => 'Платёж ('
                        . rtrim(rtrim(number_format($amountUsd * ($invCur === 'USD' ? 1 : $invRate), 2, '.', ''), '0'), '.')
                        . " {$invCur}) больше остатка по счёту ("
                        . rtrim(rtrim(number_format($remShow, 2, '.', ''), '0'), '.')
                        . " {$invCur}) — оплата не проведена."];
                }

                $who = $_SESSION['user']['name'] ?? '';
                $res = $api->addSupplierInvoicePayment($invId, $method['code'], (int)$acc['id'], $amountUsd, "Оплата поставщику ({$who})");
                if ($res === null) {
                    return ['ok' => false, 'error' => 'Ошибка оплаты: ' . $api->lastError];
                }

                // Dolibarr записал в банковскую строку ДОЛЛАРОВУЮ сумму, не глядя на валюту счёта —
                // переписываем на ту, что реально ушла (см. пояснение в fix_payment_bank_amount()).
                $warn = '';
                if ($accCur !== 'USD') {
                    $paymentId = is_array($res) ? (int)($res['id'] ?? 0) : (int)$res;
                    if (!$paymentId || !fix_payment_bank_amount($paymentId, $amount)) {
                        $warn = 'ВНИМАНИЕ: оплата проведена, но сумму списания со счёта поправить не удалось — '
                              . "проверьте проводку на счёте «{$acc['label']}» вручную. ";
                    }
                }
                return ['ok' => true, 'warn' => $warn];
            });

            if (!$result['ok']) {
                $message = $result['error'];
                $messageType = 'err';
            } else {
                $curLabel = $accCur === 'USD' ? '$' : $accCur;
                $message = ($result['warn'] ?? '')
                    . "Оплачено {$amount} {$curLabel} по счёту #$invId ({$acc['label']}, {$method['label']})"
                    . ($accCur === 'USD' ? '' : ' — это ' . number_format($amountUsd, 2, '.', '') . ' $ по курсу ' . $payRate)
                    . '.';
                $messageType = !empty($result['warn']) ? 'warn' : 'ok';
                // Оплата уже реально проведена — редирект (POST → GET), чтобы F5 не отправил эту же
                // форму повторно и не оплатил счёт дважды.
                flash_set($message, $messageType);
                header('Location: payments.php');
                exit;
            }
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

// --- Дашборд "кому должны": показываем ДО выбора поставщика, чтобы не искать вручную ---
// Две категории денег, ожидающих оплаты: (1) уже оформленные счета поставщику с остатком > 0,
// (2) заказы, которые УЖЕ получены на склад, но счёт по ним ещё не оформлен (тоже реальный долг,
// просто ещё не формализованный документом) — считаем и показываем оба сигнала в одном блоке.
$owedSuppliers = [];
if (empty($_SESSION['pay_supplier'])) {
    $bySoc = []; // socid => ['unpaid'=>float, 'invCount'=>int, 'pendingCount'=>int, 'pendingSum'=>float]

    $rawUnpaid = $api->getUnpaidSupplierInvoices();
    if (is_array($rawUnpaid)) {
        foreach ($rawUnpaid as $inv) {
            $socid = (int)($inv['socid'] ?? 0);
            if (!$socid) continue;
            $invId = (int)$inv['id'];
            $paidSum = 0;
            foreach ($api->getSupplierInvoicePayments($invId) as $p) { $paidSum += (float)($p['amount'] ?? 0); }
            $remaining = (float)($inv['total_ttc'] ?? 0) - $paidSum;
            if ($remaining <= 0.01) continue; // счёт числится "unpaid", но по факту уже добит частичными оплатами
            if (!isset($bySoc[$socid])) $bySoc[$socid] = ['unpaid' => 0, 'invCount' => 0, 'pendingCount' => 0, 'pendingSum' => 0];
            $bySoc[$socid]['unpaid'] += $remaining;
            $bySoc[$socid]['invCount']++;
        }
    }

    // BUG-N1 (внешний отчёт, 02.09.2026): заказы, по которым счёт УЖЕ создан, не должны считаться
    // "без счёта" — раньше список/счётчик показывали ВСЕ полученные заказы независимо от наличия
    // счёта. Набор уже выставленных номеров заказов (ref_supplier) — одним запросом на всю базу,
    // не по поставщику в цикле.
    $invoicedRefs = $api->getInvoicedSupplierOrderRefs();
    foreach (['received_start', 'received_end'] as $st) {
        $rows = $api->getSupplierOrdersByStatus($st, 'id,ref,socid,statut,total_ttc');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $socid = (int)($row['socid'] ?? 0);
                if (!$socid) continue;
                if (!empty($invoicedRefs[$row['ref'] ?? ''])) continue; // счёт уже есть — не "без счёта"
                if (!isset($bySoc[$socid])) $bySoc[$socid] = ['unpaid' => 0, 'invCount' => 0, 'pendingCount' => 0, 'pendingSum' => 0];
                $bySoc[$socid]['pendingCount']++;
                $bySoc[$socid]['pendingSum'] += (float)($row['total_ttc'] ?? 0);
            }
        }
    }

    uasort($bySoc, fn($a, $b) => ($b['unpaid'] + $b['pendingSum']) <=> ($a['unpaid'] + $a['pendingSum']));

    // Имена всех поставщиков в списке — ОДНИМ запросом, не getThirdparty() по одному в цикле
    // (см. отчёт ревью P0#5).
    $socNames = $api->getThirdpartiesByIds(array_keys($bySoc));
    foreach ($bySoc as $socid => $sums) {
        $soc = $socNames[$socid] ?? null;
        $owedSuppliers[] = [
            'id' => $socid,
            'name' => is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? "#{$socid}") : "#{$socid}",
        ] + $sums;
    }
}

// --- Заказы этого поставщика, полученные (частично/полностью), готовые к оформлению счёта ---
$readyOrders = [];
$invoices = [];
if ($_SESSION['pay_supplier']) {
    $socId = (int)$_SESSION['pay_supplier']['id'];
    $invoicedRefs = $api->getInvoicedSupplierOrderRefs(); // BUG-N1, см. выше — не показывать заказы, по которым счёт уже есть
    foreach (['received_start', 'received_end'] as $st) {
        $rows = $api->getSupplierOrdersByStatus($st, 'id,ref,socid,statut,total_ttc');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if ((int)$row['socid'] === $socId && empty($invoicedRefs[$row['ref'] ?? ''])) {
                    $readyOrders[] = ['id' => (int)$row['id'], 'ref' => $row['ref'], 'total_ttc' => (float)$row['total_ttc']];
                }
            }
        }
    }

    $rawInvoices = $api->getSupplierInvoicesForSupplier($socId);
    if (is_array($rawInvoices)) {
        foreach ($rawInvoices as $inv) {
            $invId = (int)$inv['id'];
            $totalTtc = (float)($inv['total_ttc'] ?? 0);
            $payments = $api->getSupplierInvoicePayments($invId);
            $paidSum = 0;
            foreach ($payments as $p) { $paidSum += (float)($p['amount'] ?? 0); }
            $remaining = $totalTtc - $paidSum;
            // Суммы Dolibarr ведёт в базовой валюте (доллары), а поставщику мы должны в валюте его
            // счёта — показываем именно её, иначе европейский счёт выглядит долларовым.
            $invCur = strtoupper(trim((string)($inv['multicurrency_code'] ?? ''))) ?: 'USD';
            $invRate = (float)($inv['multicurrency_tx'] ?? 1) ?: 1.0;
            $k = $invCur === 'USD' ? 1.0 : $invRate;
            $invoices[] = [
                'id' => $invId,
                'ref' => $inv['ref'] ?? '',
                'ref_supplier' => $inv['ref_supplier'] ?? '',
                'total_ttc' => $totalTtc,
                'paid' => $paidSum,
                'remaining' => $remaining,
                'currency' => $invCur,
                'rate' => $invRate,
                'total_cur' => $totalTtc * $k,
                'paid_cur' => $paidSum * $k,
                'remaining_cur' => $remaining * $k,
            ];
        }
    }
    usort($invoices, fn($a, $b) => $b['id'] <=> $a['id']);
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Оплата поставщикам</h1>
<p class="muted">Счёт поставщику оформляется из уже полученного заказа, оплата может быть частичной.</p>
<?php if ($_SESSION['pay_supplier']): ?>
  <form method="post" style="margin-bottom:14px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_supplier">
    <button type="submit" class="secondary">← Назад к списку</button>
  </form>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>

<div class="card">
  <h2>Поставщик</h2>
  <?php if ($_SESSION['pay_supplier']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['pay_supplier']['name']) ?></strong>
        <div><a href="supplier_form.php?ctx=payments&id=<?= (int)$_SESSION['pay_supplier']['id'] ?>" class="muted">✏️ Редактировать</a></div>
      </div>
      <form method="post" style="flex:0">
  <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_supplier">
        <button type="submit" class="secondary">Сменить</button>
      </form>
    </div>
  <?php else: ?>
    <input type="text" id="supplierSearch" placeholder="Нажмите, чтобы увидеть список, или начните печатать название...">
    <div id="supplierResults" class="result-list"></div>
    <p style="margin-top:8px"><a href="supplier_form.php?ctx=payments" class="btn secondary small">+ Новый поставщик</a></p>
  <?php endif; ?>
</div>

<?php if (empty($_SESSION['pay_supplier'])): ?>
<div class="card">
  <h2>Ждут оплаты</h2>
  <?php if (empty($owedSuppliers)): ?>
    <p class="muted">Никто не ждёт оплаты — все счета оплачены, неоформленных приёмок нет.</p>
  <?php else: ?>
    <div class="debtor-grid">
      <?php foreach ($owedSuppliers as $s): ?>
        <form method="post" class="debtor-block">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="select_supplier">
          <input type="hidden" name="supplier_id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="supplier_name" value="<?= htmlspecialchars($s['name']) ?>">
          <button type="submit" class="debtor-block-btn">
            <span class="debtor-block-name"><?= htmlspecialchars($s['name']) ?></span>
            <?php if ($s['unpaid'] > 0.01): ?>
              <span class="badge badge-debt"><?= number_format($s['unpaid'], 2) ?> $ · <?= $s['invCount'] ?> <?= $s['invCount'] == 1 ? 'счёт' : 'счёта(ов)' ?></span>
            <?php endif; ?>
            <?php if ($s['pendingCount'] > 0): ?>
              <span class="badge badge-warn"><?= $s['pendingCount'] ?> заказ(ов) получено, счёт не оформлен (<?= number_format($s['pendingSum'], 2) ?> $)</span>
            <?php endif; ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($_SESSION['pay_supplier']): ?>

<div class="card">
  <h2>Полученные заказы без счёта</h2>
  <p class="muted">Если счёт по заказу уже был создан раньше — не создавайте повторно.</p>
  <?php if (empty($readyOrders)): ?>
    <p class="muted">Нет полученных заказов у этого поставщика.</p>
  <?php else: ?>
    <table>
      <tr><th>Заказ</th><th>Сумма</th><th></th></tr>
      <?php foreach ($readyOrders as $o): ?>
        <tr>
          <td><?= htmlspecialchars($o['ref']) ?></td>
          <td><?= number_format($o['total_ttc'], 2) ?> $</td>
          <td>
            <form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="create_invoice_from_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>"><button type="submit" class="small">Создать счёт</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Счета к оплате</h2>
  <?php if (empty($invoices)): ?>
    <p class="muted">Счетов пока нет.</p>
  <?php else: ?>
    <?php foreach ($invoices as $inv): ?>
      <div class="doc-block" style="border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:10px;">
        <div class="row" style="align-items:center; margin-bottom:8px">
          <div>
            <strong><?= htmlspecialchars($inv['ref']) ?></strong>
            <?php if ($inv['ref_supplier']): ?><span class="muted"> · заказ <?= htmlspecialchars($inv['ref_supplier']) ?></span><?php endif; ?>
          </div>
          <?php $ic = $inv['currency'] === 'USD' ? '$' : $inv['currency']; ?>
          <div style="text-align:right">
            Итого: <?= number_format($inv['total_cur'], 2) ?> <?= htmlspecialchars($ic) ?> ·
            Оплачено: <?= number_format($inv['paid_cur'], 2) ?> <?= htmlspecialchars($ic) ?> ·
            <span class="<?= $inv['remaining'] > 0.01 ? 'err' : 'ok' ?>">Остаток: <?= number_format($inv['remaining_cur'], 2) ?> <?= htmlspecialchars($ic) ?></span>
            <?php if ($inv['currency'] !== 'USD'): ?>
              <div class="muted">в долларах ≈ <?= number_format($inv['remaining'], 2) ?> $ по курсу <?= rtrim(rtrim(number_format($inv['rate'], 4, '.', ''), '0'), '.') ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($inv['remaining'] > 0.01): ?>
        <?php
          // Счёт списания по умолчанию — тот, что в валюте счёта-фактуры: платить европейский счёт
          // с евро-счёта естественнее всего, и тогда курс вообще не нужен.
          $defaultAccKey = '';
          foreach ($moneyAccounts as $k2 => $a2) {
              if (($a2['currency'] ?? 'USD') === $inv['currency']) { $defaultAccKey = $k2; break; }
          }
        ?>
        <form method="post" class="row pay-form" style="align-items:end"
              data-inv-cur="<?= htmlspecialchars($inv['currency']) ?>"
              data-inv-rate="<?= htmlspecialchars((string)$inv['rate']) ?>"
              data-remaining-usd="<?= htmlspecialchars((string)$inv['remaining']) ?>">
  <?= csrf_field() ?>
          <input type="hidden" name="action" value="pay_invoice">
          <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
          <div>
            <label>Сумма <span class="acc-cur-label"></span></label>
            <input type="number" step="0.01" min="0.01" name="amount" class="pay-amount"
                   value="<?= number_format($inv['remaining_cur'], 2, '.', '') ?>">
          </div>
          <div>
            <label>Списать со счёта</label>
            <select name="account" class="pay-account">
              <?php foreach ($moneyAccounts as $key => $acc): ?>
                <option value="<?= $key ?>" data-currency="<?= htmlspecialchars($acc['currency']) ?>"
                        <?= $key === $defaultAccKey ? 'selected' : '' ?>>
                  <?= htmlspecialchars($acc['label']) ?> (<?= htmlspecialchars($acc['currency']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="pay-rate-box" style="display:none; max-width:170px">
            <label>Курс: 1 $ = <span class="rate-cur"></span></label>
            <input type="number" step="0.0001" min="0.0001" name="rate" class="pay-rate">
          </div>
          <div>
            <label>Способ</label>
            <select name="method">
              <?php foreach ($payMethods as $key => $m): ?><option value="<?= $key ?>"><?= htmlspecialchars($m['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="flex:0"><button type="submit">Оплатить</button></div>
          <div class="muted pay-hint" style="align-self:center; min-width:200px"></div>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
// Валюта оплаты (04.09.2026). Сумма всегда вводится в валюте ВЫБРАННОГО счёта — именно столько
// уйдёт со счёта. Если валюта счёта не совпадает с валютой счёта-фактуры, нужен курс, и рядом
// сразу видно, на сколько при этом закроется долг.
(function () {
  const refRates = <?= json_encode(['EUR' => dolibarr_currency_rate('EUR'), 'RUB' => dolibarr_currency_rate('RUB'), 'UZS' => dolibarr_currency_rate('UZS')]) ?>;

  document.querySelectorAll('form.pay-form').forEach(function (form) {
    const sel = form.querySelector('.pay-account');
    const amt = form.querySelector('.pay-amount');
    const rateBox = form.querySelector('.pay-rate-box');
    const rate = form.querySelector('.pay-rate');
    const rateCur = form.querySelector('.rate-cur');
    const curLabel = form.querySelector('.acc-cur-label');
    const hint = form.querySelector('.pay-hint');
    const invCur = form.dataset.invCur;
    const invRate = parseFloat(form.dataset.invRate) || 1;
    const remainingUsd = parseFloat(form.dataset.remainingUsd) || 0;

    function accCur() {
      const o = sel.options[sel.selectedIndex];
      return o ? (o.dataset.currency || 'USD') : 'USD';
    }

    function sync() {
      const cur = accCur();
      curLabel.textContent = ', ' + (cur === 'USD' ? '$' : cur);
      if (cur === 'USD') {
        rateBox.style.display = 'none';
        rate.value = '';
      } else {
        rateBox.style.display = '';
        rateCur.textContent = cur;
        if (!rate.value) rate.value = (cur === invCur ? invRate : (refRates[cur] || '')) || '';
      }
      // сумма по умолчанию — остаток, пересчитанный в валюту выбранного счёта
      const r = cur === 'USD' ? 1 : (parseFloat(rate.value) || 0);
      if (r > 0) amt.value = (remainingUsd * r).toFixed(2);
      recalc();
    }

    function recalc() {
      const cur = accCur();
      const a = parseFloat(amt.value) || 0;
      const r = cur === 'USD' ? 1 : (parseFloat(rate.value) || 0);
      if (a <= 0 || r <= 0) { hint.textContent = ''; return; }
      const usd = a / r;
      const inInv = invCur === 'USD' ? usd : usd * invRate;
      let t = 'спишется ' + a.toFixed(2) + ' ' + (cur === 'USD' ? '$' : cur);
      if (cur !== invCur) {
        t += ' → закроет ' + inInv.toFixed(2) + ' ' + (invCur === 'USD' ? '$' : invCur) + ' долга';
      }
      hint.textContent = t;
    }

    sel.addEventListener('change', function () { rate.value = ''; sync(); });
    rate.addEventListener('input', recalc);
    amt.addEventListener('input', recalc);
    sync();
  });
})();
</script>
<script src="assets/picker.js"></script>
<script>
window.wireSupplierSearch('supplierSearch', 'supplierResults', function (s) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_supplier">' +
    '<input type="hidden" name="supplier_id" value="' + s.id + '">' +
    '<input type="hidden" name="supplier_name" value="' + s.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
