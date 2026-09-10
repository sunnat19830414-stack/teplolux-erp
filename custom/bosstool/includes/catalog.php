<?php
/**
 * Каталог товаров: поиск с фильтрами, карточка, правка недостающих данных (10.09.2026).
 *
 * Один и тот же файл лежит во всех трёх инструментах — как `bank_transfer.php` и `named_lock.php`.
 * Разница между инструментами задаётся не кодом, а массивом возможностей `$caps`, который
 * передаёт вызывающая страница:
 *   'directions' => ['J'] | ['J','T']   какие направления показывать (касса видит только своё)
 *   'view'       => ['phys','customs','purchase','stock']   что показывать
 *   'edit'       => ['phys','customs','price']              что разрешено менять
 *
 * ПОЧЕМУ ПРАВКА ИДЁТ ДВУМЯ ПУТЯМИ, А НЕ ОДНИМ:
 *   - цена продажи — только через REST (`PUT /products/{id}`), потому что Dolibarr при этом
 *     заводит строку в `llx_product_price`, то есть историю цен. Прямой UPDATE историю потеряет,
 *     а на ней стоят отчёты руководства;
 *   - название и описание — только прямым SQL: REST молча срезает двойные кавычки, а у нас
 *     дюймы в названиях (`1/2"`) у сотен товаров;
 *   - остальное (вес, габариты, ТНВЭД, упаковка) — прямым SQL, это простые числовые поля.
 *
 * Каждая правка пишется в `llx_nt_product_log`: кто, когда, из какого инструмента, что было и что
 * стало. Без этого через месяц нельзя ответить на вопрос «кто поставил такую цену».
 */

/**
 * Подключение к БД Dolibarr. Путь `config/db.local.php` одинаков во всех трёх инструментах,
 * поэтому файл остаётся идентичным и копируется без правок.
 * Нужен именно прямой доступ: списку требуются остатки, заполненность полей и оборот одним
 * запросом — через REST это десятки вызовов на страницу.
 */
function catalog_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** Единицы Dolibarr (llx_c_units): вес кг = 0, размер мм = -3, объём дм³ (литр) = -3. */
const CAT_UNIT_WEIGHT = 0;
const CAT_UNIT_SIZE   = -3;
const CAT_UNIT_VOLUME = -3;

/**
 * Описание полей карточки. `group` определяет, кто их видит и правит.
 * `col` — колонка llx_product, `ef` — колонка llx_product_extrafields.
 */
function catalog_fields(): array
{
    return [
        'label'          => ['group'=>'base',    'col'=>'label',       'type'=>'text',  'title'=>'Наименование'],
        'description'    => ['group'=>'phys',    'col'=>'description', 'type'=>'area',  'title'=>'Описание'],
        'price'          => ['group'=>'price',   'col'=>'price',       'type'=>'money', 'title'=>'Цена продажи', 'unit'=>'USD'],
        // Дилерская цена: залита 10.09.2026 из базы «Бизнес» склада Турк (Bus.gdb, колонка PRICE1).
        // Ниже розничной примерно на 20% там, где уровни вообще различаются.
        'dealer_price'   => ['group'=>'dealer',  'ef'=>'dealer_price', 'type'=>'money', 'title'=>'Дилерская цена', 'unit'=>'USD'],
        'weight'         => ['group'=>'phys',    'col'=>'weight',      'type'=>'num',   'title'=>'Вес единицы',  'unit'=>'кг', 'step'=>'0.0001'],
        'weight_net'     => ['group'=>'phys',    'ef'=>'weight_net',   'type'=>'num',   'title'=>'Вес нетто',    'unit'=>'кг', 'step'=>'0.0001'],
        'weight_gross'   => ['group'=>'phys',    'ef'=>'weight_gross', 'type'=>'num',   'title'=>'Вес брутто',   'unit'=>'кг', 'step'=>'0.0001'],
        'length'         => ['group'=>'phys',    'col'=>'length',      'type'=>'num',   'title'=>'Длина',        'unit'=>'мм', 'step'=>'0.1'],
        'width'          => ['group'=>'phys',    'col'=>'width',       'type'=>'num',   'title'=>'Ширина',       'unit'=>'мм', 'step'=>'0.1'],
        'height'         => ['group'=>'phys',    'col'=>'height',      'type'=>'num',   'title'=>'Высота',       'unit'=>'мм', 'step'=>'0.1'],
        'volume'         => ['group'=>'phys',    'col'=>'volume',      'type'=>'num',   'title'=>'Объём',        'unit'=>'дм³', 'step'=>'0.001'],
        'pcs_per_box'    => ['group'=>'phys',    'ef'=>'pcs_per_box',    'type'=>'int', 'title'=>'Штук в коробке'],
        'pcs_per_master' => ['group'=>'phys',    'ef'=>'pcs_per_master', 'type'=>'int', 'title'=>'Штук в большой упаковке'],
        'ship_multiple'  => ['group'=>'phys',    'ef'=>'ship_multiple',  'type'=>'int', 'title'=>'Кратность отгрузки'],
        'customcode'     => ['group'=>'customs', 'col'=>'customcode',  'type'=>'text',  'title'=>'Код ТНВЭД'],
    ];
}

