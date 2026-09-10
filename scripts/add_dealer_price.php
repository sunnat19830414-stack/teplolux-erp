<?php
/**
 * Доп.поле «Дилерская цена» на товаре + заливка значений из Bus.gdb (10.09.2026).
 *
 * ⚠️ ПОЧЕМУ ОТДЕЛЬНОЕ ПОЛЕ, А НЕ ПОВЕРХ ЦЕНЫ ПРОДАЖИ. Пользователь предположил, что цена в Dolibarr
 * и есть дилерская. Проверка на 64 товарах, где уровни в Bus.gdb расходятся, показала обратное:
 * цена Dolibarr совпала с РОЗНИЧНОЙ 64 раза из 64 и с дилерской — ни разу. Дилерская ровно на 20%
 * ниже. Запись поверх уронила бы цены этих позиций на пятую часть, и увидели бы это по выручке.
 *
 * Многоуровневые цены Dolibarr пользователь планирует включить позже — тогда значение переносится
 * в уровень цен одним запросом. Пока поле просто хранит цифру и видно в карточке.
 *
 * ExtraFields::addExtraField() на PHP 8 падает, если передать пустой массив параметров — передаём ''.
 */
$apply = in_array('--apply', $argv, true);

define('NOREQUIRESOC', 1); define('NOREQUIRETRAN', 1); define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1); define('NOLOGIN', 1);
$_SERVER['DOCUMENT_ROOT'] = 'C:/Dolibarr/htdocs';
require_once 'C:/Dolibarr/htdocs/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';

$ef = new ExtraFields($db);
$ef->fetch_name_optionals_label('product');
$exists = isset($ef->attributes['product']['label']['dealer_price']);
echo $exists ? "поле dealer_price уже есть\n" : "поле dealer_price нужно создать\n";

if (!$exists) {
    if ($apply) {
        $ok = $ef->addExtraField('dealer_price', 'Дилерская цена', 'double', 120, '24,8',
                                 'product', 0, 0, '', '', 1, '', 1, '', 0, 0, '', null, 1);
        if (!$ok) { fwrite(STDERR, "не удалось создать поле: " . $ef->error . "\n"); exit(1); }
        echo "поле создано\n";
    } else {
        echo "(пробный прогон — поле не создано)\n";
    }
}
