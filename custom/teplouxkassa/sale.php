<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_split.php';
require_once __DIR__ . '/includes/draft_orders.php';
require_once __DIR__ . '/includes/dolibarr_direct.php'; // get_invoice_line_warehouses(), has_existing_credit_note_for_invoice()
require_once __DIR__ . '/includes/brand_discount.php'; // автоскидка Caleffi/Madas/Sitem/Fantini Cosmi/Mut (02.09.2026)

if (empty($_SESSION['cart'])) $_SESSION['cart'] = [];
if (empty($_SESSION['sale_client'])) $_SESSION['sale_client'] = null;
if (!array_key_exists('last_sale', $_SESSION)) $_SESSION['last_sale'] = null;

/**
 * Проверить, хватает ли остатка на складе для КАЖДОЙ позиции корзины — ДО создания счёта, чтобы
 * документ вообще не появлялся, если списание всё равно не пройдёт (Dolibarr не даёт уйти в минус).
 * Считает суммарно по паре (товар, склад) — на случай если один и тот же товар с одного склада попал
 * в корзину несколькими строками. Остаток берётся ЖИВЬЁМ (не из stock_by_warehouse, снятого в момент
 * добавления в корзину — он мог устареть). Возвращает список текстов нехватки (пусто = всё ок).
 */
function check_cart_stock(DolibarrApi $api, array $cfg, array $cart): array
{
    $neededByKey = [];
    foreach ($cart as $item) {
        $key = (int)$item['product_id'] . ':' . (int)($item['warehouse_id'] ?? $cfg['default_warehouse_id']);
        $neededByKey[$key] = ($neededByKey[$key] ?? 0) + (float)$item['qty'];
    }
    $stockCache = [];
    $problems = [];
    foreach ($neededByKey as $key => $neededQty) {
        [$productId, $whId] = array_map('intval', explode(':', $key));
        if (!array_key_exists($productId, $stockCache)) {
            $prod = $api->getProduct($productId, true);
            $stockCache[$productId] = is_array($prod) ? ($prod['stock_warehouse'] ?? []) : [];
        }
        $available = (float)($stockCache[$productId][$whId]['real'] ?? 0);
        if ($neededQty > $available + 0.0001) {
            $label = (string)$productId;
            foreach ($cart as $item) {
                if ((int)$item['product_id'] === $productId) { $label = $item['label']; break; }
            }
            $whLabel = $cfg['warehouse_labels'][$whId] ?? $whId;
            $problems[] = "\"{$label}\" на складе \"{$whLabel}\": нужно " . rtrim(rtrim(number_format($neededQty, 3, '.', ''), '0'), '.') .
                ', есть только ' . rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.') . '.';
        }
    }
    return $problems;
}
// Если текущая корзина открыта ИЗ черновика (см. drafts.php) — id черновика, чтобы после успешного
// оформления пометить его "переведён в продажу", а не потерять связь. Не сбрасывается при обычной
// навигации (как и сама корзина) — только после сохранения/оформления/явной очистки корзины.
if (!array_key_exists('loaded_draft_id', $_SESSION)) $_SESSION['loaded_draft_id'] = null;

// Обычный (не форма) заход в раздел — вернулись через сайдбар из другого раздела — сбрасывает
// выбранного клиента, чтобы не "застревать" на нём. Корзину не трогаем: если уже начали набирать
// продажу и просто отвлеклись на другой раздел, позиции не должны потеряться.
reset_selection_unless_preserved('sale_client');

$message = '';
$messageType = '';
$lastInvoiceId = null;

