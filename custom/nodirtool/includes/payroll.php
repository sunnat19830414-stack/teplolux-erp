<?php
/**
 * Зарплата и авансы сотрудникам (04.09.2026, задача Нодира) + хозрасходы/коммуналка (задача
 * Абдурашида) — свои вспомогательные таблицы, как у логистики (includes/logistics.php): чистый SQL,
 * без тяжёлого bootstrap Dolibarr. САМИ ДЕНЬГИ идут настоящими банковскими проводками через REST
 * (addBankLine) — видны в самом Dolibarr, не отдельная бухгалтерия.
 *
 * МОДЕЛЬ ЗАРПЛАТЫ — единый журнал движений по сотруднику, баланс = сумма всех строк:
 *   начисление (accrual) +700  — фирма должна сотруднику за месяц
 *   аванс      (advance) −200  — выдали деньги до зарплаты
 *   выплата    (payout)  −500  — выдали остаток зарплаты
 *   правка     (adjust)  ±X    — премия/штраф/исправление руками
 * Баланс > 0 — фирма должна сотруднику; < 0 — сотрудник взял больше начисленного, долг переходит
 * на следующий месяц сам собой (ничего обнулять не нужно — журнал сквозной).
 *
 * ВАЛЮТА: оклад и весь учёт — в долларах (решение пользователя). Выдавать можно наличными в долларах
 * или на карту в сумах по курсу — тот же принцип "сумма + курс в моменте", что везде в проекте.
 *
 * НАЛОГ при выплате на карту (официальные сотрудники): сотрудник должен получить на руки
 * оговорённую сумму, а со счёта списывается БОЛЬШЕ — разница и есть налог. Процент нам пока
 * неизвестен (пользователь уточнит), поэтому НЕ угадываем: Нодир вводит обе фактические суммы —
 * сколько пришло на карту и сколько списалось со счёта. Долг сотрудника закрывается на то, что он
 * ПОЛУЧИЛ; на счёт ложится то, что реально ушло; разница пишется как налог отдельным полем.
 */

function payroll_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/**
 * ⚠️ ВАЖНО (найдено тестом 04.09.2026): начиная с PHP 8.1 mysqli по умолчанию БРОСАЕТ
 * mysqli_sql_exception вместо возврата false — поэтому привычная проверка `if (!$stmt->execute())`
 * НИКОГДА не срабатывает, а страница падает с HTTP 500 вместо понятного сообщения. Ровно так себя и
 * повёл повторный ввод отдела с тем же названием (UNIQUE-ключ): вместо «Такой отдел уже есть»
 * пользователь получал белый экран. Все записи в свои таблицы идут через эту обёртку.
 *
 * @return array{ok: bool, error?: string, errno?: int}
 */
function payroll_exec(mysqli_stmt $stmt, string $failMessage = 'Ошибка сохранения'): array
{
    try {
        $stmt->execute();
        return ['ok' => true];
    } catch (mysqli_sql_exception $e) {
        return ['ok' => false, 'errno' => $e->getCode(), 'error' => $failMessage . ': ' . $e->getMessage()];
    }
}

