/*
 * Таблица позиций заказа — корзина нового заказа (orders.php) и черновик (order_view.php), 11.09.2026.
 *
 * Замечание Абдурашида (заказ ICMA, 154 позиции): каждая правка количества или цены перезагружала
 * страницу — список прыгал наверх, нужную строку приходилось искать заново; удаление каждой строки
 * спрашивало подтверждение; отсортировать список было нельзя. Теперь:
 *  - количество и цена сохраняются без перезагрузки (fetch), строка остаётся на месте, сумма и итог
 *    пересчитываются сразу; ошибка — красным в самой строке, значение возвращается прежнее;
 *  - ✕ удаляет сразу, без вопроса, но внизу появляется «Вернуть» (позиция добавляется обратно);
 *  - галочки + «Удалить отмеченные» — одно подтверждение на сколько угодно строк;
 *  - сортировка по щелчку на заголовке (артикул, наименование, количество, цена, сумма), второй
 *    щелчок — в обратную сторону; выбор запоминается в этом браузере.
 *
 * Сервер отвечает JSON на запросы с ajax=1: {ok, error?, qty?, price?, line_total?, totals?, key?,
 * deleted?[]}. totals = {main, sub, count} — готовые строки (формат денег знает PHP, а не JS).
 *
 * opt: {endpoint, csrf, extra:{}, update:'update_line', remove:'delete_lines', undo:'add_line'|null,
 *       storeKey, onTotals(totals)}
 */
