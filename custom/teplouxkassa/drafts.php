<?php
/**
 * Черновики продаж — список + открытие в обычной корзине sale.php / отмена / выгрузка в Excel в
 * любой момент. См. includes/draft_orders.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/draft_orders.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $draftId = (int)($_POST['draft_id'] ?? 0);

    if ($action === 'open_draft') {
        $draft = get_draft($draftId, $_SESSION['direction']);
        if (!$draft || $draft['status'] !== 'open') {
            $message = 'Черновик не найден или уже закрыт.';
            $messageType = 'err';
        } else {
            $_SESSION['cart'] = $draft['items'];
            $_SESSION['sale_client'] = ['id' => (int)$draft['fk_societe'], 'name' => $draft['client_name']];
            $_SESSION['loaded_draft_id'] = (int)$draft['rowid'];
            // Редирект — тоже GET-запрос, а sale.php сбрасывает sale_client на обычном GET-заходе
            // (см. reset_selection_unless_preserved) — тот же одноразовый флаг, что и в client_form.php,
            // иначе только что установленный клиент тут же стёрся бы при переходе.
            $_SESSION['_preserve_once']['sale_client'] = true;
            header('Location: sale.php');
            exit;
        }
    } elseif ($action === 'cancel_draft') {
        if (cancel_draft_order($draftId, $_SESSION['direction'])) {
            $message = "Черновик #$draftId отменён.";
            $messageType = 'ok';
        } else {
            $message = 'Не удалось отменить — черновик не найден или уже закрыт.';
            $messageType = 'err';
        }
    }
}

$showHistory = !empty($_GET['history']);
$openDrafts = get_open_drafts($_SESSION['direction']);
$historyDrafts = $showHistory ? get_draft_history($_SESSION['direction']) : [];
$vatMult = 1 + $cfg['vat_rate'] / 100;

function draft_total(array $draft, float $vatMult): float
{
    $total = 0;
    foreach ($draft['items'] as $item) {
        $total += ($item['price'] ?? 0) * ($item['qty'] ?? 0) * $vatMult;
    }
    return round($total, 2);
}

$currentCartNonEmpty = !empty($_SESSION['cart']);

require __DIR__ . '/includes/layout_top.php';
?>

<h1>Черновики продаж</h1>
<p class="muted">Заготовки продаж — можно создать сразу несколько (кнопка «Сохранить как черновик» на
   странице «Продажа»), они не трогают склад и долг клиента, пока их не откроете и не доведёте до
   настоящего оформления. Скачать Excel можно в любой момент, не закрывая черновик.</p>
<?php if ($message): ?><p class="<?= $messageType ?>"><?= htmlspecialchars($message) ?></p><?php endif; ?>

<div class="card">
  <h2>Открытые черновики</h2>
  <?php if (empty($openDrafts)): ?>
    <p class="muted">Пока пусто. Чтобы создать — соберите корзину на странице «Продажа» и нажмите «Сохранить как черновик».</p>
  <?php else: ?>
    <?php foreach ($openDrafts as $d): ?>
      <div class="doc-block" style="border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-bottom:10px;">
        <div class="row" style="align-items:center; margin-bottom:6px">
          <div>
            <strong>#<?= (int)$d['rowid'] ?><?= $d['label'] !== '' ? ' — ' . htmlspecialchars($d['label']) : '' ?></strong>
            <div class="muted"><?= htmlspecialchars($d['client_name']) ?> · <?= count($d['items']) ?> позиц. · создан <?= htmlspecialchars(substr($d['datec'], 0, 16)) ?></div>
          </div>
          <div style="text-align:right; font-weight:700; font-size:16px;"><?= number_format(draft_total($d, $vatMult), 2) ?> $</div>
        </div>
        <div class="stage-row">
          <form method="post" style="display:inline" onsubmit="return <?= $currentCartNonEmpty ? "appConfirmSubmit(this, 'В текущей корзине уже есть позиции — они будут заменены содержимым черновика. Продолжить?')" : 'true' ?>;">
  <?= csrf_field() ?>
            <input type="hidden" name="action" value="open_draft">
            <input type="hidden" name="draft_id" value="<?= (int)$d['rowid'] ?>">
            <button type="submit">Открыть в продаже</button>
          </form>
          <a class="btn secondary small" href="draft_excel.php?id=<?= (int)$d['rowid'] ?>">📄 Excel</a>
          <form method="post" style="display:inline" onsubmit="return appConfirmSubmit(this, 'Отменить черновик #<?= (int)$d['rowid'] ?>? Это необратимо.');">
  <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_draft">
            <input type="hidden" name="draft_id" value="<?= (int)$d['rowid'] ?>">
            <button type="submit" class="secondary small">Отменить</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (!$showHistory): ?>
    <a href="drafts.php?history=1" class="muted">Показать историю (переведённые в продажу / отменённые) →</a>
  <?php else: ?>
    <h2>История</h2>
    <a href="drafts.php" class="muted">← Скрыть историю</a>
    <?php if (empty($historyDrafts)): ?>
      <p class="muted" style="margin-top:10px">Пусто.</p>
    <?php else: ?>
      <table style="margin-top:10px">
        <tr><th>№</th><th>Клиент</th><th>Пометка</th><th>Сумма</th><th>Статус</th><th>Когда</th><th></th></tr>
        <?php foreach ($historyDrafts as $d): ?>
          <tr>
            <td>#<?= (int)$d['rowid'] ?></td>
            <td><?= htmlspecialchars($d['client_name']) ?></td>
            <td class="muted"><?= htmlspecialchars($d['label']) ?></td>
            <td><?= number_format(draft_total($d, $vatMult), 2) ?> $</td>
            <td>
              <?php if ($d['status'] === 'converted'): ?>
                <span class="badge badge-ok">Продажа #<?= (int)$d['fk_invoice'] ?></span>
              <?php else: ?>
                <span class="badge badge-neutral">Отменён</span>
              <?php endif; ?>
            </td>
            <td class="muted"><?= htmlspecialchars(substr($d['date_converted'] ?? $d['date_cancelled'] ?? $d['datec'], 0, 16)) ?></td>
            <td><a href="draft_excel.php?id=<?= (int)$d['rowid'] ?>">📄</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