function payroll_ensure_tables(): void
{
    static $done = false;
    if ($done) return;
    $db = payroll_db();

    // Отделы (04.09.2026, просьба пользователя) — только для зарплаты: группировка сотрудников и
    // разбивка расходов на зарплату по отделам. К хозрасходам отделы НЕ привязываются (решение
    // пользователя), поэтому таблица живёт рядом с сотрудниками, а не отдельным общим справочником.
    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_department (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        datec DATETIME NOT NULL,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_employee (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        position VARCHAR(150) DEFAULT NULL,
        fk_department INT DEFAULT NULL,
        salary_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        card_payment TINYINT NOT NULL DEFAULT 0,
        active TINYINT NOT NULL DEFAULT 1,
        note VARCHAR(255) DEFAULT NULL,
        datec DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Для баз, созданных до появления отделов — добавляем колонку, если её ещё нет.
    $db->query("ALTER TABLE llx_nt_employee ADD COLUMN IF NOT EXISTS fk_department INT DEFAULT NULL");

    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_payroll_entry (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_employee INT NOT NULL,
        entry_type VARCHAR(10) NOT NULL,
        period VARCHAR(7) DEFAULT NULL,
        amount_usd DECIMAL(12,2) NOT NULL,
        tax_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        fk_bank INT DEFAULT NULL,
        native_amount DECIMAL(18,2) DEFAULT NULL,
        native_currency VARCHAR(3) DEFAULT NULL,
        rate DECIMAL(18,4) DEFAULT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        who VARCHAR(50) DEFAULT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_emp (fk_employee),
        INDEX idx_period (period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Хозрасходы (Абдурашид). Категории заводит сам, списка "из коробки" нет по решению пользователя. ---
    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_expense_category (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        datec DATETIME NOT NULL,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_household_expense (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_category INT NOT NULL,
        expense_date DATE NOT NULL,
        amount_usd DECIMAL(12,2) NOT NULL,
        fk_bank INT DEFAULT NULL,
        native_amount DECIMAL(18,2) DEFAULT NULL,
        native_currency VARCHAR(3) DEFAULT NULL,
        rate DECIMAL(18,4) DEFAULT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        who VARCHAR(50) DEFAULT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_cat (fk_category),
        INDEX idx_date (expense_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- Прочие доходы (04.09.2026): деньги, приходящие МИМО продажи товара — солнечные батареи
    // (электричество государству), аренда, услуги и работы и т.п. Источники Абдурашид заводит сам,
    // готового списка нет (то же решение, что и по категориям расходов). Отдельный раздел, не общий
    // с расходами (решение пользователя) — поэтому и таблицы свои, зеркальные.
    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_income_source (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        active TINYINT NOT NULL DEFAULT 1,
        datec DATETIME NOT NULL,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS llx_nt_income (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_source INT NOT NULL,
        income_date DATE NOT NULL,
        amount_usd DECIMAL(12,2) NOT NULL,
        fk_bank INT DEFAULT NULL,
        native_amount DECIMAL(18,2) DEFAULT NULL,
        native_currency VARCHAR(3) DEFAULT NULL,
        rate DECIMAL(18,4) DEFAULT NULL,
        comment VARCHAR(255) DEFAULT NULL,
        who VARCHAR(50) DEFAULT NULL,
        datec DATETIME NOT NULL,
        INDEX idx_src (fk_source),
        INDEX idx_date (income_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}

// ==================== ОТДЕЛЫ ====================

function payroll_get_departments(bool $onlyActive = true): array
{
    payroll_ensure_tables();
    $where = $onlyActive ? 'WHERE active=1' : '';
    $res = payroll_db()->query("SELECT * FROM llx_nt_department $where ORDER BY name");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[] = $row; }
    return $out;
}

function payroll_add_department(string $name): array
{
    payroll_ensure_tables();
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Укажите название отдела.'];
    $db = payroll_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_department (name, active, datec) VALUES (?, 1, NOW())");
    $stmt->bind_param('s', $name);
    $r = payroll_exec($stmt);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => ($r['errno'] ?? 0) === 1062 ? 'Такой отдел уже есть.' : $r['error']];
    }
    return ['ok' => true, 'id' => $db->insert_id];
}

function payroll_rename_department(int $id, string $name): array
{
    payroll_ensure_tables();
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Укажите название отдела.'];
    $db = payroll_db();
    $stmt = $db->prepare("UPDATE llx_nt_department SET name=? WHERE rowid=?");
    $stmt->bind_param('si', $name, $id);
    $r = payroll_exec($stmt);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => ($r['errno'] ?? 0) === 1062 ? 'Отдел с таким названием уже есть.' : $r['error']];
    }
    return ['ok' => true];
}

/**
 * Скрыть/вернуть отдел. Сотрудники при скрытии НЕ теряют привязку — отдел просто пропадает из выбора
 * в карточке, а в списках и отчётах прошлое остаётся видно (тот же принцип, что у категорий расходов).
 */
function payroll_set_department_active(int $id, bool $active): void
{
    payroll_ensure_tables();
    payroll_db()->query("UPDATE llx_nt_department SET active=" . ($active ? 1 : 0) . " WHERE rowid=" . (int)$id);
}

/** Сколько сотрудников числится в каждом отделе — чтобы предупредить перед скрытием. */
function payroll_department_employee_counts(): array
{
    payroll_ensure_tables();
    $res = payroll_db()->query("SELECT fk_department, COUNT(*) c FROM llx_nt_employee
        WHERE fk_department IS NOT NULL GROUP BY fk_department");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[(int)$row['fk_department']] = (int)$row['c']; }
    return $out;
}

// ==================== СОТРУДНИКИ ====================

function payroll_get_employees(bool $onlyActive = true): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $where = $onlyActive ? 'WHERE e.active=1' : '';
    // Сортировка сразу по отделу — список на экране группируется без доп. обработки.
    $res = $db->query("SELECT e.*, d.name AS department_name
        FROM llx_nt_employee e
        LEFT JOIN llx_nt_department d ON d.rowid = e.fk_department
        $where
        ORDER BY e.active DESC, (d.name IS NULL), d.name, e.name");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[] = $row; }
    return $out;
}

function payroll_get_employee(int $id): ?array
{
    payroll_ensure_tables();
    $res = payroll_db()->query("SELECT e.*, d.name AS department_name
        FROM llx_nt_employee e
        LEFT JOIN llx_nt_department d ON d.rowid = e.fk_department
        WHERE e.rowid=" . (int)$id);
    return ($res && $res->num_rows) ? $res->fetch_assoc() : null;
}

function payroll_save_employee(int $id, string $name, string $position, ?int $departmentId, float $salaryUsd,
                               bool $cardPayment, bool $active, string $note): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Укажите имя сотрудника.'];
    $dept = ($departmentId && $departmentId > 0) ? $departmentId : null;
    $stmt = $id > 0
        ? $db->prepare("UPDATE llx_nt_employee SET name=?, position=?, fk_department=?, salary_usd=?, card_payment=?, active=?, note=? WHERE rowid=?")
        : $db->prepare("INSERT INTO llx_nt_employee (name, position, fk_department, salary_usd, card_payment, active, note, datec) VALUES (?,?,?,?,?,?,?,NOW())");
    $card = $cardPayment ? 1 : 0;
    $act = $active ? 1 : 0;
    if ($id > 0) {
        $stmt->bind_param('ssidiisi', $name, $position, $dept, $salaryUsd, $card, $act, $note, $id);
    } else {
        $stmt->bind_param('ssidiis', $name, $position, $dept, $salaryUsd, $card, $act, $note);
    }
    $r = payroll_exec($stmt, 'Ошибка сохранения');
    if (!$r['ok']) return $r;
    return ['ok' => true, 'id' => $id > 0 ? $id : $db->insert_id];
}

// ==================== БАЛАНС И ЖУРНАЛ ====================

/** Баланс сотрудника: >0 — фирма должна ему, <0 — он взял больше начисленного (долг перейдёт дальше). */
function payroll_employee_balance(int $employeeId): float
{
    payroll_ensure_tables();
    $res = payroll_db()->query("SELECT COALESCE(SUM(amount_usd),0) s FROM llx_nt_payroll_entry WHERE fk_employee=" . (int)$employeeId);
    return (float)$res->fetch_assoc()['s'];
}

/** Балансы ВСЕХ сотрудников одним запросом (для списка — не в цикле). */
function payroll_all_balances(): array
{
    payroll_ensure_tables();
    $res = payroll_db()->query("SELECT fk_employee, COALESCE(SUM(amount_usd),0) s FROM llx_nt_payroll_entry GROUP BY fk_employee");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[(int)$row['fk_employee']] = (float)$row['s']; }
    return $out;
}

function payroll_get_entries(int $employeeId, int $limit = 200): array
{
    payroll_ensure_tables();
    $res = payroll_db()->query("SELECT * FROM llx_nt_payroll_entry WHERE fk_employee=" . (int)$employeeId
        . " ORDER BY datec DESC, rowid DESC LIMIT " . (int)$limit);
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[] = $row; }
    return $out;
}

/** Начислена ли уже зарплата за этот месяц (чтобы не начислить дважды). */
function payroll_has_accrual(int $employeeId, string $period): bool
{
    payroll_ensure_tables();
    $db = payroll_db();
    $res = $db->query("SELECT COUNT(*) c FROM llx_nt_payroll_entry WHERE fk_employee=" . (int)$employeeId
        . " AND entry_type='accrual' AND period='" . $db->real_escape_string($period) . "'");
    return (int)$res->fetch_assoc()['c'] > 0;
}

/** Начислить зарплату за месяц (оклад из карточки, можно переопределить суммой). */
function payroll_accrue(int $employeeId, string $period, ?float $amountUsd, string $who, string $comment = ''): array
{
    payroll_ensure_tables();
    $emp = payroll_get_employee($employeeId);
    if (!$emp) return ['ok' => false, 'error' => 'Сотрудник не найден.'];
    if (payroll_has_accrual($employeeId, $period)) {
        return ['ok' => false, 'error' => "За {$period} этому сотруднику уже начислено — повторно нельзя."];
    }
    $amount = $amountUsd !== null ? $amountUsd : (float)$emp['salary_usd'];
    if ($amount <= 0) return ['ok' => false, 'error' => 'Сумма начисления должна быть больше нуля (проверьте оклад в карточке).'];

    $db = payroll_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_payroll_entry (fk_employee, entry_type, period, amount_usd, comment, who, datec)
                          VALUES (?, 'accrual', ?, ?, ?, ?, NOW())");
    $stmt->bind_param('isdss', $employeeId, $period, $amount, $comment, $who);
    $r = payroll_exec($stmt, 'Ошибка начисления');
    if (!$r['ok']) return $r;
    return ['ok' => true, 'amount' => $amount];
}

/**
 * Выдать деньги сотруднику (аванс или зарплата). ОДНА выплата = одна проводка по счёту.
 *
 * @param string $type       'advance' | 'payout'
 * @param float  $receivedUsd Сколько сотрудник РЕАЛЬНО получил на руки, в долларах (на эту сумму
 *                            закрывается его долг — см. докблок файла про налог).
 * @param float  $debitedUsd  Сколько списалось со счёта, в долларах (для наличных = $receivedUsd;
 *                            для карты больше — разница это налог).
 * @param array  $native      ['amount'=>сумма в валюте счёта, 'currency'=>'UZS'|'USD', 'rate'=>курс|null]
 *                            — то, что реально проводим по счёту.
 */
function payroll_pay(DolibarrApi $api, int $employeeId, string $type, float $receivedUsd, float $debitedUsd,
                     int $bankAccountId, array $native, string $paymentCode, string $who, string $comment = '',
                     ?string $period = null): array
{
    payroll_ensure_tables();
    $emp = payroll_get_employee($employeeId);
    if (!$emp) return ['ok' => false, 'error' => 'Сотрудник не найден.'];
    if (!in_array($type, ['advance', 'payout'], true)) return ['ok' => false, 'error' => 'Неизвестный вид выплаты.'];
    if ($receivedUsd <= 0.001) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
    if ($debitedUsd + 0.001 < $receivedUsd) {
        return ['ok' => false, 'error' => 'Со счёта не может списаться меньше, чем сотрудник получил на руки — проверьте суммы.'];
    }
    if (!$bankAccountId) return ['ok' => false, 'error' => 'Выберите счёт, с которого выдаются деньги.'];

    $taxUsd = round($debitedUsd - $receivedUsd, 2);
    $label = ($type === 'advance' ? 'Аванс' : 'Зарплата') . ($period ? " за $period" : '')
        . ' — ' . $emp['name'] . ($comment !== '' ? " ({$comment})" : '') . " [$who]";

    // Реальные деньги: списываем с указанного счёта ФАКТИЧЕСКУЮ сумму в валюте этого счёта.
    $nativeAmount = (float)($native['amount'] ?? 0);
    if ($nativeAmount <= 0) return ['ok' => false, 'error' => 'Не удалось определить сумму списания по счёту.'];
    $bankRes = $api->addBankLine($bankAccountId, $label, -1 * $nativeAmount, $paymentCode);
    if ($bankRes === null) {
        return ['ok' => false, 'error' => 'Деньги не списаны со счёта: ' . $api->lastError . ' — выплата не записана.'];
    }

    // Долг сотрудника закрывается на то, что он ПОЛУЧИЛ (не на то, что ушло со счёта).
    $db = payroll_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_payroll_entry
        (fk_employee, entry_type, period, amount_usd, tax_usd, fk_bank, native_amount, native_currency, rate, comment, who, datec)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $negative = -1 * $receivedUsd;
    $curr = (string)($native['currency'] ?? 'USD');
    $rate = isset($native['rate']) ? (float)$native['rate'] : null;
    $stmt->bind_param('issddidsdss', $employeeId, $type, $period, $negative, $taxUsd, $bankAccountId,
        $nativeAmount, $curr, $rate, $comment, $who);
    $r = payroll_exec($stmt, 'Деньги списаны, но запись не сохранилась');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] . ' — сообщите Суннату.'];
    return ['ok' => true, 'tax_usd' => $taxUsd, 'received_usd' => $receivedUsd, 'debited_usd' => $debitedUsd];
}

/** Ручная правка баланса (премия/штраф/исправление) — без движения денег. */
function payroll_adjust(int $employeeId, float $amountUsd, string $comment, string $who): array
{
    payroll_ensure_tables();
    if (abs($amountUsd) < 0.001) return ['ok' => false, 'error' => 'Укажите сумму.'];
    if (trim($comment) === '') return ['ok' => false, 'error' => 'Укажите причину правки — она останется в истории.'];
    $db = payroll_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_payroll_entry (fk_employee, entry_type, amount_usd, comment, who, datec)
                          VALUES (?, 'adjust', ?, ?, ?, NOW())");
    $stmt->bind_param('idss', $employeeId, $amountUsd, $comment, $who);
    $r = payroll_exec($stmt, 'Ошибка записи правки');
    if (!$r['ok']) return $r;
    return ['ok' => true];
}

/** Сводка за месяц: начислено / выдано авансами / выдано зарплатой / налоги. */
function payroll_month_summary(string $period): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $p = $db->real_escape_string($period);
    // Начисления привязаны к периоду; выплаты — по дате проведения (аванс может быть без периода).
    $accrued = (float)$db->query("SELECT COALESCE(SUM(amount_usd),0) s FROM llx_nt_payroll_entry
        WHERE entry_type='accrual' AND period='$p'")->fetch_assoc()['s'];
    $res = $db->query("SELECT entry_type, COALESCE(SUM(-amount_usd),0) paid, COALESCE(SUM(tax_usd),0) tax
        FROM llx_nt_payroll_entry
        WHERE entry_type IN ('advance','payout') AND DATE_FORMAT(datec, '%Y-%m')='$p'
        GROUP BY entry_type");
    $advances = 0.0; $payouts = 0.0; $tax = 0.0;
    while ($row = $res->fetch_assoc()) {
        if ($row['entry_type'] === 'advance') $advances = (float)$row['paid'];
        else $payouts = (float)$row['paid'];
        $tax += (float)$row['tax'];
    }
    return ['period' => $period, 'accrued' => $accrued, 'advances' => $advances, 'payouts' => $payouts, 'tax' => $tax];
}

/**
 * Разбивка расходов на зарплату ПО ОТДЕЛАМ за месяц (04.09.2026). Считаем то же, что и в общей
 * сводке, только сгруппированно: начислено за период + фактически выданное (авансы и зарплата) по
 * дате проведения. Сотрудники без отдела идут отдельной строкой «Без отдела» — не теряются.
 */
function payroll_month_by_department(string $period): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $p = $db->real_escape_string($period);

    $rows = [];
    $res = $db->query("SELECT
            COALESCE(d.name, 'Без отдела') AS dept,
            COALESCE(SUM(CASE WHEN e.entry_type='accrual' AND e.period='$p' THEN e.amount_usd ELSE 0 END), 0) AS accrued,
            COALESCE(SUM(CASE WHEN e.entry_type='advance' AND DATE_FORMAT(e.datec,'%Y-%m')='$p' THEN -e.amount_usd ELSE 0 END), 0) AS advances,
            COALESCE(SUM(CASE WHEN e.entry_type='payout'  AND DATE_FORMAT(e.datec,'%Y-%m')='$p' THEN -e.amount_usd ELSE 0 END), 0) AS payouts,
            COALESCE(SUM(CASE WHEN e.entry_type IN ('advance','payout') AND DATE_FORMAT(e.datec,'%Y-%m')='$p' THEN e.tax_usd ELSE 0 END), 0) AS tax,
            COUNT(DISTINCT emp.rowid) AS people
        FROM llx_nt_payroll_entry e
        JOIN llx_nt_employee emp ON emp.rowid = e.fk_employee
        LEFT JOIN llx_nt_department d ON d.rowid = emp.fk_department
        WHERE (e.entry_type='accrual' AND e.period='$p')
           OR (e.entry_type IN ('advance','payout') AND DATE_FORMAT(e.datec,'%Y-%m')='$p')
        GROUP BY dept
        ORDER BY (dept='Без отдела'), dept");
    while ($row = $res->fetch_assoc()) {
        $row['paid_total'] = (float)$row['advances'] + (float)$row['payouts'];
        $rows[] = $row;
    }
    return $rows;
}

// ==================== ХОЗРАСХОДЫ ====================

function household_get_categories(bool $onlyActive = true): array
{
    payroll_ensure_tables();
    $where = $onlyActive ? 'WHERE active=1' : '';
    $res = payroll_db()->query("SELECT * FROM llx_nt_expense_category $where ORDER BY name");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[] = $row; }
    return $out;
}

function household_add_category(string $name): array
{
    payroll_ensure_tables();
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Укажите название категории.'];
    $db = payroll_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_expense_category (name, active, datec) VALUES (?, 1, NOW())");
    $stmt->bind_param('s', $name);
    $r = payroll_exec($stmt);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => ($r['errno'] ?? 0) === 1062 ? 'Такая категория уже есть.' : $r['error']];
    }
    return ['ok' => true, 'id' => $db->insert_id];
}

