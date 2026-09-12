<?php
/**
 * Массовое заполнение карточек поставщиков (05.09.2026).
 *
 * Зачем: несколько уже готовых вещей упираются в пустые поля справочника —
 *   почта заполнена у 1 поставщика из 52  → отправлять заказы письмом некому;
 *   условие оплаты у 0 из 52              → срок оплаты и напоминания на Сводке не работают;
 *   номер контракта у 0 из 52             → в шапке спецификации прочерк;
 *   контактное лицо у 0 из 52             → «кому писать» живёт в личной переписке.
 * Заполнять по одной карточке — 52 захода; здесь всё в одной таблице и правится в строках.
 *
 * Сохраняются ТОЛЬКО изменившиеся поля: рядом с каждым лежит исходное значение, сравнение перед
 * отправкой. Иначе кнопка переписывала бы все 52 карточки при каждом нажатии.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payment_terms.php';

$message = '';
$messageType = '';

const BULK_FIELDS = ['email', 'contact_person', 'contract_number', 'payment_term', 'payment_when'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $rows = (array)($_POST['row'] ?? []);
    $saved = 0; $errors = []; $badEmail = [];

    foreach ($rows as $socId => $vals) {
        $socId = (int)$socId;
        if (!$socId) continue;

        // Что реально изменилось — сравниваем с исходными значениями из скрытых полей.
        $orig = (array)($vals['orig'] ?? []);
        $changed = [];
        foreach (BULK_FIELDS as $f) {
            $new = trim((string)($vals[$f] ?? ''));
            $was = trim((string)($orig[$f] ?? ''));
            if ($new !== $was) $changed[$f] = $new;
        }
        if (!$changed) continue;

        if (isset($changed['email']) && $changed['email'] !== ''
            && !filter_var($changed['email'], FILTER_VALIDATE_EMAIL)) {
            $badEmail[] = (string)($vals['name'] ?? "#$socId");
            continue;
        }

        $payload = [];
        $options = [];
        if (isset($changed['email']))           $payload['email'] = $changed['email'];
        if (isset($changed['contact_person']))  $options['options_contact_person'] = $changed['contact_person'];
        if (isset($changed['contract_number'])) $options['options_contract_number'] = $changed['contract_number'];
        if (isset($changed['payment_when'])) {
            $options['options_payment_terms'] =
                in_array($changed['payment_when'], ['prepay', 'postpay'], true) ? $changed['payment_when'] : '';
        }
        if (isset($changed['payment_term'])) {
            $payload['cond_reglement_supplier_id'] = (int)$changed['payment_term'] ?: null;
        }
        if ($options) $payload['array_options'] = $options;

        if ($api->put("thirdparties/{$socId}", $payload) === null) {
            $errors[] = (string)($vals['name'] ?? "#$socId") . ': ' . $api->lastError;
        } else {
            $saved++;
        }
    }

    $parts = [];
    if ($saved)    $parts[] = "Сохранено карточек: $saved.";
    if ($badEmail) $parts[] = 'Почта указана неверно, не сохранена: ' . implode(', ', $badEmail) . '.';
    if ($errors)   $parts[] = 'Ошибки: ' . implode('; ', $errors);
    if (!$parts)   $parts[] = 'Менять было нечего — ничего не изменилось.';

    flash_set(implode(' ', $parts), ($errors || $badEmail) ? 'warn' : 'ok');
    header('Location: suppliers_bulk.php');
    exit;
}

$flash = flash_get();
if ($flash) { $message = $flash['message']; $messageType = $flash['type']; }

$suppliers = $api->searchSuppliers('', 300);
usort($suppliers, fn($a, $b) => strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$terms = payment_terms_list();

// Сколько ещё не заполнено — чтобы было видно, сколько работы осталось
$missing = ['email' => 0, 'contact' => 0, 'contract' => 0, 'term' => 0, 'when' => 0];
foreach ($suppliers as $s) {
    $o = $s['array_options'] ?? [];
    if (trim((string)($s['email'] ?? '')) === '')            $missing['email']++;
    if (trim((string)($o['options_contact_person'] ?? '')) === '')  $missing['contact']++;
    if (trim((string)($o['options_contract_number'] ?? '')) === '') $missing['contract']++;
    if (!(int)($s['cond_reglement_supplier_id'] ?? 0))       $missing['term']++;
    if (trim((string)($o['options_payment_terms'] ?? '')) === '')   $missing['when']++;
}

$wideLayout = true;
require __DIR__ . '/includes/layout_top.php';
?>

<h1>Заполнить карточки поставщиков</h1>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <p class="muted">Правьте прямо в строках и нажмите «Сохранить» внизу — запишутся только изменённые
  поля. Всего поставщиков: <strong><?= count($suppliers) ?></strong>.</p>
  <p class="muted">Не заполнено: почта — <strong><?= $missing['email'] ?></strong>,
    контактное лицо — <strong><?= $missing['contact'] ?></strong>,
    номер контракта — <strong><?= $missing['contract'] ?></strong>,
    срок оплаты — <strong><?= $missing['term'] ?></strong>,
    когда платим — <strong><?= $missing['when'] ?></strong>.</p>
  <p class="note">Без почты нельзя отправить заказ письмом, без номера контракта в шапке
  спецификации будет прочерк, без «когда платим» счёт может выставиться по неверному количеству.</p>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <div class="card">
    <input type="text" id="filter" placeholder="фильтр по названию" style="max-width:320px">
    <span class="muted" id="counter"></span>

    <table id="bulkTable">
      <tr>
        <th>Поставщик</th>
        <th style="width:210px">Почта</th>
        <th style="width:170px">Контактное лицо</th>
        <th style="width:130px">№ контракта</th>
        <th style="width:150px">Срок оплаты</th>
        <th style="width:170px">Когда платим</th>
      </tr>
      <?php foreach ($suppliers as $s): ?>
        <?php
          $sid = (int)$s['id'];
          $o = $s['array_options'] ?? [];
          $email = (string)($s['email'] ?? '');
          $contact = (string)($o['options_contact_person'] ?? '');
          $contract = (string)($o['options_contract_number'] ?? '');
          $termId = (int)($s['cond_reglement_supplier_id'] ?? 0);
          $when = (string)($o['options_payment_terms'] ?? '');
          $n = "row[$sid]";
        ?>
        <tr data-name="<?= htmlspecialchars(mb_strtolower((string)($s['name'] ?? ''))) ?>">
          <td>
            <?= htmlspecialchars((string)($s['name'] ?? '')) ?>
            <input type="hidden" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string)($s['name'] ?? '')) ?>">
            <input type="hidden" name="<?= $n ?>[orig][email]" value="<?= htmlspecialchars($email) ?>">
            <input type="hidden" name="<?= $n ?>[orig][contact_person]" value="<?= htmlspecialchars($contact) ?>">
            <input type="hidden" name="<?= $n ?>[orig][contract_number]" value="<?= htmlspecialchars($contract) ?>">
            <input type="hidden" name="<?= $n ?>[orig][payment_term]" value="<?= $termId ?: '' ?>">
            <input type="hidden" name="<?= $n ?>[orig][payment_when]" value="<?= htmlspecialchars($when) ?>">
          </td>
          <td><input type="text" name="<?= $n ?>[email]" value="<?= htmlspecialchars($email) ?>" style="margin:0"></td>
          <td><input type="text" name="<?= $n ?>[contact_person]" value="<?= htmlspecialchars($contact) ?>" style="margin:0"></td>
          <td><input type="text" name="<?= $n ?>[contract_number]" value="<?= htmlspecialchars($contract) ?>" style="margin:0"></td>
          <td>
            <select name="<?= $n ?>[payment_term]" style="margin:0">
              <option value="">—</option>
              <?php foreach ($terms as $tid => $t): ?>
                <option value="<?= (int)$tid ?>" <?= $termId === (int)$tid ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="<?= $n ?>[payment_when]" style="margin:0">
              <option value="" <?= $when === '' ? 'selected' : '' ?>>—</option>
              <option value="prepay"  <?= $when === 'prepay'  ? 'selected' : '' ?>>Предоплата</option>
              <option value="postpay" <?= $when === 'postpay' ? 'selected' : '' ?>>Постоплата</option>
            </select>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <button type="submit">Сохранить изменения</button>
    <span class="muted">Запишутся только те карточки, где что-то поменяли.</span>
  </div>
</form>

<script>
// Фильтр по названию — клиентский, 52 строки, ходить на сервер незачем.
(function () {
  const input = document.getElementById('filter');
  const table = document.getElementById('bulkTable');
  const counter = document.getElementById('counter');
  if (!input || !table) return;
  const rows = Array.from(table.querySelectorAll('tr[data-name]'));
  function apply() {
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(function (r) {
      const hit = !q || r.dataset.name.indexOf(q) !== -1;
      r.style.display = hit ? '' : 'none';
      if (hit) shown++;
    });
    counter.textContent = 'показано ' + shown + ' из ' + rows.length;
  }
  input.addEventListener('input', apply);
  apply();
})();
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
