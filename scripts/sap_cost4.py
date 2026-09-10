# -*- coding: utf-8 -*-
"""Себестоимость из TEPLOLUX_PROD → Dolibarr, сопоставление по коду поставщика.

⚠️ `ItemCode` старой компании (`J0701`, 4 цифры) НЕ совпадает с `kod_sap` в Dolibarr
(`J01449`, 5 цифр) — это разные компании SAP с собственной нумерацией. Общий ключ —
`OITM.ItemName`, то есть код поставщика, он же `ref` в Dolibarr.

Нормализация снимает кириллические двойники латиницы: 79 артикулов каталога ими заражены,
без этого часть совпадений потерялась бы молча.
"""
import io, os, re, sys, json, subprocess
import win32com.client

HOMO = {'С':'C','А':'A','Р':'P','О':'O','Е':'E','М':'M','Т':'T','К':'K','В':'B','Н':'H','Х':'X',
        'с':'c','а':'a','р':'p','о':'o','е':'e','м':'m','т':'t','к':'k','в':'b','н':'h','х':'x'}
def tight(s):
    s = ''.join(HOMO.get(ch, ch) for ch in str(s or ''))
    return re.sub(r'[^0-9a-zа-яё]+', '', s.lower())

c = win32com.client.Dispatch("SAPbobsCOM.Company")
c.Server = "NDB@192.168.0.162:30013"; c.CompanyDB = "TEPLOLUX_PROD"
c.UserName = "Sunnat"; c.Password = "1234"; c.DbServerType = 9; c.UseTrusted = False
if c.Connect() != 0:
    print('FAIL', c.GetLastErrorDescription()); sys.exit(1)
rs = c.GetBusinessObject(300)
def q(sql):
    rs.DoQuery(sql); rows = []
    while not rs.EoF:
        rows.append([rs.Fields.Item(i).Value for i in range(rs.Fields.Count)]); rs.MoveNext()
    return rows

cost_by_code = {}
for code, s, qty in q('''SELECT "ItemCode",
        SUM(CASE WHEN "OnHand" > 0 THEN "AvgPrice" * "OnHand" ELSE 0 END) AS "S",
        SUM(CASE WHEN "OnHand" > 0 THEN "OnHand" ELSE 0 END) AS "Q"
     FROM OITW WHERE "AvgPrice" > 0 GROUP BY "ItemCode"'''):
    if qty and qty > 0:
        cost_by_code[str(code).strip()] = s / qty
for code, a in q('SELECT "ItemCode", AVG("AvgPrice") AS "A" FROM OITW WHERE "AvgPrice" > 0 GROUP BY "ItemCode"'):
    cost_by_code.setdefault(str(code).strip(), a)

items = q('SELECT "ItemCode", "ItemName", "LastPurPrc" FROM OITM')
c.Disconnect()

by_ref = {}
for code, name, lpp in items:
    k = tight(name)
    if not k:
        continue
    by_ref.setdefault(k, []).append((str(code).strip(), float(lpp or 0)))

php = r'''<?php
require_once 'C:\NodirTool\includes\logistics.php';
$db = logistics_db();
$r = $db->query("SELECT p.rowid,p.ref,p.label,p.price,p.pmp,e.kod_sap,e.artikul
   FROM llx_product p LEFT JOIN llx_product_extrafields e ON e.fk_object=p.rowid");
$o=[]; while($x=$r->fetch_assoc()) $o[]=$x;
file_put_contents('C:\PHPTMP\dol_all.json', json_encode($o, JSON_UNESCAPED_UNICODE));'''
io.open(r'C:\PHPTMP\_a.php', 'w', encoding='utf-8').write(php)
subprocess.run([r'C:\PHP82\php.exe', '-f', r'C:\PHPTMP\_a.php'], capture_output=True)
dol = json.load(io.open(r'C:\PHPTMP\dol_all.json', encoding='utf-8'))
os.remove(r'C:\PHPTMP\_a.php')

out = []
def say(s=''): out.append(str(s))
say('в SAP (старая компания) товаров: %d, с себестоимостью: %d' % (len(items), len(cost_by_code)))
say('в Dolibarr товаров: %d' % len(dol))

plan, ambiguous, nocost = [], 0, 0
for d in dol:
    for key in (tight(d['ref']), tight(d.get('artikul') or '')):
        if not key or key not in by_ref:
            continue
        cands = by_ref[key]
        if len(cands) != 1:
            ambiguous += 1
            break
        code, lpp = cands[0]
        cost = cost_by_code.get(code)
        if not cost or cost <= 0:
            nocost += 1
            break
        plan.append({'rowid': int(d['rowid']), 'ref': d['ref'], 'label': d['label'],
                     'price': float(d['price'] or 0), 'pmp_now': float(d['pmp'] or 0),
                     'cost': round(float(cost), 4), 'lastpur': round(lpp, 4), 'sap': code})
        break

say('сопоставлено с себестоимостью: %d' % len(plan))
say('  неоднозначных (несколько товаров SAP на один код): %d' % ambiguous)
say('  нашлись, но себестоимости нет: %d' % nocost)

ratios = sorted(p['cost'] / p['price'] for p in plan if p['price'] > 0)
if ratios:
    say()
    say('отношение себестоимость / цена продажи:')
    say('  медиана %.3f · 10%% %.3f · 90%% %.3f · мин %.3f · макс %.1f'
        % (ratios[len(ratios)//2], ratios[len(ratios)//10], ratios[len(ratios)*9//10], ratios[0], ratios[-1]))
    below = sum(1 for r in ratios if r < 1)
    say('  себестоимость ниже цены: %d из %d (%d%%)' % (below, len(ratios), below*100//len(ratios)))

weird = [p for p in plan if p['price'] > 0 and (p['cost'] / p['price'] > 1.3 or p['cost'] / p['price'] < 0.05)]
say()
say('⚠ подозрительных: %d' % len(weird))
for p in sorted(weird, key=lambda x: -(x['cost']/x['price']))[:10]:
    say('  %-16s %-28s цена %9.2f себест %9.2f ×%.2f'
        % (p['ref'][:16], p['label'][:28], p['price'], p['cost'], p['cost']/p['price']))
say()
say('примеры:')
for p in plan[:8]:
    say('  %-16s %-28s цена %8.2f | себест %8.2f | сейчас pmp %8.2f'
        % (p['ref'][:16], p['label'][:28], p['price'], p['cost'], p['pmp_now']))

json.dump(plan, io.open(r'C:\PHPTMP\sap_cost_plan.json', 'w', encoding='utf-8'), ensure_ascii=False)
io.open(r'C:\PHPTMP\sap_out.txt', 'w', encoding='utf-8').write('\n'.join(out))
print('OK')
