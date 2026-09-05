<?php
// S-1 (внешний QA-аудит, раунд 2, 03.09.2026) — тот же пропуск, что и в logout.php: без
// session_boot.php сессия стартовала под дефолтным именем, здесь это привело бы к тому, что уже
// залогиненный пользователь всегда видел бы "не залогинен" и уходил на login.php.
require_once __DIR__ . '/includes/session_boot.php';
session_start();
header('Location: ' . (empty($_SESSION['direction']) ? 'login.php' : 'sale.php'));
exit;
