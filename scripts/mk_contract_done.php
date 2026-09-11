<?php
/**
 * Доп.поля «Выполнено по контракту до учёта в программе» и «на дату» (11.09.2026, просьба
 * пользователя: «пишем срок и сумму контракта, как будто начинаем с нуля, хотя давно работаем»).
 * ExtraFields::addExtraField() на PHP 8 падает на пустом массиве параметров — передаём ''.
 */
define('NOREQUIRESOC', 1); define('NOREQUIRETRAN', 1); define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1); define('NOLOGIN', 1);
$_SERVER['DOCUMENT_ROOT'] = 'C:/Dolibarr/htdocs';
require_once 'C:/Dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
$ef = new ExtraFields($db);
$ef->fetch_name_optionals_label('societe');
foreach ([['contract_done_amount', 'Выполнено по контракту до учёта в программе', 'price', 103, ''],
          ['contract_done_date', 'Выполнено по контракту — на дату', 'date', 104, '']] as [$n, $l, $t, $pos, $size]) {
    if (isset($ef->attributes['societe']['label'][$n])) { echo "$n уже есть\n"; continue; }
    $ok = $ef->addExtraField($n, $l, $t, $pos, $size, 'societe', 0, 0, '', '', 1, '', 1, '', 0, 0, '', null, 0);
    echo $ok ? "$n создано\n" : "$n НЕ создано: {$ef->error}\n";
}
$r = $db->query("SELECT name, type FROM " . MAIN_DB_PREFIX . "extrafields WHERE elementtype='societe' AND name LIKE 'contract_done%'");
while ($x = $db->fetch_object($r)) echo "  в справочнике: {$x->name} ({$x->type})\n";
$r = $db->query("SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "societe_extrafields LIKE 'contract_done%'");
while ($x = $db->fetch_array($r)) echo "  колонка: {$x[0]} {$x[1]}\n";