function household_set_category_active(int $id, bool $active): void
{
    payroll_ensure_tables();
    payroll_db()->query("UPDATE llx_nt_expense_category SET active=" . ($active ? 1 : 0) . " WHERE rowid=" . (int)$id);
}

/** Записать хозрасход + реально списать деньги со счёта. */
function household_record_expense(DolibarrApi $api, int $categoryId, string $dateYmd, float $amountUsd,
                                  int $bankAccountId, array $native, string $paymentCode, string $who, string $comment = ''): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    if (!$categoryId) return ['ok' => false, 'error' => 'Выберите категорию расхода.'];
    if ($amountUsd <= 0.001) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
    if (!$bankAccountId) return ['ok' => false, 'error' => 'Выберите счёт, с которого платим.'];
    $catRes = $db->query("SELECT name FROM llx_nt_expense_category WHERE rowid=" . (int)$categoryId);
    if (!$catRes || !$catRes->num_rows) return ['ok' => false, 'error' => 'Категория не найдена.'];
    $catName = $catRes->fetch_assoc()['name'];

    $nativeAmount = (float)($native['amount'] ?? 0);
    if ($nativeAmount <= 0) return ['ok' => false, 'error' => 'Не удалось определить сумму списания по счёту.'];
    $label = 'Хозрасход: ' . $catName . ($comment !== '' ? " — {$comment}" : '') . " [$who]";
    $bankRes = $api->addBankLine($bankAccountId, $label, -1 * $nativeAmount, $paymentCode);
    if ($bankRes === null) {
        return ['ok' => false, 'error' => 'Деньги не списаны со счёта: ' . $api->lastError . ' — расход не записан.'];
    }

    $stmt = $db->prepare("INSERT INTO llx_nt_household_expense
        (fk_category, expense_date, amount_usd, fk_bank, native_amount, native_currency, rate, comment, who, datec)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $curr = (string)($native['currency'] ?? 'USD');
    $rate = isset($native['rate']) ? (float)$native['rate'] : null;
    $stmt->bind_param('isdidsdss', $categoryId, $dateYmd, $amountUsd, $bankAccountId, $nativeAmount, $curr, $rate, $comment, $who);
    $r = payroll_exec($stmt, 'Деньги списаны, но расход не сохранился');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] . ' — сообщите Суннату.'];
    return ['ok' => true, 'category' => $catName];
}

