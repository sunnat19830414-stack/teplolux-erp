<?php
/**
 * Тонкий клиент к REST API Dolibarr — тот же паттерн, что в TeplouxKassa/includes/dolibarr_api.php,
 * но без направленческой изоляции (этот инструмент видит оба направления, оба поставщика).
 */
class DolibarrApi
{
    private $baseUrl;
    private $apiKey;
    public $lastError = '';
    public $lastHttpCode = 0;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Ограничить поисковый термин (вводит закупщик в поле поиска) безопасным набором символов, ПЕРЕД
     * addslashes() и склейкой в sqlfilters — доп. слой поверх экранирования на случай обхода WAF
     * самого Dolibarr, раз это пользовательский ввод, а не доверенное значение из конфига.
     */
    private static function safeSearchTerm(string $term): string
    {
        return preg_replace('/[^\p{L}\p{N}\s\-\._\/]/u', '', $term) ?? '';
    }

    private function request(string $method, string $path, $data = null)
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = ['DOLAPIKEY: ' . $this->apiKey, 'Content-Type: application/json'];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        // 60, не 20 — документы к заказу (includes/dolibarr_api.php, uploadOrderDocument/
        // downloadOrderDocument) идут тем же путём base64 в JSON, файлы разрешены без ограничения
        // размера (по решению пользователя), запас по времени не помешает.
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode = $httpCode;

        if ($curlErr) {
            $this->lastError = "Ошибка соединения: $curlErr";
            return null;
        }

        $decoded = json_decode($resp, true);

        if ($httpCode >= 400) {
            $this->lastError = is_array($decoded) && isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : "HTTP $httpCode: " . substr((string)$resp, 0, 300);
            return null;
        }

