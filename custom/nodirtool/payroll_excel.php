<?php
/**
 * Зарплата за месяц — выгрузка в Excel (04.09.2026). Доступ только Нодиру (page_access в config.php,
 * проверка в auth.php). Тот же SpreadsheetML, что и у остальных выгрузок проекта.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payroll.php';
require_once __DIR__ . '/includes/xls_helper.php';

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

$summary = payroll_month_summary($period);
$byDepartment = payroll_month_by_department($period);
$employees = payroll_get_employees(false);
$balances = payroll_all_balances();

// Движения ЗА ЭТОТ МЕСЯЦ по каждому сотруднику (начисления по периоду, выплаты по дате проведения).
$db = payroll_db();
$p = $db->real_escape_string($period);
$entries = [];
$res = $db->query("SELECT e.*, emp.name AS emp_name FROM llx_nt_payroll_entry e
    JOIN llx_nt_employee emp ON emp.rowid = e.fk_employee
    WHERE (e.entry_type='accrual' AND e.period='$p')
       OR (e.entry_type IN ('advance','payout','adjust') AND DATE_FORMAT(e.datec,'%Y-%m')='$p')
    ORDER BY emp.name, e.datec");
while ($row = $res->fetch_assoc()) { $entries[] = $row; }

$typeLabels = ['accrual' => 'Начислено', 'advance' => 'Аванс', 'payout' => 'Зарплата', 'adjust' => 'Правка'];

xls_send_headers('Payroll_' . $period . '.xls', 'Зарплата_' . $period . '.xls');
?>
<?xml version="1.0" encoding="UTF-8"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
<?= xls_common_styles() ?>
 </Styles>

 <Worksheet ss:Name="Зарплата">
  <Table>
   <Column ss:Width="180"/>
   <Column ss:Width="110"/>
   <Column ss:Width="80"/>
   <Column ss:Width="95"/>
   <Column ss:Width="85"/>
   <Column ss:Width="230"/>
   <Column ss:Width="110"/>

   <Row ss:Height="22"><?= xls_cell_str('Title', 'Теплолюкс — зарплата', 6) ?></Row>
   <Row ss:Height="20"><?= xls_cell_str('SubTitle', 'Месяц: ' . $period, 6) ?></Row>
   <Row/>

   <Row><?= xls_cell_str('Label', 'Начислено за месяц') ?><?= xls_cell_num('Money', number_format($summary['accrued'], 2, '.', '')) ?></Row>
   <Row><?= xls_cell_str('Label', 'Выдано авансами') ?><?= xls_cell_num('Money', number_format($summary['advances'], 2, '.', '')) ?></Row>
   <Row><?= xls_cell_str('Label', 'Выдано зарплатой') ?><?= xls_cell_num('Money', number_format($summary['payouts'], 2, '.', '')) ?></Row>
   <Row><?= xls_cell_str('Label', 'Налогов уплачено') ?><?= xls_cell_num('Money', number_format($summary['tax'], 2, '.', '')) ?></Row>
   <Row/>

   <?php // Разбивка по отделам (04.09.2026) — та же, что на экране. ?>
   <?php if (!empty($byDepartment)): ?>
   <Row ss:Height="18"><?= xls_cell_str('SubTitle', 'По отделам за ' . $period, 6) ?></Row>
   <Row>
    <?= xls_cell_str('Label', 'Отдел') ?><?= xls_cell_str('Label', 'Человек') ?><?= xls_cell_str('Label', 'Начислено, $') ?>
    <?= xls_cell_str('Label', 'Авансами, $') ?><?= xls_cell_str('Label', 'Зарплатой, $') ?><?= xls_cell_str('Label', 'Налоги, $') ?>
   </Row>
   <?php foreach ($byDepartment as $d): ?>
   <Row>
    <?= xls_cell_str('Cell', $d['dept']) ?>
    <?= xls_cell_num('CellCenter', (int)$d['people']) ?>
    <?= xls_cell_num('Money', number_format((float)$d['accrued'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format((float)$d['advances'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format((float)$d['payouts'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format((float)$d['tax'], 2, '.', '')) ?>
   </Row>
   <?php endforeach; ?>
   <Row/>
   <?php endif; ?>

   <Row ss:Height="18"><?= xls_cell_str('SubTitle', 'Кто сколько должен / кому сколько должны', 6) ?></Row>
   <Row>
    <?= xls_cell_str('Label', 'Отдел') ?><?= xls_cell_str('Label', 'Сотрудник') ?><?= xls_cell_str('Label', 'Должность') ?>
    <?= xls_cell_str('Label', 'Оклад, $') ?><?= xls_cell_str('Label', 'Числится за ним, $') ?><?= xls_cell_str('Label', 'Состояние') ?>
   </Row>
   <?php foreach ($employees as $e): ?>
   <?php $bal = $balances[(int)$e['rowid']] ?? 0.0; ?>
   <Row>
    <?= xls_cell_str('Cell', $e['department_name'] ?? 'Без отдела') ?>
    <?= xls_cell_str('Cell', $e['name']) ?>
    <?= xls_cell_str('Cell', $e['position'] ?? '') ?>
    <?= xls_cell_num('Money', number_format((float)$e['salary_usd'], 2, '.', '')) ?>
    <?= xls_cell_num($bal < -0.01 ? 'PaidNo' : ($bal > 0.01 ? 'MoneyBold' : 'Money'), number_format($bal, 2, '.', '')) ?>
    <?= xls_cell_str('Cell', $bal > 0.01 ? 'к выдаче' : ($bal < -0.01 ? 'взял вперёд' : 'рассчитан')) ?>
   </Row>
   <?php endforeach; ?>
   <Row/>

   <Row ss:Height="18"><?= xls_cell_str('SubTitle', 'Движения за ' . $period, 6) ?></Row>
   <Row>
    <?= xls_cell_str('Label', 'Сотрудник') ?><?= xls_cell_str('Label', 'Когда') ?><?= xls_cell_str('Label', 'Что') ?>
    <?= xls_cell_str('Label', 'Сумма, $') ?><?= xls_cell_str('Label', 'Налог, $') ?>
    <?= xls_cell_str('Label', 'Комментарий') ?><?= xls_cell_str('Label', 'Кто провёл') ?>
   </Row>
   <?php if (empty($entries)): ?>
   <Row><?= xls_cell_str('Cell', 'За этот месяц движений не было.', 6) ?></Row>
   <?php endif; ?>
   <?php foreach ($entries as $en): ?>
   <Row>
    <?= xls_cell_str('Cell', $en['emp_name']) ?>
    <?= xls_cell_str('Cell', substr((string)$en['datec'], 0, 16)) ?>
    <?= xls_cell_str('Cell', $typeLabels[$en['entry_type']] ?? $en['entry_type']) ?>
    <?= xls_cell_num('Money', number_format((float)$en['amount_usd'], 2, '.', '')) ?>
    <?= xls_cell_num('Money', number_format((float)$en['tax_usd'], 2, '.', '')) ?>
    <?php
      $c = (string)($en['comment'] ?? '');
      if (!empty($en['native_currency']) && $en['native_currency'] === 'UZS') {
          $c = trim($c . ' (' . number_format((float)$en['native_amount'], 0, '.', ' ') . ' сум по курсу '
              . rtrim(rtrim(number_format((float)$en['rate'], 2, '.', ''), '0'), '.') . ')');
      }
    ?>
    <?= xls_cell_str('Cell', $c) ?>
    <?= xls_cell_str('Cell', $en['who'] ?? '') ?>
   </Row>
   <?php endforeach; ?>
  </Table>
 </Worksheet>
</Workbook>
