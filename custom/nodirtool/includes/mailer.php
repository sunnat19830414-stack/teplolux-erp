<?php
/**
 * Отправка писем поставщикам (05.09.2026, по просьбе пользователя: «прикрепить корпоративную почту,
 * чтобы отправлять заказы через неё сразу поставщикам»).
 *
 * Отправляем через штатный `CMailFile` Dolibarr — библиотека (SwiftMailer) там уже лежит, ставить
 * ничего не нужно, а настройки живут в одном месте (`llx_const`, MAIN_MAIL_*). Плюс: та же почта
 * начинает работать и в самом Dolibarr, а не только у нас.
 *
 * Бутстрап Dolibarr тяжёлый, поэтому подключается ТОЛЬКО в момент реальной отправки — как и в
 * includes/dolibarr_direct.php.
 */

function mailer_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Текущие настройки почты из Dolibarr. Пароль не возвращаем — только признак, задан ли он. */
function mail_settings(): array
{
    $db = mailer_db();
    $names = ['MAIN_MAIL_SMTP_SERVER', 'MAIN_MAIL_SMTP_PORT', 'MAIN_MAIL_SMTPS_ID', 'MAIN_MAIL_SMTPS_PW',
              'MAIN_MAIL_EMAIL_FROM', 'MAIN_MAIL_EMAIL_TLS', 'MAIN_MAIL_EMAIL_STARTTLS',
              'MAIN_MAIL_SENDMODE', 'MAIN_MAIL_SMTPS_AUTH_TYPE'];
    $in = "'" . implode("','", $names) . "'";
    $res = $db->query("SELECT name, value FROM llx_const WHERE name IN ($in)");
    $out = array_fill_keys($names, '');
    while ($r = $res->fetch_assoc()) $out[$r['name']] = (string)$r['value'];

    $out['_has_password'] = $out['MAIN_MAIL_SMTPS_PW'] !== '';
    unset($out['MAIN_MAIL_SMTPS_PW']);
    // Заглушка из установки Dolibarr — считаем, что отправитель не настроен.
    if ($out['MAIN_MAIL_EMAIL_FROM'] === 'robot@domain.com') $out['MAIN_MAIL_EMAIL_FROM'] = '';
    return $out;
}

/** Настроена ли почта настолько, чтобы можно было отправлять. */
function mail_is_configured(): bool
{
    $s = mail_settings();
    return $s['MAIN_MAIL_SMTP_SERVER'] !== '' && $s['MAIN_MAIL_SMTP_PORT'] !== ''
        && $s['MAIN_MAIL_EMAIL_FROM'] !== '';
}

/** Записать/обновить настройку в llx_const (тот же способ, каким пользуется сам Dolibarr). */
function mail_set_const(string $name, string $value): void
{
    $db = mailer_db();
    $stmt = $db->prepare("SELECT rowid FROM llx_const WHERE name = ? AND entity = 1");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $stmt = $db->prepare("UPDATE llx_const SET value = ?, tms = NOW() WHERE rowid = ?");
        $stmt->bind_param('si', $value, $row['rowid']);
    } else {
        $stmt = $db->prepare("INSERT INTO llx_const (name, entity, value, type, visible, note, tms)
                              VALUES (?, 1, ?, 'chaine', 0, 'Настроено через NodirTool', NOW())");
        $stmt->bind_param('ss', $name, $value);
    }
    $stmt->execute();
    $stmt->close();
}

