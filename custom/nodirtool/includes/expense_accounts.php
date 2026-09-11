<?php
/**
 * «Откуда оплачено» для логистических расходов — заказа и партии (11.09.2026).
 *
 * Раньше счёт выбирался молча: в долларах — всегда USD-MAIN, в сумах — всегда UZS-MAIN (банк).
 * Пользователь: «платят по-разному». Если Абдурашид отдал расходы декларанту наличными из своей
 * кассы, а программа списала их с банка — расходятся обе цифры: в кассе денег больше, чем на деле,
 * в банке меньше. Теперь счёт выбирает человек, а валюта берётся из самого счёта.
 *
 * Какие счета предлагаем: своя касса вошедшего (чужую кассу трогать нельзя — за её остаток отвечает
 * другой человек), сумовый банк и валютные счета компании. Кассы продавцов и шефа — нет.
 *
 * Курс везде в одной форме — «единиц валюты за 1 $», как в Dolibarr и в «Перевозках»
 * (сум за $ ~12 700, рубль ~86, евро ~0,86). Для евро и рубля подставляем курс Dolibarr (он
 * обновляется ежедневно) — для сума нет: курс сума в Dolibarr заглушка, его вводят в моменте.
 */

/** Счета, с которых этот пользователь может оплатить расход: [id => ['label','currency']]. */
function expense_payment_accounts(mysqli $db, array $cfg, string $login): array
{
    $ids = [];
    $own = $cfg['personal_cash_accounts'][$login]['id'] ?? null;
    if ($own) $ids[] = (int)$own;
    $ids[] = (int)$cfg['uzs_account_id'];
    foreach ($cfg['currency_accounts'] as $accId) $ids[] = (int)$accId;
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];

    $out = [];
    $r = $db->query("SELECT rowid, ref, label, currency_code FROM llx_bank_account
                     WHERE rowid IN (" . implode(',', $ids) . ") AND clos = 0");
    $rows = [];
    while ($x = $r->fetch_assoc()) $rows[(int)$x['rowid']] = $x;
    foreach ($ids as $id) {                      // порядок: своя касса первой — чаще всего платят из неё
        if (!isset($rows[$id])) continue;
        $x = $rows[$id];
        $label = ($id === (int)$own) ? 'Моя касса — ' . $x['label'] : $x['label'];
        $out[$id] = ['label' => $label . ' (' . $x['currency_code'] . ')', 'currency' => strtoupper($x['currency_code'])];
    }
    return $out;
}

