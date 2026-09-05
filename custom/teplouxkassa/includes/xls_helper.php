<?php
/**
 * Общие утилиты для выгрузки в Excel — настоящий формат SpreadsheetML (родной XML-формат Excel
 * 2003+), без сторонних библиотек. В отличие от старого трюка "HTML под видом .xls", Excel открывает
 * такой файл БЕЗ предупреждения "формат файла не совпадает с расширением".
 */

/** Экранирование текста для XML (не то же самое, что htmlspecialchars для HTML). */
function xls_esc($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Ячейка со строкой. $mergeAcross — сколько ДОПОЛНИТЕЛЬНЫХ колонок объединить вправо. */
function xls_cell_str($styleId, $value, $mergeAcross = 0)
{
    $merge = $mergeAcross ? " ss:MergeAcross=\"{$mergeAcross}\"" : '';
    return "<Cell ss:StyleID=\"{$styleId}\"{$merge}><Data ss:Type=\"String\">" . xls_esc($value) . "</Data></Cell>";
}

/** Ячейка с числом. */
function xls_cell_num($styleId, $value)
{
    return "<Cell ss:StyleID=\"{$styleId}\"><Data ss:Type=\"Number\">" . xls_esc((string)$value) . "</Data></Cell>";
}

/**
 * Заголовки HTTP для скачивания. ASCII-имя для старых браузеров + RFC5987 filename* с UTF-8 —
 * иначе кириллица в filename= у некоторых браузеров/ОС ломается при сохранении на диск.
 */
function xls_send_headers($asciiName, $utf8Name)
{
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($utf8Name));
}

/** Общий набор стилей — один раз, переиспользуется всеми экспортами. */
function xls_common_styles()
{
    return <<<'XML'
  <Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/></Style>
  <Style ss:ID="SubTitle"><Font ss:Bold="1" ss:Size="12"/></Style>
  <Style ss:ID="Label"><Font ss:Bold="1"/></Style>
  <Style ss:ID="Plain"/>
  <Style ss:ID="Header">
   <Font ss:Bold="1"/>
   <Interior ss:Color="#F0F0F0" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Cell">
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="CellCenter">
   <Alignment ss:Horizontal="Center"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="Money">
   <Alignment ss:Horizontal="Right"/>
   <NumberFormat ss:Format="0.00"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="MoneyBold">
   <Font ss:Bold="1"/>
   <Alignment ss:Horizontal="Right"/>
   <NumberFormat ss:Format="0.00"/>
   <Borders>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
  <Style ss:ID="TotalLabel"><Font ss:Bold="1"/><Alignment ss:Horizontal="Right"/></Style>
  <Style ss:ID="PaidYes"><Font ss:Bold="1" ss:Color="#16A34A"/></Style>
  <Style ss:ID="PaidNo"><Font ss:Bold="1" ss:Color="#DC2626"/></Style>
XML;
}
