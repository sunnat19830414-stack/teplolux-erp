<?php
/**
 * Тонкий клиент к REST API Dolibarr. Все запросы идут через этот класс —
 * единая точка, где добавляется API-ключ текущего направления.
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
     * Ограничить поисковый термин (вводит кассир в поле поиска) безопасным набором символов, ПЕРЕД
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

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

    public function get(string $path)
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data)
    {
        return $this->request('POST', $path, $data);
    }

    public function put(string $path, array $data)
    {
        return $this->request('PUT', $path, $data);
    }

    // --- Удобные обёртки под конкретные экраны ---

    /**
     * Поиск товаров по названию/артикулу/ref, ограничено направлением и (опционально) категорией/брендом.
     * ВАЖНО: направление определяется по полю ef.kod_sap (J.../T... — исходный код SAP), НЕ по t.ref —
     * у большинства товаров ref давно заменён на короткий артикул поставщика (см. CLAUDE.md, 27.08.2026).
     */
    public function searchProducts(string $term, string $refPrefix, int $categoryId = 0, int $limit = 50)
    {
        $prefixEsc = addslashes($refPrefix);
        $filters = "(ef.kod_sap:like:'{$prefixEsc}%')";
        if ($term !== '') {
            $termEsc = addslashes(self::safeSearchTerm($term));
            $filters .= " and ((t.ref:like:'%{$termEsc}%') or (t.label:like:'%{$termEsc}%'))";
        }
        $params = [
            'sqlfilters' => $filters,
            'limit' => $limit,
            'includestockdata' => 1,
        ];
        if ($categoryId > 0) {
            $params['category'] = $categoryId;
        }
        $q = http_build_query($params);
        return $this->get('products?' . $q) ?? [];
    }

    public function getProduct(int $id, bool $withStock = true)
    {
        $q = $withStock ? '?includestockdata=1' : '';
        return $this->get("products/{$id}{$q}");
    }

    /** Поиск клиентов по имени/ref, ограничено префиксом направления. Пустой $term — весь список (по алфавиту). */
    public function searchThirdparties(string $term, string $refPrefix, int $limit = 50)
    {
        $prefixEsc = addslashes($refPrefix);
        $filters = "(t.code_client:like:'{$prefixEsc}%')";
        if ($term !== '') {
            $termEsc = addslashes(self::safeSearchTerm($term));
            $filters .= " and (t.nom:like:'%{$termEsc}%')";
        }
        $q = http_build_query([
            'sqlfilters' => $filters,
            'limit' => $limit,
            'sortfield' => 't.nom',
            'sortorder' => 'ASC',
        ]);
        return $this->get('thirdparties?' . $q) ?? [];
    }

    public function createInvoice(array $data)
    {
        return $this->post('invoices', $data);
    }

    /**
     * Добавить строку в черновик счёта. API требует много полей у объекта строки —
     * тут проставлены разумные значения по умолчанию, вызывающий код передаёт только суть.
     */
    public function addInvoiceLine(int $invoiceId, int $productId, string $label, float $qty, float $priceHt, float $vatRate)
    {
        $line = [
            'desc' => $label,
            'label' => $label,
            'subprice' => $priceHt,
            'qty' => $qty,
            'tva_tx' => $vatRate,
            'localtax1_tx' => 0,
            'localtax2_tx' => 0,
            'fk_product' => $productId,
            'remise_percent' => 0,
            'date_start' => '',
            'date_end' => '',
            'fk_code_ventilation' => 0,
            'info_bits' => 0,
            'fk_remise_except' => '',
            'price_base_type' => 'HT',
            'product_type' => 0,
            'rang' => -1,
            'special_code' => 0,
            'origin' => '',
            'origin_id' => '',
            'fk_parent_line' => '',
            'fk_fournprice' => null,
            'array_options' => [],
            'situation_percent' => 100,
            'fk_prev_id' => '',
            'fk_unit' => null,
            'ref_ext' => '',
        ];
        return $this->post("invoices/{$invoiceId}/lines", $line);
    }

    public function validateInvoice(int $id)
    {
        return $this->post("invoices/{$id}/validate", []);
    }

    /**
     * Закрыть документ (счёт ИЛИ кредит-ноту) как "оплачен" БЕЗ создания платежа — штатный Dolibarr
     * механизм (Facture::setPaid(), только ставит paye=1/fk_statut=CLOSED, никакой проводки в банк не
     * создаёт). Используется для закрытия кредит-ноты при физической выдаче денег клиенту (payout.php)
     * — реальное движение денег делается ОТДЕЛЬНО, прямой проводкой (см. postPayoutMoney()), а этот
     * вызов только гасит "переплату" в общем балансе клиента. Проверено эмпирически 02.09.2026: после
     * вызова агрегированный getOutstandingInvoices()['opened'] корректно перестаёт учитывать эту
     * кредит-ноту (хотя её собственное поле remaintopay в API остаётся как было — это отдельная
     * особенность Dolibarr, agregat считается по paye/status, а не по remaintopay документа).
     */
    public function setInvoicePaid(int $id): bool
    {
        return $this->post("invoices/{$id}/settopaid", []) !== null;
    }

    public function addPayment(int $invoiceId, int $paytype, int $accountId, string $comment = '')
    {
        return $this->post("invoices/{$invoiceId}/payments", [
            'datepaye' => time(),
            'paymentid' => $paytype,
            'closepaidinvoices' => 'yes',
            'accountid' => $accountId,
            'num_payment' => '',
            'comment' => $comment,
        ]);
    }

    public function createStockMovement(array $data)
    {
        return $this->post('stockmovements', $data);
    }

    public function getWarehouse(int $id)
    {
        return $this->get("warehouses/{$id}");
    }

    /**
     * Заказы поставщику в конкретном статусе Dolibarr (для экрана "Приём товара" — раздел должен
     * показывать ТОЛЬКО заказы, которые Нодир/Абдурашид уже оформили И утвердили, не любой товар
     * произвольно). Значения статуса: draft|validated|approved|running|received_start|received_end|
     * cancelled|refused. "Ждут приёма" = approved (утверждён) + running (отправлен поставщику) +
     * received_start (частично получен) — набор статусов, которые ещё не закрыты и не черновики.
     */
    public function getSupplierOrdersByStatus(string $status, string $properties = 'id,ref,socid,statut')
    {
        $q = http_build_query(['status' => $status, 'properties' => $properties, 'limit' => 200]);
        return $this->get('supplierorders?' . $q) ?? [];
    }

    /** Полная карточка заказа поставщику вместе со строками (товар/количество/цена). */
    public function getSupplierOrder(int $id)
    {
        return $this->get("supplierorders/{$id}");
    }

    /**
     * Принять товар по заказу поставщику — одна или несколько строк сразу. $lines = [
     *   ['line_id'=>.., 'fk_product'=>.., 'qty'=>.., 'warehouse'=>.., 'price'=>..], ...
     * ]. $closeOrder=true — если это довозит заказ до полного количества, Dolibarr закроет его
     * (иначе корректно проставит "получен частично" сам, независимо от этого флага).
     *
     * ВАЖНО (найдено и исправлено 29.08.2026, см. CLAUDE.md "себестоимость товара"): раньше здесь
     * всегда передавалась `price => 0` — а Dolibarr (`MouvementStock::_create()`) при цене 0 НАРОЧНО
     * не трогает `pmp` (защита от порчи себестоимости нулём) — то есть реальная закупочная цена
     * вообще никогда не попадала в себестоимость товара при обычной приёмке. Теперь `price` берётся
     * из вызывающего кода (реальная цена строки заказа) — `'price' => $l['price'] ?? 0` оставлен как
     * запасной вариант только для обратной совместимости, вызывающий код должен передавать реальную
     * цену явно.
     */
    public function receiveSupplierOrder(int $orderId, array $lines, bool $closeOrder, string $comment = '')
    {
        $payload = [
            'closeopenorder' => $closeOrder ? 1 : 0,
            'comment' => $comment,
            'lines' => array_map(function ($l) {
                return [
                    'id' => $l['line_id'],
                    'fk_product' => $l['fk_product'],
                    'qty' => $l['qty'],
                    'warehouse' => $l['warehouse'],
                    'price' => (float)($l['price'] ?? 0),
                    'comment' => '',
                    'eatby' => '',
                    'sellby' => '',
                    'batch' => '',
                    'notrigger' => 0,
                ];
            }, $lines),
        ];
        return $this->post("supplierorders/{$orderId}/receive", $payload);
    }

    /** Текущий остаток кассового/банковского счёта (например, наличные направления). */
    public function getAccountBalance(int $accountId)
    {
        $r = $this->get("bankaccounts/{$accountId}/balance");
        return $r === null ? null : (float)$r;
    }

    /**
     * Добавить проводку по кассовому/банковскому счёту напрямую (не через оплату счёта) —
     * используется для "передачи кассы": списание $amount (отрицательное) со счёта наличных,
     * когда кассир физически отдаёт деньги старшему (Нодир/Sunnatilla).
     */
    public function addBankLine(int $accountId, string $label, float $amount, string $type = 'LIQ')
    {
        return $this->post("bankaccounts/{$accountId}/lines", [
            'date' => time(),
            'type' => $type,
            'label' => $label,
            'amount' => $amount,
            'category' => 0,
        ]);
    }

    /**
     * Все неоплаченные счета/возвраты направления (без привязки к конкретному клиенту) —
     * для дашборда "список должников". Возвращает список [id, ref, socid, remaintopay, date].
     */
    public function getUnpaidInvoicesForDirection(string $refPrefix, int $limit = 500)
    {
        $prefixEsc = addslashes($refPrefix);
        $q = http_build_query([
            'status' => 'unpaid',
            'sqlfilters' => "(s.code_client:like:'{$prefixEsc}%')",
            'limit' => $limit,
            'properties' => 'id,ref,socid,remaintopay,date',
        ]);
        return $this->get('invoices?' . $q) ?? [];
    }

    /**
     * Все счета/возвраты направления (ЛЮБОЙ статус оплаты), датированные ровно одним днём — для
     * сменного отчёта кассира (shift.php). Одним запросом, без обхода по клиентам (в отличие от
     * getUnpaidInvoicesForDirection, которая по конструкции не ограничена датой) — `t.datef` у счёта
     * хранится как чистая дата без времени, поэтому точное совпадение достаточно, диапазон не нужен.
     */
    public function getInvoicesForDirectionByDate(string $refPrefix, string $dateYmd, int $limit = 500)
    {
        $prefixEsc = addslashes($refPrefix);
        $dateEsc = addslashes($dateYmd);
        $q = http_build_query([
            'sqlfilters' => "(s.code_client:like:'{$prefixEsc}%') and (t.datef:=:'{$dateEsc}')",
            'limit' => $limit,
            'properties' => 'id,ref,socid,type,total_ttc,remaintopay,date',
        ]);
        return $this->get('invoices?' . $q) ?? [];
    }

    /**
     * Неоплаченные счета ОДНОГО клиента, СРАЗУ с ref/датой/суммой — одним запросом. Раньше это место
     * (debt.php — карточка клиента и FIFO-оплата) сначала звало getOutstandingInvoices() (даёт только
     * id+ref), а потом ещё getInvoice() на КАЖДЫЙ неоплаченный счёт отдельно, чтобы узнать дату/сумму
     * (см. отчёт ревью P0#5) — все нужные поля есть прямо в списке, если их запросить.
     */
    public function getUnpaidInvoicesForClient(int $socid, int $limit = 200)
    {
        $q = http_build_query([
            'thirdparty_ids' => $socid,
            'status' => 'unpaid',
            'limit' => $limit,
            'properties' => 'id,ref,date,total_ttc,remaintopay',
        ]);
        return $this->get('invoices?' . $q) ?? [];
    }

    /**
     * Несколько контрагентов по списку id ОДНИМ запросом (оператор :in: у Dolibarr sqlfilters,
     * проверено эмпирически — синтаксис "(t.rowid:in:1,2,3)", БЕЗ кавычек/внутренних скобок вокруг
     * списка, с ними Dolibarr отвечает "Bad syntax of the search string"). Возвращает [id => контрагент].
     * Раньше дашборды (debt.php и т.п.) звали getThirdparty() в цикле по одному на каждого — при 80
     * должниках это 80 лишних запросов на одну загрузку страницы (см. отчёт ревью P0#5).
     */
    public function getThirdpartiesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) return [];
        $q = http_build_query([
            'sqlfilters' => '(t.rowid:in:' . implode(',', $ids) . ')',
            'limit' => count($ids),
            'properties' => 'id,name,code_client',
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

    /**
     * Строки банковского счёта за один день (см. addBankLine) — для сменного отчёта: реально
     * поступившие/выданные деньги за день (в отличие от суммы счетов, которая включает то, что ушло
     * "в долг" и ещё не оплачено). `t.dateo` — та же дата, что мы сами пишем в addBankLine().
     */
    public function getBankLinesForDate(int $accountId, string $dateYmd)
    {
        // ВАЖНО: у этого эндпоинта (Dolibarr api_bankaccounts.class.php::getLines()) запрос идёт БЕЗ
        // алиаса таблицы ("FROM llx_bank", не "... AS t") — в отличие от большинства других
        // sqlfilters-эндпоинтов проекта. Поле — просто "dateo", не "t.dateo" (проверено эмпирически:
        // с "t.dateo" Dolibarr возвращает "Unknown column 't.dateo' in 'WHERE'").
        $dateEsc = addslashes($dateYmd);
        $q = http_build_query([
            'sqlfilters' => "(dateo:=:'{$dateEsc}')",
            'limit' => 500,
        ]);
        return $this->get("bankaccounts/{$accountId}/lines?" . $q) ?? [];
    }

    public function getThirdparty(int $id)
    {
        return $this->get("thirdparties/{$id}");
    }

    /** Создать нового клиента (карточка контрагента с флагом client=1). */
    public function createClient(array $fields)
    {
        return $this->post('thirdparties', $fields + ['client' => 1]);
    }

    /** Изменить данные существующего клиента (частичное обновление — только переданные поля). */
    public function updateClient(int $id, array $fields)
    {
        return $this->put("thirdparties/{$id}", $fields);
    }

    /**
     * Следующий свободный код клиента для направления (J000460, T000124, ...). Модуль нумерации
     * кодов в этой инсталляции — mod_codeclient_leopard (свободный ввод, см. CLAUDE.md 27.08.2026),
     * он НЕ генерирует код сам — считаем сами по уже существующим кодам этого направления.
     */
    public function getNextClientCode(string $refPrefix): string
    {
        $prefixEsc = addslashes($refPrefix);
        $q = http_build_query([
            'sqlfilters' => "(t.code_client:like:'{$prefixEsc}%')",
            'limit' => 30,
            'sortfield' => 't.code_client',
            'sortorder' => 'DESC',
            'properties' => 'id,code_client',
        ]);
        $rows = $this->get('thirdparties?' . $q);
        $max = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $code = $row['code_client'] ?? '';
                // Интересуют только "чистые" коды вида J000459 (префикс + ровно 6 цифр) — тестовые/
                // ручные вроде J-TEST01 пропускаем, они не должны влиять на следующий номер.
                if (preg_match('/^' . preg_quote($refPrefix, '/') . '(\d{6})$/', $code, $m)) {
                    $max = max($max, (int)$m[1]);
                }
            }
        }
        return sprintf('%s%06d', $refPrefix, $max + 1);
    }

    /**
     * Разнести один платёж по НЕСКОЛЬКИМ счетам сразу (для приёма оплаты "просто дайте денег",
     * закрытие FIFO — самые старые счета первыми). $arrayofamounts = [invoiceId => сумма, ...] —
     * суммы уже посчитаны вызывающим кодом (сколько списать с какого счёта).
     */
    public function addPaymentDistributed(array $arrayofamounts, int $paytype, int $accountId, string $comment = '')
    {
        $payload = [
            'arrayofamounts' => [],
            'datepaye' => time(),
            'paymentid' => $paytype,
            'closepaidinvoices' => 'yes',
            'accountid' => $accountId,
            'num_payment' => '',
            'comment' => $comment,
        ];
        foreach ($arrayofamounts as $invId => $amount) {
            $payload['arrayofamounts'][(string)$invId] = ['amount' => (string)$amount, 'multicurrency_amount' => ''];
        }
        return $this->post('invoices/paymentsdistributed', $payload);
    }

    /** Долг клиента: ['opened'=>сумма, 'refsopened'=>{id:ref неоплаченных счетов}] */
    public function getOutstandingInvoices(int $thirdpartyId)
    {
        return $this->get("thirdparties/{$thirdpartyId}/outstandinginvoices?mode=customer");
    }

    public function getInvoice(int $id)
    {
        return $this->get("invoices/{$id}");
    }

    /** Все счета и возвраты конкретного клиента (для отчёта "История клиента") — любой статус. */
    /**
     * ВАЖНО: 'lines' в properties — список счетов у Dolibarr и так возвращает полные строки каждого
     * счёта по умолчанию (withLines=true в api_invoices.class.php::index()), просто properties-фильтр
     * их вырезает, если явно не запросить. Раньше buildClientHistory() не пользовался этим и делал
     * ОТДЕЛЬНЫЙ getInvoice() на каждый счёт клиента (при 150-200 счетах — 150-200 лишних запросов на
     * одну загрузку отчёта, см. отчёт ревью P0#5) — теперь строки уже приезжают этим же вызовом.
     */
    public function getInvoicesForClient(int $socid, int $limit = 500)
    {
        $q = http_build_query([
            'thirdparty_ids' => $socid,
            'limit' => $limit,
            'properties' => 'id,ref,type,date,total_ttc,statut,lines',
        ]);
        return $this->get('invoices?' . $q) ?? [];
    }

    /** Список платежей по конкретному счёту: [{amount, type (код c_paiement), date, ref}, ...] */
    public function getInvoicePayments(int $invoiceId)
    {
        return $this->get("invoices/{$invoiceId}/payments") ?? [];
    }

    /**
     * K-1 (внешний QA-аудит, раунд 2, 03.09.2026) — все ПРОВЕДЁННЫЕ кредит-ноты ("Возврат по счёту"),
     * связанные с исходным счётом через штатное поле Dolibarr `fk_facture_source`. Нужно, чтобы
     * посчитать, сколько по каждому товару уже возвращали раньше, и не дать вернуть больше, чем
     * реально было продано (раньше проверялось только "не больше проданного", без учёта прошлых
     * возвратов — можно было вернуть один и тот же счёт сколько угодно раз).
     */
    public function getCreditNotesForSourceInvoice(int $sourceInvoiceId): array
    {
        $q = http_build_query([
            'sqlfilters' => "(t.fk_facture_source:=:{$sourceInvoiceId}) and (t.type:=:2)",
            'limit' => 200,
            'properties' => 'id,ref,statut,lines',
        ]);
        return $this->get('invoices?' . $q) ?? [];
    }

    /**
     * Создать возврат (кредит-нота), type=2 у Dolibarr = Facture::TYPE_CREDIT_NOTE.
     * $sourceInvoiceId — необязательный номер счёта-продажи, по которому оформляется возврат
     * ("Возврат по счёту", см. return.php) — записывается в штатное поле Dolibarr
     * `fk_facture_source` (стандартный механизм связи кредит-ноты с исходным счётом, есть у самого
     * Dolibarr, не наша самодеятельность) — так связь видна и в самом интерфейсе Dolibarr, не только
     * в нашем приложении.
     */
    public function createCreditNote(int $socId, ?int $sourceInvoiceId = null)
    {
        $data = ['socid' => $socId, 'type' => 2];
        if ($sourceInvoiceId) $data['fk_facture_source'] = $sourceInvoiceId;
        return $this->post('invoices', $data);
    }

    /**
     * Строка счёта БЕЗ привязки к товару — для авансов/предоплаты и подобного (не для продажи товара).
     * product_type=1 (услуга) вместо 0 (товар), fk_product не передаём вообще.
     */
    public function addGenericInvoiceLine(int $invoiceId, string $label, float $amount, float $vatRate = 0)
    {
        $line = [
            'desc' => $label,
            'label' => $label,
            'subprice' => $amount,
            'qty' => 1,
            'tva_tx' => $vatRate,
            'product_type' => 1,
            'price_base_type' => 'HT',
        ];
        return $this->post("invoices/{$invoiceId}/lines", $line);
    }

    /**
     * Складское перемещение между двумя складами направления: два движения одной операцией
     * (type=1 списание-перемещение с исходного, type=0 поступление-перемещение на целевой).
     * Возвращает true, если ОБА движения прошли; при частичном сбое возвращает false и лог в lastError.
     */
    public function transferStock(int $productId, int $fromWarehouseId, int $toWarehouseId, float $qty, string $label)
    {
        $out = $this->createStockMovement([
            'product_id' => $productId, 'warehouse_id' => $fromWarehouseId,
            'qty' => -1 * abs($qty), 'type' => 1, 'label' => $label,
        ]);
        if ($out === null) {
            return false;
        }
        $in = $this->createStockMovement([
            'product_id' => $productId, 'warehouse_id' => $toWarehouseId,
            'qty' => abs($qty), 'type' => 0, 'label' => $label,
        ]);
        return $in !== null;
    }
}
