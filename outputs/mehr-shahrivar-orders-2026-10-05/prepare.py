import csv
import json
import re
from collections import defaultdict
from pathlib import Path
from openpyxl import load_workbook

root = Path('/home/sadegh-khan/Documents/Sale Marketing - ZB - SP')
out = Path(__file__).with_name('matched.json')

def norm(value):
    return re.sub(r'[^a-z0-9آ-ی]+', '', str(value).lower().replace('ي', 'ی').replace('ك', 'ک'))

def money(value):
    try:
        return float(value) if value not in (None, '', 'NULL') else None
    except ValueError:
        return None

sales = defaultdict(list)
for filename, site in [('zb-sales.csv', 'زوم‌بازار'), ('SP-sales.csv', 'اسمارت‌پیشرو')]:
    with (root / filename).open(encoding='utf-8-sig', newline='') as f:
        for item in csv.DictReader(f):
            item['site'] = site
            item['file'] = filename
            sales[str(item['شماره سفارش']).strip()].append(item)

workbook = load_workbook(root / 'Merge - mehr-shahrivar.xlsx', read_only=True, data_only=True)
rows = []
for sheet in workbook:
    for source_row, values in enumerate(sheet.values, 1):
        if source_row == 1 or values[0] is None:
            continue
        number, product, reported_purchase = values[:3]
        source_id = str(number).strip()
        candidates = sales.get(source_id, []) if source_id.isdigit() else []
        match = None
        status = 'در فایل‌های فروش سایت یافت نشد'
        if not source_id.isdigit():
            status = 'شناسه سفارش غیرعددی؛ تطبیق خودکار ممکن نیست'
        elif len(candidates) == 1:
            match = candidates[0]
            status = 'تطبیق با شماره سفارش'
        elif len(candidates) > 1:
            key = norm(product)
            direct = [c for c in candidates if key and key in norm(c['نام کالا'])]
            if len(direct) == 1:
                match = direct[0]
                status = 'تطبیق با شماره سفارش و مدل کالا'
            else:
                status = 'سفارش چندکالایی؛ ردیف کالا مبهم است'
        rows.append({
            'sheet': sheet.title,
            'row': source_row,
            'reported_id': source_id,
            'reported_product': '' if product is None else str(product),
            'reported_purchase': money(reported_purchase),
            'site': match['site'] if match else (candidates[0]['site'] if candidates else ''),
            'sale_price': money(match['قیمت فروش']) if match else None,
            'central_id': None,
            'source_id': source_id if match else '',
            'agent': match['نام Agent'] if match else '',
            'site_purchase': money(match['قیمت خرید']) if match else None,
            'site_product': match['نام کالا'] if match else '',
            'status': status,
            'source_file': match['file'] if match else '',
            'candidate_count': len(candidates),
        })

out.write_text(json.dumps(rows, ensure_ascii=False), encoding='utf-8')
print(f'rows={len(rows)}; matched={sum(bool(r["source_id"]) for r in rows)}; ambiguous={sum("مبهم" in r["status"] for r in rows)}; unmatched={sum(not r["source_id"] and "مبهم" not in r["status"] for r in rows)}')
