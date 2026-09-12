<?php
/** Доп.поле заказа поставщику «Готов у поставщика» (12.09.2026): дата отправки + срок поставки из карточки. */
define('NOREQUIRESOC', 1); define('NOREQUIRETRAN', 1); define('NOCSRFCHECK', 1); define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1); define('NOREQUIREAJAX', 1); define('NOLOGIN', 1);
$_SERVER['DOCUMENT_ROOT'] = 'C:/Dolibarr/htdocs';
require_once 'C:/Dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
$ef = new ExtraFields($db);
$ef->fetch_name_optionals_label('commande_fournisseur');
if (isset($ef->attributes['commande_fournisseur']['label']['ready_date'])) { echo "поле уже есть\n"; exit; }
$ok = $ef->addExtraField('ready_date', 'Готов у поставщика', 'date', 40, '', 'commande_fournisseur', 0, 0, '', '', 1, '', 1, '', 0, 0, '', null, 0);
echo $ok ? "поле ready_date создано\n" : "НЕ создано: {$ef->error}\n";
$r = $db->query("SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "commande_fournisseur_extrafields LIKE 'ready_date'");
while ($x = $db->fetch_array($r)) echo "  колонка: {$x[0]} {$x[1]}\n";