function catalog_can(array $caps, string $what, string $group): bool
{
    return in_array($group, $caps[$what] ?? [], true);
}

/**
 * Список товаров с фильтрами. Прямым SQL, а не через REST: нужны остатки, заполненность полей
 * и сортировка по обороту — через REST это десятки запросов на страницу.
 *
 * @param array $f  ref, name, category, direction, missing (какое поле пусто), instock, page
 */
function catalog_search(mysqli $db, array $caps, array $f): array
{
    $where = ['1=1']; $args = []; $types = '';

    // Направление — жёсткая изоляция, а не удобство: касса Жоми не должна видеть товары Турк.
    $dirs = $caps['directions'] ?? ['J', 'T'];
    $dirWhere = [];
    foreach ($dirs as $d) { $dirWhere[] = "e.kod_sap LIKE ?"; $args[] = $d . '%'; $types .= 's'; }
    $where[] = '(' . implode(' OR ', $dirWhere) . ')';

    if (trim((string)($f['q'] ?? '')) !== '') {
        $q = '%' . trim($f['q']) . '%';
        $where[] = '(p.ref LIKE ? OR p.label LIKE ? OR e.artikul LIKE ? OR e.kod_sap LIKE ?)';
        array_push($args, $q, $q, $q, $q); $types .= 'ssss';
    }
    if ((int)($f['category'] ?? 0) > 0) {
        // вместе с подкатегориями: «Арматура» должна показать все краны и клапаны
        $catIds = catalog_category_with_children($db, (int)$f['category']);
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $where[] = "EXISTS (SELECT 1 FROM llx_categorie_product cp
                            WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN ($ph))";
        foreach ($catIds as $cid) { $args[] = $cid; $types .= 'i'; }
    }
    // «Чего не хватает» — главный смысл экрана: показать дыры, а не весь каталог подряд.
    $missingMap = [
        'weight'      => 'COALESCE(p.weight,0) <= 0',
        'customcode'  => "COALESCE(p.customcode,'') = ''",
        'description' => "COALESCE(p.description,'') = ''",
        'dims'        => 'COALESCE(p.length,0) <= 0 AND COALESCE(p.width,0) <= 0 AND COALESCE(p.height,0) <= 0',
        'box'         => 'COALESCE(e.pcs_per_box,0) <= 0',
        'price'       => 'COALESCE(p.price,0) <= 0',
    ];
    if (!empty($f['missing']) && isset($missingMap[$f['missing']])) $where[] = $missingMap[$f['missing']];

    if (!empty($f['instock'])) {
        $where[] = 'COALESCE((SELECT SUM(ps.reel) FROM llx_product_stock ps WHERE ps.fk_product = p.rowid),0) > 0';
    }

    $order = [
        'sold'  => 'sold DESC, p.ref',
        'ref'   => 'p.ref',
        'label' => 'p.label',
        'stock' => 'stock DESC, p.ref',
    ][$f['sort'] ?? 'sold'] ?? 'sold DESC, p.ref';

    $page  = max(1, (int)($f['page'] ?? 1));
    $per   = 50;
    $off   = ($page - 1) * $per;

    $sql = "SELECT p.rowid, p.ref, p.label, p.price, p.weight, p.customcode, p.description,
                   p.length, p.width, p.height, p.volume,
                   e.artikul, e.kod_sap, e.weight_net, e.weight_gross, e.pcs_per_box, e.pcs_per_master,
                   COALESCE((SELECT SUM(ps.reel) FROM llx_product_stock ps WHERE ps.fk_product = p.rowid),0) stock,
                   COALESCE((SELECT SUM(h.qty_sold) FROM llx_nt_sales_history h WHERE h.fk_product = p.rowid),0) sold
            FROM llx_product p
            LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $order LIMIT $per OFFSET $off";
    $st = $db->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$args);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $cs = $db->prepare("SELECT COUNT(*) n FROM llx_product p
                        LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
                        WHERE " . implode(' AND ', $where));
    if ($types !== '') $cs->bind_param($types, ...$args);
    $cs->execute();
    $total = (int)$cs->get_result()->fetch_assoc()['n'];
    $cs->close();

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $per), 'per' => $per];
}

