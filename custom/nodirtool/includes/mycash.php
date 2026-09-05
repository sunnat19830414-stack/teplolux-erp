<?php
/**
 * Личная касса закупщика (Нодир/Абдурашид) — топ-5 пункт 2, 02.09.2026.
 *
 * Деньги в NODIR-CASH/ABDUR-CASH уже реально проводятся сегодня: Жамшид/MuhammadAli жмут "Передать
 * кассу" в TeplouxKassa — списание с их кассы и зачисление на личный счёт закупщика происходит СРАЗУ,
 * это уже проверенный рабочий механизм (см. CLAUDE.md 28.08.2026). Здесь мы это НЕ дублируем и не
 * задерживаем — по решению пользователя (02.09.2026) подтверждение "пересчитал, подтверждаю сумму" не
 * гейт для денег, а отдельная квитанция поверх уже свершившейся проводки: помогает закупщику явно
 * отметить "да, я это видел и физически пересчитал", не более.
 *
 * Своя вспомогательная таблица (не Dolibarr) — тот же паттерн, что includes/logistics.php.
 */

function mycash_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $db = require __DIR__ . '/../config/db.local.php';
        $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

function mycash_ensure_table(): void
{
    static $done = false;
    if ($done) return;
    mycash_db()->query("CREATE TABLE IF NOT EXISTS llx_nodirtool_cash_ack (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_bank_line INT NOT NULL,
        fk_account INT NOT NULL,
        confirmed_by VARCHAR(50) NOT NULL,
        confirmed_at DATETIME NOT NULL,
        UNIQUE KEY uk_line (fk_bank_line)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** rowid проводки => ['by' => логин, 'at' => 'Y-m-d H:i:s'] для всех подтверждённых строк одного счёта. */
function mycash_get_ack_map(int $accountId): array
{
    mycash_ensure_table();
    $db = mycash_db();
    $stmt = $db->prepare("SELECT fk_bank_line, confirmed_by, confirmed_at FROM llx_nodirtool_cash_ack WHERE fk_account = ?");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['fk_bank_line']] = ['by' => $row['confirmed_by'], 'at' => $row['confirmed_at']];
    }
    return $out;
}

/** true — если запись создана; false — если эта проводка уже была подтверждена раньше (не гонка, просто идемпотентно). */
function mycash_confirm_line(int $lineId, int $accountId, string $login): bool
{
    mycash_ensure_table();
    $db = mycash_db();
    $stmt = $db->prepare("INSERT IGNORE INTO llx_nodirtool_cash_ack (fk_bank_line, fk_account, confirmed_by, confirmed_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('iis', $lineId, $accountId, $login);
    $stmt->execute();
    return $db->affected_rows > 0;
}
