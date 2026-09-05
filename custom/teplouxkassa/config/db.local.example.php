<?php
/**
 * Реквизиты прямого подключения к БД Dolibarr (mysqli) — вынесены в отдельный файл из
 * includes/dolibarr_direct.php, чтобы не держать пароль БД внутри кода приложения вперемешку с
 * логикой; упрощает будущую ротацию пароля и перенос на VPS (см. CLAUDE.md).
 */
return [
    'host' => 'localhost',
    'user' => 'dolibarruser',
    'pass' => 'ЗАМЕНИТЬ_ПАРОЛЬ_БД',
    'name' => 'dolibarr',
];
