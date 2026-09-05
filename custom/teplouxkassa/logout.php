<?php
// S-1 (внешний QA-аудит, раунд 2, 03.09.2026): без session_boot.php этот файл стартовал сессию под
// ДЕФОЛТНЫМ именем (PHPSESSID), а не TEPLOUXKASSASESSID — уничтожал не ту сессию, реальная (под
// правильным именем cookie) оставалась залогиненной. Найдено и исправлено сразу при проверке фикса S-1.
require_once __DIR__ . '/includes/session_boot.php';
session_start();
session_destroy();
header('Location: login.php');
exit;
