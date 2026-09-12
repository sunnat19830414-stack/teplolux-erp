<?php
/**
 * Брак при приёмке (11.09.2026, просьба Жамшида, схема утверждена пользователем).
 *
 * Как оформляется: пришло 10, из них 2 с браком →
 *   8 шт на выбранный склад (продаются как обычно),
 *   2 шт на склад брака направления («08 Брак Жоми» / «09 Брак Турк»),
 *   на эти 2 шт сразу заводится рекламация поставщику — её ведёт закупщик в NodirTool.
 *
 * Почему не «принять 8, а 2 оставить недопоставкой»: поставщик их отгрузил, товар физически у нас —
 *   заказ навсегда повис бы «принят частично», и все ждали бы 2 штуки, которые не приедут.
 * Почему не «принять 10 и списать 2»: пропало бы основание для претензии — мы заплатили за 10.
 *
 * Склад брака НЕ входит в `warehouse_ids` направления, поэтому в продаже, перемещении и
 * инвентаризации кассы его нет; в остатках остальных инструментов его исключает sellable_stock.php.
 *
 * Фото прикрепляются к САМОМУ заказу поставщику через хранилище документов Dolibarr — там же, где
 * NodirTool держит инвойсы и ГТД, поэтому закупщик видит их на странице заказа без отдельного экрана.
 * Имя файла `brak_<id рекламации>_<n>.<ext>` связывает фото с рекламацией.
 */

/** Причины брака — из списка, чтобы потом было видно, у кого проблемы чаще (решение пользователя). */
const DEFECT_REASONS = [
    'broken'     => 'Бой / разбито',
    'damaged'    => 'Царапины, вмятины, деформация',
    'packaging'  => 'Повреждена упаковка',
    'incomplete' => 'Некомплект',
    'factory'    => 'Заводской брак (не работает)',
    'wrong'      => 'Привезли не тот товар',
    'other'      => 'Другое',
];

const DEFECT_MAX_PHOTOS = 5;
const DEFECT_MAX_BYTES  = 15 * 1024 * 1024;   // после сжатия в браузере фото весят ~0,3–0,6 МБ
const DEFECT_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function defect_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/**
 * Проверка ввода брака по одной строке. Возвращает [defect, reason, note, error|null].
 * $qty — сколько прибыло сейчас по строке; брак — часть этого количества.
 */
function defect_parse_line(float $qty, $rawDefect, $rawReason, $rawNote): array
{
    $defect = round(max(0.0, (float)str_replace(',', '.', (string)$rawDefect)), 3);
    $reason = (string)$rawReason;
    $note   = trim((string)$rawNote);
    if ($defect <= 0) return [0.0, '', '', null];
    if ($defect > $qty + 0.0001) return [0.0, '', '', "брака указано {$defect}, а прибыло всего {$qty}"];
    if (!isset(DEFECT_REASONS[$reason])) return [0.0, '', '', 'не выбрана причина брака'];
    if ($reason === 'other' && $note === '') return [0.0, '', '', 'причина «Другое» — опишите, что не так'];
    return [$defect, $reason, mb_substr($note, 0, 500), null];
}

/**
 * Загруженные фото одной строки из $_FILES['defect_photo'][$i]. Отбрасывает всё, что не картинка:
 * тип определяется по содержимому файла (finfo), а не по расширению и не по тому, что прислал браузер.
 * Возвращает [[bytes, ext], ...] и список предупреждений.
 */
function defect_collect_photos(int $i): array
{
    $out = []; $warn = [];
    $f = $_FILES['defect_photo'] ?? null;
    if (!$f || !isset($f['name'][$i]) || !is_array($f['name'][$i])) return [$out, $warn];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($f['name'][$i] as $k => $name) {
        $err = $f['error'][$i][$k] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if (count($out) >= DEFECT_MAX_PHOTOS) { $warn[] = 'больше ' . DEFECT_MAX_PHOTOS . ' фото — лишние не прикреплены'; break; }
        if ($err !== UPLOAD_ERR_OK) { $warn[] = "«{$name}» не загрузилось (код {$err})"; continue; }
        $tmp = $f['tmp_name'][$i][$k];
        $size = (int)($f['size'][$i][$k] ?? 0);
        if ($size <= 0 || $size > DEFECT_MAX_BYTES) { $warn[] = "«{$name}» слишком большое"; continue; }
        $mime = $finfo->file($tmp) ?: '';
        if (!isset(DEFECT_IMAGE_TYPES[$mime])) { $warn[] = "«{$name}» не похоже на фото ({$mime})"; continue; }
        $out[] = [file_get_contents($tmp), DEFECT_IMAGE_TYPES[$mime]];
    }
    return [$out, $warn];
}

/**
 * Рекламация поставщику на брак. Пишем в ту же таблицу `llx_nt_claim`, что и NodirTool
 * (`claim_create()` в C:\NodirTool\includes\claims.php) — с теми же полями и статусом 'open',
 * чтобы закупщик видел её в своём разделе «Рекламации» как любую другую.
 */
function defect_create_claim(array $c, string $who): array
{
    if ((int)$c['fk_party'] <= 0) return ['ok' => false, 'error' => 'у заказа не определён поставщик'];
    $db = defect_db();
    $st = $db->prepare("INSERT INTO llx_nt_claim
        (target_type, fk_party, fk_order, fk_shipment, fk_product, product_label,
         qty, amount, currency, description, status, datec, created_by)
        VALUES ('supplier', ?, ?, NULL, ?, ?, ?, ?, ?, ?, 'open', NOW(), ?)");
    $party = (int)$c['fk_party']; $order = (int)$c['fk_order']; $prod = (int)$c['fk_product'];
    $label = (string)$c['product_label']; $qty = (float)$c['qty']; $amount = round((float)$c['amount'], 2);
    $cur = (string)$c['currency']; $desc = (string)$c['description'];
    $st->bind_param('iiisddsss', $party, $order, $prod, $label, $qty, $amount, $cur, $desc, $who);
    $st->execute();
    $id = (int)$db->insert_id;
    $st->close();
    return ['ok' => $id > 0, 'id' => $id];
}