function mail_log_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    mailer_db()->query("CREATE TABLE IF NOT EXISTS llx_nt_mail_log (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_order INT DEFAULT NULL,
        order_ref VARCHAR(64) DEFAULT NULL,
        fk_supplier INT DEFAULT NULL,
        to_email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        attachments VARCHAR(500) DEFAULT NULL,
        sent_by VARCHAR(100) DEFAULT NULL,
        ok TINYINT NOT NULL DEFAULT 0,
        error VARCHAR(500) DEFAULT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_order (fk_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Что уже отправляли по этому заказу — показываем на карточке, чтобы не слать дважды вслепую. */
function mail_log_for_order(int $orderId): array
{
    mail_log_ensure_table();
    $db = mailer_db();
    $stmt = $db->prepare("SELECT * FROM llx_nt_mail_log WHERE fk_order = ? ORDER BY rowid DESC LIMIT 20");
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $stmt->close();
    return $out;
}

function mail_log_write(array $d): void
{
    mail_log_ensure_table();
    $db = mailer_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_mail_log
        (fk_order, order_ref, fk_supplier, to_email, subject, attachments, sent_by, ok, error, datec)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $ok = $d['ok'] ? 1 : 0;
    $stmt->bind_param('isissssis', $d['fk_order'], $d['order_ref'], $d['fk_supplier'], $d['to_email'],
        $d['subject'], $d['attachments'], $d['sent_by'], $ok, $d['error']);
    try { $stmt->execute(); } catch (mysqli_sql_exception $e) { /* журнал не должен ронять отправку */ }
    $stmt->close();
}

/**
 * Поднять Dolibarr — только в момент реальной отправки, не на каждый заход на страницу.
 *
 * ⚠️ Грабля, пойманная тестом 05.09.2026: `require master.inc.php` ВНУТРИ функции создаёт $conf,
 * $user, $langs, $db как ЛОКАЛЬНЫЕ переменные этой функции. А `CMailFile` и его зависимости берут
 * их через `global $conf;` — то есть видят пустоту и падают ("Attempt to modify property global on
 * null"). Поэтому после подключения переносим всё созданное в глобальную область. Объекты
 * копируются по ссылке, лишней памяти это не занимает.
 */
function mailer_bootstrap_dolibarr(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    require_once 'C:\\Dolibarr\\htdocs\\master.inc.php';
    foreach (get_defined_vars() as $k => $v) {
        if ($k === 'done') continue;
        $GLOBALS[$k] = $v;
    }
    require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
}

/**
 * Отправить письмо. $files — [['path' => полный путь, 'name' => имя для получателя], ...].
 * Возвращает ['ok' => bool, 'error' => string].
 */
function mail_send(string $to, string $subject, string $bodyHtml, array $files = [], string $replyTo = ''): array
{
    if (!mail_is_configured()) {
        return ['ok' => false, 'error' => 'Почта ещё не настроена — откройте раздел «Настройка почты».'];
    }
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Адрес получателя пуст или указан неверно: ' . $to];
    }

    $paths = []; $mimes = []; $names = [];
    foreach ($files as $f) {
        if (empty($f['path']) || !is_file($f['path'])) continue;
        $paths[] = $f['path'];
        $mimes[] = $f['mime'] ?? 'application/octet-stream';
        $names[] = $f['name'] ?? basename($f['path']);
    }

    mailer_bootstrap_dolibarr();

    $s = mail_settings();
    $from = $s['MAIN_MAIL_EMAIL_FROM'];

    $mail = new CMailFile($subject, $to, $from, $bodyHtml, $paths, $mimes, $names,
        '', '', 0, 1 /* html */, '', '', '', '', 'standard', $replyTo);

    if (!$mail->sendfile()) {
        // Настоящая причина обычно лежит в errors[], а $mail->error — общая заглушка; берём то,
        // что информативнее. Текст системной ошибки Windows приходит в cp1251 — переводим в UTF-8,
        // иначе на экране будут «кракозябры» вместо объяснения.
        $parts = (!empty($mail->errors) && is_array($mail->errors)) ? $mail->errors : [];
        if ($mail->error) array_unshift($parts, $mail->error);
        $err = trim(implode('; ', array_filter($parts))) ?: 'неизвестная ошибка отправки';
        if (!mb_check_encoding($err, 'UTF-8')) $err = mb_convert_encoding($err, 'UTF-8', 'CP1251');
        return ['ok' => false, 'error' => $err];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * Текст письма с заказом. Список позиций прямо в письме — чтобы поставщик видел заказ, не открывая
 * вложение; спецификация при этом всё равно прикладывается файлом.
 */
function mail_order_body(array $order, array $lines, string $supplierName, string $currency, string $who): string
{
    $curLabel = $currency === 'USD' ? 'USD' : $currency;
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // Артикул берём из карточки товара — тем же способом, что и спецификация. В строке заказа поле
    // `ref` это ссылка САМОЙ строки, а не артикул товара: без этого в письме были бы номера строк.
    require_once __DIR__ . '/product_lookup.php';
    $productIds = [];
    foreach ($lines as $l) { $pid = (int)($l['fk_product'] ?? 0); if ($pid) $productIds[] = $pid; }
    $productInfo = $productIds ? get_product_customs_bulk($productIds) : [];

    $rows = '';
    $total = 0.0;
    $n = 0;
    foreach ($lines as $l) {
        $n++;
        $info = $productInfo[(int)($l['fk_product'] ?? 0)] ?? null;
        $qty = (float)($l['qty'] ?? 0);
        $price = $currency !== 'USD'
            ? (float)($l['multicurrency_subprice'] ?? 0)
            : (float)($l['subprice'] ?? 0);
        $sum = $qty * $price;
        $total += $sum;
        $rows .= '<tr>'
            . '<td style="padding:4px 8px;border:1px solid #ddd">' . $n . '</td>'
            . '<td style="padding:4px 8px;border:1px solid #ddd">' . $esc($info['ref'] ?? ($l['ref'] ?? '')) . '</td>'
            . '<td style="padding:4px 8px;border:1px solid #ddd">' . $esc($l['product_label'] ?? $l['desc'] ?? '') . '</td>'
            . '<td style="padding:4px 8px;border:1px solid #ddd;text-align:right">' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . '</td>'
            . '<td style="padding:4px 8px;border:1px solid #ddd;text-align:right">' . number_format($price, 2, '.', ' ') . '</td>'
            . '<td style="padding:4px 8px;border:1px solid #ddd;text-align:right">' . number_format($sum, 2, '.', ' ') . '</td>'
            . '</tr>';
    }

    return '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222">'
        . '<p>Здравствуйте' . ($supplierName !== '' ? ', ' . $esc($supplierName) : '') . '!</p>'
        . '<p>Направляем заказ <b>' . $esc($order['ref'] ?? '') . '</b>. Спецификация во вложении.</p>'
        . '<table style="border-collapse:collapse;font-size:13px">'
        . '<tr style="background:#f0f0f0">'
        . '<th style="padding:5px 8px;border:1px solid #ddd">№</th>'
        . '<th style="padding:5px 8px;border:1px solid #ddd">Артикул</th>'
        . '<th style="padding:5px 8px;border:1px solid #ddd">Наименование</th>'
        . '<th style="padding:5px 8px;border:1px solid #ddd">Кол-во</th>'
        . '<th style="padding:5px 8px;border:1px solid #ddd">Цена, ' . $esc($curLabel) . '</th>'
        . '<th style="padding:5px 8px;border:1px solid #ddd">Сумма</th>'
        . '</tr>' . $rows
        . '<tr><td colspan="5" style="padding:5px 8px;border:1px solid #ddd;text-align:right"><b>Итого</b></td>'
        . '<td style="padding:5px 8px;border:1px solid #ddd;text-align:right"><b>'
        . number_format($total, 2, '.', ' ') . ' ' . $esc($curLabel) . '</b></td></tr>'
        . '</table>'
        . '<p>С уважением,<br>' . $esc($who) . '<br>Теплолюкс</p>'
        . '</div>';
}