/** Последний курс Dolibarr для валюты («единиц за 1 $»), кроме сума — там заглушка. */
function expense_default_rate(mysqli $db, string $currency): ?float
{
    if ($currency === 'USD' || $currency === 'UZS') return null;
    $st = $db->prepare("SELECT r.rate FROM llx_multicurrency m JOIN llx_multicurrency_rate r ON r.fk_multicurrency = m.rowid
                        WHERE m.code = ? ORDER BY r.date_sync DESC LIMIT 1");
    $st->bind_param('s', $currency); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();
    return $row ? (float)$row['rate'] : null;
}

/**
 * Разбор формы. Возвращает ['ok'=>true, 'account'=>id, 'currency'=>..., 'amount'=>..., 'rate'=>...|null]
 * или ['ok'=>false, 'error'=>...]. Счёт проверяется по списку разрешённых — подставить в POST чужую
 * кассу нельзя.
 */
function expense_parse_payment(array $post, array $accounts): array
{
    $acc = (int)($post['pay_account'] ?? 0);
    if (!isset($accounts[$acc])) return ['ok' => false, 'error' => 'Выберите, откуда оплачено.'];
    $cur = $accounts[$acc]['currency'];
    $amount = round((float)str_replace([' ', ','], ['', '.'], (string)($post['pay_amount'] ?? 0)), 2);
    if ($amount <= 0) return ['ok' => false, 'error' => 'Укажите сумму расхода.'];
    $rate = null;
    if ($cur !== 'USD') {
        $rate = (float)str_replace([' ', ','], ['', '.'], (string)($post['pay_rate'] ?? 0));
        if ($rate <= 0) return ['ok' => false, 'error' => "Укажите курс: сколько {$cur} за 1 \$ — он нужен, чтобы расход попал в себестоимость."];
    }
    return ['ok' => true, 'account' => $acc, 'currency' => $cur, 'amount' => $amount, 'rate' => $rate];
}

/**
 * Поля формы «откуда оплачено / сумма / курс» + живой пересчёт в доллары. $suffix различает формы,
 * если их несколько на странице.
 */
function expense_payment_fields_html(mysqli $db, array $accounts, string $suffix = '',
                                     string $usdHint = 'попадёт в себестоимость'): string
{
    $defaults = [];
    foreach ($accounts as $id => $a) $defaults[$id] = expense_default_rate($db, $a['currency']);
    $sel = 'payAcc' . $suffix; $amt = 'payAmt' . $suffix; $rt = 'payRate' . $suffix;
    $rtBox = 'payRateBox' . $suffix; $cur = 'payCur' . $suffix; $usd = 'payUsd' . $suffix; $rtLbl = 'payRateLbl' . $suffix;

    ob_start(); ?>
    <div class="row">
      <div>
        <label>Откуда оплачено</label>
        <select name="pay_account" id="<?= $sel ?>" required>
          <?php foreach ($accounts as $id => $a): ?>
            <option value="<?= $id ?>" data-cur="<?= htmlspecialchars($a['currency']) ?>"
                    data-rate="<?= $defaults[$id] !== null ? rtrim(rtrim(number_format($defaults[$id], 6, '.', ''), '0'), '.') : '' ?>">
              <?= htmlspecialchars($a['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Сумма, <span id="<?= $cur ?>">USD</span></label>
        <input type="number" step="0.01" min="0.01" name="pay_amount" id="<?= $amt ?>" required></div>
      <div id="<?= $rtBox ?>" style="display:none"><label id="<?= $rtLbl ?>">Курс</label>
        <input type="number" step="any" min="0" name="pay_rate" id="<?= $rt ?>"></div>
    </div>
    <p class="muted" id="<?= $usd ?>" style="margin-top:-4px"></p>
    <script>
    (function () {
      const sel = document.getElementById('<?= $sel ?>'), amt = document.getElementById('<?= $amt ?>');
      const rt = document.getElementById('<?= $rt ?>'), box = document.getElementById('<?= $rtBox ?>');
      const cur = document.getElementById('<?= $cur ?>'), usd = document.getElementById('<?= $usd ?>');
      const lbl = document.getElementById('<?= $rtLbl ?>');
      function opt() { return sel.options[sel.selectedIndex]; }
      function sync(resetRate) {
        const c = opt().dataset.cur;
        cur.textContent = c;
        const foreign = c !== 'USD';
        box.style.display = foreign ? '' : 'none';
        rt.required = foreign;
        lbl.textContent = 'Курс: ' + c + ' за 1 $';
        if (resetRate) rt.value = opt().dataset.rate || '';
        calc();
      }
      // Пересчёт в доллары прямо в форме — чтобы перепутанный курс (1,16 вместо 0,86 у евро)
      // был виден до сохранения, а не в себестоимости через месяц.
      function calc() {
        const c = opt().dataset.cur, a = parseFloat(amt.value), r = parseFloat(rt.value);
        if (!(a > 0)) { usd.textContent = ''; return; }
        if (c === 'USD') { usd.textContent = ''; return; }
        usd.textContent = r > 0 ? '≈ ' + (a / r).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' $ <?= htmlspecialchars($usdHint) ?>' : 'укажите курс';
      }
      sel.addEventListener('change', () => sync(true));
      amt.addEventListener('input', calc); rt.addEventListener('input', calc);
      sync(true);
    })();
    </script>
    <?php
    return ob_get_clean();
}
