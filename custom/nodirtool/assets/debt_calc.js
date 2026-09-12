/*
 * «Сколько долга закрыто» при оплате перевозчику не в валюте долга (11.09.2026).
 *
 * Раньше поле заполнялось полным остатком рейса — и первая же живая оплата 2 000 $ «закрыла» 3 500 €
 * (по курсу рейса это 1 720 €). Теперь поле считается из суммы оплаты по курсу рейса (или Dolibarr)
 * и пересчитывается при каждом вводе, пока человек не исправит его сам (договорной курс наличных).
 * Сервер дополнительно отказывает, если суммы расходятся больше чем на 15% (carrier_pay()).
 *
 * o: {acc, amt, rate, box, debt, hint, label?, cur(), ref(), left()}
 *    ref() — курс валюты долга «единиц за 1 $»; rate — поле курса счёта (для счёта не в долларах).
 */
function nt_debt_calc(o) {
  let manual = false;
  const fmt = v => v.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const accCur = () => (o.acc.options[o.acc.selectedIndex] || {dataset: {}}).dataset.cur;
  function suggest() {
    const a = parseFloat(o.amt.value), ref = o.ref();
    const ra = accCur() === 'USD' ? 1 : parseFloat(o.rate.value);
    if (!(a > 0) || !(ref > 0) || !(ra > 0)) return null;
    let v = Math.round(a / ra * ref * 100) / 100;
    const left = o.left();
    if (left > 0 && v > left && v <= left * 1.02) v = left;   // копейки курса не должны давать «переплату»
    return v;
  }
  function sync() {
    const cur = o.cur(), same = !cur || accCur() === cur;
    o.box.style.display = same ? 'none' : '';
    o.debt.required = !same;
    if (o.label) o.label.textContent = cur || '';
    if (same) { if (!o.amt.value && o.left() > 0) o.amt.value = o.left(); o.hint.textContent = ''; return; }
    const v = suggest(), ref = o.ref();
    if (!manual) o.debt.value = v !== null ? v : '';
    o.hint.textContent = v !== null
      ? 'По курсу ' + ref + ' ' + cur + ' за 1 $ эта оплата закрывает ≈ ' + fmt(v) + ' ' + cur +
        '. Договорились на другой курс — исправьте. Осталось по долгу: ' + fmt(o.left()) + ' ' + cur + '.'
      : 'Введите сумму оплаты — посчитаю, сколько ' + cur + ' она закрывает. Осталось по долгу: ' + fmt(o.left()) + ' ' + cur + '.';
  }
  o.debt.addEventListener('input', () => { manual = o.debt.value !== ''; });
  o.amt.addEventListener('input', sync);
  o.rate.addEventListener('input', sync);
  // смена счёта: помощник курса (expense_payment_fields_html) подставляет свой курс в том же событии —
  // пересчитываем после него
  o.acc.addEventListener('change', () => { manual = false; o.debt.value = ''; setTimeout(sync, 0); });
  sync();
  return { reset() { manual = false; o.debt.value = ''; sync(); } };
}
