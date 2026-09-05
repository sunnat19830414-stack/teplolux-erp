<?php
/**
 * Спецификация к заказу поставщику — тот самый документ, который реально уходит партнёру и на
 * таможню (пункт B1 отчёта «Пробелы NodirTool», 04.09.2026). Раньше закупщик набирал заказ здесь,
 * а потом ЗАНОВО перепечатывал его в Excel («CALEFFI ALL IN ONE.xlsx» и т.п. у каждого поставщика) —
 * двойной ввод и неизбежные расхождения.
 *
 * Формат повторяет реальные файлы Теплолюкс (разобраны «Спецификация_#20_CALEFFI.xlsx» и
 * «Спецификация №28.xlsx» ZILIO): шапка «Приложение № N к контракту № X», заголовок
 * «СПЕЦИФИКАЦИЯ / SPECIFICATION № N», колонки № / Код ТНВЭД / Артикул / НАИМЕНОВАНИЕ ТОВАРА /
 * Кол-во / Ед. / Цена / Сумма, валюта подписана под колонкой суммы, снизу — итог, сумма прописью,
 * страна происхождения и оговорка о новизне товара.
 *
 * Номер спецификации выдаётся АВТОМАТИЧЕСКИ и отдельной последовательностью по каждому поставщику
 * (решение пользователя): у Caleffi своя нумерация, у ZILIO своя. Присвоенный номер запоминается
 * у заказа, поэтому повторное скачивание даёт тот же документ, а не следующий номер.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/xls_helper.php';
require_once __DIR__ . '/includes/num_to_words.php';
require_once __DIR__ . '/includes/currency.php';
require_once __DIR__ . '/includes/product_lookup.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Не указан заказ.');
}

$order = $api->getSupplierOrder($id);
if (!is_array($order)) {
    http_response_code(404);
    die('Заказ не найден.');
}
if (empty($order['lines'])) {
    http_response_code(400);
    die('В заказе нет позиций — спецификацию формировать не из чего.');
}

$socId = (int)($order['socid'] ?? 0);
$soc = $api->getThirdparty($socId);
$socOpts = is_array($soc) ? ($soc['array_options'] ?? []) : [];
$supplierName = is_array($soc) ? ($soc['name'] ?? $soc['nom'] ?? '') : '';

$currency = strtoupper(trim((string)($order['multicurrency_code'] ?? ''))) ?: 'USD';
$isForeign = $currency !== 'USD';

// --- номер и дата спецификации ---
$orderOpts = $order['array_options'] ?? [];
$specNumber = (int)($orderOpts['options_spec_number'] ?? 0);
$specDateTs = !empty($orderOpts['options_spec_date']) ? (int)$orderOpts['options_spec_date'] : 0;

if ($specNumber <= 0) {
    // Первое скачивание — присваиваем следующий номер по этому поставщику и запоминаем его
    // и у заказа, и у поставщика. Дальше номер уже не меняется, сколько бы раз ни скачивали.
    $specNumber = (int)($socOpts['options_spec_last_number'] ?? 0) + 1;
    $specDateTs = time();
    $api->updateSupplierOrderExtrafields($id, ['spec_number' => $specNumber, 'spec_date' => date('Y-m-d', $specDateTs)]);
    $api->updateThirdpartyExtrafields($socId, ['spec_last_number' => $specNumber]);
}
if (!$specDateTs) $specDateTs = time();

$contractNumber = trim((string)($socOpts['options_contract_number'] ?? ''));
$contractDateTs = !empty($socOpts['options_contract_start']) ? (int)$socOpts['options_contract_start'] : 0;
$originText = trim((string)($socOpts['options_origin_text'] ?? ''));
if ($originText === '') $originText = trim((string)($soc['country'] ?? ''));

// --- позиции: ТНВЭД берём из карточки товара (нативное поле customcode) ---
$productIds = array_map(fn($l) => (int)($l['fk_product'] ?? 0), $order['lines']);
$productInfo = get_product_customs_bulk($productIds);   // одним запросом, не по товару за раз

$rows = [];
$totalQty = 0.0;
$totalSum = 0.0;
foreach ($order['lines'] as $l) {
    $productId = (int)($l['fk_product'] ?? 0);
    $info = $productInfo[$productId] ?? null;
    $qty = (float)($l['qty'] ?? 0);
    $price = $isForeign ? (float)($l['multicurrency_subprice'] ?? 0) : (float)($l['subprice'] ?? 0);
    $sum = round($qty * $price, 2);
    $rows[] = [
        'hs' => $info['hs'] ?? '',
        'ref' => $info['ref'] ?? (string)($l['ref'] ?? ''),
        'label' => (string)($l['product_label'] ?? $l['desc'] ?? ''),
        'qty' => $qty,
        'price' => $price,
        'sum' => $sum,
    ];
    $totalQty += $qty;
    $totalSum += $sum;
}
$totalSum = round($totalSum, 2);

$safeName = preg_replace('/[^A-Za-z0-9_-]/', '', $supplierName) ?: 'supplier';
// В режиме «отдать строкой» (вложение к письму, см. includes/spec_render.php) заголовки скачивания
// слать нельзя — идёт обычная страница, а не файл.
if (empty($GLOBALS['SPEC_RETURN_MODE'])) {
    xls_send_headers("Specification_{$specNumber}_{$safeName}.xls", "Спецификация №{$specNumber} {$supplierName}.xls");
}
?>
<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
<?= xls_common_styles() ?>
  <Style ss:ID="SpecHead"><Font ss:Bold="1" ss:Size="13"/><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="ColHead">
   <Font ss:Bold="1"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
   <Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Name"><Alignment ss:Vertical="Top" ss:WrapText="1"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Qty"><Alignment ss:Horizontal="Right"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Price"><Alignment ss:Horizontal="Right"/><NumberFormat ss:Format="0.0000"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Missing"><Alignment ss:Horizontal="Center"/><Font ss:Color="#B45309"/>
   <Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
 </Styles>

 <Worksheet ss:Name="Спецификация">
  <Table>
   <Column ss:Width="30"/>
   <Column ss:Width="85"/>
   <Column ss:Width="95"/>
   <Column ss:Width="360"/>
   <Column ss:Width="65"/>
   <Column ss:Width="45"/>
   <Column ss:Width="70"/>
   <Column ss:Width="85"/>

   <Row>
    <?= xls_cell_str('Plain', 'Приложение / annex № ' . $specNumber . ' от ' . date('d.m.Y', $specDateTs) . ' г.', 5) ?>
    <?= xls_cell_str('Plain', $contractNumber !== ''
          ? 'к контракту / to contract No. ' . $contractNumber . ($contractDateTs ? ' от ' . date('d.m.Y', $contractDateTs) . ' г.' : '')
          : 'к контракту / to contract No. ______') ?>
   </Row>
   <Row><?= xls_cell_str('Plain', 'ФОРМА') ?></Row>
   <Row ss:Height="20"><?= xls_cell_str('SpecHead', 'СПЕЦИФИКАЦИЯ / SPECIFICATION № ' . $specNumber, 7) ?></Row>
   <Row/>

   <Row ss:Height="34">
    <?= xls_cell_str('ColHead', '№') ?>
    <?= xls_cell_str('ColHead', 'Код ТНВЭД /Customs Code UZB') ?>
    <?= xls_cell_str('ColHead', 'Артикул') ?>
    <?= xls_cell_str('ColHead', 'НАИМЕНОВАНИЕ ТОВАРА') ?>
    <?= xls_cell_str('ColHead', 'Кол-во / Quan-tity') ?>
    <?= xls_cell_str('ColHead', 'Ед./ Unit') ?>
    <?= xls_cell_str('ColHead', 'Цена / Unit price') ?>
    <?= xls_cell_str('ColHead', 'Сумма / Total amount') ?>
   </Row>
   <Row>
    <?php for ($i = 0; $i < 7; $i++) echo xls_cell_str('Plain', ''); ?>
    <?= xls_cell_str('Label', '(' . $currency . ')') ?>
   </Row>

   <?php $n = 0; foreach ($rows as $r): $n++; ?>
   <Row>
    <?= xls_cell_num('CellCenter', $n) ?>
    <?php // ТНВЭД не заполнен — не молчим, а подсвечиваем: без него груз не растаможить. ?>
    <?= $r['hs'] !== '' ? xls_cell_str('CellCenter', $r['hs']) : xls_cell_str('Missing', 'нет кода') ?>
    <?= xls_cell_str('Cell', $r['ref']) ?>
    <?= xls_cell_str('Name', $r['label']) ?>
    <?= xls_cell_num('Qty', rtrim(rtrim(number_format($r['qty'], 3, '.', ''), '0'), '.')) ?>
    <?= xls_cell_str('CellCenter', 'шт') ?>
    <?= xls_cell_num('Price', number_format($r['price'], 4, '.', '')) ?>
    <?= xls_cell_num('Money', number_format($r['sum'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>

   <Row>
    <?php for ($i = 0; $i < 4; $i++) echo xls_cell_str('Plain', ''); ?>
    <?= xls_cell_num('MoneyBold', rtrim(rtrim(number_format($totalQty, 3, '.', ''), '0'), '.')) ?>
    <?= xls_cell_str('Plain', '') ?>
    <?= xls_cell_str('Plain', '') ?>
    <?= xls_cell_num('MoneyBold', number_format($totalSum, 2, '.', '')) ?>
   </Row>
   <Row/>
   <Row><?= xls_cell_str('Label', 'Итого: ' . number_format($totalSum, 2, ',', '.')
        . ' (' . ntw_money($totalSum, $currency) . ').', 7) ?></Row>
   <Row/>
   <?php if ($isForeign): ?>
   <Row><?= xls_cell_str('Plain', 'Курс на дату спецификации: 1 доллар = '
        . rtrim(rtrim(number_format((float)($order['multicurrency_tx'] ?? 1), 4, '.', ''), '0'), '.')
        . ' ' . $currency . ' (в долларах ≈ ' . number_format((float)($order['total_ttc'] ?? 0), 2, '.', '') . ')', 7) ?></Row>
   <?php endif; ?>
   <Row/>
   <Row><?= xls_cell_str('Plain', 'Страна происхождения товара и производитель: ' . ($originText !== '' ? $originText : '__________'), 7) ?></Row>
   <Row><?= xls_cell_str('Plain', 'Поставляемые товары по данной спецификации должны быть новыми', 7) ?></Row>
  </Table>
 </Worksheet>
</Workbook>
