<?php
/**
 * Тонкий клиент к REST API Dolibarr для инструмента руководства. Тот же паттерн, что в
 * TeplouxKassa/NodirTool, но набор методов другой: почти всё — ЧТЕНИЕ для отчётов, из записи только
 * банковские проводки (операции со своей кассой). Служебный пользователь `api_boss` и прав больше
 * не имеет — создавать документы отсюда нельзя даже при ошибке в коде.
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

    /** Ограничить пользовательский поисковый ввод безопасным набором символов ДО склейки в sqlfilters. */
    private static function safeSearchTerm(string $term): string
    {
        return preg_replace('/[^\p{L}\p{N}\s\-\._\/]/u', '', $term) ?? '';
    }

    private function request(string $method, string $path, $data = null)
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['DOLAPIKEY: ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 60,
        ]);
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

    // --- Товары ---

    /**
     * Поиск товара для заявки. $directions — префиксы направлений ('J'/'T'), различаются по доп.полю
     * `kod_sap` (именно по нему, а НЕ по `ref` — `ref` это код поставщика, см. CLAUDE.md 27.08.2026).
     * $supplierId — показать только товары, связанные с этим поставщиком (по просьбе пользователя:
     * шеф сначала выбирает поставщика и видит его товары).
     *
     * ⚠️ Делается ОДНИМ прямым SQL, а не через REST. Через REST так не выходит: Dolibarr не умеет
     * фильтровать товары по поставщику (закупочные цены отдаются только по одному товару за раз),
     * поэтому пришлось бы взять первые N товаров каталога и отсеять лишние уже у себя — а до товаров
     * конкретного поставщика очередь при этом просто не доходит. Именно на этом поиск по ZILIO
     * возвращал «ничего не найдено» при 63 реально связанных товарах (найдено пользователем
     * 04.09.2026). В SQL ограничение по поставщику применяется ДО LIMIT, и всё находится.
     *
     * Остаток берём из `llx_product.stock` — сверено с суммой по складам (`llx_product_stock.reel`),
     * поле достоверно.
     */
    public function searchProducts(string $term, array $directions, int $supplierId = 0, int $limit = 60): array
    {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');

        // tobuy=1 — товар помечен как закупаемый. Сейчас это все 2273 карточки, но если что-то
        // когда-нибудь снимут с закупки, оно перестанет предлагаться в заявке само собой.
        $where = ['p.tobuy = 1'];
        if ($supplierId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM llx_product_fournisseur_price f
                                WHERE f.fk_product = p.rowid AND f.fk_soc = ' . (int)$supplierId . ')';
        }
        if (count($directions) === 1) {
            $where[] = "e.kod_sap LIKE '" . $conn->real_escape_string($directions[0]) . "%'";
        }
        $term = self::safeSearchTerm($term);
        if ($term !== '') {
            $t = $conn->real_escape_string($term);
            $where[] = "(p.ref LIKE '%{$t}%' OR p.label LIKE '%{$t}%')";
        }

        $sql = "SELECT p.rowid AS id, p.ref, p.label, p.stock AS stock_reel
                FROM llx_product p
                LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.label
                LIMIT " . (int)$limit;

        $res = $conn->query($sql);
        $out = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $out[] = $row;
            }
        }
        $conn->close();
        return $out;
    }

    public function getProduct(int $id)
    {
        return $this->get("products/{$id}?includestockdata=1");
    }

    /**
     * Создать карточку товара (04.09.2026, по просьбе пользователя — «+ Новый товар» прямо в заявке):
     * поставщик прислал новинку, а заказать её нельзя, пока её нет в каталоге. Единственная запись
     * помимо банковских проводок, которую делает этот инструмент; под неё выдано ровно одно право
     * `produit->creer` (удалять и трогать документы api_boss по-прежнему не может).
     */
    public function createProduct(string $ref, string $label, ?int $defaultWarehouseId = null)
    {
        $payload = [
            'ref' => $ref,
            'label' => $label,
            'type' => 0,        // товар, не услуга
            'status' => 1,      // в продаже
            'status_buy' => 1,  // в закупке
        ];
        if ($defaultWarehouseId) $payload['fk_default_warehouse'] = $defaultWarehouseId;
        return $this->post('products', $payload);
    }

    /**
     * Доп.поля ставятся ОТДЕЛЬНЫМ запросом после создания: в этой версии Dolibarr `array_options`
     * на POST проходит мимо типизированной санации (та же грабля, что с заказом поставщику).
     */
    /**
     * Цена продажи товара. ⚠️ Это ровно та цена, по которой продаёт касса (TeplouxKassa берёт
     * `product.price`) — правка здесь сразу меняет цену для продавцов.
     */
    public function saveSalePrice(int $productId, float $price): bool
    {
        return $this->put("products/{$productId}", [
            'price' => $price,
            'price_base_type' => 'HT',
        ]) !== null;
    }

    public function updateProductExtrafields(int $id, array $options)
    {
        $keyed = [];
        foreach ($options as $k => $v) { $keyed["options_{$k}"] = $v; }
        return $this->put("products/{$id}", ['array_options' => $keyed]);
    }

    /**
     * Записать заводскую (закупочную) цену товара от поставщика — $price В ВАЛЮТЕ ДОГОВОРА.
     *
     * Две грабли Dolibarr, обе проверены эмпирически (см. CLAUDE.md 29.08 и 04.09.2026):
     *  - `ref_fourn` обязателен и должен быть непустым, хотя в докблоке помечен необязательным;
     *    держим его постоянным на товар, чтобы повторная запись ЗАМЕНЯЛА цену, а не плодила строки;
     *  - при включённой мультивалютности `update_buyprice()` считает базовую цену как
     *    multicurrency_buyprice / multicurrency_tx и БЕЗУСЛОВНО перезаписывает валюту с курсом —
     *    поэтому передаём их явно, иначе цена обнулится, а валюта записи сотрётся.
     */
    public function savePurchasePrice(int $productId, int $supplierId, float $price, string $currency, float $rate): bool
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'USD') $currency = '';
        if ($rate <= 0) $rate = 1.0;

        return $this->post("products/{$productId}/purchase_prices", [
            'qty' => 1,
            // buyprice в базовой валюте: Dolibarr всё равно выведет её из валютной по курсу.
            'buyprice' => $currency === '' ? $price : round($price / $rate, 8),
            'price_base_type' => 'HT',
            'fourn_id' => $supplierId,
            'availability' => 0,
            'ref_fourn' => 'P' . $productId,
            'tva_tx' => 0,
            'multicurrency_buyprice' => $price,
            'multicurrency_tx' => $currency === '' ? 1 : $rate,
            'multicurrency_code' => $currency,
        ]) !== null;
    }

    // --- Контрагенты ---

    public function searchSuppliers(string $term = '', int $limit = 100): array
    {
        $filters = "(t.fournisseur:=:1) and ((ef.is_carrier:is:null) or (ef.is_carrier:=:0))";
        if ($term !== '') {
            $t = addslashes(self::safeSearchTerm($term));
            $filters .= " and (t.nom:like:'%{$t}%')";
        }
        return $this->get('thirdparties?' . http_build_query([
            'limit' => $limit, 'sortfield' => 't.nom', 'sortorder' => 'ASC', 'sqlfilters' => $filters,
        ])) ?? [];
    }

    public function getThirdparty(int $id)
    {
        return $this->get("thirdparties/{$id}");
    }

    /** Несколько контрагентов одним запросом (оператор :in: — без кавычек и внутренних скобок). */
    public function getThirdpartiesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) return [];
        $rows = $this->get('thirdparties?' . http_build_query([
            'sqlfilters' => '(t.rowid:in:' . implode(',', $ids) . ')',
            'limit' => count($ids),
            'properties' => 'id,name,code_client,code_fournisseur',
        ]));
        $out = [];
        foreach ((array)$rows as $row) $out[(int)$row['id']] = $row;
        return $out;
    }

    // --- Деньги ---

    public function getAccountBalance(int $accountId): ?float
    {
        $r = $this->get("bankaccounts/{$accountId}/balance");
        if ($r === null) return null;
        return (float)(is_array($r) ? ($r['value'] ?? 0) : $r);
    }

    public function getBankLines(int $accountId): array
    {
        $r = $this->get("bankaccounts/{$accountId}/lines");
        return is_array($r) ? $r : [];
    }

    /**
     * Банковские проводки за период. ВАЖНО: `api_bankaccounts::getLines()` строит SQL БЕЗ алиаса
     * таблицы, поэтому в sqlfilters колонка пишется голой — `dateo`, а не `t.dateo` (иначе Dolibarr
     * отвечает «Unknown column 't.dateo'»; поймано в TeplouxKassa при сменном отчёте 02.09.2026).
     */
    public function getBankLinesBetween(int $accountId, string $from, string $to): array
    {
        $q = http_build_query([
            'sqlfilters' => "(dateo:>=:'" . addslashes($from) . "') and (dateo:<=:'" . addslashes($to) . "')",
        ]);
        $r = $this->get("bankaccounts/{$accountId}/lines?" . $q);
        return is_array($r) ? $r : [];
    }

    /** Проводка по счёту. Отрицательная сумма — списание. Единственная запись, которую делает этот инструмент. */
    public function addBankLine(int $accountId, string $label, float $amount, string $type = 'LIQ')
    {
        return $this->post("bankaccounts/{$accountId}/lines", [
            'date' => time(),
            'type' => $type,
            'label' => $label,
            'amount' => $amount,
        ]);
    }

    // --- Документы для отчётов ---

    /** Счета и возвраты клиентов за период (по направлениям — фильтр по префиксу кода клиента). */
    public function getClientInvoicesBetween(array $directions, string $from, string $to): array
    {
        $dirFilter = count($directions) === 1
            ? " and (s.code_client:like:'" . addslashes($directions[0]) . "%')"
            : '';
        $q = http_build_query([
            'sqlfilters' => "(t.datef:>=:'" . addslashes($from) . "') and (t.datef:<=:'" . addslashes($to) . "')" . $dirFilter,
            'limit' => 5000,
            'properties' => 'id,ref,socid,type,datef,total_ht,total_ttc,remaintopay,paye,lines',
        ]);
        $r = $this->get('invoices?' . $q);
        return is_array($r) ? $r : [];
    }

    /** Неоплаченные клиентские счета (долги клиентов) по направлениям. */
    public function getUnpaidClientInvoices(array $directions): array
    {
        $dirFilter = count($directions) === 1
            ? "(s.code_client:like:'" . addslashes($directions[0]) . "%')"
            : '';
        $params = ['status' => 'unpaid', 'limit' => 5000,
                   'properties' => 'id,ref,socid,type,datef,total_ttc,remaintopay'];
        if ($dirFilter !== '') $params['sqlfilters'] = $dirFilter;
        $r = $this->get('invoices?' . http_build_query($params));
        return is_array($r) ? $r : [];
    }

    /** Заказы поставщику по статусу — для отчёта «что в пути / что закупили». */
    public function getSupplierOrdersByStatus(string $status): array
    {
        $r = $this->get('supplierorders?' . http_build_query([
            'status' => $status, 'limit' => 500,
            'properties' => 'id,ref,socid,statut,date_commande,total_ht,total_ttc,multicurrency_code,multicurrency_total_ttc,delivery_date',
        ]));
        return is_array($r) ? $r : [];
    }

    /** Счета поставщиков — для отчёта «кому должны». */
    public function getSupplierInvoices(string $status = ''): array
    {
        $params = ['limit' => 2000, 'properties' => 'id,ref,ref_supplier,socid,statut,paye,total_ht,total_ttc,date'];
        if ($status !== '') $params['status'] = $status;
        $r = $this->get('supplierinvoices?' . http_build_query($params));
        return is_array($r) ? $r : [];
    }

    public function getSupplierOutstanding(int $socId): ?array
    {
        $r = $this->get("thirdparties/{$socId}/outstandinginvoices?mode=supplier");
        return is_array($r) ? $r : null;
    }
}