function household_delete_expense(DolibarrApi $api, int $expenseId): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $res = $db->query("SELECT * FROM llx_nt_household_expense WHERE rowid=" . (int)$expenseId);
    if (!$res || !$res->num_rows) return ['ok' => false, 'error' => 'Расход не найден.'];
    $e = $res->fetch_assoc();
    // Деньги возвращаем обратной проводкой на тот же счёт той же суммой — как у логистики.
    $note = '';
    if (!empty($e['fk_bank']) && (float)$e['native_amount'] > 0) {
        $r = $api->addBankLine((int)$e['fk_bank'], 'Отмена хозрасхода #' . $expenseId, (float)$e['native_amount'], 'LIQ');
        $note = $r === null
            ? ' ВНИМАНИЕ: деньги на счёт вернуть не удалось (' . $api->lastError . ') — поправьте вручную.'
            : ' Деньги возвращены на счёт.';
    }
    $db->query("DELETE FROM llx_nt_household_expense WHERE rowid=" . (int)$expenseId);
    return ['ok' => true, 'note' => trim($note)];
}

/** Отчёт по хозрасходам за период: итоги по категориям + все строки. */
function household_report(string $dateFrom, string $dateTo): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $from = $db->real_escape_string($dateFrom);
    $to = $db->real_escape_string($dateTo);

    $byCategory = [];
    $res = $db->query("SELECT c.rowid, c.name, COALESCE(SUM(e.amount_usd),0) total, COUNT(e.rowid) cnt
        FROM llx_nt_household_expense e
        JOIN llx_nt_expense_category c ON c.rowid = e.fk_category
        WHERE e.expense_date BETWEEN '$from' AND '$to'
        GROUP BY c.rowid, c.name ORDER BY total DESC");
    while ($row = $res->fetch_assoc()) { $byCategory[] = $row; }

    $rows = [];
    $res2 = $db->query("SELECT e.*, c.name AS category_name
        FROM llx_nt_household_expense e
        JOIN llx_nt_expense_category c ON c.rowid = e.fk_category
        WHERE e.expense_date BETWEEN '$from' AND '$to'
        ORDER BY e.expense_date DESC, e.rowid DESC");
    while ($row = $res2->fetch_assoc()) { $rows[] = $row; }

    $total = 0.0;
    foreach ($byCategory as $c) { $total += (float)$c['total']; }
    return ['from' => $dateFrom, 'to' => $dateTo, 'by_category' => $byCategory, 'rows' => $rows, 'total' => $total];
}

// ==================== ПРОЧИЕ ДОХОДЫ ====================
// Зеркало хозрасходов, только деньги ЗАЧИСЛЯЮТСЯ на счёт, а не списываются. Отдельный раздел меню
// (решение пользователя 04.09.2026) — списки источников и категорий расходов не перемешиваются.

function income_get_sources(bool $onlyActive = true): array
{
    payroll_ensure_tables();
    $where = $onlyActive ? 'WHERE active=1' : '';
    $res = payroll_db()->query("SELECT * FROM llx_nt_income_source $where ORDER BY name");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[] = $row; }
    return $out;
}