        $this->lastError = '';
        return $decoded;
    }

    public function get(string $path) { return $this->request('GET', $path); }
    public function post(string $path, array $data) { return $this->request('POST', $path, $data); }
    public function put(string $path, array $data) { return $this->request('PUT', $path, $data); }
    public function delete(string $path) { return $this->request('DELETE', $path); }

    // --- Товары (для добавления позиций в заказ, без ограничения направления) ---

    public function searchProducts(string $term, int $limit = 50)
    {
        $params = ['limit' => $limit, 'includestockdata' => 1];
        if ($term !== '') {
            $termEsc = addslashes(self::safeSearchTerm($term));
            $params['sqlfilters'] = "((t.ref:like:'%{$termEsc}%') or (t.label:like:'%{$termEsc}%'))";
        }
        return $this->get('products?' . http_build_query($params)) ?? [];
    }

    public function getProduct(int $id)
    {
        return $this->get("products/{$id}?includestockdata=1");
    }

    /**
     * Создать карточку товара (04.09.2026, пункт B9 отчёта «Пробелы NodirTool»): раньше в заказ
     * можно было поставить только товар, уже заведённый в каталоге — новинку от поставщика
     * приходилось заводить в самом Dolibarr или просить Сунната, и оформление заказа вставало.
     *
     * Доп.поля (`kod_sap` — J/T-код направления) выставляются ОТДЕЛЬНЫМ PUT: у этой версии Dolibarr
     * `array_options` на POST документов проходит мимо типизированной санации (та же грабля, что с
     * заказом поставщику, см. CLAUDE.md) — создаём, потом дописываем.
     */
    public function createProduct(string $ref, string $label, float $price = 0, ?int $defaultWarehouseId = null)
    {
        $payload = [
            'ref' => $ref,
            'label' => $label,
            'type' => 0,          // 0 = товар (не услуга)
            'status' => 1,        // в продаже
            'status_buy' => 1,    // в закупке
        ];
        if ($price > 0) {
            $payload['price'] = $price;
            $payload['price_base_type'] = 'HT';
        }
        if ($defaultWarehouseId) $payload['fk_default_warehouse'] = $defaultWarehouseId;

        return $this->post('products', $payload);
    }

    public function updateProductExtrafields(int $id, array $options)
    {
        $keyed = [];
        foreach ($options as $k => $v) { $keyed["options_{$k}"] = $v; }
        return $this->put("products/{$id}", ['array_options' => $keyed]);
    }

    /** Привязать товар к категории (бренду) — категории в Dolibarr общие на оба направления. */
    public function addProductToCategory(int $productId, int $categoryId)
    {
        return $this->post("categories/{$categoryId}/objects/product/{$productId}", []);
    }

    // --- Поставщики ---

    public function searchSuppliers(string $term = '', int $limit = 100)
    {
        $params = ['limit' => $limit, 'sortfield' => 't.nom', 'sortorder' => 'ASC'];
        // Перевозчики (is_carrier=1) технически тоже могут иметь fournisseur=1 по недосмотру —
        // исключаем явно, чтобы они не путались со списком товарных поставщиков (топ-5 пункт 3).
        // ВАЖНО: каждый атом фильтра — СВОИ скобки (a:op:b), иначе "Bad syntax of the search string" —
        // проверено эмпирически, "(a:is:null or a:=:0)" одной группой не парсится.
        $filters = "(t.fournisseur:=:1) and ((ef.is_carrier:is:null) or (ef.is_carrier:=:0))";
        if ($term !== '') {
            $termEsc = addslashes(self::safeSearchTerm($term));
            $filters .= " and (t.nom:like:'%{$termEsc}%')";
        }
        $params['sqlfilters'] = $filters;
        return $this->get('thirdparties?' . http_build_query($params)) ?? [];
    }

    public function getThirdparty(int $id)
    {
        return $this->get("thirdparties/{$id}");
    }

    /**
     * Несколько контрагентов по списку id ОДНИМ запросом (оператор :in: у Dolibarr sqlfilters,
     * проверено эмпирически — синтаксис "(t.rowid:in:1,2,3)", БЕЗ кавычек/внутренних скобок). Раньше
     * дашборды (payments.php "кому должны" и т.п.) звали getThirdparty() в цикле по одному на каждого
     * поставщика — см. отчёт ревью P0#5. Возвращает [id => контрагент].
     */
    public function getThirdpartiesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) return [];
        $q = http_build_query([
            'sqlfilters' => '(t.rowid:in:' . implode(',', $ids) . ')',
            'limit' => count($ids),
            'properties' => 'id,name',
        ]);
        $rows = $this->get('thirdparties?' . $q);
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[(int)$row['id']] = $row;
            }
        }
        return $out;
    }

    /** Создать нового поставщика (карточка контрагента с флагом fournisseur=1). */
    public function createSupplier(array $fields)
    {
        return $this->post('thirdparties', $fields + ['fournisseur' => 1]);
    }

    /**
     * Справочник стран (04.09.2026, риск R6 отчёта «Пробелы NodirTool»): раньше при создании
     * поставщика страна ЖЁСТКО проставлялась «Узбекистан», хотя 25 из 52 поставщиков иностранные.
     * Список статичный — кэшируем в сессии на всё время работы, чтобы не дёргать API на каждый заход
     * в форму. Возвращает [id => название] в алфавитном порядке.
     */
    public function getCountries(): array
    {
        if (!empty($_SESSION['_countries_cache'])) return $_SESSION['_countries_cache'];

        $rows = $this->get('setup/dictionary/countries?' . http_build_query([
            'limit' => 500, 'lang' => 'ru_RU', 'sortfield' => 't.code', 'sortorder' => 'ASC',
        ]));
        $out = [];
        foreach ((array)$rows as $c) {
            $label = trim((string)($c['label'] ?? ''));
            if ($label !== '' && !empty($c['id'])) $out[(int)$c['id']] = $label;
        }
        asort($out, SORT_LOCALE_STRING);
        if (!empty($out)) $_SESSION['_countries_cache'] = $out;
        return $out;
    }

    // --- Перевозчики (топ-5 пункт 3, 02.09.2026) ---
    // Настоящие контрагенты Dolibarr (societe), помеченные extrafield `is_carrier=1` — НЕ поставщики
    // товара (fournisseur=0), чтобы не попадать в поиск/списки поставщиков (searchSuppliers() ниже
    // явно их исключает). Долг/оплата считаются не через supplierinvoices (это для товарных поставок),
    // а через свою логику в includes/logistics.php — см. CLAUDE.md.

    public function searchCarriers(string $term = '', int $limit = 100): array
    {
        $filters = "(ef.is_carrier:=:1)";
        if ($term !== '') {
            $termEsc = addslashes(self::safeSearchTerm($term));
            $filters .= " and (t.nom:like:'%{$termEsc}%')";
        }
        $params = ['limit' => $limit, 'sortfield' => 't.nom', 'sortorder' => 'ASC', 'sqlfilters' => $filters];
        return $this->get('thirdparties?' . http_build_query($params)) ?? [];
    }

    public function createCarrier(array $fields)
    {
        return $this->post('thirdparties', $fields + ['array_options' => ['options_is_carrier' => 1]]);
    }

    /** Изменить данные существующего поставщика (частичное обновление — только переданные поля). */
    public function updateSupplier(int $id, array $fields)
    {
        return $this->put("thirdparties/{$id}", $fields);
    }

    /**
     * Следующий свободный код поставщика (P0028, P0029, ...). Как и у клиентов (см. TeplouxKassa),
     * модуль нумерации кодов в этой инсталляции не генерирует код сам — считаем по уже существующим.
     */
    public function getNextSupplierCode(): string
    {
        $q = http_build_query([
            'sqlfilters' => "(t.code_fournisseur:like:'P%')",
            'limit' => 30,
            'sortfield' => 't.code_fournisseur',
            'sortorder' => 'DESC',
            'properties' => 'id,code_fournisseur',
        ]);
        $rows = $this->get('thirdparties?' . $q);
        $max = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $code = $row['code_fournisseur'] ?? '';
                if (preg_match('/^P(\d{4})$/', $code, $m)) {
                    $max = max($max, (int)$m[1]);
                }
            }
        }
        return sprintf('P%04d', $max + 1);
    }

    /** Обновить доп.поля контрагента (например поля контракта) — сливается с уже существующими. */
    public function updateThirdpartyExtrafields(int $id, array $options)
    {
        $keyed = [];
        foreach ($options as $k => $v) { $keyed["options_{$k}"] = $v; }
        return $this->put("thirdparties/{$id}", ['array_options' => $keyed]);
    }

    // --- Заказы поставщику ---

    /**
     * Создать заказ поставщику. Если у поставщика валюта договора не доллар (пункт B2 отчёта,
     * 04.09.2026) — заказ сразу заводится В ЭТОЙ ВАЛЮТЕ с курсом, который ввёл закупщик. Проверено
     * эмпирически: Dolibarr сохраняет ИМЕННО переданный курс, а не берёт свой справочный.
     */
    public function createSupplierOrder(int $socId, string $currency = 'USD', float $rate = 1.0)
    {
        $payload = ['socid' => $socId];
        if ($currency !== '' && $currency !== 'USD' && $rate > 0) {
            $payload['multicurrency_code'] = $currency;
            $payload['multicurrency_tx'] = $rate;
        }
        return $this->post('supplierorders', $payload);
    }

    /**
     * Добавить позицию. $price — цена В ВАЛЮТЕ ЗАКАЗА. Для валютного заказа она передаётся как
     * `multicurrency_subprice`, а долларовую Dolibarr считает сам по курсу заказа (в `addline()`
     * есть `if ($pu_ht_devise > 0) { $pu = 0; }` — базовая цена намеренно игнорируется и выводится
     * из валютной). Для долларового заказа всё как раньше — обычный `subprice`.
     */
    public function addSupplierOrderLine(int $orderId, int $productId, string $label, float $qty, float $price, float $vatRate = 0, string $currency = 'USD')
    {
        $line = [
            'desc' => $label,
            'label' => $label,
            'qty' => $qty,
            'tva_tx' => $vatRate,
            'fk_product' => $productId,
            'remise_percent' => 0,
            'price_base_type' => 'HT',
        ];
        if ($currency !== '' && $currency !== 'USD') {
            $line['subprice'] = 0;
            $line['multicurrency_subprice'] = $price;
        } else {
            $line['subprice'] = $price;
        }
        return $this->post("supplierorders/{$orderId}/lines", $line);
    }

    public function validateSupplierOrder(int $id)
    {
        return $this->post("supplierorders/{$id}/validate", []);
    }

    /** Поиск заказов поставщику по номеру — для добавления в партию (batches.php). */
    public function searchSupplierOrders(string $term, int $limit = 20)
    {
        $termEsc = addslashes(self::safeSearchTerm($term));
        $q = http_build_query([
            'sqlfilters' => "(t.ref:like:'%{$termEsc}%')",
            'limit' => $limit,
            'sortfield' => 't.rowid',
            'sortorder' => 'DESC',
            'properties' => 'id,ref,socid,statut,total_ttc',
        ]);
        return $this->get('supplierorders?' . $q) ?? [];
    }

    public function approveSupplierOrder(int $id)
    {
        return $this->post("supplierorders/{$id}/approve", []);
    }

    /** "Отправить поставщику" — переводит в статус running(3), после этого товар может ехать. */
    public function sendSupplierOrder(int $id)
    {
        return $this->post("supplierorders/{$id}/makeorder", ['date' => time(), 'method' => 0]);
    }

    public function getSupplierOrder(int $id)
    {
        return $this->get("supplierorders/{$id}");
    }

    /**
     * Удалить заказ поставщику целиком. Dolibarr не даёт редактировать/удалять ОТДЕЛЬНЫЕ строки уже
     * созданного заказа через REST API (нет PUT/DELETE на {id}/lines/{lineId} в этой версии) —
     * единственный способ "переделать" заказ, который уже не устраивает, это удалить его целиком
     * (работает только для черновика — CommandeFournisseur::delete() сам откажет для остальных статусов).
     */
    public function deleteSupplierOrder(int $id): bool
    {
        return $this->delete("supplierorders/{$id}") !== null;
    }

    /** Изменить общие поля заказа (например заметку) — доступно в любом статусе, в отличие от строк. */
    public function updateSupplierOrderNote(int $id, string $notePublic)
    {
        return $this->put("supplierorders/{$id}", ['note_public' => $notePublic]);
    }

    // --- Документы к заказу поставщику (инвойс поставщика, ГТД, сертификаты, CMR и т.п.) ---
    // Настоящее хранилище Dolibarr (не своя таблица) — прикреплённые файлы видны и в самом Dolibarr,
    // не только в NodirTool, если открыть тот же заказ напрямую. Требует трёх правок ядра Dolibarr
    // (см. CLAUDE.md 03.09.2026, "NodirTool — документы к заказу") — без них либо загрузка отбивается
    // "Access not allowed", либо список всегда пуст, даже когда файлы реально на месте.

    /** Загрузить файл к заказу — $ref обязателен и должен быть РЕАЛЬНЫМ ref заказа (не id). */
    public function uploadOrderDocument(string $orderRef, string $filename, string $base64Content): ?string
    {
        return $this->post('documents/upload', [
            'filename' => $filename,
            'modulepart' => 'supplier_order',
            'ref' => $orderRef,
            'filecontent' => $base64Content,
            'fileencoding' => 'base64',
            'overwriteifexists' => 1,
        ]);
    }

    /** Список файлов, уже прикреплённых к заказу — [{filename, size, date, content-type, ...}]. */
    public function getOrderDocuments(string $orderRef): array
    {
        $q = http_build_query(['modulepart' => 'supplier_order', 'ref' => $orderRef]);
        $r = $this->get('documents?' . $q);
        return is_array($r) ? $r : [];
    }

    /** Скачать файл целиком (base64) — для отдачи браузеру через свой download-эндпоинт. */
    public function downloadOrderDocument(string $orderRef, string $filename): ?array
    {
        $q = http_build_query(['modulepart' => 'supplier_order', 'original_file' => $orderRef . '/' . $filename]);
        return $this->get('documents/download?' . $q);
    }

    public function deleteOrderDocument(string $orderRef, string $filename): bool
    {
        $q = http_build_query(['modulepart' => 'supplier_order', 'original_file' => $orderRef . '/' . $filename]);
        return $this->delete('documents?' . $q) !== null;
    }

    // --- Документы к перевозчику (договор и т.п.) — modulepart='societe' работает "из коробки" в
    // этой версии Dolibarr, без core-патчей (проверено эмпирически 02.09.2026, в отличие от
    // supplier_order выше). Путь на диске — по ЧИСЛОВОМУ id контрагента (не по названию — названия
    // компаний не уникальны), поэтому $ref везде ниже это (string)$carrierId, не имя.

    // 04.09.2026: то же хранилище понадобилось и поставщикам (контракты и допсоглашения, пункт B3
    // отчёта «Пробелы NodirTool») — поставщик в Dolibarr это тот же `societe`, что и перевозчик,
    // поэтому методы ниже сделаны общими для любого контрагента, а Carrier-имена оставлены тонкими
    // обёртками, чтобы не трогать уже работающий и проверенный carriers.php.

    public function uploadPartyDocument(int $partyId, string $filename, string $base64Content): ?string
    {
        return $this->post('documents/upload', [
            'filename' => $filename,
            'modulepart' => 'societe',
            'ref' => (string)$partyId,
            'filecontent' => $base64Content,
            'fileencoding' => 'base64',
            'overwriteifexists' => 1,
        ]);
    }

    public function getPartyDocuments(int $partyId): array
    {
        $q = http_build_query(['modulepart' => 'societe', 'id' => $partyId]);
        $r = $this->get('documents?' . $q);
        return is_array($r) ? $r : [];
    }

    public function downloadPartyDocument(int $partyId, string $filename): ?array
    {
        $q = http_build_query(['modulepart' => 'societe', 'original_file' => $partyId . '/' . $filename]);
        return $this->get('documents/download?' . $q);
    }

    public function deletePartyDocument(int $partyId, string $filename): bool
    {
        $q = http_build_query(['modulepart' => 'societe', 'original_file' => $partyId . '/' . $filename]);
        return $this->delete('documents?' . $q) !== null;
    }

    public function uploadCarrierDocument(int $carrierId, string $filename, string $base64Content): ?string
    {
        return $this->uploadPartyDocument($carrierId, $filename, $base64Content);
    }

    public function getCarrierDocuments(int $carrierId): array
    {
        return $this->getPartyDocuments($carrierId);
    }

    public function downloadCarrierDocument(int $carrierId, string $filename): ?array
    {
        return $this->downloadPartyDocument($carrierId, $filename);
    }

    public function deleteCarrierDocument(int $carrierId, string $filename): bool
    {
        return $this->deletePartyDocument($carrierId, $filename);
    }

    /**
     * Закупочные цены на товар от разных поставщиков (таблица product_fournisseur_price) —
     * сейчас в базе почти пусто (её ещё предстоит заполнить), метод готов на будущее: как только
     * появятся цены, они сразу подхватятся и покажутся при оформлении заказа без доп. правок.
     */
    public function getPurchasePrices(int $productId): array
    {
        $r = $this->get("products/{$productId}/purchase_prices");
        return is_array($r) ? $r : [];
    }

    /** Закупочная цена этого товара ИМЕННО от указанного поставщика (null, если ещё не заполнена). */
    public function getPurchasePriceForSupplier(int $productId, int $supplierId): ?float
    {
        $info = $this->getPurchasePriceInfoForSupplier($productId, $supplierId);
        return $info === null ? null : $info['price'];
    }

    /**
     * То же, но с валютой записи (04.09.2026, риск R1 из отчёта «Пробелы NodirTool»). В базе 1010
     * закупочных цен в EUR и 149 в RUB — у каждой своя валюта и свой курс на момент записи. Сама
     * цена (`fourn_unitprice`) ВСЕГДА в долларах (базовая валюта компании), валютная лежит рядом.
     * Возвращает ['price' => USD, 'currency' => 'EUR'|'', 'rate' => float, 'native' => цена в валюте].
     */
    public function getPurchasePriceInfoForSupplier(int $productId, int $supplierId): ?array
    {
        foreach ($this->getPurchasePrices($productId) as $p) {
            if ((int)($p['fourn_id'] ?? 0) === $supplierId) {
                $rate = (float)($p['fourn_multicurrency_tx'] ?? 1);
                return [
                    'price' => (float)($p['fourn_unitprice'] ?? $p['unitprice'] ?? 0),
                    'currency' => (string)($p['fourn_multicurrency_code'] ?? ''),
                    'rate' => $rate > 0 ? $rate : 1.0,
                    'native' => (float)($p['fourn_multicurrency_unitprice'] ?? 0),
                ];
            }
        }
        return null;
    }

    /**
     * Записать/обновить закупочную цену товара от поставщика — по просьбе пользователя: цена,
     * которую Нодир/Абдурашид вписали при оформлении заказа, тут же сохраняется как "цена от
     * поставщика" в самом Dolibarr (`product_fournisseur_price`). Так база, которая сейчас почти
     * пустая, естественным образом заполняется по мере реальных заказов — отдельно заполнять её не
     * придётся. `ref_fourn`/`qty` намеренно постоянные (id товара как строка, 1) — Dolibarr требует
     * непустой `ref_fourn` (проверено эмпирически: пустая строка → HTTP 400 "ref_fourn is required")
     * и обновляет (не дублирует) запись по паре (поставщик, ref_fourn, qty), см.
     * ProductFournisseur::update_buyprice() — держим ref_fourn стабильным на товар, чтобы повторный
     * вызов для того же товара+поставщика правильно ЗАМЕНЯЛ цену, а не плодил новые строки.
     *
     * ВТОРОЙ найденный эмпирически баг Dolibarr (та же природа, что и в оплате счетов поставщику,
     * см. CLAUDE.md 29.08.2026): при включённом модуле мультивалютности (у нас включён) метод
     * `ProductFournisseur::update_buyprice()` БЕЗУСЛОВНО пересчитывает `$buyprice =
     * $multicurrency_buyprice / $multicurrency_tx` — если не передать `multicurrency_buyprice`,
     * он по умолчанию 0, и реальная цена молча обнуляется, даже если `buyprice` был передан верно.
     * Обходим БЕЗ патча core-файла (в отличие от того бага) — передаём multicurrency-поля явно.
     *
     * ⚠️ 04.09.2026, риск R1 из отчёта «Пробелы NodirTool» — ИСПРАВЛЕНО. Раньше здесь безусловно
     * слалось `multicurrency_tx = 1` и НЕ слался `multicurrency_code`. А `update_buyprice()`
     * (fourn/class/fournisseur.product.class.php:546-548) пишет обе колонки БЕЗУСЛОВНО — то есть
     * каждый заказ европейского товара затирал у него валюту (`multicurrency_code` → пустая строка)
     * и курс (→ 1). Под этим 1010 строк в EUR и 149 в RUB — денег это не теряло, но портило
     * справочник закупочных цен. Теперь валюта и курс СУЩЕСТВУЮЩЕЙ записи сохраняются, а валютная
     * цена пересчитывается из долларовой по этому же курсу (`price` внутри Dolibarr всегда
     * получается как multicurrency_buyprice / multicurrency_tx — то есть базовая долларовая цена
     * остаётся ровно той, которую ввёл закупщик, семантика ввода не изменилась).
     *
     * 04.09.2026, пункт B2: если валюта и курс переданы явно (заказ оформляется в валюте поставщика),
     * цена записывается ИМЕННО в этой валюте — `$price` тогда в валюте, а не в долларах. Если не
     * переданы — поведение как выше: валюта и курс существующей записи сохраняются, цена в долларах.
     */
    public function savePurchasePrice(int $productId, int $supplierId, float $price, string $currency = '', float $rate = 0.0): bool
    {
        if ($currency !== '' && $currency !== 'USD' && $rate > 0) {
            // Цена пришла в валюте поставщика: она и есть multicurrency_buyprice, доллары Dolibarr
            // посчитает сам как buyprice = multicurrency_buyprice / multicurrency_tx.
            $nativePrice = $price;
        } else {
            $existing = $this->getPurchasePriceInfoForSupplier($productId, $supplierId);
            $currency = $existing['currency'] ?? '';
            $rate = $existing['rate'] ?? 1.0;
            if ($rate <= 0) $rate = 1.0;
            $nativePrice = round($price * $rate, 8);
        }

        return $this->post("products/{$productId}/purchase_prices", [
            'qty' => 1,
            'buyprice' => $price,
            'price_base_type' => 'HT',
            'fourn_id' => $supplierId,
            'availability' => 0,
            'ref_fourn' => 'P' . $productId,
            'tva_tx' => 0,
            'multicurrency_buyprice' => $nativePrice,
            'multicurrency_tx' => $rate,
            'multicurrency_code' => $currency,
        ]) !== null;
    }

    public function getSupplierOrdersByStatus(string $status, string $properties = 'id,ref,socid,statut,date_commande,delivery_date')
    {
        $q = http_build_query(['status' => $status, 'properties' => $properties, 'limit' => 300]);
        return $this->get('supplierorders?' . $q) ?? [];
    }

    /** Дописать доп.поля заказа, не трогая остальные (номер/дата спецификации — пункт B1). */
    public function updateSupplierOrderExtrafields(int $id, array $options)
    {
        $keyed = [];
        foreach ($options as $k => $v) { $keyed["options_{$k}"] = $v; }
        return $this->put("supplierorders/{$id}", ['array_options' => $keyed]);
    }

    /** Доп.поля заказа (перевозчик/трек-номер) + нативная ожидаемая дата доставки. */
    public function updateSupplierOrderDetails(int $id, ?string $carrierName, ?string $trackingNumber, ?int $deliveryDateTs)
    {
        $payload = [];
        $options = [];
        if ($carrierName !== null) $options['options_carrier_name'] = $carrierName;
        if ($trackingNumber !== null) $options['options_tracking_number'] = $trackingNumber;
        if ($options) $payload['array_options'] = $options;
        if ($deliveryDateTs !== null) $payload['delivery_date'] = $deliveryDateTs;
        if (!$payload) return true;
        return $this->put("supplierorders/{$id}", $payload) !== null;
    }

    // --- Счета поставщику ---

    /** $type: 0=обычный счёт (по умолчанию), 2=кредит-нота (используется и для предоплаты — см. ниже). */
    /**
     * Создать счёт поставщику. Валюта (04.09.2026): счёт должен быть в той же валюте, что и заказ —
     * иначе европейский заказ превращался в долларовый счёт, закупщик видел доллары там, где у
     * поставщика евро, и оплата уходила не тем числом.
     */
    public function createSupplierInvoice(int $socId, string $refSupplier = '', int $type = 0, string $currency = 'USD', float $rate = 1.0)
    {
        $data = ['socid' => $socId, 'type' => $type];
        if ($refSupplier !== '') $data['ref_supplier'] = $refSupplier;
        if ($currency !== '' && $currency !== 'USD' && $rate > 0) {
            $data['multicurrency_code'] = $currency;
            $data['multicurrency_tx'] = $rate;
        }
        return $this->post('supplierinvoices', $data);
    }

    /** Строка счёта БЕЗ товара (услуга/обобщённая позиция) — для предоплаты, где нет конкретного товара. */
    public function addGenericSupplierInvoiceLine(int $invoiceId, string $label, float $priceHt): ?int
    {
        return $this->post("supplierinvoices/{$invoiceId}/lines", [
            'description' => $label,
            'pu_ht' => $priceHt,
            'tva_tx' => 0,
            'localtax1_tx' => 0,
            'localtax2_tx' => 0,
            'qty' => 1,
            'fk_product' => 0,
            'remise_percent' => 0,
            'price_base_type' => 'HT',
            'product_type' => 1, // услуга — без товара
        ]);
    }

    /** Вернуть кредит-ноту/предоплату в черновик — снимает её из общего остатка `opened`, если нужно отменить (проверено эмпирически). */
    public function setSupplierInvoiceToDraft(int $id): bool
    {
        return $this->post("supplierinvoices/{$id}/settodraft", []) !== null;
    }

    /**
     * ВАЖНО: у строк счёта поставщику Dolibarr использует ДРУГИЕ имена полей, чем у строк заказа
     * поставщику/счёта клиенту — `description` (не `desc`) и `pu_ht` (не `subprice`) читаются
     * напрямую из объекта в api_supplier_invoices.class.php::postLine(). Отправка `subprice` тут
     * тихо игнорируется, и цена уходит в 0 — проверено эмпирически.
     */
    /**
     * $priceHt — цена В ВАЛЮТЕ СЧЁТА. Для валютного счёта базовая цена передаётся НУЛ�ём: Dolibarr
     * выводит её сам как «цена в валюте / курс счёта» (`calcul_price_total()` — «pu calculation from
     * pu_devise if pu empty»). Если передать одно и то же число в оба поля, для EUR-счёта вышло бы
     * «45 евро = 45 долларов» — та же грабля, что уже ловилась на строках заказа 04.09.2026.
     */
    public function addSupplierInvoiceLine(int $invoiceId, int $productId, string $label, float $qty, float $priceHt, float $vatRate = 0, string $currency = 'USD')
    {
        $isForeign = $currency !== '' && $currency !== 'USD';
        $line = [
            'description' => $label,
            'pu_ht' => $isForeign ? 0 : $priceHt,
            'tva_tx' => $vatRate,
            'localtax1_tx' => 0,
            'localtax2_tx' => 0,
            'qty' => $qty,
            'fk_product' => $productId,
            'remise_percent' => 0,
            'date_start' => '',
            'date_end' => '',
            'fk_code_ventilation' => 0,
            'info_bits' => 0,
            'price_base_type' => 'HT',
            'product_type' => 0,
            'rang' => -1,
            'array_options' => [],
            'fk_unit' => null,
            'origin_id' => 0,
            'multicurrency_subprice' => $isForeign ? $priceHt : 0,
            'ref_supplier' => '',
            'special_code' => 0,
        ];
        return $this->post("supplierinvoices/{$invoiceId}/lines", $line);
    }

    public function validateSupplierInvoice(int $id)
    {
        return $this->post("supplierinvoices/{$id}/validate", []);
    }

    public function getSupplierInvoice(int $id)
    {
        return $this->get("supplierinvoices/{$id}");
    }

    public function getSupplierInvoicesForSupplier(int $socId, string $status = '')
    {
        $params = ['thirdparty_ids' => $socId, 'limit' => 200];
        if ($status !== '') $params['status'] = $status;
        return $this->get('supplierinvoices?' . http_build_query($params)) ?? [];
    }

    /**
     * Набор (set) непустых `ref_supplier` ВСЕХ счетов поставщику в базе — одним запросом (не по
     * поставщику в цикле). Используется, чтобы отфильтровать из списков "заказ получен, но счёт ещё
     * не создан" (payments.php, index.php) заказы, по которым счёт уже реально есть — та же проверка
     * по номеру заказа, что и в create_invoice_from_order при самом создании (см. BUG-N1, 02.09.2026:
     * дашборд/список "без счёта" не учитывали уже выставленные счета).
     */
    public function getInvoicedSupplierOrderRefs(): array
    {
        $rows = $this->get('supplierinvoices?' . http_build_query(['limit' => 1000, 'properties' => 'ref_supplier']));
        $set = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $ref = trim($row['ref_supplier'] ?? '');
                if ($ref !== '') $set[$ref] = true;
            }
        }
        return $set;
    }

    /**
     * Общий остаток по поставщику (топ-5 пункт 4, 02.09.2026) — штатный агрегат Dolibarr
     * (`Societe::getOutstandingBills('supplier')`), а не ручной перебор счетов: сам корректно
     * учитывает кредит-ноты/предоплаты (проверено эмпирически — валидированная кредит-нота на 100$
     * сразу и верно уменьшает `opened` на 100). `opened` > 0 — мы должны поставщику; < 0 — у нас
     * переплата/предоплата.
     */
    public function getSupplierOutstanding(int $socId): array
    {
        $r = $this->get("thirdparties/{$socId}/outstandinginvoices?" . http_build_query(['mode' => 'supplier']));
        return is_array($r) ? $r : ['opened' => 0, 'refs' => [], 'refsopened' => []];
    }

    /** Все неоплаченные счета поставщику (для сводки на главной) — без привязки к конкретному поставщику. */
    public function getUnpaidSupplierInvoices()
    {
        $q = http_build_query(['status' => 'unpaid', 'limit' => 300, 'properties' => 'id,ref,socid,total_ttc']);
        return $this->get('supplierinvoices?' . $q) ?? [];
    }

    /** Все поставщики (для сводки контрактов на главной) — с extrafields (array_options уже включён). */
    public function getAllSuppliers()
    {
        return $this->searchSuppliers('', 300);
    }

    public function getSupplierInvoicePayments(int $invoiceId)
    {
        $r = $this->get("supplierinvoices/{$invoiceId}/payments");
        return is_array($r) ? $r : [];
    }

    /**
     * Частичная (или полная, если $amount не указать) оплата счёта поставщику. В отличие от
     * клиентских счетов — здесь нет paymentsdistributed, только один вызов на один счёт с явной суммой.
     */
    public function addSupplierInvoicePayment(int $invoiceId, int $paytype, int $accountId, float $amount, string $comment = '')
    {
        return $this->post("supplierinvoices/{$invoiceId}/payments", [
            'datepaye' => time(),
            'payment_mode_id' => $paytype,
            'closepaidinvoices' => 'yes',
            'accountid' => $accountId,
            'num_payment' => '',
            'comment' => $comment,
            'amount' => number_format($amount, 2, '.', ''),
        ]);
    }

    // --- Заказы поставщику: сводка по контракту ---

    /** Все заказы поставщику (любой статус ≥ approved), нужны для подсчёта "закуплено по контракту". */
    public function getSupplierOrdersForSupplier(int $socId)
    {
        // multicurrency_code добавлен, чтобы отчёт по контракту мог показать валюту каждого заказа —
        // часть карточек поставщиков в EUR, и суммирование total_ttc "как есть" по заказам разных
        // валют без конвертации может вводить в заблуждение (см. отчёт аудита, "валюта в контрактах").
        $q = http_build_query(['thirdparty_ids' => $socId, 'limit' => 500, 'properties' => 'id,ref,statut,total_ttc,date_commande,multicurrency_code']);
        return $this->get('supplierorders?' . $q) ?? [];
    }

    // --- Банк / конвертация ---

    public function getAccountBalance(int $accountId)
    {
        $r = $this->get("bankaccounts/{$accountId}/balance");
        return $r === null ? null : (float)$r;
    }

    public function addBankLine(int $accountId, string $label, float $amount, string $type = 'VIR')
    {
        return $this->post("bankaccounts/{$accountId}/lines", [
            'date' => time(),
            'type' => $type,
            'label' => $label,
            'amount' => $amount,
            'category' => 0,
        ]);
    }

    /** Все проводки по счёту (возрастание по rowid) — для истории личной кассы закупщика. */
    public function getBankLines(int $accountId): array
    {
        $r = $this->get("bankaccounts/{$accountId}/lines");
        return is_array($r) ? $r : [];
    }
}