/** Одна карточка со всем, что нужно экрану. null — товара нет или он чужого направления. */
function catalog_load(mysqli $db, array $caps, int $id): ?array
{
    $dirs = $caps['directions'] ?? ['J', 'T'];
    $st = $db->prepare("SELECT p.*, e.artikul, e.kod_sap, e.weight_net, e.weight_gross,
                               e.pcs_per_box, e.pcs_per_master, e.ship_multiple
                        FROM llx_product p LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
                        WHERE p.rowid = ?");
    $st->bind_param('i', $id); $st->execute();
    $p = $st->get_result()->fetch_assoc(); $st->close();
    if (!$p) return null;

    $kod = (string)($p['kod_sap'] ?? '');
    $ok = false;
    foreach ($dirs as $d) if (str_starts_with($kod, $d)) $ok = true;
    if (!$ok) return null;   // чужое направление — как будто товара нет

    $st = $db->prepare("SELECT w.ref, w.lieu, ps.reel FROM llx_product_stock ps
                        JOIN llx_entrepot w ON w.rowid = ps.fk_entrepot
                        WHERE ps.fk_product = ? AND ps.reel <> 0 ORDER BY w.ref");
    $st->bind_param('i', $id); $st->execute();
    $p['stock_rows'] = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    if (catalog_can($caps, 'view', 'purchase')) {
        $st = $db->prepare("SELECT fp.multicurrency_price, fp.multicurrency_code, fp.price,
                                   fp.desc_fourn, s.nom
                            FROM llx_product_fournisseur_price fp
                            LEFT JOIN llx_societe s ON s.rowid = fp.fk_soc
                            WHERE fp.fk_product = ? ORDER BY fp.rowid LIMIT 3");
        $st->bind_param('i', $id); $st->execute();
        $p['supplier_prices'] = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    }

    $st = $db->prepare("SELECT field, old_value, new_value, who, tool, datec
                        FROM llx_nt_product_log WHERE fk_product = ? ORDER BY rowid DESC LIMIT 15");
    $st->bind_param('i', $id); $st->execute();
    $p['log'] = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    $st = $db->prepare("SELECT SUM(qty_sold) q FROM llx_nt_sales_history WHERE fk_product = ?");
    $st->bind_param('i', $id); $st->execute();
    $p['sold'] = (float)($st->get_result()->fetch_assoc()['q'] ?? 0); $st->close();

    return $p;
}

/** Приведение введённого значения к типу поля. Пустая строка = «не трогать». */
function catalog_normalize(string $type, $raw)
{
    if ($raw === null) return null;
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    switch ($type) {
        case 'int':   return (string)max(0, (int)$raw);
        case 'num':
        case 'money': return (string)round((float)str_replace([' ', ','], ['', '.'], $raw), 6);
        default:      return $raw;
    }
}

/**
 * Сохранение карточки. Возвращает ['ok'=>bool, 'changed'=>[поле=>[было,стало]], 'errors'=>[]].
 * Пишем ТОЛЬКО изменившиеся поля — иначе журнал забьётся пустыми правками, а лишний PUT на
 * цену заведёт лишнюю строку в истории цен.
 */
function catalog_save(mysqli $db, $api, array $caps, int $id, array $post, string $who, string $tool): array
{
    $cur = catalog_load($db, $caps, $id);
    if (!$cur) return ['ok' => false, 'changed' => [], 'errors' => ['Товар не найден или относится к другому направлению']];

    $fields  = catalog_fields();
    $changed = []; $errors = [];
    $sqlSet  = []; $sqlArgs = []; $sqlTypes = '';
    $restData = [];

    foreach ($fields as $key => $def) {
        if (!catalog_can($caps, 'edit', $def['group'])) continue;
        if (!array_key_exists($key, $post)) continue;

        $new = catalog_normalize($def['type'], $post[$key]);
        if ($new === null) continue;                       // пустое поле = «оставить как есть»

        $old = $cur[$def['col'] ?? $def['ef']] ?? null;
        $oldS = ($old === null || $old === '') ? '' : (string)$old;
        // числа сравниваем по значению, иначе «0.29» и «0.29000000» выглядят как правка
        $same = in_array($def['type'], ['num', 'money', 'int'], true)
              ? (abs((float)$oldS - (float)$new) < 0.0000005)
              : ($oldS === (string)$new);
        if ($same) continue;

        if ($def['type'] === 'money' && (float)$new < 0) { $errors[] = "{$def['title']}: цена не может быть отрицательной"; continue; }
        if (in_array($def['type'], ['num', 'int'], true) && (float)$new < 0) { $errors[] = "{$def['title']}: значение не может быть отрицательным"; continue; }

        $changed[$key] = [$oldS, (string)$new];

        if ($key === 'price') {
            $restData['price'] = (float)$new;              // через REST — ради истории цен
        } elseif (isset($def['ef'])) {
            $restData['array_options']['options_' . $def['ef']] = $new;
        } else {
            $sqlSet[] = "{$def['col']} = ?";
            $sqlArgs[] = $new; $sqlTypes .= 's';
        }
    }

    if (!$changed) return ['ok' => true, 'changed' => [], 'errors' => $errors];

    // Единицы измерения проставляем вместе со значением, иначе Dolibarr покажет вес в «штуках».
    if (isset($changed['weight']))                              { $sqlSet[] = 'weight_units = ' . CAT_UNIT_WEIGHT; }
    foreach (['length', 'width', 'height'] as $d)
        if (isset($changed[$d]))                                { $sqlSet[] = $d . '_units = ' . CAT_UNIT_SIZE; }
    if (isset($changed['volume']))                              { $sqlSet[] = 'volume_units = ' . CAT_UNIT_VOLUME; }

    try {
        $db->begin_transaction();

        if ($sqlSet) {
            $st = $db->prepare("UPDATE llx_product SET " . implode(', ', $sqlSet) . ", tms = NOW() WHERE rowid = ?");
            $sqlTypes .= 'i'; $sqlArgs[] = $id;
            $st->bind_param($sqlTypes, ...$sqlArgs);
            $st->execute(); $st->close();
        }

        // Доп.поля: строки может не быть вовсе — тогда её надо создать.
        if (!empty($restData['array_options'])) {
            $db->query("INSERT IGNORE INTO llx_product_extrafields (fk_object) VALUES ($id)");
            $set = []; $a = []; $t = '';
            foreach ($restData['array_options'] as $k => $v) {
                $col = substr($k, strlen('options_'));
                $set[] = "$col = ?"; $a[] = $v; $t .= 's';
            }
            $st = $db->prepare("UPDATE llx_product_extrafields SET " . implode(', ', $set) . " WHERE fk_object = ?");
            $t .= 'i'; $a[] = $id;
            $st->bind_param($t, ...$a); $st->execute(); $st->close();
        }

        $log = $db->prepare("INSERT INTO llx_nt_product_log (fk_product, field, old_value, new_value, who, tool, datec)
                             VALUES (?,?,?,?,?,?,NOW())");
        foreach ($changed as $k => [$o, $n]) {
            $title = $fields[$k]['title'];
            $log->bind_param('isssss', $id, $title, $o, $n, $who, $tool);
            $log->execute();
        }
        $log->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        return ['ok' => false, 'changed' => [], 'errors' => ['Не удалось сохранить: ' . $e->getMessage()]];
    }

    // Цена — отдельно и ПОСЛЕ фиксации остального: REST заводит строку в истории цен, и если он
    // упадёт, физические данные всё равно сохранятся, а про цену мы честно скажем.
    if (isset($restData['price']) && $api !== null) {
        $res = $api->put('products/' . $id, ['price' => $restData['price']]);
        if ($res === null) {
            $errors[] = 'Цена продажи НЕ сохранена: ' . ($api->lastError ?? 'ошибка Dolibarr');
            unset($changed['price']);
            $db->query("DELETE FROM llx_nt_product_log WHERE fk_product = $id AND field = 'Цена продажи'
                        ORDER BY rowid DESC LIMIT 1");
        }
    }

    return ['ok' => empty($errors), 'changed' => $changed, 'errors' => $errors];
}

/**
 * Категории для выпадающего списка — деревом: сначала корень, следом его подкатегории
 * с отступом. Плоский список из 124 штук (11 групп типов + 65 подгрупп + 48 брендов)
 * не читается, а иерархия у нас появилась 10.09.2026.
 * Считаем только товары нужных направлений, поэтому у кассы Жоми цифры свои.
 */
function catalog_categories(mysqli $db, array $caps): array
{
    $dirs = $caps['directions'] ?? ['J', 'T'];
    $like = []; $args = []; $types = '';
    foreach ($dirs as $d) { $like[] = 'e.kod_sap LIKE ?'; $args[] = $d . '%'; $types .= 's'; }
    $st = $db->prepare("SELECT c.rowid, c.label, c.fk_parent, c.description, COUNT(*) n
                        FROM llx_categorie c
                        JOIN llx_categorie_product cp ON cp.fk_categorie = c.rowid
                        JOIN llx_product p ON p.rowid = cp.fk_product
                        LEFT JOIN llx_product_extrafields e ON e.fk_object = p.rowid
                        WHERE (" . implode(' OR ', $like) . ")
                        GROUP BY c.rowid, c.label, c.fk_parent, c.description ORDER BY c.label");
    $st->bind_param($types, ...$args); $st->execute();
    $all = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

    $byParent = [];
    foreach ($all as $c) $byParent[(int)$c['fk_parent']][] = $c;

    // Два разреза: дерево типов (создано 10.09.2026) и плоский список брендов из миграции.
    // Признак — пометка в описании категории; по ней же их различает build_tree.php.
    $out = ['type' => [], 'brand' => []];
    foreach ($byParent[0] ?? [] as $root) {
        $isType = str_starts_with((string)($root['description'] ?? ''), 'Тип товара');
        $bucket = $isType ? 'type' : 'brand';
        $root['depth'] = 0;
        $out[$bucket][] = $root;
        foreach ($byParent[(int)$root['rowid']] ?? [] as $child) {
            $child['depth'] = 1;
            $out[$bucket][] = $child;
        }
    }
    return $out;
}

/** Категория и все её подкатегории — выбрав «Арматура», человек ждёт и краны, и клапаны. */
function catalog_category_with_children(mysqli $db, int $id): array
{
    $ids = [$id];
    $st = $db->prepare("SELECT rowid FROM llx_categorie WHERE fk_parent = ?");
    $st->bind_param('i', $id); $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $ids[] = (int)$r['rowid'];
    $st->close();
    return $ids;
}
