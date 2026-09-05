<?php
/**
 * Реквизиты прямого подключения к БД Dolibarr (mysqli) — вынесены в отдельный файл из
 * includes/logistics.php и includes/price_history.php, чтобы не держать пароль БД внутри кода
 * приложения вперемешку с логикой; упрощает будущую ротацию пароля и перенос на VPS (см. CLAUDE.md).
 */
return [
    'host' => 'localhost',
    'user' => 'dolibarruser',
    'pass' => 'ЗАМЕНИТЬ_ПАРОЛЬ_БД',
    'name' => 'dolibarr',
];
