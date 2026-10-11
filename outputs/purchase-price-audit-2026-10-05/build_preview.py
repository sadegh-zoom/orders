import hashlib
import json
from pathlib import Path


base = Path(__file__).parent
data = json.loads((base / 'comparison.json').read_text(encoding='utf-8'))
records = sorted(data['proposals'], key=lambda x: x['central']['central_item_id'])

parts = []
for index, record in enumerate(records):
    item = record['central']
    name_hash = hashlib.sha256(item['name'].encode('utf-8')).hexdigest()
    fields = [item['number'], item['central_order_id'], item['central_item_id'],
              item['source_item_id'], record['price_raw'], record['source_rows'][0]]
    head = 'SELECT ' if index == 0 else 'UNION ALL SELECT '
    aliases = [' AS input_order_number', ' AS central_order_id', ' AS central_item_id',
               ' AS source_item_id', ' AS raw_purchase', ' AS source_file_row'] if index == 0 else [''] * 6
    parts.append(head + ', '.join(str(value) + alias for value, alias in zip(fields, aliases))
                 + ", '" + name_hash + "'" + (' AS title_sha256' if index == 0 else ''))

query = '''-- فقط خواندنی. این خروجی، طرح تغییر قیمت را با داده زنده Orders کنترل می کند.
-- قیمت پیشنهادی برای مقادیر کمتر از ۱۰۰٬۰۰۰، فعلاً فرض «ضربدر ۱۰۰۰» دارد و نباید بدون تأیید اجرا شود.
-- ۱۳۳ قلم تطبیق داده شده؛ ۵ قلم دیگر به دلیل نبود قیمت یا اختلاف نام برای بررسی جدا مانده اند.
WITH proposed AS (
'''+ '\n'.join(parts) + '''
), item_check AS (
    SELECT p.*, oi.order_item_name AS live_item_name,
           CASE WHEN oi.order_item_id IS NULL THEN 0 ELSE 1 END AS item_exists
    FROM proposed p
    LEFT JOIN `35pf_woocommerce_order_items` oi
      ON oi.order_item_id = p.central_item_id
     AND oi.order_id = p.central_order_id
     AND oi.order_item_type = 'line_item'
), meta_check AS (
    SELECT p.central_item_id,
           MAX(CASE WHEN im.meta_key = '_company_source_line_item_id'
                    THEN CAST(im.meta_value AS UNSIGNED) END) AS live_source_item_id,
           SUM(CASE WHEN im.meta_key = '_zb_torob_purchase_price' THEN 1 ELSE 0 END) AS purchase_meta_count,
           MAX(CASE WHEN im.meta_key = '_zb_torob_purchase_price' THEN im.meta_value END) AS live_purchase
    FROM proposed p
    LEFT JOIN `35pf_woocommerce_order_itemmeta` im ON im.order_item_id = p.central_item_id
      AND im.meta_key IN ('_company_source_line_item_id', '_zb_torob_purchase_price')
    GROUP BY p.central_item_id
)
SELECT p.input_order_number AS `شماره سفارش فایل`,
       p.source_file_row AS `ردیف فایل قیمت`,
       p.central_order_id AS `سفارش Central`,
       p.central_item_id AS `شناسه قلم Central`,
       p.source_item_id AS `شناسه قلم مبدأ در خروجی قبلی`,
       m.live_source_item_id AS `شناسه قلم مبدأ اکنون`,
       p.live_item_name AS `نام قلم اکنون`,
       p.raw_purchase AS `قیمت خام فایل`,
       CASE WHEN p.raw_purchase < 100000 THEN p.raw_purchase * 1000 ELSE p.raw_purchase END
           AS `قیمت پیشنهادی با فرض واحد هزار`,
       m.live_purchase AS `قیمت خرید اکنون در Orders`,
       CASE WHEN p.item_exists = 0 THEN 'قلم پیدا نشد'
            WHEN SHA2(CONVERT(p.live_item_name USING utf8mb4), 256) <> p.title_sha256 THEN 'عنوان قلم تغییر کرده'
            WHEN m.live_source_item_id <> p.source_item_id OR m.live_source_item_id IS NULL THEN 'شناسه قلم مبدأ تغییر کرده'
            WHEN m.purchase_meta_count > 1 THEN 'متای قیمت خرید تکراری'
            WHEN m.live_purchase IS NOT NULL AND TRIM(m.live_purchase) <> '' THEN 'قیمت خرید قبلاً ثبت شده'
            WHEN p.raw_purchase < 100000 THEN 'آماده پس از تأیید واحد'
            ELSE 'آماده' END AS `نتیجه کنترل`
FROM item_check p
JOIN meta_check m ON m.central_item_id = p.central_item_id
ORDER BY p.input_order_number, p.central_item_id;
'''
(base / 'purchase-price-preview.sql').write_text(query, encoding='utf-8')
print('preview rows:', len(records), 'bytes:', len(query))
