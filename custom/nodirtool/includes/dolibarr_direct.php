<?php
/**
 * Прямой доступ к PHP-классам Dolibarr в обход REST API — там просто нет нужных операций:
 * "переоткрыть" проведённый/утверждённый/отправленный заказ обратно в черновик, изменить или
 * удалить уже существующую строку (см. CLAUDE.md, 29.08.2026 — "NodirTool: полное редактирование
 * заказа поставщику"). REST API supplier orders умеет только СОЗДАТЬ новую строку — ни изменить, ни
 * удалить, ни переоткрыть статус там нельзя, хотя сами методы (`updateline`, `deleteLine`,
 * `setReopen`) есть в самом классе `CommandeFournisseur`, их просто не вывели наружу.
 *
 * Подключать ТОЛЬКО из тех обработчиков действий, где это реально нужно (не на каждый заход на
 * страницу) — bootstrap Dolibarr тяжелее, чем один HTTP-вызов к REST API. Логика партий/расходов
 * (расчёт себестоимости) — в отдельном includes/logistics.php, она не использует классы Dolibarr
 * вообще (чистый SQL), незачем платить за этот bootstrap ради неё.
 *
 * Действует от имени пользователя `api_purchasing` (id=4 в Dolibarr) — того же, чей API-ключ уже
 * используется всем NodirTool, для единообразного аудита (в fk_user_valid/fk_user_approve и т.п.
 * будет видно "api_purchasing", как и от всех остальных действий этого инструмента).
 *
 * ВАЖНО: master.inc.php не трогает PHP-сессии (проверено перед написанием) — безопасно подключать
 * посреди уже идущего запроса NodirTool, где сессия уже стартовала через includes/auth.php.
 */

require_once 'C:\\Dolibarr\\htdocs\\master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';

/** Пользователь Dolibarr, от чьего имени идут прямые правки. */
function dolibarr_direct_user()
{
    global $db;
    static $cachedUser = null;
    if ($cachedUser === null) {
        $cachedUser = new User($db);
        $cachedUser->fetch(4); // api_purchasing
        $cachedUser->getrights();
    }
    return $cachedUser;
}

/**
 * Переоткрыть заказ вплоть до черновика (statut=0) — может понадобиться несколько вызовов
 * setReopen() подряд (Dolibarr откатывает статус только на ОДИН шаг за раз: "Отправлен поставщику"
 * сначала станет "Утверждён", и только следующим вызовом — черновик). Разрешено только для статусов
 * 1/2/3 (Проведён/Утверждён/Отправлен поставщику) — для остальных явный отказ, чтобы не трогать
 * заказы с уже реальными складскими движениями (частично/полностью получен) или отменённые.
 */
function dolibarr_reopen_to_draft(int $orderId, string $reason = ''): array
{
    global $db;
    $user = dolibarr_direct_user();
    $order = new CommandeFournisseur($db);
    if ($order->fetch($orderId) <= 0) {
        return ['ok' => false, 'error' => 'Заказ не найден.'];
    }
    if (!in_array((int)$order->status, [1, 2, 3], true)) {
        return ['ok' => false, 'error' => 'Переоткрыть для правки можно только заказ в статусе "Проведён/Утверждён/Отправлен поставщику".'];
    }

    $guard = 0;
    while ((int)$order->status !== 0 && $guard < 5) {
        $r = $order->setReopen($user);
        if ($r <= 0) {
            return ['ok' => false, 'error' => 'Ошибка переоткрытия: ' . ($order->error ?: 'неизвестная ошибка Dolibarr')];
        }
        $order->fetch($orderId);
        $guard++;
    }
    if ((int)$order->status !== 0) {
        return ['ok' => false, 'error' => 'Не удалось довести заказ до черновика (остался на статусе ' . $order->status . ').'];
    }

    // След в самом Dolibarr — переоткрытие не логируется штатно (llx_commande_fournisseur_log не
    // задействована в этой версии), поэтому дописываем строку в заметку вручную. Причина — обязательна
    // (проверяется в order_view.php перед вызовом), чтобы у последующего "были правки после отправки
    // поставщику" всегда было объяснение, а не голая отметка времени.
    $reasonText = trim($reason) !== '' ? trim($reason) : '(причина не указана)';
    $order->note_private = trim(trim((string)$order->note_private) . "\n[NodirTool] Заказ переоткрыт для правки: " . date('d.m.Y H:i') . ' — ' . $reasonText);
    $order->update($user);

    return ['ok' => true];
}

/** Изменить существующую строку заказа (описание/цена/количество) — только пока заказ черновик. */
function dolibarr_update_line(int $orderId, int $lineId, string $desc, float $qty, float $price): array
{
    global $db;
    $user = dolibarr_direct_user();
    $order = new CommandeFournisseur($db);
    if ($order->fetch($orderId) <= 0) {
        return ['ok' => false, 'error' => 'Заказ не найден.'];
    }
    if ((int)$order->status !== 0) {
        return ['ok' => false, 'error' => 'Строки можно менять только пока заказ черновик — сначала «Изменить заказ».'];
    }
    // 04.09.2026 (B2): $price приходит В ВАЛЮТЕ ЗАКАЗА. Для валютного заказа базовую цену передаём
    // НУЛЁМ, чтобы Dolibarr вывел её из валютной по курсу заказа — `updateline()`, в отличие от
    // `addline()`, сама этого не делает (там нет строки `if ($pu_ht_devise > 0) { $pu = 0; }`), а
    // `calcul_price_total()` берёт базовую цену как есть, если она непустая. Передать одно и то же
    // число в оба поля, как было раньше, для EUR-заказа означало бы «3.69 евро = 3.69 доллара».
    $isForeign = !empty($order->multicurrency_code) && $order->multicurrency_code !== 'USD'
        && (float)$order->multicurrency_tx > 0;
    $puBase = $isForeign ? 0 : $price;
    $r = $order->updateline($lineId, $desc, $puBase, $qty, 0, 0, 0, 0, 'HT', 0, 0, 0, 0, 0, [], null, $price, '');
    if ($r < 0) {
        $err = $order->error ?: (is_array($order->errors) ? implode('; ', $order->errors) : 'неизвестная ошибка');
        return ['ok' => false, 'error' => 'Ошибка изменения строки: ' . $err];
    }
    return ['ok' => true];
}

/** Удалить существующую строку заказа — только пока заказ черновик. */
function dolibarr_delete_line(int $orderId, int $lineId): array
{
    global $db;
    $user = dolibarr_direct_user();
    $order = new CommandeFournisseur($db);
    if ($order->fetch($orderId) <= 0) {
        return ['ok' => false, 'error' => 'Заказ не найден.'];
    }
    if ((int)$order->status !== 0) {
        return ['ok' => false, 'error' => 'Строки можно удалять только пока заказ черновик — сначала «Изменить заказ».'];
    }
    $r = $order->deleteLine($lineId);
    if ($r <= 0) {
        $err = $order->error ?: (is_array($order->errors) ? implode('; ', $order->errors) : 'неизвестная ошибка');
        if (strpos($err, 'LineAlreadyDispatched') !== false) {
            $err = 'Эта позиция уже частично принята на склад — удалить нельзя.';
        }
        return ['ok' => false, 'error' => 'Ошибка удаления строки: ' . $err];
    }
    return ['ok' => true];
}
