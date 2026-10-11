from __future__ import annotations

import hashlib
from collections import defaultdict
from pathlib import Path

import openpyxl


SOURCE = Path('/home/sadegh-khan/Documents/Sale Marketing - ZB - SP/new-merged-Orders_Origin-002.xlsx')
OUT = Path(__file__).parent
PURCHASE_KEY = '_zb_torob_purchase_price'
SOURCE_KEY = '_company_source_line_item_id'


def missing(value: object) -> bool:
    return value is None or str(value).strip().upper() in ('', 'NULL')


sheet = openpyxl.load_workbook(SOURCE, read_only=True, data_only=True).active
groups: dict[int, list[tuple[int, tuple]]] = defaultdict(list)
ambiguous = []
for excel_row, cells in enumerate(sheet.values, 1):
    if excel_row == 1 or cells[0] is None:
        continue
    if cells[5] != 'تطبیق شد':
        ambiguous.append((excel_row, cells[1], cells[17], cells[5]))
    groups[int(cells[17])].append((excel_row, cells))

records = []
for item_id, group in sorted(groups.items()):
    row, cells = group[0]
    core = (cells[7], cells[18], cells[19], cells[3], cells[22], cells[5])
    if any((v[7], v[18], v[19], v[3], v[22], v[5]) != core for _, v in group):
        raise ValueError(f'Conflicting duplicate item {item_id}: {[r for r, _ in group]}')
    raw_price = int(cells[3])
    if raw_price <= 0 or raw_price != cells[3]:
        raise ValueError(f'Invalid purchase price at row {row}: {cells[3]}')
    scaled = raw_price < 100_000
    if scaled and cells[22] != 'aylar':
        raise ValueError(f'Short purchase price is not aylar at row {row}')
    if missing(cells[18]):
        raise ValueError(f'Missing source item id at row {row}')
    title_hash = hashlib.sha256(str(cells[19]).encode('utf-8')).hexdigest()
    records.append({
        'file_row': row,
        'file_order': int(cells[1]),
        'order_id': int(cells[7]),
        'item_id': item_id,
        'source_item_id': int(cells[18]),
        'raw_price': raw_price,
        'price': raw_price * (1000 if scaled else 1),
        'scaled': int(scaled),
        'agent': str(cells[22] if not missing(cells[22]) else cells[21]),
        'title_hash': title_hash,
        'had_price_in_file': int(not missing(cells[23])),
        'needs_single_item': int(cells[5] != 'تطبیق شد'),
        'file_model_if_ambiguous': str(cells[2]) if cells[5] != 'تطبیق شد' else '',
        'old_price_in_file_export': None if missing(cells[23]) else int(cells[23]),
    })