(function () {
  let toastEl = null, toastTimer = null;
  function toast(html, ms) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'nt-toast';
      document.body.appendChild(toastEl);
    }
    toastEl.innerHTML = html;
    toastEl.style.display = 'flex';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastEl.style.display = 'none'; }, ms || 8000);
    return toastEl;
  }
  const num = v => { const n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? 0 : n; };
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

  window.ntLineTable = function (table, opt) {
    if (!table) return;
    const body = table.tBodies[0];
    const bulkBtn = opt.bulkButton || null;

    async function send(fields) {
      const fd = new URLSearchParams();
      fd.append('_csrf', opt.csrf); fd.append('ajax', '1');
      Object.entries(opt.extra || {}).forEach(([k, v]) => fd.append(k, v));
      Object.entries(fields).forEach(([k, v]) => {
        if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x)); else fd.append(k, v);
      });
      let res, text;
      try {
        res = await fetch(opt.endpoint, {method: 'POST', body: fd, credentials: 'same-origin',
                                         headers: {'X-Requested-With': 'fetch'}});
        text = await res.text();
      } catch (e) {
        return {ok: false, error: 'Нет связи с сервером — изменение не сохранено.'};
      }
      try { return JSON.parse(text); } catch (e) {
        return {ok: false, error: res.status === 403 || /login/i.test(res.url)
          ? 'Сессия истекла или форма устарела — обновите страницу (F5).'
          : 'Сервер ответил не так, как ожидалось — изменение не сохранено.'};
      }
    }
    function totals(t) { if (t && opt.onTotals) opt.onTotals(t); }

    function markPrice(tr) {
      const p = num(tr.dataset.price);
      tr.classList.toggle('nt-noprice', p <= 0.011);
      const hint = tr.querySelector('.nt-hint');
      if (hint && tr.dataset.refprice !== undefined) {
        const rp = tr.dataset.refprice;
        hint.textContent = rp === '' ? 'нет в прайсе'
          : (Math.abs(num(rp) - p) < 0.0001 ? 'из прайса' : 'в прайсе: ' + rp);
      }
    }
    function flash(tr, cls) {
      tr.classList.remove('nt-saved', 'nt-failed');
      void tr.offsetWidth;
      tr.classList.add(cls);
      setTimeout(() => tr.classList.remove(cls), 1600);
    }

    // ── правка количества / цены
    async function onEdit(input) {
      const tr = input.closest('tr');
      const field = input.dataset.field;                    // 'qty' | 'price'
      const old = tr.dataset[field];
      if (num(input.value) === num(old)) return;
      if (field === 'qty' && !(num(input.value) > 0)) { input.value = old; toast('Количество должно быть больше нуля.'); return; }
      input.disabled = true;
      const r = await send({action: opt.update, key: tr.dataset.key, product_id: tr.dataset.product || '',
        desc: tr.dataset.desc || '', qty: field === 'qty' ? input.value : tr.dataset.qty,
        price: field === 'price' ? input.value : tr.dataset.price});
      input.disabled = false;
      if (!r.ok) {
        input.value = old; flash(tr, 'nt-failed');
        toast('<span class="err">' + esc(r.error || 'Не сохранено.') + '</span>');
        return;
      }
      tr.dataset.qty = r.qty ?? tr.dataset.qty;
      tr.dataset.price = r.price ?? tr.dataset.price;
      tr.dataset.sum = r.line_total_raw ?? (num(tr.dataset.qty) * num(tr.dataset.price));
      const sumCell = tr.querySelector('.nt-sum');
      if (sumCell && r.line_total) sumCell.textContent = r.line_total;
      markPrice(tr); flash(tr, 'nt-saved'); totals(r.totals);
    }
    table.addEventListener('change', e => { if (e.target.matches('input.nt-edit')) onEdit(e.target); });
    // Enter в поле — сохранить, а не отправить форму (форм в строках больше нет, но на всякий случай)
    table.addEventListener('keydown', e => {
      if (e.key === 'Enter' && e.target.matches('input.nt-edit')) { e.preventDefault(); e.target.blur(); }
    });

    // ── удаление
    function selected() { return [...body.querySelectorAll('input.nt-check:checked')].map(c => c.closest('tr')); }
    function refreshBulk() {
      if (!bulkBtn) return;
      const n = selected().length;
      bulkBtn.style.display = n === 0 ? 'none' : '';
      bulkBtn.textContent = 'Удалить отмеченные (' + n + ')';
    }
    async function removeRows(rows, withUndo) {
      const r = await send({action: opt.remove, keys: rows.map(tr => tr.dataset.key)});
      const gone = new Set((r.deleted || []).map(String));
      const removed = [];
      rows.forEach(tr => {
        if (!gone.has(String(tr.dataset.key))) return;
        removed.push({tr, next: tr.nextElementSibling});
        tr.remove();
      });
      totals(r.totals); refreshBulk();
      if (!r.ok) toast('<span class="err">' + esc(r.error || 'Не всё удалилось.') + '</span>');
      else if (withUndo && opt.undo && removed.length === 1) {
        const {tr, next} = removed[0];
        const el = toast('Удалено: ' + esc(tr.dataset.ref || tr.dataset.desc || '') +
          ' <button type="button" class="secondary small">↶ Вернуть</button>', 10000);
        el.querySelector('button').onclick = async () => {
          el.style.display = 'none';
          const u = await send({action: opt.undo, product_id: tr.dataset.product, label: tr.dataset.desc || '',
            ref: tr.dataset.ref || '', qty: tr.dataset.qty, price: tr.dataset.price, refprice: tr.dataset.refprice ?? ''});
          if (!u.ok) { toast('<span class="err">' + esc(u.error || 'Вернуть не удалось — добавьте позицию заново.') + '</span>'); return; }
          tr.dataset.key = u.key;
          const cb = tr.querySelector('input.nt-check'); if (cb) cb.checked = false;
          if (next && next.parentNode === body) body.insertBefore(tr, next); else body.appendChild(tr);
          flash(tr, 'nt-saved'); totals(u.totals);
        };
      } else if (removed.length > 1) toast('Удалено позиций: ' + removed.length + '.');
    }
    table.addEventListener('click', e => {
      const del = e.target.closest('button.nt-del');
      if (del) { e.preventDefault(); removeRows([del.closest('tr')], true); }
    });
    table.addEventListener('change', e => {
      if (e.target.matches('input.nt-check-all')) {
        body.querySelectorAll('input.nt-check').forEach(c => { c.checked = e.target.checked; });
      }
      if (e.target.matches('input.nt-check, input.nt-check-all')) refreshBulk();
    });
    if (bulkBtn) bulkBtn.addEventListener('click', () => {
      const rows = selected();
      if (!rows.length) return;
      appConfirm('Удалить отмеченные позиции (' + rows.length + ')?').then(ok => {
        if (!ok) return;
        const all = table.querySelector('input.nt-check-all'); if (all) all.checked = false;
        removeRows(rows, false);
      });
    });

    // ── сортировка
    const heads = [...table.querySelectorAll('th[data-sort]')];
    function sortBy(key, dir) {
      const isNum = ['qty', 'price', 'sum'].includes(key);
      const rows = [...body.rows];
      rows.sort((a, b) => {
        const x = a.dataset[key] ?? '', y = b.dataset[key] ?? '';
        const c = isNum ? num(x) - num(y) : String(x).localeCompare(String(y), 'ru', {numeric: true, sensitivity: 'base'});
        return dir === 'desc' ? -c : c;
      });
      rows.forEach(r => body.appendChild(r));
      heads.forEach(h => { h.dataset.dir = h.dataset.sort === key ? dir : ''; });
      try { localStorage.setItem(opt.storeKey, key + ':' + dir); } catch (e) {}
    }
    heads.forEach(h => {
      h.classList.add('nt-sortable');
      h.title = 'Щёлкните, чтобы отсортировать';
      h.addEventListener('click', () => sortBy(h.dataset.sort, h.dataset.dir === 'asc' ? 'desc' : 'asc'));
    });
    body.querySelectorAll('tr').forEach(markPrice);
    try {
      const saved = localStorage.getItem(opt.storeKey);
      if (saved) { const [k, d] = saved.split(':'); if (heads.some(h => h.dataset.sort === k)) sortBy(k, d); }
    } catch (e) {}
  };
})();