function income_add_source(string $name): array
{
    payroll_ensure_tables();
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Укажите название источника дохода.'];
    $db = payroll_db();
    $stmt = $db->prepare("INSERT INTO llx_nt_income_source (name, active, datec) VALUES (?, 1, NOW())");
    $stmt->bind_param('s', $name);
    $r = payroll_exec($stmt);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => ($r['errno'] ?? 0) === 1062 ? 'Такой источник уже есть.' : $r['error']];
    }
    return ['ok' => true, 'id' => $db->insert_id];
}

function income_set_source_active(int $id, bool $active): void
{
    payroll_ensure_tables();
    payroll_db()->query("UPDATE llx_nt_income_source SET active=" . ($active ? 1 : 0) . " WHERE rowid=" . (int)$id);
}

/**
 * Записать доход + реально ЗАЧИСЛИТЬ деньги на счёт (положительная проводка — в отличие от расхода).
 * $native — то, что реально пришло по счёту: ['amount', 'currency', 'rate'|null].
 */
function income_record(DolibarrApi $api, int $sourceId, string $dateYmd, float $amountUsd,
                       int $bankAccountId, array $native, string $paymentCode, string $who, string $comment = ''): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    if (!$sourceId) return ['ok' => false, 'error' => 'Выберите источник дохода.'];
    if ($amountUsd <= 0.001) return ['ok' => false, 'error' => 'Укажите сумму больше нуля.'];
    if (!$bankAccountId) return ['ok' => false, 'error' => 'Выберите счёт, на который пришли деньги.'];
    $srcRes = $db->query("SELECT name FROM llx_nt_income_source WHERE rowid=" . (int)$sourceId);
    if (!$srcRes || !$srcRes->num_rows) return ['ok' => false, 'error' => 'Источник не найден.'];
    $srcName = $srcRes->fetch_assoc()['name'];

    $nativeAmount = (float)($native['amount'] ?? 0);
    if ($nativeAmount <= 0) return ['ok' => false, 'error' => 'Не удалось определить сумму зачисления по счёту.'];
    $label = 'Доход: ' . $srcName . ($comment !== '' ? " — {$comment}" : '') . " [$who]";
    $bankRes = $api->addBankLine($bankAccountId, $label, $nativeAmount, $paymentCode); // ПЛЮС, деньги приходят
    if ($bankRes === null) {
        return ['ok' => false, 'error' => 'Деньги не зачислены на счёт: ' . $api->lastError . ' — доход не записан.'];
    }

    $stmt = $db->prepare("INSERT INTO llx_nt_income
        (fk_source, income_date, amount_usd, fk_bank, native_amount, native_currency, rate, comment, who, datec)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $curr = (string)($native['currency'] ?? 'USD');
    $rate = isset($native['rate']) ? (float)$native['rate'] : null;
    $stmt->bind_param('isdidsdss', $sourceId, $dateYmd, $amountUsd, $bankAccountId, $nativeAmount, $curr, $rate, $comment, $who);
    $r = payroll_exec($stmt, 'Деньги зачислены, но доход не сохранился');
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'] . ' — сообщите Суннату.'];
    return ['ok' => true, 'source' => $srcName];
}