def sql_quote(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


columns = ('file_row', 'file_order', 'order_id', 'item_id', 'source_item_id',
           'raw_price', 'price', 'scaled', 'agent', 'title_hash', 'had_price_in_file',
           'needs_single_item', 'file_model_if_ambiguous')


def row_sql(record: dict) -> str:
    values = [sql_quote(record[k]) if isinstance(record[k], str) else str(record[k]) for k in columns]
    return 'SELECT ' + ', '.join(values)


# MariaDB assigns derived-table column names from the first SELECT.
first = records[0]
first_fields = [sql_quote(first[k]) if isinstance(first[k], str) else str(first[k]) for k in columns]
first_sql = 'SELECT ' + ', '.join(f'{value} AS {name}' for value, name in zip(first_fields, columns))
derived = '\nUNION ALL\n'.join([first_sql, *(row_sql(r) for r in records[1:])])
proposed = f'(\n{derived}\n)'

safe_item = (
    'oi.order_item_id = p.item_id\n'
    ' AND oi.order_id = p.order_id\n'
    " AND oi.order_item_type = 'line_item'\n"
    # Normalize only U+00A0 (non-breaking space) to a regular space.
    " AND BINARY SHA2(REPLACE(oi.order_item_name, '\u00a0', ' '), 256) = BINARY p.title_hash"
)
safe_source = (
    'EXISTS (SELECT 1 FROM `35pf_woocommerce_order_itemmeta` src\n'
    '        WHERE src.order_item_id = p.item_id\n'
    f"          AND src.meta_key = '{SOURCE_KEY}'\n"
    '          AND CAST(src.meta_value AS UNSIGNED) = p.source_item_id)'
)
safe_single_item = (
    '(p.needs_single_item = 0 OR\n'
    ' (SELECT COUNT(*) FROM `35pf_woocommerce_order_items` sibling\n'
    "   WHERE sibling.order_id = p.order_id AND sibling.order_item_type = 'line_item') = 1)"
)
missing_meta = "(pm.meta_value IS NULL OR TRIM(pm.meta_value) = '' OR UPPER(TRIM(pm.meta_value)) = 'NULL')"

preview = f'''-- فقط خواندنی؛ ۱۸۹ قلم یکتا از فایل 002، شامل سه ردیف با اختلاف مدل.
-- قیمت کوتاه فقط برای agent=aylar در فایل در ۱۰۰۰ ضرب شده است.
WITH proposed AS {proposed},
live AS (
    SELECT p.*, oi.order_item_name,
           (SELECT COUNT(*) FROM `35pf_woocommerce_order_items` sibling
             WHERE sibling.order_id = p.order_id AND sibling.order_item_type = 'line_item') AS live_item_count,
           (SELECT COUNT(*) FROM `35pf_woocommerce_order_itemmeta` src
             WHERE src.order_item_id = p.item_id AND src.meta_key = '{SOURCE_KEY}'
               AND CAST(src.meta_value AS UNSIGNED) = p.source_item_id) AS source_match_count,
           (SELECT COUNT(*) FROM `35pf_woocommerce_order_itemmeta` pm
             WHERE pm.order_item_id = p.item_id AND pm.meta_key = '{PURCHASE_KEY}') AS purchase_meta_count,
           (SELECT MAX(pm.meta_value) FROM `35pf_woocommerce_order_itemmeta` pm
             WHERE pm.order_item_id = p.item_id AND pm.meta_key = '{PURCHASE_KEY}') AS live_purchase,
           CASE WHEN oi.order_item_id IS NOT NULL
                     AND BINARY SHA2(REPLACE(oi.order_item_name, '\u00a0', ' '), 256) = BINARY p.title_hash THEN 1 ELSE 0 END AS title_matches
    FROM proposed p
    LEFT JOIN `35pf_woocommerce_order_items` oi
      ON oi.order_item_id = p.item_id AND oi.order_id = p.order_id
     AND oi.order_item_type = 'line_item'
)
SELECT file_row AS `ردیف اکسل`, file_order AS `شماره سفارش فایل`, order_id AS `شماره Central`,
       item_id AS `شناسه قلم Central`, source_item_id AS `شناسه قلم مبدأ`,
       agent AS `agent فایل`, needs_single_item AS `مدل نامنطبق و نیازمند تک‌قلمی`,
       file_model_if_ambiguous AS `مدل فایل در ردیف نامنطبق`,
       live_item_count AS `تعداد اقلام فعلی سفارش`, raw_price AS `قیمت خام فایل`, scaled AS `ضربدر ۱۰۰۰`,
       price AS `قیمت نهایی پیشنهادی`, live_purchase AS `قیمت خرید فعلی Orders`,
       purchase_meta_count AS `تعداد متای قیمت`, order_item_name AS `عنوان فعلی قلم`,
       CASE WHEN order_item_name IS NULL THEN 'قلم یا سفارش پیدا نشد'
            WHEN title_matches = 0 THEN 'عنوان قلم تغییر کرده؛ رد شد'
            WHEN source_match_count = 0 THEN 'شناسه قلم مبدأ تغییر کرده؛ رد شد'
            WHEN needs_single_item = 1 AND live_item_count <> 1 THEN 'مدل نامنطبق و سفارش چندقلمی؛ رد شد'
            WHEN purchase_meta_count > 1 THEN 'متای قیمت تکراری؛ رد شد'
            WHEN purchase_meta_count = 1 AND live_purchase IS NOT NULL
                 AND TRIM(live_purchase) <> '' AND UPPER(TRIM(live_purchase)) <> 'NULL'
                 THEN 'قیمت خرید از قبل موجود؛ بدون تغییر'
            WHEN purchase_meta_count = 1 AND needs_single_item = 1
                 THEN 'آماده: متای خالی؛ مدل فایل متفاوت، سفارش تک‌قلمی'
            WHEN purchase_meta_count = 1 THEN 'آماده: جایگزینی متای خالی'
            WHEN needs_single_item = 1 THEN 'آماده: متای جدید؛ مدل فایل متفاوت، سفارش تک‌قلمی'
            ELSE 'آماده: افزودن متای قیمت' END AS `نتیجه`
FROM live
ORDER BY file_row, item_id;
'''

update = f'''-- پس از بررسی خروجی preview اجرا شود. فقط متای قیمت خرید خالی قلم‌ها تغییر می‌کند.
-- ۳ ردیف با مدل نامنطبق (95، 128، 161) فقط اگر سفارش اکنون تک‌قلمی باشد لحاظ می‌شوند.
-- شناسه سفارش، شناسه قلم، شناسه قلم مبدأ و عنوان فعلی پیش از نوشتن کنترل می‌شوند.
START TRANSACTION;

UPDATE `35pf_woocommerce_order_itemmeta` pm
JOIN {proposed} p ON p.item_id = pm.order_item_id
JOIN `35pf_woocommerce_order_items` oi ON {safe_item}
JOIN `35pf_woocommerce_order_itemmeta` src
  ON src.order_item_id = p.item_id AND src.meta_key = '{SOURCE_KEY}'
 AND CAST(src.meta_value AS UNSIGNED) = p.source_item_id
LEFT JOIN `35pf_woocommerce_order_itemmeta` duplicate_pm
  ON duplicate_pm.order_item_id = pm.order_item_id
 AND duplicate_pm.meta_key = '{PURCHASE_KEY}' AND duplicate_pm.meta_id <> pm.meta_id
SET pm.meta_value = CAST(p.price AS CHAR)
WHERE pm.meta_key = '{PURCHASE_KEY}'
  AND {missing_meta}
  AND duplicate_pm.meta_id IS NULL
  AND {safe_single_item};

INSERT INTO `35pf_woocommerce_order_itemmeta` (order_item_id, meta_key, meta_value)
SELECT p.item_id, '{PURCHASE_KEY}', CAST(p.price AS CHAR)
FROM {proposed} p
JOIN `35pf_woocommerce_order_items` oi ON {safe_item}
WHERE {safe_source}
  AND {safe_single_item}
  AND NOT EXISTS (SELECT 1 FROM `35pf_woocommerce_order_itemmeta` pm
                  WHERE pm.order_item_id = p.item_id AND pm.meta_key = '{PURCHASE_KEY}');

COMMIT;
-- تعداد ردیف‌های درج‌شده در پیام خودِ INSERT نمایش داده می‌شود.
-- برای کنترل ماندگار نتیجه، فایل verify-purchase-prices.sql را اجرا کنید.
'''

verification_columns = ('file_row', 'order_id', 'item_id', 'source_item_id',
                        'price', 'old_price_in_file_export')
verification_lines = []
for i, record in enumerate(records):
    vals = [record[k] for k in verification_columns]
    literals = ['NULL' if value is None else str(value) for value in vals]
    if i == 0:
        verification_lines.append('SELECT ' + ', '.join(
            f'{value} AS {column}' for value, column in zip(literals, verification_columns)))
    else:
        verification_lines.append('UNION ALL SELECT ' + ', '.join(literals))
verification_data = '\n'.join(verification_lines)

verification = f'''-- فقط خواندنی؛ نتیجه ثبت قیمت را با همهٔ ۱۸۹ قلم یکتای فایل 002 مقایسه می‌کند.
-- ۱۶۶ قلمِ بدون قیمت باید قیمت پیشنهادی را داشته باشند؛ ۲۳ قیمت قبلی باید محفوظ باشند.
WITH plan AS (
{verification_data}
), live AS (
    SELECT p.*,
           oi.order_item_id AS found_item_id,
           (SELECT COUNT(*) FROM `35pf_woocommerce_order_itemmeta` src
             WHERE src.order_item_id = p.item_id AND src.meta_key = '{SOURCE_KEY}'
               AND CAST(src.meta_value AS UNSIGNED) = p.source_item_id) AS source_matches,
           (SELECT COUNT(*) FROM `35pf_woocommerce_order_itemmeta` pm
             WHERE pm.order_item_id = p.item_id AND pm.meta_key = '{PURCHASE_KEY}') AS purchase_meta_count,
           (SELECT MAX(pm.meta_value) FROM `35pf_woocommerce_order_itemmeta` pm
             WHERE pm.order_item_id = p.item_id AND pm.meta_key = '{PURCHASE_KEY}') AS live_purchase
    FROM plan p
    LEFT JOIN `35pf_woocommerce_order_items` oi
      ON oi.order_item_id = p.item_id AND oi.order_id = p.order_id
     AND oi.order_item_type = 'line_item'
), assessed AS (
    SELECT file_row, item_id,
           CASE WHEN found_item_id IS NULL THEN 'قلم پیدا نشد'
                WHEN source_matches = 0 THEN 'شناسه قلم مبدأ نامعتبر'
                WHEN purchase_meta_count <> 1 THEN 'تعداد متای قیمت نادرست'
                WHEN old_price_in_file_export IS NULL
                     AND CAST(live_purchase AS DECIMAL(20,2)) = price
                     THEN 'قیمت جدید صحیح ثبت شده'
                WHEN old_price_in_file_export IS NOT NULL
                     AND CAST(live_purchase AS DECIMAL(20,2)) = old_price_in_file_export
                     THEN 'قیمت قبلی حفظ شده'
                ELSE 'مقدار قیمت نیازمند بررسی' END AS result
    FROM live
)
SELECT result AS `نتیجه کنترل`, COUNT(*) AS `تعداد اقلام`,
       GROUP_CONCAT(CASE WHEN result NOT IN ('قیمت جدید صحیح ثبت شده', 'قیمت قبلی حفظ شده')
                         THEN CONCAT(file_row, ' (', item_id, ')') END
                    ORDER BY file_row SEPARATOR ', ') AS `ردیف‌های نیازمند بررسی`
FROM assessed
GROUP BY result
ORDER BY result;
'''

OUT.mkdir(parents=True, exist_ok=True)
(OUT / 'purchase-price-preview.sql').write_text(preview, encoding='utf-8')
(OUT / 'purchase-price-update.sql').write_text(update, encoding='utf-8')
(OUT / 'verify-purchase-prices.sql').write_text(verification, encoding='utf-8')
print({
    'matched_file_rows': sum(map(len, groups.values())),
    'unique_items': len(records),
    'short_prices_scaled': sum(r['scaled'] for r in records),
    'missing_purchase_in_file_export': sum(not r['had_price_in_file'] for r in records),
    'ambiguous_model_rows': ambiguous,
})
