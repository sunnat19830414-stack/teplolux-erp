/**
 * Общий компонент "плитки брендов + живой поиск" для товаров.
 * Использование на странице:
 *   <div id="categoryTiles"></div>
 *   <div id="categoryBackRow" style="display:none"><button type="button" id="btnBackToCats" class="secondary">← Все категории</button> <strong id="currentCatLabel"></strong></div>
 *   <input type="text" id="productSearch" placeholder="Поиск по названию или артикулу...">
 *   <div id="productResults"></div>
 * Перед подключением скрипта задать: window.CATEGORIES = {id: 'Label', ...}
 * И определить: window.onProductPick = function(product) { ... }
 */
(function () {
  function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  }

  let currentCategoryId = 0;
  let currentCategoryLabel = '';

  function renderTiles() {
    const box = document.getElementById('categoryTiles');
    if (!box) return;
    box.innerHTML = '';
    const cats = window.CATEGORIES || {};
    Object.keys(cats).forEach(id => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cat-tile';
      btn.textContent = cats[id];
      btn.onclick = () => selectCategory(id, cats[id]);
      box.appendChild(btn);
    });
  }

  function selectCategory(id, label) {
    currentCategoryId = id;
    currentCategoryLabel = label;
    document.getElementById('categoryTiles').style.display = 'none';
    const backRow = document.getElementById('categoryBackRow');
    if (backRow) {
      backRow.style.display = 'flex';
      document.getElementById('currentCatLabel').textContent = label;
    }
    document.getElementById('productSearch').value = '';
    document.getElementById('productSearch').focus();
    runSearch('');
  }

  function backToCategories() {
    currentCategoryId = 0;
    currentCategoryLabel = '';
    document.getElementById('categoryTiles').style.display = 'flex';
    const backRow = document.getElementById('categoryBackRow');
    if (backRow) backRow.style.display = 'none';
    document.getElementById('productSearch').value = '';
    document.getElementById('productResults').innerHTML = '';
  }

  async function runSearch(term) {
    const box = document.getElementById('productResults');
    if (term === '' && currentCategoryId === 0) { box.innerHTML = ''; return; }
    box.innerHTML = '<p class="muted">Ищу...</p>';
    const params = new URLSearchParams({ q: term });
    if (currentCategoryId) params.set('category_id', currentCategoryId);
    const res = await fetch('ajax_search_product.php?' + params.toString());
    const items = await res.json();
    box.innerHTML = '';
    if (items.error) { box.innerHTML = '<p class="err">' + items.error + '</p>'; return; }
    if (items.length === 0) { box.innerHTML = '<p class="muted">Ничего не найдено</p>'; return; }
    items.forEach(p => {
      const div = document.createElement('div');
      div.className = 'search-result';
      if (window.renderProductResult) {
        div.innerHTML = window.renderProductResult(p);
      } else {
        div.innerHTML = '<strong>' + p.label + '</strong><br><span class="muted">' + p.ref + ' · остаток: ' + p.stock + (p.price ? ' · ' + p.price.toFixed(2) + ' $' : '') + '</span>';
      }
      div.onclick = () => window.onProductPick && window.onProductPick(p);
      box.appendChild(div);
    });
  }

  // --- Клиенты: живой список сразу по фокусу/клику, печать сужает ---
  async function runClientSearch(term) {
    const box = document.getElementById('clientResults');
    if (!box) return;
    box.innerHTML = '<p class="muted">Загрузка...</p>';
    const res = await fetch('ajax_search_client.php?q=' + encodeURIComponent(term));
    const items = await res.json();
    box.innerHTML = '';
    if (items.error) { box.innerHTML = '<p class="err">' + items.error + '</p>'; return; }
    if (items.length === 0) { box.innerHTML = '<p class="muted">Ничего не найдено</p>'; return; }
    items.forEach(c => {
      const div = document.createElement('div');
      div.className = 'search-result';
      div.innerHTML = '<strong>' + c.name + '</strong><br><span class="muted">' + c.code_client + '</span>';
      div.onclick = () => window.onClientPick && window.onClientPick(c);
      box.appendChild(div);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    renderTiles();
    const backBtn = document.getElementById('btnBackToCats');
    if (backBtn) backBtn.onclick = backToCategories;
    const input = document.getElementById('productSearch');
    if (input) {
      input.addEventListener('input', debounce(e => runSearch(e.target.value.trim()), 350));
    }

    const clientInput = document.getElementById('clientSearch');
    if (clientInput) {
      let opened = false;
      clientInput.addEventListener('focus', () => { if (!opened) { opened = true; runClientSearch(''); } });
      clientInput.addEventListener('input', debounce(e => runClientSearch(e.target.value.trim()), 350));
    }
  });
})();
