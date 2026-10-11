from __future__ import annotations

from collections import defaultdict
from pathlib import Path

import openpyxl


SOURCE = Path('/home/sadegh-khan/Documents/Sale Marketing - ZB - SP/new-merged-Orders_Origin-002.xlsx')
OUT = Path(__file__).parent
PURCHASE_KEY = '_zb_torob_purchase_price'
SITES = {'site1': ('zoombazar', 'wp_'), 'site2': ('smartpishro', 'mskh_')}


def missing(value: object) -> bool:
    return value is None or str(value).strip().upper() in ('', 'NULL')


def quote(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


sheet = openpyxl.load_workbook(SOURCE, read_only=True, data_only=True).active
groups: dict[tuple[str, int, int], list[tuple[int, tuple]]] = defaultdict(list)
for file_row, cells in enumerate(sheet.values, 1):
    if file_row == 1 or cells[0] is None:
        continue
    site = str(cells[10])
    if site not in SITES:
        raise ValueError(f'Unknown origin at row {file_row}: {site}')
    source_order_id = int(cells[8])
    source_item_id = int(cells[18])
    groups[(site, source_order_id, source_item_id)].append((file_row, cells))

data_by_site: dict[str, list[dict]] = defaultdict(list)
for (site, source_order_id, source_item_id), group in sorted(groups.items()):
    file_row, cells = group[0]
    raw_price = int(cells[3])
    if raw_price <= 0 or raw_price != cells[3]:
        raise ValueError(f'Invalid file price at row {file_row}: {cells[3]}')
    if raw_price < 100_000 and cells[22] != 'aylar':
        raise ValueError(f'Short price without aylar at row {file_row}')
    central_price = int(cells[23]) if not missing(cells[23]) else raw_price * (1000 if raw_price < 100_000 else 1)
    if central_price <= 0:
        raise ValueError(f'Invalid Central price at row {file_row}')
    identity = (int(cells[7]), int(cells[17]), raw_price, central_price)
    for other_row, other in group[1:]:
        other_raw = int(other[3])
        other_central = int(other[23]) if not missing(other[23]) else other_raw * (1000 if other_raw < 100_000 else 1)
        if (int(other[7]), int(other[17]), other_raw, other_central) != identity:
            raise ValueError(f'Conflicting duplicate source item: {file_row}, {other_row}')
    data_by_site[site].append({
        'file_row': file_row,
        'source_order_id': source_order_id,
        'source_item_id': source_item_id,
        'central_order_id': int(cells[7]),
        'central_item_id': int(cells[17]),
        'target_price': central_price,
        'price_origin': 'Orders قدیمی' if not missing(cells[23]) else 'ثبت جدید Orders',
        'file_model': str(cells[2]),
        'model_mismatch_in_central': int(cells[5] != 'تطبیق شد'),
    })


def derived_sql(records: list[dict], include_model: bool = True) -> str:
    columns = ['file_row', 'source_order_id', 'source_item_id', 'central_order_id',
               'central_item_id', 'target_price', 'price_origin']
    if include_model:
        columns.extend(('file_model', 'model_mismatch_in_central'))
    sql_rows = []
    for i, record in enumerate(records):
        vals = [quote(record[k]) if isinstance(record[k], str) else str(record[k]) for k in columns]
        if i == 0:
            sql_rows.append('SELECT ' + ', '.join(f'{v} AS {k}' for v, k in zip(vals, columns)))
        else:
            sql_rows.append('UNION ALL SELECT ' + ', '.join(vals))
    return '(\n' + '\n'.join(sql_rows) + '\n)'


OUT.mkdir(parents=True, exist_ok=True)
for site, (name, prefix) in SITES.items():
    records = data_by_site[site]
    plan = derived_sql(records)
    items = f'`{prefix}woocommerce_order_items`'
    meta = f'`{prefix}woocommerce_order_itemmeta`'
    item_join = (
        'oi.order_item_id = p.source_item_id\n'
        ' AND oi.order_id = p.source_order_id\n'
        " AND oi.order_item_type = 'line_item'"
    )
    blank = "(pm.meta_value IS NULL OR TRIM(pm.meta_value) = '' OR UPPER(TRIM(pm.meta_value)) = 'NULL')"
    preview = f'''-- فقط خواندنی؛ پیش نمایش قیمت خرید {name} ({site})، پیشوند {prefix}.
-- قیمت هدف از نتیجه تأییدشده Orders گرفته شده است. قیمت موجود متفاوت تغییر نمی‌کند.
WITH proposed AS {plan}, live AS (
    SELECT p.*, oi.order_item_name,
           (SELECT COUNT(*) FROM {meta} pm
             WHERE pm.order_item_id = p.source_item_id AND pm.meta_key = '{PURCHASE_KEY}') AS purchase_meta_count,
           (SELECT MAX(pm.meta_value) FROM {meta} pm
             WHERE pm.order_item_id = p.source_item_id AND pm.meta_key = '{PURCHASE_KEY}') AS current_purchase
    FROM proposed p
    LEFT JOIN {items} oi ON {item_join}
)
SELECT file_row AS `ردیف اکسل`, source_order_id AS `شناسه سفارش مبدأ`,
       source_item_id AS `شناسه قلم مبدأ`, central_order_id AS `سفارش Orders`,
       central_item_id AS `قلم Orders`, file_model AS `مدل فایل`,
       model_mismatch_in_central AS `مدل فایل با Orders نامنطبق بود`,
       order_item_name AS `عنوان قلم در سایت مبدأ`, price_origin AS `منبع قیمت هدف`,
       target_price AS `قیمت هدف`, current_purchase AS `قیمت فعلی مبدأ`,
       purchase_meta_count AS `تعداد متای قیمت`,
       CASE WHEN order_item_name IS NULL THEN 'قلم یا سفارش مبدأ پیدا نشد'
            WHEN purchase_meta_count > 1 THEN 'متای قیمت تکراری؛ رد شد'
            WHEN purchase_meta_count = 0 AND model_mismatch_in_central = 1
                 THEN 'آماده: افزودن قیمت؛ مدل فایل با Orders نامنطبق بود'
            WHEN purchase_meta_count = 0 THEN 'آماده: افزودن قیمت'
            WHEN current_purchase IS NULL OR TRIM(current_purchase) = ''
                 OR UPPER(TRIM(current_purchase)) = 'NULL' THEN 'آماده: تکمیل متای خالی'
            WHEN CAST(current_purchase AS DECIMAL(20,2)) = target_price
                 THEN 'قیمت مبدأ برابر است؛ بدون تغییر'
            ELSE 'قیمت موجود مبدأ متفاوت است؛ بررسی شود' END AS `نتیجه`
FROM live
ORDER BY file_row, source_item_id;
'''
    update = f'''-- {name} ({site})، پیشوند {prefix}. بعد از بررسی پیش نمایش اجرا شود.
-- فقط قیمت خرید خالی هر قلم سفارش مبدأ ثبت می‌شود؛ قیمت موجود حفظ می‌شود.
START TRANSACTION;

UPDATE {meta} pm
JOIN {plan} p ON p.source_item_id = pm.order_item_id
JOIN {items} oi ON {item_join}
LEFT JOIN {meta} duplicate_pm
  ON duplicate_pm.order_item_id = pm.order_item_id
 AND duplicate_pm.meta_key = '{PURCHASE_KEY}' AND duplicate_pm.meta_id <> pm.meta_id
SET pm.meta_value = CAST(p.target_price AS CHAR)
WHERE pm.meta_key = '{PURCHASE_KEY}'
  AND {blank}
  AND duplicate_pm.meta_id IS NULL;

INSERT INTO {meta} (order_item_id, meta_key, meta_value)
SELECT p.source_item_id, '{PURCHASE_KEY}', CAST(p.target_price AS CHAR)
FROM {plan} p
JOIN {items} oi ON {item_join}
WHERE NOT EXISTS (SELECT 1 FROM {meta} pm
                  WHERE pm.order_item_id = p.source_item_id
                    AND pm.meta_key = '{PURCHASE_KEY}');

COMMIT;
-- تعداد ردیف‌های تغییرکرده را از پیام UPDATE و INSERT در phpMyAdmin بخوانید.
'''
    verify_plan = derived_sql(records, include_model=False)
    verify = f'''-- فقط خواندنی؛ کنترل قیمت خرید همهٔ {len(records)} قلم مبدأ در {name} ({site}).
WITH proposed AS {verify_plan}, live AS (
    SELECT p.*, oi.order_item_id AS found_item_id,
           (SELECT COUNT(*) FROM {meta} pm
             WHERE pm.order_item_id = p.source_item_id AND pm.meta_key = '{PURCHASE_KEY}') AS purchase_meta_count,
           (SELECT MAX(pm.meta_value) FROM {meta} pm
             WHERE pm.order_item_id = p.source_item_id AND pm.meta_key = '{PURCHASE_KEY}') AS current_purchase
    FROM proposed p
    LEFT JOIN {items} oi ON {item_join}
), assessed AS (
    SELECT file_row, source_item_id,
           CASE WHEN found_item_id IS NULL THEN 'قلم یا سفارش مبدأ پیدا نشد'
                WHEN purchase_meta_count <> 1 THEN 'تعداد متای قیمت نادرست'
                WHEN CAST(current_purchase AS DECIMAL(20,2)) = target_price
                     THEN 'قیمت مبدأ با Orders برابر است'
                ELSE 'قیمت مبدأ با Orders متفاوت است' END AS result
    FROM live
)
SELECT result AS `نتیجه کنترل`, COUNT(*) AS `تعداد اقلام`,
       GROUP_CONCAT(CASE WHEN result <> 'قیمت مبدأ با Orders برابر است'
                         THEN CONCAT(file_row, ' (', source_item_id, ')') END
                    ORDER BY file_row SEPARATOR ', ') AS `ردیف‌های نیازمند بررسی`
FROM assessed
GROUP BY result
ORDER BY result;
'''
    (OUT / f'{name}-preview.sql').write_text(preview, encoding='utf-8')
    (OUT / f'{name}-fill-missing.sql').write_text(update, encoding='utf-8')
    (OUT / f'{name}-verify.sql').write_text(verify, encoding='utf-8')
    print(name, len(records), 'using previous Orders price:',
          sum(r['price_origin'] == 'Orders قدیمی' for r in records))
