<?php
/**
 * Настройка сессии ДО session_start(). Своё имя cookie обязательно: браузер не различает
 * localhost:8010 / :8011 / :8012 по порту (порт не входит в область действия cookie), и без разных
 * имён вход в один инструмент выбивал бы из другого — ровно та проблема S-1, которую уже чинили для
 * кассы и закупок 03.09.2026.
 *
 * Время жизни — 8 часов: руководство заходит редко и подолгу читает отчёты, дефолтные 24 минуты
 * простоя выбрасывали бы посреди работы.
 */

const BOSSTOOL_SESSION_LIFETIME = 28800;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)BOSSTOOL_SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => BOSSTOOL_SESSION_LIFETIME,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('BOSSTOOLSESSID');
}
