<?php
/**
 * Настройка корпоративной почты (05.09.2026). Данные вводятся здесь и ложатся в настройки самого
 * Dolibarr (`llx_const`, MAIN_MAIL_*) — то есть почта заработает и в Dolibarr, и в этом инструменте,
 * а хранится в одном месте.
 *
 * Пароль показывается только признаком «задан»: назад его не выводим, чтобы не светить лишний раз.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$message = '';
$messageType = '';

// Готовые наборы: у Google и Яндекса адреса серверов постоянные — не заставляем вспоминать.
const MAIL_PRESETS = [
    'gmail'  => ['label' => 'Google Workspace / Gmail', 'server' => 'smtp.gmail.com',      'port' => 587, 'tls' => 'starttls'],
    'yandex' => ['label' => 'Яндекс 360 / Яндекс',      'server' => 'smtp.yandex.ru',      'port' => 465, 'tls' => 'ssl'],
    'mailru' => ['label' => 'Mail.ru для бизнеса',      'server' => 'smtp.mail.ru',        'port' => 465, 'tls' => 'ssl'],
    'office' => ['label' => 'Microsoft 365 / Outlook',  'server' => 'smtp.office365.com',  'port' => 587, 'tls' => 'starttls'],
    'custom' => ['label' => 'Свой сервер',              'server' => '',                    'port' => 587, 'tls' => 'starttls'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $server = trim($_POST['server'] ?? '');
        $port   = (int)($_POST['port'] ?? 0);
        $login  = trim($_POST['login'] ?? '');
        $pass   = (string)($_POST['password'] ?? '');
        $from   = trim($_POST['from'] ?? '');
        $tls    = $_POST['tls'] ?? 'starttls';

        if ($server === '' || $port <= 0 || $from === '') {
            $message = 'Заполните сервер, порт и адрес отправителя.';
            $messageType = 'err';
        } elseif (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $message = 'Адрес отправителя указан неверно.';
            $messageType = 'err';
        } else {
            mail_set_const('MAIN_MAIL_SMTP_SERVER', $server);
            mail_set_const('MAIN_MAIL_SMTP_PORT', (string)$port);
            mail_set_const('MAIN_MAIL_EMAIL_FROM', $from);
            mail_set_const('MAIN_MAIL_SMTPS_ID', $login);
            // Пустой пароль в форме означает «оставить прежний», а не «стереть».
            if ($pass !== '') mail_set_const('MAIN_MAIL_SMTPS_PW', $pass);
            mail_set_const('MAIN_MAIL_EMAIL_TLS', $tls === 'ssl' ? '1' : '0');
            mail_set_const('MAIN_MAIL_EMAIL_STARTTLS', $tls === 'starttls' ? '1' : '0');
            // swiftmailer — штатный режим Dolibarr, умеет SSL/STARTTLS и авторизацию.
            mail_set_const('MAIN_MAIL_SENDMODE', 'swiftmailer');
            mail_set_const('MAIN_MAIL_SMTPS_AUTH_TYPE', 'LOGIN');

            flash_set('Настройки почты сохранены. Проверьте отправку тестовым письмом.', 'ok');
            header('Location: mail_setup.php');
            exit;
        }
    } elseif ($action === 'test') {
        $to = trim($_POST['test_to'] ?? '');
        $who = $_SESSION['user']['name'] ?? '';
        $r = mail_send($to, 'Проверка почты — Теплолюкс',
            '<p>Это тестовое письмо из инструмента закупок.</p>'
            . '<p>Если вы его видите — почта настроена правильно, заказы можно отправлять поставщикам.</p>'
            . '<p>Отправил: ' . htmlspecialchars($who) . '</p>');
        flash_set($r['ok'] ? "Тестовое письмо отправлено на {$to}. Проверьте ящик — если письма нет, посмотрите папку «Спам»."
                           : ('Не удалось отправить: ' . $r['error']),
                  $r['ok'] ? 'ok' : 'err');
        header('Location: mail_setup.php');
        exit;
    }
}

$flash = flash_get();
if ($flash && $message === '') { $message = $flash['message']; $messageType = $flash['type']; }

$s = mail_settings();
$configured = mail_is_configured();
$currentTls = $s['MAIN_MAIL_EMAIL_TLS'] === '1' ? 'ssl'
            : ($s['MAIN_MAIL_EMAIL_STARTTLS'] === '1' ? 'starttls' : 'none');

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Настройка корпоративной почты</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <p class="<?= $configured ? 'ok' : 'warn' ?>" style="display:inline-block">
    <?= $configured ? 'Почта настроена — заказы можно отправлять поставщикам.' : 'Почта пока не настроена — отправка заказов недоступна.' ?>
  </p>
  <p class="muted">Эти настройки общие с Dolibarr: заполнив их здесь, вы включаете почту и там.
  Письма уходят с одного общего адреса компании; в ответе поставщика будет указан тот, кто отправил.</p>
</div>

<div class="grid-2col">
<div>
<div class="card">
  <h2>Данные почты</h2>
  <form method="post">
  <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <label>Какая почта <span class="muted">— подставит сервер и порт</span></label>
    <select id="preset">
      <option value="">— выбрать —</option>
      <?php foreach (MAIL_PRESETS as $k => $p): ?>
        <option value="<?= $k ?>" data-server="<?= htmlspecialchars($p['server']) ?>"
                data-port="<?= (int)$p['port'] ?>" data-tls="<?= $p['tls'] ?>"><?= htmlspecialchars($p['label']) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Адрес отправителя <span class="muted">— с него уходят письма</span></label>
    <input type="text" name="from" value="<?= htmlspecialchars($s['MAIN_MAIL_EMAIL_FROM']) ?>"
           placeholder="например info@teplolux.uz" required>

    <div class="row">
      <div style="flex:2"><label>SMTP-сервер</label>
        <input type="text" name="server" id="server" value="<?= htmlspecialchars($s['MAIN_MAIL_SMTP_SERVER']) ?>"
               placeholder="smtp.gmail.com" required></div>
      <div style="flex:1"><label>Порт</label>
        <input type="number" name="port" id="port" value="<?= htmlspecialchars($s['MAIN_MAIL_SMTP_PORT'] ?: '587') ?>" required></div>
    </div>

    <label>Шифрование</label>
    <select name="tls" id="tls">
      <option value="starttls" <?= $currentTls === 'starttls' ? 'selected' : '' ?>>STARTTLS (обычно порт 587)</option>
      <option value="ssl" <?= $currentTls === 'ssl' ? 'selected' : '' ?>>SSL/TLS (обычно порт 465)</option>
      <option value="none" <?= $currentTls === 'none' ? 'selected' : '' ?>>без шифрования</option>
    </select>

    <label>Логин <span class="muted">— обычно тот же адрес почты</span></label>
    <input type="text" name="login" value="<?= htmlspecialchars($s['MAIN_MAIL_SMTPS_ID']) ?>" placeholder="info@teplolux.uz">

    <label>Пароль
      <span class="muted"><?= $s['_has_password'] ? '— уже задан, оставьте пустым, чтобы не менять' : '' ?></span></label>
    <input type="password" name="password" placeholder="<?= $s['_has_password'] ? '•••••• (не менять)' : 'пароль приложения' ?>"
           autocomplete="new-password">

    <p class="note">У Gmail, Яндекса и Mail.ru обычный пароль от почты <strong>не подойдёт</strong> —
    нужен отдельный «пароль приложения». Он создаётся в настройках безопасности вашего почтового
    ящика и выглядит как набор из 16 букв.</p>

    <button type="submit">Сохранить настройки</button>
  </form>
</div>
</div>

<div>
<div class="card">
  <h2>Проверка</h2>
  <?php if (!$configured): ?>
    <p class="muted">Сначала заполните и сохраните данные слева.</p>
  <?php else: ?>
    <p class="muted">Отправьте себе тестовое письмо — так сразу видно, принимает ли сервер эти данные.</p>
    <form method="post">
    <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <label>Кому отправить</label>
      <input type="text" name="test_to" value="<?= htmlspecialchars($s['MAIN_MAIL_EMAIL_FROM']) ?>" required>
      <button type="submit" class="secondary">Отправить тестовое письмо</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Что дальше</h2>
  <p class="muted">Когда почта заработает, на карточке каждого заказа появится кнопка
  «Отправить поставщику»: письмо со списком позиций и спецификацией во вложении уйдёт на адрес из
  карточки поставщика.</p>
  <?php
    $dbm = mailer_db();
    $cnt = $dbm->query("SELECT COUNT(*) total, SUM(CASE WHEN email IS NOT NULL AND email<>'' THEN 1 ELSE 0 END) with_mail
                        FROM llx_societe WHERE fournisseur=1")->fetch_assoc();
  ?>
  <p class="<?= (int)$cnt['with_mail'] < (int)$cnt['total'] ? 'warn' : 'ok' ?>" style="display:inline-block">
    Почта заполнена у <?= (int)$cnt['with_mail'] ?> поставщиков из <?= (int)$cnt['total'] ?>.
  </p>
  <p class="muted">Без адреса письмо отправить некуда — адрес вписывается в карточке поставщика
  (раздел «Поставщики / контракты» → «Редактировать»). Можно вписать и прямо перед отправкой,
  но тогда он не сохранится на будущее.</p>
</div>
</div>
</div>

<script>
// Выбрал почту из списка — подставили сервер, порт и шифрование. Руками править всё равно можно.
(function () {
  const sel = document.getElementById('preset');
  if (!sel) return;
  sel.addEventListener('change', function () {
    const o = sel.options[sel.selectedIndex];
    if (!o || !o.dataset.server) return;
    document.getElementById('server').value = o.dataset.server;
    document.getElementById('port').value = o.dataset.port;
    document.getElementById('tls').value = o.dataset.tls;
  });
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
