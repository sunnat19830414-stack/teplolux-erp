<?php
/**
 * Создание нового поставщика / редактирование существующего. Вызывается со страниц, где выбирается
 * поставщик (payments/orders/suppliers) — параметр ctx определяет, куда и в какую сессионную
 * переменную вернуться после сохранения (белый список ниже — не принимаем произвольные значения
 * из запроса). В отличие от TeplouxKassa — нет изоляции направлений, любой логин видит и может
 * редактировать любого поставщика.
 *
 * 04.09.2026 (пункты B3 и R6 отчёта «Пробелы NodirTool»): добавлены почта, контактное лицо, СТРАНА
 * и валюта договора. Раньше страна жёстко проставлялась «Узбекистан» — при том что 25 из 52
 * поставщиков иностранные; почта была заполнена у 1 поставщика из 52, контактных лиц не было вовсе,
 * то есть «кому писать» жило в личном ящике закупщика.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_terms.php';   // срок оплаты (B7, 05.09.2026)

const SUPPLIER_FORM_CONTEXTS = [
    'payments'  => ['page' => 'payments.php',  'field' => 'pay_supplier'],
    'orders'    => ['page' => 'orders.php',    'field' => 'po_supplier'],
    'suppliers' => ['page' => 'suppliers.php', 'field' => 'selected_supplier'],
];

// Валюты, в которых реально ведутся договоры с поставщиками (совпадают с валютными счетами проекта).
const SUPPLIER_CURRENCIES = ['' => 'Доллары США (по умолчанию)', 'EUR' => 'Евро (EUR)', 'RUB' => 'Рубли (RUB)'];

const COUNTRY_UZBEKISTAN = 230;

$ctxKey = $_GET['ctx'] ?? ($_POST['ctx'] ?? '');
$ctx = SUPPLIER_FORM_CONTEXTS[$ctxKey] ?? null;

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

$message = '';
$messageType = '';
$fields = [
    'name' => '', 'phone' => '', 'email' => '', 'town' => '', 'address' => '',
    'country_id' => COUNTRY_UZBEKISTAN, 'multicurrency_code' => '',
];
$contactPerson = '';
$leadTime = '';
// Реквизиты для спецификации (B1): номер контракта, с какого номера продолжать нумерацию
// спецификаций у этого поставщика, и как писать страну происхождения в самом документе.
$contractNumber = '';
$specLastNumber = '';
$originText = '';

$countries = $api->getCountries();

if ($isEdit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $existing = $api->getThirdparty($id);
    if (!is_array($existing)) {
        http_response_code(404);
        die('Поставщик не найден.');
    }
    $fields['name'] = $existing['name'] ?? $existing['nom'] ?? '';
    $fields['phone'] = $existing['phone'] ?? '';
    $fields['email'] = $existing['email'] ?? '';
    $fields['town'] = $existing['town'] ?? '';
    $fields['address'] = $existing['address'] ?? '';
    $fields['country_id'] = (int)($existing['country_id'] ?? COUNTRY_UZBEKISTAN);
    $fields['multicurrency_code'] = (string)($existing['multicurrency_code'] ?? '');
    $opts = $existing['array_options'] ?? [];
    $contactPerson = (string)($opts['options_contact_person'] ?? '');
    $contractNumber = (string)($opts['options_contract_number'] ?? '');
    $specLastNumber = (string)($opts['options_spec_last_number'] ?? '');
    $originText = (string)($opts['options_origin_text'] ?? '');
    $leadTime = (string)($opts['options_lead_time_days'] ?? '');
    // Срок оплаты (B7, 05.09.2026) — родное поле Dolibarr, а не наше доп.поле.
    $paymentTermId = (int)($existing['cond_reglement_supplier_id'] ?? 0);
    // Предоплата/постоплата (R4 отчёта «Пробелы NodirTool»): признак показывался бейджем, но
    // формы для правки не было — он был проставлен разом всем 52 поставщикам и застыл. От него
    // зависит, по какому количеству выставляется счёт (заказанному или принятому), то есть деньги.
    $payWhen = (string)($opts['options_payment_terms'] ?? '');
}
$paymentTermId = $paymentTermId ?? 0;
$payWhen = $payWhen ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields['name'] = trim($_POST['name'] ?? '');
    $fields['phone'] = trim($_POST['phone'] ?? '');
    $fields['email'] = trim($_POST['email'] ?? '');
    $fields['town'] = trim($_POST['town'] ?? '');
    $fields['address'] = trim($_POST['address'] ?? '');
    $fields['country_id'] = (int)($_POST['country_id'] ?? COUNTRY_UZBEKISTAN);
    $currency = (string)($_POST['multicurrency_code'] ?? '');
    $fields['multicurrency_code'] = isset(SUPPLIER_CURRENCIES[$currency]) ? $currency : '';
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $contractNumber = trim($_POST['contract_number'] ?? '');
    $specLastNumber = trim($_POST['spec_last_number'] ?? '');
    $originText = trim($_POST['origin_text'] ?? '');
    $leadTime = trim($_POST['lead_time_days'] ?? '');
    $paymentTermId = (int)($_POST['payment_term_id'] ?? 0);
    $payWhen = in_array($_POST['payment_when'] ?? '', ['prepay', 'postpay'], true) ? $_POST['payment_when'] : '';

    // Условие оплаты — обычное поле контрагента, идёт тем же PUT/POST (проверено: сохраняется).
    $fields['cond_reglement_supplier_id'] = $paymentTermId ?: null;

    $payload = $fields + ['array_options' => [
        'options_contact_person' => $contactPerson,
        'options_contract_number' => $contractNumber,
        'options_spec_last_number' => $specLastNumber === '' ? null : (int)$specLastNumber,
        'options_origin_text' => $originText,
        'options_payment_terms' => $payWhen,
        'options_lead_time_days' => $leadTime === '' ? null : (int)$leadTime,
    ]];

    if ($fields['name'] === '') {
        $message = 'Укажите название поставщика.';
        $messageType = 'err';
    } elseif ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $message = 'Почта указана неверно — проверьте адрес.';
        $messageType = 'err';
    } elseif (!isset($countries[$fields['country_id']])) {
        $message = 'Выберите страну из списка.';
        $messageType = 'err';
    } elseif ($isEdit) {
        $ok = $api->updateSupplier($id, $payload);
        if ($ok === null) {
            $message = 'Ошибка сохранения: ' . $api->lastError;
            $messageType = 'err';
        } elseif ($ctx) {
            $_SESSION[$ctx['field']] = ['id' => $id, 'name' => $fields['name']];
            $_SESSION['_preserve_once'][$ctx['field']] = true;
            header('Location: ' . $ctx['page']);
            exit;
        } else {
            $message = 'Данные поставщика сохранены.';
            $messageType = 'ok';
        }
    } else {
        $code = $api->getNextSupplierCode();
        $newId = $api->createSupplier($payload + ['code_fournisseur' => $code]);
        if (!$newId) {
            $message = 'Ошибка создания: ' . $api->lastError;
            $messageType = 'err';
        } elseif ($ctx) {
            $_SESSION[$ctx['field']] = ['id' => (int)$newId, 'name' => $fields['name']];
            $_SESSION['_preserve_once'][$ctx['field']] = true;
            header('Location: ' . $ctx['page']);
            exit;
        } else {
            $id = (int)$newId;
            $isEdit = true;
            $message = "Поставщик создан (код {$code}).";
            $messageType = 'ok';
        }
    }
}

require __DIR__ . '/includes/layout_top.php';
?>

<h1><?= $isEdit ? 'Редактировать поставщика' : 'Новый поставщик' ?></h1>
<?php if ($ctx): ?>
  <p class="muted">После сохранения вы вернётесь в раздел «<?= htmlspecialchars(basename($ctx['page'], '.php')) ?>» с этим поставщиком уже выбранным.</p>
<?php endif; ?>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card" style="max-width:560px">
  <form method="post">
  <?= csrf_field() ?>
    <?php if ($ctxKey): ?><input type="hidden" name="ctx" value="<?= htmlspecialchars($ctxKey) ?>"><?php endif; ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <label>Название</label>
    <input type="text" name="name" value="<?= htmlspecialchars($fields['name']) ?>" required autofocus>

    <label>Контактное лицо (менеджер)</label>
    <input type="text" name="contact_person" value="<?= htmlspecialchars($contactPerson) ?>" placeholder="кому писать и звонить">

    <label>Почта</label>
    <input type="text" name="email" value="<?= htmlspecialchars($fields['email']) ?>" placeholder="например sales@caleffi.com">

    <label>Телефон</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($fields['phone']) ?>">

    <label>Страна</label>
    <select name="country_id">
      <?php foreach ($countries as $cid => $cname): ?>
        <option value="<?= (int)$cid ?>" <?= (int)$cid === (int)$fields['country_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Валюта договора</label>
    <select name="multicurrency_code">
      <?php foreach (SUPPLIER_CURRENCIES as $ccode => $clabel): ?>
        <option value="<?= htmlspecialchars($ccode) ?>" <?= $ccode === $fields['multicurrency_code'] ? 'selected' : '' ?>><?= htmlspecialchars($clabel) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="muted" style="margin:-4px 0 12px">Валюта, в которой подписаны контракт и спецификации. Пока используется как справочная — суммы заказов ведутся в долларах.</p>

    <h2 style="margin-top:18px">Для спецификаций</h2>
    <p class="muted" style="margin:-6px 0 12px">Эти данные подставляются в шапку документа, который
    вы отправляете поставщику и на таможню.</p>

    <label>Номер контракта</label>
    <input type="text" name="contract_number" value="<?= htmlspecialchars($contractNumber) ?>" placeholder="например 04">

    <label>Типичный срок поставки, дней</label>
    <input type="number" name="lead_time_days" min="0" max="365" value="<?= htmlspecialchars($leadTime) ?>"
           placeholder="например 45">
    <p class="muted" style="margin:-6px 0 12px">Сколько обычно проходит от заказа до прихода на склад.
    Нужен, чтобы «Что пора закупать» подсказывало заказ заранее, а не в день, когда товар кончился.
    Пока заполняется по памяти — дальше начнёт считаться сам по датам заказов и приёмок.</p>

    <label>Когда платим</label>
    <select name="payment_when">
      <option value="" <?= $payWhen === '' ? 'selected' : '' ?>>— не задано —</option>
      <option value="prepay"  <?= $payWhen === 'prepay'  ? 'selected' : '' ?>>Предоплата 100% — платим до отгрузки</option>
      <option value="postpay" <?= $payWhen === 'postpay' ? 'selected' : '' ?>>Постоплата — товар сначала, оплата потом</option>
    </select>
    <p class="muted" style="margin-top:-4px">От этого зависит, по какому количеству выставляется счёт:
    при предоплате — по заказанному (деньги ушли вперёд), при постоплате — по реально принятому.</p>

    <label>Срок оплаты по договору</label>
    <select name="payment_term_id">
      <option value="0">— не задан —</option>
      <?php foreach (payment_terms_list() as $tid => $t): ?>
        <option value="<?= (int)$tid ?>" <?= $paymentTermId === (int)$tid ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="muted" style="margin-top:-4px">Подставится в каждый счёт этого поставщика — будет видно,
    до какой даты платить. Тем, кто работает по предоплате, заполнять не нужно.</p>

    <label>Последний номер спецификации <span class="muted">(нумерация продолжится со следующего)</span></label>
    <input type="number" name="spec_last_number" value="<?= htmlspecialchars($specLastNumber) ?>" step="1" min="0" placeholder="например 30">

    <label>Страна происхождения и производитель <span class="muted">(как писать в спецификации)</span></label>
    <input type="text" name="origin_text" value="<?= htmlspecialchars($originText) ?>" placeholder="например: Евросоюз - Италия">

    <h2 style="margin-top:18px">Адрес</h2>
    <label>Город</label>
    <input type="text" name="town" value="<?= htmlspecialchars($fields['town']) ?>">

    <label>Адрес</label>
    <input type="text" name="address" value="<?= htmlspecialchars($fields['address']) ?>">

    <div class="row">
      <div style="flex:0"><button type="submit"><?= $isEdit ? 'Сохранить' : 'Создать' ?></button></div>
      <div style="flex:0"><a class="btn secondary" href="<?= htmlspecialchars($ctx['page'] ?? 'suppliers.php') ?>">Отмена</a></div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
