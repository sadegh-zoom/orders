import json
import re
from pathlib import Path

from analyze import read_rows, SOURCE


ROOT = Path(__file__).parent
TEMPLATE = ROOT.parent / 'new-origin-merge-001-order-lookup.sql'
OUTPUT = ROOT.parent / 'new-origin-merge-001-price-comparison.sql'
analysis = json.loads((ROOT / 'analysis.json').read_text(encoding='utf-8'))

file_rows = []
for row, values in read_rows(SOURCE):
    if row > 1 and values.get('B', '').strip():
        file_rows.append({
            'row': row,
            'number': int(values['B']),
            'model': values['C'],
            'purchase': int(float(values['D'])),
            'sale': int(float(values['E'])),
        })

links = []
for plan in analysis['plans']:
    item = plan['db']
    for row in plan['source_rows']:
        links.append((row, item['order_id'], item['item_id'], item['source_item_id']))

# The second row for this order has the same model and purchase price, but a
# different sale price in the source sheet. Retain the row and link the sole item.
links.append((163, 30315, 3301399, 60864))
assert len(file_rows) == 196
assert len(links) == 193
assert len({row for row, *_ in links}) == len(links)
assert len({row['row'] for row in file_rows} - {row for row, *_ in links}) == 3


def hex_utf8(value):
    return "CONVERT(0x" + value.encode('utf-8').hex() + " USING utf8mb4) COLLATE utf8mb4_unicode_ci"


file_selects = []
for index, row in enumerate(file_rows):
    model = hex_utf8(row['model'])
    if index == 0:
        file_selects.append(
            f"SELECT {row['row']} AS file_row, {row['number']} AS order_number, "
            f"{model} AS file_model, {row['purchase']} AS file_purchase, {row['sale']} AS file_sale"
        )
    else:
        file_selects.append(
            f"UNION ALL SELECT {row['row']}, {row['number']}, {model}, {row['purchase']}, {row['sale']}"
        )

link_selects = []
for index, (row, order_id, item_id, source_item_id) in enumerate(sorted(links)):
    if index == 0:
        link_selects.append(
            f'SELECT {row} AS file_row, {order_id} AS central_order_id, '
            f'{item_id} AS central_item_id, {source_item_id} AS source_item_id'
        )
    else:
        link_selects.append(f'UNION ALL SELECT {row}, {order_id}, {item_id}, {source_item_id}')

template = TEMPLATE.read_text(encoding='utf-8')
start = template.index('input_numbers AS (')
end = template.index('),\nstorage_mode AS (', start)
replacement = (
    'file_rows AS (\n' + '\n'.join(file_selects) + '\n),\n'
    'file_item_links AS (\n' + '\n'.join(link_selects) + '\n),\n'
    'input_numbers AS (\nSELECT DISTINCT order_number FROM file_rows\n'
)
sql = template[:start] + replacement + template[end:]
sql = sql.replace(
    '-- ورودی: ۱۸۵ شماره سفارش یکتا از ستون B فایل «new-origin-merge-001.xlsx»؛ ردیف‌های تکراری فقط یک‌بار بررسی می‌شوند.',
    '-- ورودی: تمام ۱۹۶ ردیف دارای سفارش از «new-origin-merge-001.xlsx»؛ قیمت خرید ستون D عیناً حفظ شده است.\n'
    '-- پیوند شناسه قلم از خروجی ادغام‌شدهٔ «new-merged-Orders_Origin-001.xlsx» آمده و با دیتابیس زنده کنترل می‌شود.'
)
sql = sql.replace(
    '-- هر ردیف خروجی یک قلم سفارش است. شماره های پیدا نشده هم با ستون های خالی می آیند.',
    '-- هر ردیف خروجی متناظر با یک ردیف فایل است؛ ۳ ردیف با مدل نامنطبق نیز حذف نمی‌شوند.'
)
sql = sql.replace(
    '-- قیمت خرید از متای ثبت شده روی همان قلم است؛ مبلغ فروش پس از تخفیف و بدون مالیات است.',
    '-- قیمت خرید فایل و قیمت خرید Orders در دو ستون مستقل نمایش داده می‌شوند؛ هیچ واحدی تبدیل نشده است.'
)
marker = 'SELECT x.order_number AS `شماره سفارش در فایل`,'
assert sql.count(marker) == 1
sql = sql.replace(marker,
    'SELECT f.file_row AS `ردیف فایل`,\n'
    '       f.order_number AS `شماره سفارش در فایل`,\n'
    '       f.file_model AS `مدل کالا در فایل`,\n'
    '       f.file_purchase AS `قیمت خرید فایل`,\n'
    '       f.file_sale AS `قیمت فروش فایل`,\n'
    "       CASE WHEN lk.file_row IS NULL THEN 'مدل فایل با قلم Orders تطبیق قطعی ندارد'\n"
    "            WHEN iv.order_item_id IS NULL THEN 'شناسه قلم در Orders تغییر کرده یا یافت نشد'\n"
    "            ELSE 'تطبیق شد' END AS `وضعیت تطبیق قلم`,"
)
sql = sql.replace('iv.purchase_price_per_item AS `قیمت خرید هر قلم`,',
                  'iv.purchase_price_per_item AS `قیمت خرید Orders`,')
old_tail = '''FROM input_numbers x
LEFT JOIN matched m ON m.input_order_number = x.order_number
LEFT JOIN match_counts mc ON mc.input_order_number = x.order_number
LEFT JOIN item_values iv ON iv.central_order_id = m.central_order_id
ORDER BY x.order_number, m.central_order_id, iv.order_item_id;'''
new_tail = '''FROM file_rows f
LEFT JOIN file_item_links lk ON lk.file_row = f.file_row
LEFT JOIN matched m ON m.input_order_number = f.order_number
  AND (lk.central_order_id IS NULL OR m.central_order_id = lk.central_order_id)
LEFT JOIN match_counts mc ON mc.input_order_number = f.order_number
LEFT JOIN item_values iv ON iv.central_order_id = lk.central_order_id
  AND iv.order_item_id = lk.central_item_id
  AND iv.source_line_item_id = lk.source_item_id
ORDER BY f.file_row, m.central_order_id, iv.order_item_id;'''
assert sql.count(old_tail) == 1
sql = sql.replace(old_tail, new_tail)
OUTPUT.write_text(sql, encoding='utf-8')
print('created', OUTPUT, 'file rows', len(file_rows), 'linked rows', len(links),
      'unlinked rows', len(file_rows) - len(links))