// --- Обработка действий ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $stockByWh = json_decode($_POST['stock_by_warehouse'] ?? '[]', true);
        if (!is_array($stockByWh)) $stockByWh = [];
        if ($productId) {
            // K-6 (внешний QA-аудит, раунд 2, 03.09.2026): проверяем направление товара по СВЕЖИМ
            // данным с сервера — не доверяем ref/label/price из формы (поиск и так их прислал верно
            // при обычной работе через интерфейс, но прямой POST мог подставить чужой товар/склад
            // другого направления). Цена/название берём из этого же свежего ответа, а не из формы.
            $freshProduct = $api->getProduct($productId, true);
            if (!is_array($freshProduct) || !product_belongs_to_direction($freshProduct, $cfg['ref_prefix'])) {
                $message = 'Этот товар не найден или относится к другому направлению.';
                $messageType = 'err';
            } else {
                $_SESSION['cart'][] = [
                    'product_id' => $productId,
                    'ref' => $freshProduct['ref'] ?? ($_POST['ref'] ?? ''),
                    'label' => $freshProduct['label'] ?? ($_POST['label'] ?? ''),
                    'price' => (float)($freshProduct['price'] ?? 0),
                    'qty' => 1, 'warehouse_id' => $cfg['default_warehouse_id'], 'stock_by_warehouse' => $stockByWh,
                    // null — кассир скидку на эту строку ещё не трогал, используется предложенная системой
                    // ставка (0% для обычных товаров, автоставка для брендовых) — см. includes/brand_discount.php.
                    'discount_rate' => null,
                ];
            }
        }
    } elseif ($action === 'update_cart_item') {
        $idx = (int)($_POST['idx'] ?? -1);
        // Клиент нужен и для проверки K-4 (что система предложила бы), и для финального JSON-ответа
        // K-3 — считаем один раз, не дважды.
        $saleClientForSync = $_SESSION['sale_client'] ? $api->getThirdparty((int)$_SESSION['sale_client']['id']) : null;
        if (!is_array($saleClientForSync)) $saleClientForSync = null;
        if (isset($_SESSION['cart'][$idx])) {
            $qty = (float)($_POST['qty'] ?? 0);
            $wh = (int)($_POST['warehouse_id'] ?? $cfg['default_warehouse_id']);
            // K-6: склад строго из своего направления — та же проверка, что уже есть в transfer.php/
            // inventory.php, но раньше отсутствовала здесь. Если склад чужой — просто не применяем
            // правку склада (qty всё равно обновляется, если валиден), сообщение не выводим намеренно,
            // т.к. это тихий fetch()-запрос без перезагрузки страницы (см. persistCartItem() в JS).
            if ($wh && !in_array($wh, $cfg['warehouse_ids'], false)) {
                $wh = $_SESSION['cart'][$idx]['warehouse_id'] ?? $cfg['default_warehouse_id'];
            }
            if ($qty > 0) {
                $_SESSION['cart'][$idx]['qty'] = $qty;
                $_SESSION['cart'][$idx]['warehouse_id'] = $wh;
            }
            // Скидка на эту строку (02.09.2026) — необязательный параметр, пришёл только если форма
            // редактирования скидки реально его прислала (пустое поле в запросе — не значит "очистить",
            // отличаем через isset, чтобы обычное сохранение qty/склада не затирало скидку случайно).
            if (isset($_POST['discount']) && $_POST['discount'] !== '') {
                $entered = max(0.0, min(100.0, (float)$_POST['discount']));
                // K-4 (внешний QA-аудит, раунд 2, 03.09.2026): раньше ЛЮБОЕ касание поля "%" помечало
                // строку "ручная" — даже если кассир вписал ЧИСЛО, СОВПАДАЮЩЕЕ с тем, что система и так
                // уже предлагала (например, случайно кликнул в поле и не глядя нажал ту же цифру). Из-за
                // этого строка теряла право на будущий авто-переход 10%→14,5%, хотя кассир ничего не
                // решал. Теперь: если введённое значение СОВПАДАЕТ с тем, что предложила бы система
                // ИМЕННО СЕЙЧАС (без учёта этой правки) — считаем, что решения не было, оставляем авто.
                $cartCopy = $_SESSION['cart'];
                $cartCopy[$idx]['discount_rate'] = null; // "как будто кассир ещё не трогал" — узнать предложение системы
                $checkResult = bd_apply_discounts($api, $cfg, $cartCopy, $saleClientForSync);
                $suggestedForThisLine = (float)($checkResult['cart'][$idx]['discount_rate'] ?? 0.0);
                $_SESSION['cart'][$idx]['discount_rate'] = (abs($entered - $suggestedForThisLine) < 0.01) ? null : $entered;
            } elseif (isset($_POST['discount_clear'])) {
                $_SESSION['cart'][$idx]['discount_rate'] = null; // вернуть к автоматической ставке
            }
        }

        // K-3 (внешний QA-аудит, раунд 2, 03.09.2026): раньше тихая правка qty/склада/скидки без
        // перезагрузки НЕ обновляла проценты скидки/цены/итог на экране — они пересчитывались только
        // на клиенте по УЖЕ показанному проценту, а сервер мог тем временем решить иначе (например,
        // порог $10 000 пройден — скидка должна была стать 14,5%, но экран продолжал показывать 10%).
        // Итог: кассир вводил оплату по неверной сумме, оформление блокировалось с непонятным
        // сообщением. Теперь update_cart_item (вызывается ТОЛЬКО через тихий fetch(), см. persistCartItem()
        // в JS — обычной формой это действие никогда не отправляется) всегда отвечает JSON со свежим
        // состоянием ВСЕЙ корзины (не только тронутой строки — переход порога может задеть сразу
        // несколько брендовых строк), JS обновляет экран из этого ответа, а не считает сам.
        $bdSync = bd_apply_discounts($api, $cfg, $_SESSION['cart'], $saleClientForSync);
        $vatMultSync = 1 + $cfg['vat_rate'] / 100;
        $rowsSync = [];
        $grandTotalSync = 0.0;
        foreach ($bdSync['cart'] as $rowIdx => $rowItem) {
            $priceTtc = round((float)$rowItem['discounted_price'] * $vatMultSync, 4);
            $subtotal = round($priceTtc * (float)$rowItem['qty'], 2);
            $grandTotalSync += $subtotal;
            $rowsSync[$rowIdx] = [
                'discount_rate' => round((float)$rowItem['discount_rate'], 2),
                'discount_is_manual' => (bool)$rowItem['discount_is_manual'],
                'price_ttc' => $priceTtc,
                'subtotal' => $subtotal,
            ];
        }
        header('Content-Type: application/json');
        echo json_encode(['rows' => $rowsSync, 'grand_total' => round($grandTotalSync, 2)]);
        exit;
    } elseif ($action === 'remove_from_cart') {
        $idx = (int)($_POST['idx'] ?? -1);
        // Специально НЕ переиндексируем массив (array_values) — иначе номера позиций в уже
        // открытой странице (JS) разъедутся с сервером при удалении без перезагрузки.
        if (isset($_SESSION['cart'][$idx])) unset($_SESSION['cart'][$idx]);
    } elseif ($action === 'select_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!select_client_for_direction($api, $cfg, 'sale_client', $clientId, $_POST['client_name'] ?? '')) {
            $message = 'Клиент не найден или относится к другому направлению.';
            $messageType = 'err';
        }
    } elseif ($action === 'clear_client') {
        $_SESSION['sale_client'] = null;
    } elseif ($action === 'clear_cart') {
        $_SESSION['cart'] = [];
        $_SESSION['loaded_draft_id'] = null;
    } elseif ($action === 'save_draft') {
        // Отложить текущую корзину как черновик — не влияет на склад/долг клиента, пока не будет
        // открыт заново и доведён до настоящего оформления. См. includes/draft_orders.php.
        if (empty($_SESSION['sale_client']['id'])) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif (empty($_SESSION['cart'])) {
            $message = 'Корзина пуста — нечего сохранять.';
            $messageType = 'err';
        } else {
            $draftLabel = trim($_POST['draft_label'] ?? '');
            $draftId = save_draft_order(
                $_SESSION['direction'],
                (int)$_SESSION['sale_client']['id'],
                $_SESSION['sale_client']['name'],
                $draftLabel,
                $_SESSION['cart'],
                $_SESSION['loaded_draft_id']
            );
            $_SESSION['cart'] = [];
            $_SESSION['sale_client'] = null;
            $_SESSION['loaded_draft_id'] = null;
            $message = "Сохранено как черновик #$draftId. Посмотреть все черновики: раздел \"Черновики\".";
            $messageType = 'ok';
        }
    } elseif ($action === 'checkout') {
        $checkoutMode = $_POST['checkout_mode'] ?? 'paid'; // 'paid' или 'unpaid'

        // Оплата может быть разбита сразу по нескольким способам (например часть наличными, часть
        // картой) — наличные вводятся сразу в долларах, карта/QR/перевод — в сумах + курс на момент
        // операции (см. includes/payment_split.php).
        $paySplitResult = resolvePaySplit($cfg, $_POST);
        $paySplit = $paySplitResult['amounts'];
        $payDetail = $paySplitResult['detail'];
        $paidSum = array_sum($paySplit);
        // Автоскидка по брендам — считаем ЗАНОВО прямо перед оформлением (не доверяем тому, что было
        // отрисовано раньше: состав корзины/клиент могли поменяться с последней перерисовки страницы).
        $checkoutClient = !empty($_SESSION['sale_client']['id']) ? $api->getThirdparty((int)$_SESSION['sale_client']['id']) : null;
        $bdResult = bd_apply_discounts($api, $cfg, $_SESSION['cart'], is_array($checkoutClient) ? $checkoutClient : null);
        $discountedCart = $bdResult['cart'];

        // ВАЖНО: цена товара в корзине хранится БЕЗ НДС (Dolibarr price_base_type='HT' на строках
        // счёта), а реальная сумма к оплате — total_ttc, С НДС. Это только предварительная (быстрая)
        // проверка до создания счёта — окончательная сверка идёт ниже по НАСТОЯЩЕМУ total_ttc из
        // только что созданного счёта, чтобы не полагаться на собственный пересчёт НДС.
        $cartTotalHt = 0;
        foreach ($discountedCart as $item) { $cartTotalHt += $item['discounted_price'] * $item['qty']; }
        $cartTotalCheck = round($cartTotalHt * (1 + $cfg['vat_rate'] / 100), 2);
        $stockProblems = empty($_SESSION['cart']) ? [] : check_cart_stock($api, $cfg, $_SESSION['cart']);

        if (empty($_SESSION['sale_client']['id'])) {
            $message = 'Сначала выберите клиента.';
            $messageType = 'err';
        } elseif (empty($_SESSION['cart'])) {
            $message = 'Корзина пуста.';
            $messageType = 'err';
        } elseif ($stockProblems) {
            // Документ НЕ создаётся вообще, если остатка не хватает хотя бы по одной позиции — раньше
            // счёт создавался в любом случае, а нехватка склада только предупреждала постфактум.
            $message = "Недостаточно остатка на складе — счёт НЕ создан:\n" . implode("\n", $stockProblems) .
                "\nПоправьте остаток (Приём товара / Инвентаризация) или измените склад/количество в корзине, затем повторите.";
            $messageType = 'err';
        } elseif ($checkoutMode === 'paid' && $paySplitResult['errors']) {
            $message = implode(' ', $paySplitResult['errors']);
            $messageType = 'err';
        } elseif ($checkoutMode === 'paid' && empty($paySplit)) {
            $message = 'Укажите сумму хотя бы по одному способу оплаты (или используйте кнопку "Отпустить без оплаты").';
            $messageType = 'err';
        } elseif ($checkoutMode === 'paid' && abs($paidSum - $cartTotalCheck) > 0.01) {
            $message = "Сумма по способам оплаты ({$paidSum}) не совпадает с итогом корзины ({$cartTotalCheck}). Поправьте суммы — в сумме должно получиться ровно " . number_format($cartTotalCheck, 2) . ' $.';
            $messageType = 'err';
        } else {
            $invoiceCreated = false; // выставляется true, как только счёт реально создан и проведён —
            // с этого момента, независимо от исхода оплаты/склада ниже, обязателен редирект (см. конец
            // блока checkout) — иначе обновление страницы (F5) повторно отправит эту же форму и
            // создаст ВТОРОЙ счёт на ту же корзину (классическое "повторное оформление документа").
            $invoiceId = $api->createInvoice(['socid' => $_SESSION['sale_client']['id']]);
            if (!$invoiceId) {
                $message = 'Ошибка создания счёта: ' . $api->lastError;
                $messageType = 'err';
            } else {
                $linesOk = true;
                foreach ($discountedCart as $item) {
                    $r = $api->addInvoiceLine($invoiceId, $item['product_id'], $item['label'], $item['qty'], $item['discounted_price'], $cfg['vat_rate']);
                    if ($r === null) { $linesOk = false; break; }
                }
                if (!$linesOk) {
                    $message = 'Ошибка добавления позиции в счёт: ' . $api->lastError;
                    $messageType = 'err';
                } else {
                    $val = $api->validateInvoice($invoiceId);
                    if ($val === null) {
                        $message = 'Ошибка проведения счёта: ' . $api->lastError;
                        $messageType = 'err';
                    } else {
                        $lastInvoiceId = $invoiceId;
                        $invoiceCreated = true;
                        // Журнал скидок (02.09.2026) — кто/когда/какую скидку дал по каждой строке
                        // счёта (и автоматическую брендовую, и вручную поставленную кассиром).
                        bd_log_checkout_discounts($invoiceId, $discountedCart, $cfg['direction_label'] ?? $_SESSION['direction'], $_SESSION['direction'] ?? '');
                        // Счёт реально создан и проведён — если корзина была открыта из черновика,
                        // помечаем его переведённым СЕЙЧАС (независимо от исхода оплаты/склада ниже —
                        // документ уже настоящий, отменять связь с черновиком поздно).
                        if (!empty($_SESSION['loaded_draft_id'])) {
                            mark_draft_converted((int)$_SESSION['loaded_draft_id'], $_SESSION['direction'], $invoiceId);
                            $_SESSION['loaded_draft_id'] = null;
                        }
                        // Списываем остаток со склада, указанного для каждой позиции в корзине
                        $stockWarnings = [];
                        foreach ($_SESSION['cart'] as $item) {
                            $sres = $api->createStockMovement([
                                'product_id' => $item['product_id'],
                                'warehouse_id' => $item['warehouse_id'] ?? $cfg['default_warehouse_id'],
                                'qty' => -1 * abs($item['qty']),
                                'type' => 2,
                                'label' => 'Продажа, счёт #' . $invoiceId,
                            ]);
                            if ($sres === null) {
                                $whLabel = $cfg['warehouse_labels'][$item['warehouse_id']] ?? $item['warehouse_id'];
                                $stockWarnings[] = "\"{$item['label']}\" НЕ списан со склада ({$whLabel}): {$api->lastError}";
                            }
                        }
                        $stockWarningText = $stockWarnings ? ("\nВНИМАНИЕ, склад не списан: " . implode('; ', $stockWarnings) . ". Счёт создан, поправьте остаток вручную (например через Инвентаризацию).") : '';

                        if ($checkoutMode === 'unpaid') {
                            $message = "Готово! Счёт #$invoiceId создан БЕЗ ОПЛАТЫ (в долг)." . $stockWarningText;
                            $messageType = $stockWarnings ? 'err' : 'ok';
                            $_SESSION['cart'] = [];
                            $_SESSION['sale_client'] = null;
                        } else {
                            // Окончательная сверка — по НАСТОЯЩЕЙ сумме только что созданного счёта
                            // (total_ttc из Dolibarr), а не по нашему пересчёту НДС выше (тот был лишь
                            // быстрой прикидкой до создания счёта). Не платим вообще, если не сходится —
                            // лучше явный долг, чем тихая недоплата на сумму расхождения.
                            $freshInv = $api->getInvoice($invoiceId);
                            $trueTotal = is_array($freshInv) ? (float)($freshInv['total_ttc'] ?? 0) : null;

                            if ($trueTotal === null) {
                                $message = "Счёт #$invoiceId создан, но не удалось проверить его сумму — оплата НЕ проведена, оплатите через раздел Касса/Долги." . $stockWarningText;
                                $messageType = 'err';
                                $_SESSION['cart'] = [];
                                $_SESSION['sale_client'] = null;
                            } elseif (abs($paidSum - $trueTotal) > 0.01) {
                                $message = "Счёт #$invoiceId создан на сумму {$trueTotal} \$, но введённая оплата ({$paidSum} \$) не совпадает — оплата НЕ проведена. Счёт остался неоплаченным, оплатите точную сумму через раздел Касса/Долги." . $stockWarningText;
                                $messageType = 'err';
                                $_SESSION['cart'] = [];
                                $_SESSION['sale_client'] = null;
                            } else {
                                // Один или несколько способов оплаты сразу — каждый отдельным вызовом
                                // на ТОТ ЖЕ счёт (addPaymentDistributed с одним invoice id позволяет
                                // указать частичную сумму, обычный addPayment всегда платит "остаток целиком").
                                $payErrors = [];
                                $paidLabels = [];
                                foreach ($paySplit as $key => $amt) {
                                    $acc = $cfg['payment_accounts'][$key];
                                    $label = paySplitLabel($acc['label'], $payDetail[$key]);
                                    $res = $api->addPaymentDistributed(
                                        [$invoiceId => number_format($amt, 2, '.', '')],
                                        $acc['paytype'], $acc['id'], 'Касса ' . $cfg['direction_label'] . ' — ' . $label
                                    );
                                    if ($res === null) {
                                        $payErrors[] = "{$acc['label']}: {$api->lastError}";
                                    } else {
                                        $paidLabels[] = $label;
                                    }
                                }
                                if ($payErrors) {
                                    $message = "Счёт #$invoiceId создан и проведён, но не все оплаты записались (" . implode('; ', $payErrors) . ")." . $stockWarningText;
                                    $messageType = 'err';
                                    // Счёт уже реальный — очищаем корзину, как и в остальных ветках,
                                    // иначе кассир мог бы случайно нажать "Оформить" ещё раз на ТЕ ЖЕ
                                    // позиции и создать второй счёт поверх уже существующего. Оплату
                                    // остатка (если она вообще проходила частично) кассир доводит через
                                    // "Касса/Долги", как и написано в сообщении выше.
                                    $_SESSION['cart'] = [];
                                    $_SESSION['sale_client'] = null;
                                } else {
                                    // Реальная сумма в сумах (карта/QR/перевод) — параллельной проводкой
                                    // на единый сумовый счёт компании, независимо от списания долга в $.
                                    $uzsErrors = postUzsLedger($api, $cfg, $payDetail, 'Продажа #' . $invoiceId . ', касса ' . $cfg['direction_label']);
                                    $uzsWarning = $uzsErrors ? ("\nВНИМАНИЕ, сумовый счёт: " . implode('; ', $uzsErrors)) : '';
                                    $message = "Готово! Счёт #$invoiceId создан, оплачен: " . implode(' + ', $paidLabels) . "." . $stockWarningText . $uzsWarning;
                                    $messageType = ($stockWarnings || $uzsErrors) ? 'err' : 'ok';
                                    $_SESSION['cart'] = [];
                                    $_SESSION['sale_client'] = null;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Счёт был реально создан — редирект (POST → GET), а не отрисовка результата в ответе на этот
        // же POST. Так обновление страницы (F5) просто перезагружает эту же GET-страницу, а не
        // повторяет отправку формы оформления и не создаёт второй счёт. См. flash_set()/flash_get()
        // в includes/auth.php.
        if (!empty($invoiceCreated)) {
            flash_set($message, $messageType, ['last_invoice_id' => $lastInvoiceId ?? null]);
            // UX-K5 (внешний отчёт, 02.09.2026): раньше "Скачать накладную"/"Отменить эту продажу"
            // жили только в самой flash (одноразовое сообщение) — случайный уход со страницы (клик в
            // меню) и они пропадали без возврата. Теперь дублируем id в ОТДЕЛЬНОЕ, НЕ одноразовое
            // место сессии — переживает любую навигацию, пока не оформлена следующая продажа (тогда
            // просто перезаписывается новым id) или пока эту продажу не отменили (см. cancel_last_sale).
            if (!empty($lastInvoiceId)) {
                $_SESSION['last_sale'] = ['invoice_id' => $lastInvoiceId, 'time' => time()];
            }
            header('Location: sale.php');
            exit;
        }
    } elseif ($action === 'cancel_last_sale') {
        // Полная отмена только что оформленной продажи — кнопка видна ровно там же и столько же,
        // сколько "Скачать накладную" (сразу после оформления, через flash). Отменяет ВСЮ продажу
        // целиком (все позиции, полное количество) — для частичной правки есть "Возврат по счёту" в
        // return.php. Деньгами (если счёт был оплачен) эта кнопка НЕ занимается — угадывать способ
        // оплаты для автоматического возврата рискованно, вместо этого явно направляем в "Выдачу денег".
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $srcInvoice = $invoiceId ? $api->getInvoice($invoiceId) : null;
        $soc = is_array($srcInvoice) ? $api->getThirdparty((int)($srcInvoice['socid'] ?? 0)) : null;
        if (!is_array($srcInvoice) || !is_array($soc) || !client_belongs_to_direction($soc, $cfg['ref_prefix']) || (int)($srcInvoice['type'] ?? -1) !== 0) {
            $message = 'Счёт не найден или относится к другому направлению.';
            $messageType = 'err';
        } elseif (has_existing_credit_note_for_invoice($invoiceId)) {
            $message = 'По этому счёту уже был оформлен возврат/отмена ранее — повторно нельзя.';
            $messageType = 'err';
        } else {
            $lineWarehouses = get_invoice_line_warehouses($invoiceId);
            $itemsToReturn = [];
            foreach (($srcInvoice['lines'] ?? []) as $line) {
                $fkProduct = (int)($line['fk_product'] ?? 0);
                if (!$fkProduct) continue;
                $itemsToReturn[] = [
                    'product_id' => $fkProduct,
                    'label' => $line['product_label'] ?? $line['desc'] ?? '',
                    'price' => (float)($line['subprice'] ?? 0),
                    'qty' => abs((float)($line['qty'] ?? 0)),
                    'warehouse_id' => $lineWarehouses[$fkProduct][0] ?? $cfg['default_warehouse_id'],
                ];
            }
            if (empty($itemsToReturn)) {
                $message = 'В счёте нет позиций с товаром — отменять нечего.';
                $messageType = 'err';
            } else {
                $creditNoteId = $api->createCreditNote((int)$srcInvoice['socid'], $invoiceId);
                if (!$creditNoteId) {
                    $message = 'Ошибка создания отмены: ' . $api->lastError;
                    $messageType = 'err';
                } else {
                    $linesOk = true;
                    foreach ($itemsToReturn as $item) {
                        $r = $api->addInvoiceLine($creditNoteId, $item['product_id'], $item['label'], $item['qty'], $item['price'], $cfg['vat_rate']);
                        if ($r === null) { $linesOk = false; break; }
                    }
                    if (!$linesOk) {
                        $message = 'Ошибка добавления позиций в отмену: ' . $api->lastError;
                        $messageType = 'err';
                    } else {
                        $val = $api->validateInvoice($creditNoteId);
                        if ($val === null) {
                            $message = 'Ошибка проведения отмены: ' . $api->lastError;
                            $messageType = 'err';
                        } else {
                            $stockWarnings = [];
                            foreach ($itemsToReturn as $item) {
                                $sres = $api->createStockMovement([
                                    'product_id' => $item['product_id'],
                                    'warehouse_id' => $item['warehouse_id'],
                                    'qty' => abs($item['qty']),
                                    'type' => 3,
                                    'label' => 'Отмена продажи ' . ($srcInvoice['ref'] ?? '') . ', документ #' . $creditNoteId,
                                ]);
                                if ($sres === null) {
                                    $whLabel = $cfg['warehouse_labels'][$item['warehouse_id']] ?? $item['warehouse_id'];
                                    $stockWarnings[] = "\"{$item['label']}\" не зачислен на склад ({$whLabel}): {$api->lastError}";
                                }
                            }
                            $paidSum = 0;
                            foreach ($api->getInvoicePayments($invoiceId) as $p) { $paidSum += (float)($p['amount'] ?? 0); }
                            $warnText = $stockWarnings ? ("\nВНИМАНИЕ: " . implode('; ', $stockWarnings)) : '';
                            $message = "Продажа {$srcInvoice['ref']} отменена (возврат #$creditNoteId): товар вернулся на склад, долг клиента уменьшен.$warnText";
                            if ($paidSum > 0.01) {
                                // K-3 (внешняя приёмка, 03.09.2026): раньше текст обещал "переплата уже
                                // там появится" — но если у клиента был другой долг, отменённая оплата
                                // зачлась в него, и никакой переплаты кассир в «Выдаче денег» не находил.
                                // Теперь смотрим РЕАЛЬНЫЙ баланс клиента после отмены и пишем точную сумму.
                                $balanceAfterCancel = (float)(($api->getOutstandingInvoices((int)$srcInvoice['socid'])['opened']) ?? 0);
                                $toPayOut = $balanceAfterCancel < -0.01 ? abs($balanceAfterCancel) : 0.0;
                                $message .= "\nЭтот счёт был оплачен на " . number_format($paidSum, 2) . " \$.";
                                if ($toPayOut > 0.01) {
                                    $message .= " К выдаче клиенту: " . number_format($toPayOut, 2) . " \$ — раздел «Выдача денег клиенту».";
                                } else {
                                    $message .= " Выдавать ничего не нужно: эта оплата зачлась в другой долг клиента, его текущий долг — " . number_format(max(0, $balanceAfterCancel), 2) . " \$.";
                                }
                            }
                            $messageType = $stockWarnings ? 'err' : 'ok';
                            flash_set($message, $messageType);
                            // Отменённый счёт больше не должен предлагать "Скачать накладную"/
                            // "Отменить эту продажу" повторно — убираем персистентную ссылку (см.
                            // UX-K5 выше), но только если она указывала именно на ЭТОТ счёт (иначе
                            // можно было бы случайно затереть ссылку на другую, более свежую продажу).
                            if (($_SESSION['last_sale']['invoice_id'] ?? null) === $invoiceId) {
                                $_SESSION['last_sale'] = null;
                            }
                            header('Location: sale.php');
                            exit;
                        }
                    }
                }
            }
        }
    }
}

$flash = flash_get();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
    $lastInvoiceId = $flash['extra']['last_invoice_id'] ?? null;
}
// UX-K5: если сообщения-flash уже нет (кассир ушёл со страницы и вернулся), но продажа ещё не
// отменена — кнопки "Накладная"/"Отменить" всё равно показываем из персистентного $_SESSION['last_sale'].
if (!$lastInvoiceId && !empty($_SESSION['last_sale']['invoice_id'])) {
    $lastInvoiceId = (int)$_SESSION['last_sale']['invoice_id'];
}

// Цена товара в корзине хранится БЕЗ НДС (как и в самом Dolibarr) — но клиенту к оплате нужна сумма
// С НДС, поэтому для отображения и для полей оплаты используем именно ttc-сумму, не голую HT.
$saleClientThirdparty = $_SESSION['sale_client'] ? $api->getThirdparty((int)$_SESSION['sale_client']['id']) : null;
if (!is_array($saleClientThirdparty)) $saleClientThirdparty = null;

// Автоскидка по брендам (Caleffi/Madas/Sitem/Fantini Cosmi/Mut) — пересчитывается на каждой отрисовке,
// не хранится в самой корзине: переход через порог зависит от ВСЕГО состава корзины разом, добавили
// ещё один товар этих брендов — и порог мог быть пройден задним числом для уже лежавших строк.
$bdRender = bd_apply_discounts($api, $cfg, $_SESSION['cart'], $saleClientThirdparty);
$cartForDisplay = $bdRender['cart'];

$vatMult = 1 + $cfg['vat_rate'] / 100;
$cartTotal = 0;
foreach ($cartForDisplay as $item) {
    $cartTotal += $item['discounted_price'] * $item['qty'] * $vatMult;
}
$cartTotal = round($cartTotal, 2);

$clientDebt = null;
if ($_SESSION['sale_client']) {
    $out = $api->getOutstandingInvoices((int)$_SESSION['sale_client']['id']);
    if (is_array($out) && isset($out['opened'])) {
        $clientDebt = (float)$out['opened'];
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Продажа</h1>
<?php // UX-K1 (внешний отчёт, 02.09.2026): раньше здесь дублировался ещё один "← Сменить клиента" —
      // делал ровно то же самое, что кнопка "Сменить" внутри карточки "Клиент" чуть ниже. Убран,
      // единственная кнопка смены клиента теперь внутри самой карточки. ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= nl2br(htmlspecialchars($message)) ?></p><?php endif; ?>
<?php if ($lastInvoiceId): ?>
  <?php if (!$flash): ?>
    <p class="muted">Последняя оформленная продажа — счёт #<?= (int)$lastInvoiceId ?> (кнопки ниже
    доступны, пока не оформлена следующая продажа):</p>
  <?php endif; ?>
  <p class="stage-row">
    <a class="btn secondary" href="invoice_excel.php?id=<?= (int)$lastInvoiceId ?>">📄 Скачать накладную (Excel)</a>
    <form method="post" style="display:inline" onsubmit="return appConfirmSubmit(this, 'Отменить эту продажу целиком? Товар вернётся на склад, долг клиента уменьшится. Если счёт был оплачен — деньги придётся вернуть отдельно через «Выдачу денег».');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel_last_sale">
      <input type="hidden" name="invoice_id" value="<?= (int)$lastInvoiceId ?>">
      <button type="submit" class="secondary">❌ Отменить эту продажу</button>
    </form>
  </p>
<?php endif; ?>

<div class="grid-2col">
<div>

<div class="card">
  <h2>Клиент</h2>
  <?php if ($_SESSION['sale_client']): ?>
    <div class="row" style="align-items:center">
      <div>
        <strong><?= htmlspecialchars($_SESSION['sale_client']['name']) ?></strong>
        <?php if ($clientDebt !== null): ?>
          <div><span class="badge <?= $clientDebt > 0.01 ? 'badge-debt' : 'badge-ok' ?>">
            <?php if ($clientDebt > 0.01): ?>Долг: <?= number_format($clientDebt, 2) ?> $
            <?php elseif ($clientDebt < -0.01): ?>Аванс/переплата: <?= number_format(abs($clientDebt), 2) ?> $
            <?php else: ?>Долгов нет
            <?php endif; ?>
          </span></div>
        <?php endif; ?>
        <div><a href="client_form.php?ctx=sale&id=<?= (int)$_SESSION['sale_client']['id'] ?>" class="muted">✏️ Редактировать</a></div>
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
    <p style="margin-top:8px"><a href="client_form.php?ctx=sale" class="btn secondary small">+ Новый клиент</a></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Добавить товар</h2>
  <div id="categoryTiles" class="cat-tiles"></div>
  <div id="categoryBackRow" style="display:none">
    <button type="button" id="btnBackToCats" class="secondary">← Все категории</button>
    <strong id="currentCatLabel"></strong>
  </div>
  <input type="text" id="productSearch" placeholder="Поиск по названию или артикулу...">
  <div id="productResults" class="result-list"></div>
</div>

</div>
<div>

<div class="card">
  <h2>Корзина</h2>
  <?php if (empty($_SESSION['cart'])): ?>
    <p class="muted">Пусто</p>
  <?php else: ?>
    <div class="cart-table">
      <?php foreach ($cartForDisplay as $idx => $item): ?>
        <?php
          $itemPriceTtc = $item['discounted_price'] * $vatMult;
          $origPriceTtc = $item['price'] * $vatMult;
          $hasDiscount = $item['discount_rate'] > 0;
        ?>
        <div class="cart-row" data-idx="<?= $idx ?>" data-catalog-price="<?= htmlspecialchars($origPriceTtc) ?>">
          <div class="cart-row-main">
            <div class="cart-row-name">
              <?= htmlspecialchars($item['label']) ?>
              <span class="muted">
                · <?= htmlspecialchars($item['ref']) ?> ·
                <?php // K-3 (внешний QA-аудит, раунд 2, 03.09.2026): элементы теперь ВСЕГДА в разметке
                      // (не создаются/удаляются условно PHP-ветвлением) — JS после тихой правки
                      // qty/склада/скидки получает от сервера свежее состояние и просто переключает
                      // видимость/текст, не пересобирая DOM. ?>
                <s class="cart-orig-price" style="<?= $hasDiscount ? '' : 'display:none' ?>"><?= number_format($origPriceTtc, 2) ?></s>
                <span class="cart-item-price"><?= number_format($itemPriceTtc, 2) ?></span> $/шт
                <span class="badge cart-discount-badge <?= $item['discount_is_manual'] ? 'badge-advance' : 'badge-ok' ?>" style="margin-left:4px; <?= $hasDiscount ? '' : 'display:none' ?>"><?= $item['discount_is_manual'] ? 'скидка вручную' : 'скидка' ?> <?= rtrim(rtrim(number_format($item['discount_rate'], 1), '0'), '.') ?>%</span>
              </span>
            </div>
            <button type="button" class="cart-remove" data-idx="<?= $idx ?>" title="Убрать">✕</button>
          </div>
          <div class="cart-row-controls">
            <input type="number" class="cart-qty" step="any" min="0.001" value="<?= htmlspecialchars($item['qty']) ?>">
            <select class="cart-warehouse">
              <?php foreach ($cfg['warehouse_labels'] as $whId => $whLabel): ?>
                <?php $whStock = $item['stock_by_warehouse'][$whId] ?? null; ?>
                <option value="<?= $whId ?>" <?= $whId == ($item['warehouse_id'] ?? $cfg['default_warehouse_id']) ? 'selected' : '' ?>><?= htmlspecialchars($whLabel) ?><?= $whStock !== null ? ' · ост. ' . $whStock : '' ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" class="cart-discount <?= $item['discount_is_manual'] ? 'is-manual' : '' ?>" step="0.1" min="0" max="100" title="Скидка, %" value="<?= rtrim(rtrim(number_format($item['discount_rate'], 2), '0'), '.') ?>">
            <span class="cart-subtotal"><?= number_format($item['qty'] * $itemPriceTtc, 2) ?> $</span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (($bdRender['suggested_rate'] ?? 0) > 0): ?>
      <p class="muted" style="margin-top:6px">
        🏷️ Для товаров брендов Caleffi/Madas/Sitem/Fantini Cosmi/Mut по умолчанию предложена скидка
        <?= rtrim(rtrim(number_format($bdRender['suggested_rate'], 1), '0'), '.') ?>% — при желании поправьте её прямо в строке корзины (поле "%").
      </p>
    <?php endif; ?>
    <div class="total">Итого: <span id="cartGrandTotal"><?= number_format($cartTotal, 2) ?></span> $</div>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['cart'])): ?>
<div class="card">
  <h2>Оплата и оформление</h2>
  <p class="muted">Можно разбить оплату на несколько способов сразу (например часть наличными, часть картой) — просто заполните нужные поля.</p>
  <form method="post" id="payForm">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="checkout">
    <input type="hidden" name="checkout_mode" value="paid">
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
            <input type="number" step="0.01" min="0" class="pay-amount" name="pay[<?= $key ?>]" placeholder="0.00" data-first="<?= $key === array_key_first($cfg['payment_accounts']) ? '1' : '0' ?>">
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="muted" id="payCheckLine">Введено: <span id="paySum">0.00</span> $ из <span id="payCheckTotal"><?= number_format($cartTotal, 2) ?></span> $</div>
    <button type="submit">Оформить продажу и принять оплату</button>
  </form>
  <form method="post" onsubmit="return appConfirmSubmit(this, 'Отпустить товар без оплаты? Долг клиента увеличится.');" style="margin-top:10px">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="checkout">
    <input type="hidden" name="checkout_mode" value="unpaid">
    <button type="submit" class="secondary">Отпустить без оплаты (в долг)</button>
  </form>
  <form method="post" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_draft">
    <label>Пометка для черновика (необязательно)</label>
    <div class="row">
      <?php // UX-K2 (внешний отчёт, 02.09.2026): раньше поле предзаполнялось именем клиента — список
            // черновиков и так показывает клиента отдельной колонкой, предзаполнение выглядело как
            // случайность/дубль, а не осознанная пометка. Теперь поле всегда пустое по умолчанию. ?>
      <input type="text" name="draft_label" placeholder="например: Иванов, трубы">
      <div style="flex:0"><button type="submit" class="secondary">💾 Сохранить как черновик</button></div>
    </div>
    <p class="muted" style="margin:4px 0 0">Не влияет на склад и долг клиента — можно вернуться и оформить позже. Все черновики — в разделе «Черновики».</p>
  </form>
</div>
<?php endif; ?>

</div>
</div>

<script>
window.CATEGORIES = <?= json_encode($cfg['categories'], JSON_UNESCAPED_UNICODE) ?>;

window.onProductPick = function (p) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="add_to_cart">' +
    '<input type="hidden" name="product_id" value="' + p.id + '">' +
    '<input type="hidden" name="ref" value="' + p.ref.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="label" value="' + p.label.replace(/"/g, '&quot;') + '">' +
    '<input type="hidden" name="price" value="' + p.price + '">' +
    '<input type="hidden" name="stock_by_warehouse" value="' + JSON.stringify(p.stock_by_warehouse || {}).replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
};

window.onClientPick = function (c) {
  const form = document.createElement('form');
  form.method = 'post';
  form.innerHTML = '<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">' + '<input type="hidden" name="action" value="select_client">' +
    '<input type="hidden" name="client_id" value="' + c.id + '">' +
    '<input type="hidden" name="client_name" value="' + c.name.replace(/"/g, '&quot;') + '">';
  document.body.appendChild(form);
  form.submit();
};

// --- Оплата: по умолчанию вся сумма в первый способ оплаты (типовой случай — один способ),
// кассир может очистить и разбить на несколько полей. Живой счётчик "введено / нужно". ---
// ⚠️ 02.09.2026, BUG-K2 (внешний отчёт): раньше cartTotal был константой, снятой один раз при
// отрисовке — после любой правки строки корзины (qty/склад/скидка) "Итого" на экране менялось, а
// сверка оплаты и подпись "из Y $" продолжали сравниваться со СТАРЫМ значением. Теперь liveCartTotal —
// общая переменная, которую recalcGrandTotal() обновляет при КАЖДОЙ правке, а updatePaySum() читает
// именно её — оба места видят один и тот же, всегда актуальный итог.
let liveCartTotal = <?= json_encode($cartTotal) ?>;

function updatePaySum() {
  const payForm = document.getElementById('payForm');
  if (!payForm) return;
  let sum = 0;
  payForm.querySelectorAll('.pay-amount').forEach(inp => { sum += parseFloat(inp.value) || 0; });
  // Сумы + курс — считаем доллары на лету, чтобы кассир сразу видел, сколько это в $
  payForm.querySelectorAll('.pay-uzs-group').forEach(group => {
    const key = group.querySelector('.pay-uzs-input').dataset.key;
    const uzs = parseFloat(group.querySelector('.pay-uzs-input').value) || 0;
    const rate = parseFloat(group.querySelector('.pay-rate-input').value) || 0;
    const usd = rate > 0 ? uzs / rate : 0;
    const preview = group.querySelector('.pay-uzs-preview[data-key="' + key + '"]');
    if (preview) preview.textContent = usd.toFixed(2);
    sum += usd;
  });
  const el = document.getElementById('paySum');
  if (el) el.textContent = sum.toFixed(2);
  const line = document.getElementById('payCheckLine');
  if (line) line.style.color = Math.abs(sum - liveCartTotal) > 0.01 ? 'var(--danger)' : 'var(--ok)';
}

(function () {
  const payForm = document.getElementById('payForm');
  if (!payForm) return;
  const firstInput = payForm.querySelector('.pay-amount[data-first="1"]');
  if (firstInput && !firstInput.value) firstInput.value = liveCartTotal.toFixed(2);
  payForm.querySelectorAll('.pay-amount, .pay-uzs-input, .pay-rate-input').forEach(inp => inp.addEventListener('input', updatePaySum));
  updatePaySum();
})();

// --- Корзина: мгновенный пересчёт суммы + тихое сохранение на сервер без перезагрузки ---
// data-catalog-price — цена БЕЗ скидки (с НДС) — эффективная цена всегда считается от н.её плюс
// текущее значение поля "%", так что и скидка, и количество пересчитывают сумму мгновенно на клиенте.
function effectivePrice(row) {
  const catalogPrice = parseFloat(row.dataset.catalogPrice) || 0;
  const discountInput = row.querySelector('.cart-discount');
  const discount = discountInput ? (parseFloat(discountInput.value) || 0) : 0;
  return catalogPrice * (1 - discount / 100);
}

function recalcRow(row) {
  const price = effectivePrice(row);
  const qty = parseFloat(row.querySelector('.cart-qty').value) || 0;
  row.querySelector('.cart-subtotal').textContent = (price * qty).toFixed(2) + ' $';
  const priceEl = row.querySelector('.cart-item-price');
  if (priceEl) priceEl.textContent = price.toFixed(2);
}

function recalcGrandTotal() {
  let total = 0;
  document.querySelectorAll('.cart-row').forEach(row => {
    const price = effectivePrice(row);
    const qty = parseFloat(row.querySelector('.cart-qty').value) || 0;
    total += price * qty;
  });
  liveCartTotal = total;
  const el = document.getElementById('cartGrandTotal');
  if (el) el.textContent = total.toFixed(2);
  const totalEl = document.getElementById('payCheckTotal');
  if (totalEl) totalEl.textContent = total.toFixed(2);
  updatePaySum();
}

// K-3 (внешний QA-аудит, раунд 2, 03.09.2026): число из PHP number_format(rate,1) без хвостовых
// нулей/точки — та же отделка, что и в самой PHP-отрисовке, чтобы текст бейджа не "прыгал" между
// серверной и клиентской перерисовкой (14.5 остаётся 14.5, 10.0 становится 10).
function trimRate(n) {
  return (Math.round(n * 10) / 10).toString();
}

// K-3: применить свежее состояние ОДНОЙ строки корзины, пришедшее от сервера — цена, скидка (бейдж +
// класс "ручная"), сумма строки. Поле "%" не трогаем, если оно СЕЙЧАС в фокусе (кассир как раз печатает
// в него) — иначе рискуем сбить курсор/ввод посреди набора.
function applyServerRowState(row, r) {
  const discountInput = row.querySelector('.cart-discount');
  if (discountInput && document.activeElement !== discountInput) {
    discountInput.value = trimRate(r.discount_rate);
    discountInput.classList.toggle('is-manual', !!r.discount_is_manual);
  }
  const hasDiscount = r.discount_rate > 0.001;
  const origEl = row.querySelector('.cart-orig-price');
  if (origEl) origEl.style.display = hasDiscount ? '' : 'none';
  const priceEl = row.querySelector('.cart-item-price');
  if (priceEl) priceEl.textContent = r.price_ttc.toFixed(2);
  const badgeEl = row.querySelector('.cart-discount-badge');
  if (badgeEl) {
    badgeEl.style.display = hasDiscount ? '' : 'none';
    badgeEl.textContent = (r.discount_is_manual ? 'скидка вручную ' : 'скидка ') + trimRate(r.discount_rate) + '%';
    badgeEl.className = 'badge cart-discount-badge ' + (r.discount_is_manual ? 'badge-advance' : 'badge-ok');
  }
  const subtotalEl = row.querySelector('.cart-subtotal');
  if (subtotalEl) subtotalEl.textContent = r.subtotal.toFixed(2) + ' $';
}

// K-3: применить ответ сервера ко ВСЕЙ корзине разом (не только к тронутой строке — переход порога
// скидки может задеть сразу несколько брендовых строк) + обновить "Итого"/сверку оплаты живым числом
// от сервера, а не клиентским пересчётом (который раньше мог разойтись с тем, что решил сервер).
function applyServerCartState(data) {
  if (!data || !data.rows) return;
  document.querySelectorAll('.cart-row').forEach(row => {
    const state = data.rows[row.dataset.idx];
    if (state) applyServerRowState(row, state);
  });
  if (typeof data.grand_total === 'number') {
    liveCartTotal = data.grand_total;
    const el = document.getElementById('cartGrandTotal');
    if (el) el.textContent = data.grand_total.toFixed(2);
    const totalEl = document.getElementById('payCheckTotal');
    if (totalEl) totalEl.textContent = data.grand_total.toFixed(2);
    updatePaySum();
  }
}

// ⚠️ 02.09.2026, BUG-K1 (внешний отчёт, денежные последствия): раньше persistCartItem() ВСЕГДА
// отправляла текущее значение поля "%" как явную РУЧНУЮ скидку — в том числе когда кассир поменял
// только qty/склад, а поля "%" вообще не касался. Из-за этого автоматический переход 10% → 14,5% при
// пересечении порога $10 000 "замораживался" на старом значении при любой правке количества —
// клиент получал меньшую скидку и переплачивал. Теперь discount/discount_clear отправляются на
// сервер ТОЛЬКО когда menяли именно поле скидки (editedDiscount=true) — правка qty/склада НЕ трогает
// discount_rate вообще, автоматическая ставка продолжает пересчитываться нормально.
//
// K-3: ответ сервера теперь всегда JSON со свежим состоянием ВСЕЙ корзины — применяем его поверх
// клиентского пересчёта, который был только мгновенной прикидкой до ответа сервера.
function persistCartItem(row, editedDiscount) {
  const idx = row.dataset.idx;
  const qty = row.querySelector('.cart-qty').value;
  const warehouseId = row.querySelector('.cart-warehouse').value;
  const params = { _csrf: '<?= csrf_token() ?>', action: 'update_cart_item', idx, qty, warehouse_id: warehouseId };
  if (editedDiscount) {
    const discountInput = row.querySelector('.cart-discount');
    const discountRaw = discountInput ? discountInput.value.trim() : '';
    if (discountRaw === '') { params.discount_clear = '1'; } else { params.discount = discountRaw; }
  }
  fetch('sale.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(params),
  }).then(r => r.json()).then(applyServerCartState).catch(() => {});
}

document.querySelectorAll('.cart-row').forEach(row => {
  const qtyInput = row.querySelector('.cart-qty');
  const whSelect = row.querySelector('.cart-warehouse');
  const discountInput = row.querySelector('.cart-discount');

  qtyInput.addEventListener('input', () => { recalcRow(row); recalcGrandTotal(); });
  qtyInput.addEventListener('change', () => { if (parseFloat(qtyInput.value) > 0) persistCartItem(row, false); });
  whSelect.addEventListener('change', () => persistCartItem(row, false));
  if (discountInput) {
    discountInput.addEventListener('input', () => { recalcRow(row); recalcGrandTotal(); });
    // K-4: больше НЕ помечаем "ручная" оптимистично на клиенте до ответа сервера — сервер сам решает,
    // отличается ли введённое число от предложенного, и applyServerCartState() выставит класс верно.
    discountInput.addEventListener('change', () => persistCartItem(row, true));
  }

  row.querySelector('.cart-remove').addEventListener('click', () => {
    fetch('sale.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf: '<?= csrf_token() ?>', action: 'remove_from_cart', idx: row.dataset.idx }),
    }).then(() => {
      row.remove();
      recalcGrandTotal();
      if (document.querySelectorAll('.cart-row').length === 0) {
        location.reload(); // корзина опустела — перезагружаем, чтобы скрыть блок оплаты и показать "Пусто"
      }
    });
  });
});
</script>
<script src="assets/picker.js"></script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
