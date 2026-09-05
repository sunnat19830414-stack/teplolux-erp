// Общий живой поиск: текстовое поле + список результатов. Используется и для товаров, и для
// поставщиков — endpoint и рендер результата настраиваются через data-атрибуты + window-колбэки.
(function () {
  function wireSearch(inputId, resultsId, endpoint, onPick, renderItem) {
    const input = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    if (!input || !results) return;

    let timer = null;
    function doSearch() {
      const q = input.value.trim();
      let url = endpoint + '?q=' + encodeURIComponent(q);
      // data-extra-query на самом input — способ передать доп.параметр (например supplier_id),
      // когда его нельзя взять из сессии по умолчанию (страница просмотра конкретного заказа
      // может относиться к ДРУГОМУ поставщику, чем тот, что сейчас выбран в "Заказы поставщику").
      if (input.dataset.extraQuery) url += '&' + input.dataset.extraQuery;
      fetch(url)
        .then(r => r.json())
        .then(items => {
          results.innerHTML = '';
          items.forEach(item => {
            const div = document.createElement('div');
            div.className = 'search-result';
            div.innerHTML = renderItem(item);
            div.addEventListener('click', () => onPick(item));
            results.appendChild(div);
          });
          if (items.length === 0) {
            results.innerHTML = '<div class="muted" style="padding:8px">Ничего не найдено.</div>';
          }
        });
    }
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(doSearch, 200); });
    input.addEventListener('focus', () => { if (!results.dataset.loaded) { doSearch(); results.dataset.loaded = '1'; } });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/</g, '&lt;'); }
  function num(n) { return (Math.round(n * 100) / 100).toLocaleString('ru-RU'); }

  // Строка товара в поиске при составлении заявки. Закупочные цены руководству здесь не показываем
  // (их место в инструменте закупщика), а вот остаток и «уже едет» — это ровно то, из чего шеф
  // решает, что и сколько покупать.
  window.renderProductLine = function (p) {
    const stock = Number(p.stock || 0);
    const incoming = Number(p.incoming || 0);
    let stockLabel = stock > 0
      ? '<span style="color:#15803d;font-weight:600">На складе: ' + num(stock) + '</span>'
      : '<span style="color:#b45309;font-weight:600">На складе: 0</span>';
    if (incoming > 0) stockLabel += ' · <span style="color:#0f766e">уже едет: ' + num(incoming) + '</span>';

    return '<strong>' + esc(p.label) + '</strong>' +
      '<div class="muted">' + esc(p.ref) + '</div>' +
      '<div class="muted" style="margin-top:2px">' + stockLabel + '</div>';
  };

  window.wireProductSearch = function (inputId, resultsId, onPick, renderItem) {
    wireSearch(inputId, resultsId, 'ajax_search_product.php', onPick, renderItem || window.renderProductLine);
  };

  window.wireSupplierSearch = function (inputId, resultsId, onPick) {
    wireSearch(inputId, resultsId, 'ajax_search_supplier.php', onPick, s =>
      '<strong>' + s.name.replace(/</g, '&lt;') + '</strong>' +
      (s.code ? '<div class="muted">' + s.code.replace(/</g, '&lt;') + '</div>' : '')
    );
  };

  window.wireCarrierSearch = function (inputId, resultsId, onPick) {
    wireSearch(inputId, resultsId, 'ajax_search_carrier.php', onPick, c =>
      '<strong>' + c.name.replace(/</g, '&lt;') + '</strong>'
    );
  };

  window.wireOrderSearch = function (inputId, resultsId, onPick) {
    wireSearch(inputId, resultsId, 'ajax_search_order.php', onPick, o =>
      '<strong>' + o.ref.replace(/</g, '&lt;') + '</strong>' +
      '<div class="muted">' + o.supplier.replace(/</g, '&lt;') + ' · ' + o.status_label.replace(/</g, '&lt;') + ' · ' + o.total_ttc.toFixed(2) + ' $</div>'
    );
  };
})();
