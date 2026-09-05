<?php
/** Общая шапка «за период» — одинаковая на всех отчётах, чтобы не расходилась по виду и поведению. */
function render_period_form(string $page, string $from, string $to, array $extra = []): void
{
    $presets = [
        'Этот месяц'      => [date('Y-m-01'), date('Y-m-t')],
        'Прошлый месяц'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'С начала года'   => [date('Y-01-01'), date('Y-m-d')],
    ];
    ?>
    <div class="card">
      <form method="get" class="row" style="align-items:end">
        <?php foreach ($extra as $k => $v): ?>
          <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
        <?php endforeach; ?>
        <div style="max-width:180px"><label>С</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></div>
        <div style="max-width:180px"><label>По</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></div>
        <div style="flex:0"><button type="submit">Показать</button></div>
        <div style="align-self:center">
          <?php foreach ($presets as $name => [$f, $t]): ?>
            <a class="btn secondary small" style="margin-right:6px"
               href="<?= htmlspecialchars($page) ?>?from=<?= $f ?>&to=<?= $t ?><?php
                 foreach ($extra as $k => $v) echo '&' . urlencode($k) . '=' . urlencode((string)$v); ?>"><?= htmlspecialchars($name) ?></a>
          <?php endforeach; ?>
        </div>
      </form>
    </div>
    <?php
}