function income_delete(DolibarrApi $api, int $incomeId): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $res = $db->query("SELECT * FROM llx_nt_income WHERE rowid=" . (int)$incomeId);
    if (!$res || !$res->num_rows) return ['ok' => false, 'error' => 'Запись о доходе не найдена.'];
    $e = $res->fetch_assoc();
    // Обратная проводка — снимаем со счёта ровно то, что зачисляли (зеркало household_delete_expense).
    $note = '';
    if (!empty($e['fk_bank']) && (float)$e['native_amount'] > 0) {
        $r = $api->addBankLine((int)$e['fk_bank'], 'Отмена дохода #' . $incomeId, -1 * (float)$e['native_amount'], 'LIQ');
        $note = $r === null
            ? ' ВНИМАНИЕ: снять деньги со счёта не удалось (' . $api->lastError . ') — поправьте вручную.'
            : ' Деньги сняты со счёта обратно.';
    }
    $db->query("DELETE FROM llx_nt_income WHERE rowid=" . (int)$incomeId);
    return ['ok' => true, 'note' => trim($note)];
}

/** Отчёт по доходам за период: итоги по источникам + все строки (зеркало household_report). */
function income_report(string $dateFrom, string $dateTo): array
{
    payroll_ensure_tables();
    $db = payroll_db();
    $from = $db->real_escape_string($dateFrom);
    $to = $db->real_escape_string($dateTo);

    $bySource = [];
    $res = $db->query("SELECT s.rowid, s.name, COALESCE(SUM(i.amount_usd),0) total, COUNT(i.rowid) cnt
        FROM llx_nt_income i
        JOIN llx_nt_income_source s ON s.rowid = i.fk_source
        WHERE i.income_date BETWEEN '$from' AND '$to'
        GROUP BY s.rowid, s.name ORDER BY total DESC");
    while ($row = $res->fetch_assoc()) { $bySource[] = $row; }

    $rows = [];
    $res2 = $db->query("SELECT i.*, s.name AS source_name
        FROM llx_nt_income i
        JOIN llx_nt_income_source s ON s.rowid = i.fk_source
        WHERE i.income_date BETWEEN '$from' AND '$to'
        ORDER BY i.income_date DESC, i.rowid DESC");
    while ($row = $res2->fetch_assoc()) { $rows[] = $row; }

    $total = 0.0;
    foreach ($bySource as $s) { $total += (float)$s['total']; }
    return ['from' => $dateFrom, 'to' => $dateTo, 'by_source' => $bySource, 'rows' => $rows, 'total' => $total];
}
