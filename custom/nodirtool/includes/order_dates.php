<?php
/**
 * Две даты заказа поставщику (12.09.2026, решение пользователя).
 *
 * Раньше дата была одна — «ожидаемая доставка», её вписывали руками, и сводка по ней предупреждала.
 * Пользователь описал, как это на самом деле: сначала поставщик ДЕЛАЕТ товар (типичный срок поставки
 * из его карточки), и к концу этого срока пора договариваться с перевозчиком; только потом идёт сама
 * доставка, и её срок называет перевозчик. Поэтому даты две:
 *
 *   1. «Готов у поставщика» (доп.поле заказа `ready_date`) — ставится САМА при отправке заказа
 *      поставщику: дата отправки + «Типичный срок поставки, дней» из карточки поставщика. Правится
 *      руками в «Заказах в пути», если поставщик назвал свой срок. За неделю до неё (и после, если
 *      просрочено) заказ виден на сводке в блоке «Пора заказывать перевозку» — но только пока по нему
 *      не оформлен рейс.
 *   2. «Ожидаемая доставка» (родное поле Dolibarr `delivery_date`) — прибытие в Ташкент. Спрашивается
 *      при оформлении рейса (includes/shipments.php) и правится там же, где раньше.
 *
 * Принятый заказ (статусы 4/5) из напоминаний уходит сам — оба списка берут только «отправлен/утверждён».
 */

require_once __DIR__ . '/shipments.php';   // shipments_for_orders(), logistics_db()

const ORDER_READY_WARN_DAYS = 7;   // за сколько дней предупреждать — решение пользователя 12.09.2026

/** «Типичный срок поставки» поставщика, дней; 0 — не заполнен. */
function order_supplier_lead_days(int $socId): int
{
    if ($socId <= 0) return 0;
    $st = logistics_db()->prepare("SELECT lead_time_days FROM llx_societe_extrafields WHERE fk_object = ?");
    $st->bind_param('i', $socId); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return max(0, (int)($row['lead_time_days'] ?? 0));
}

/** Дата готовности заказа ('Y-m-d') или '' — если поле не заполнено. */
function order_ready_date(int $orderId): string
{
    $st = logistics_db()->prepare("SELECT ready_date FROM llx_commande_fournisseur_extrafields WHERE fk_object = ?");
    $st->bind_param('i', $orderId); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return (string)($row['ready_date'] ?? '');
}

/** Записать дату готовности ('' — очистить). Прямым SQL: доп.поля заказа через REST капризны (см. CLAUDE.md). */
function order_set_ready_date(int $orderId, string $date): bool
{
    $db = logistics_db();
    $val = $date !== '' ? $date : null;
    $has = $db->query("SELECT rowid FROM llx_commande_fournisseur_extrafields WHERE fk_object = " . (int)$orderId)->fetch_assoc();
    $st = $has
        ? $db->prepare("UPDATE llx_commande_fournisseur_extrafields SET ready_date = ? WHERE fk_object = ?")
        : $db->prepare("INSERT INTO llx_commande_fournisseur_extrafields (ready_date, fk_object) VALUES (?, ?)");
    $st->bind_param('si', $val, $orderId);
    $ok = $st->execute(); $st->close();
    return (bool)$ok;
}

/**
 * При отправке заказа поставщику — проставить дату готовности, если её ещё нет и в карточке есть срок.
 * Возвращает дату ('Y-m-d') или '' (срока нет / дата уже стояла).
 */
function order_fill_ready_date_on_send(int $orderId, int $socId): string
{
    if (order_ready_date($orderId) !== '') return '';
    $days = order_supplier_lead_days($socId);
    if ($days <= 0) return '';
    $date = date('Y-m-d', strtotime('+' . $days . ' days'));
    return order_set_ready_date($orderId, $date) ? $date : '';
}

/** Записать ожидаемое прибытие в Ташкент (родное поле заказа). Для партии — всем её заказам. */
function order_set_delivery_date(string $scopeType, int $scopeId, string $date): int
{
    if ($date === '') return 0;
    $db = logistics_db();
    $ids = [];
    if ($scopeType === 'batch') {
        $r = $db->query("SELECT fk_order FROM llx_supplier_shipment_batch_order WHERE fk_batch = " . (int)$scopeId);
        while ($x = $r->fetch_assoc()) $ids[] = (int)$x['fk_order'];
    } else {
        $ids[] = $scopeId;
    }
    $n = 0;
    foreach ($ids as $oid) {
        $st = $db->prepare("UPDATE llx_commande_fournisseur SET date_livraison = ? WHERE rowid = ?");
        $d = $date . ' 12:00:00';   // днём, не в полночь — см. грабли с датами в CLAUDE.md
        $st->bind_param('si', $d, $oid);
        $st->execute(); $n += $st->affected_rows > 0 ? 1 : 0; $st->close();
    }
    return $n;
}

/**
 * Заказы, по которым пора заказывать перевозку: поставщик заканчивает в ближайшую неделю (или уже
 * должен был закончить), а рейс не оформлен. Только утверждённые и отправленные — принятые уходят сами.
 */
function orders_awaiting_shipment(DolibarrApi $api, int $daysAhead = ORDER_READY_WARN_DAYS): array
{
    $rows = [];
    foreach (['approved', 'running'] as $st) {
        $list = $api->getSupplierOrdersByStatus($st, 'id,ref,socid,statut,total_ttc,multicurrency_code,multicurrency_total_ttc');
        if (is_array($list)) foreach ($list as $r) $rows[(int)$r['id']] = $r;
    }
    if (!$rows) return [];

    $ids = implode(',', array_map('intval', array_keys($rows)));
    $ready = [];
    $res = logistics_db()->query("SELECT fk_object, ready_date FROM llx_commande_fournisseur_extrafields
                                  WHERE fk_object IN ($ids) AND ready_date IS NOT NULL");
    while ($x = $res->fetch_assoc()) $ready[(int)$x['fk_object']] = (string)$x['ready_date'];
    if (!$ready) return [];

    $shipments = shipments_for_orders(array_keys($ready));   // учитывает и рейс на партию заказа
    $today = strtotime(date('Y-m-d'));
    $out = [];
    foreach ($ready as $oid => $date) {
        if (isset($shipments[$oid])) continue;               // рейс уже оформлен
        $days = (int)round((strtotime($date) - $today) / 86400);
        if ($days > $daysAhead) continue;
        $out[] = $rows[$oid] + ['ready_date' => $date, 'days_left' => $days];
    }
    usort($out, fn($a, $b) => $a['days_left'] <=> $b['days_left']);
    return $out;
}
