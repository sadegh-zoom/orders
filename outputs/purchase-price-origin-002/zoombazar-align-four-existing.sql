-- zoombazar / site1 / prefix wp_. قیمت Orders برای چهار قلم موجود مبناست.
-- فقط اگر قیمت فعلی هنوز برابر خروجی پیش‌نمایش قبلی باشد تغییر می‌کند.
-- قیمت‌های خالی را فایل zoombazar-fill-missing.sql تکمیل می‌کند.
START TRANSACTION;

UPDATE `wp_woocommerce_order_itemmeta` pm
JOIN (
    SELECT 40 AS file_row, 410243 AS source_order_id, 65899 AS source_item_id,
           2220000 AS seen_source_price, 2200000 AS orders_price
    UNION ALL SELECT 41, 410124, 65759, 900000, 860000
    UNION ALL SELECT 53, 411098, 66534, 590000, 560000
    UNION ALL SELECT 56, 411477, 66919, 900000, 860000
) p ON p.source_item_id = pm.order_item_id
JOIN `wp_woocommerce_order_items` oi
  ON oi.order_item_id = p.source_item_id
 AND oi.order_id = p.source_order_id
 AND oi.order_item_type = 'line_item'
LEFT JOIN `wp_woocommerce_order_itemmeta` duplicate_pm
  ON duplicate_pm.order_item_id = pm.order_item_id
 AND duplicate_pm.meta_key = '_zb_torob_purchase_price'
 AND duplicate_pm.meta_id <> pm.meta_id
SET pm.meta_value = CAST(p.orders_price AS CHAR)
WHERE pm.meta_key = '_zb_torob_purchase_price'
  AND duplicate_pm.meta_id IS NULL
  AND CAST(pm.meta_value AS DECIMAL(20,2)) = p.seen_source_price;

COMMIT;
-- phpMyAdmin باید برای UPDATE پیام 4 rows affected نشان دهد.
-- سپس zoombazar-verify.sql باید همهٔ ۱۲۴ قلم را برابر با Orders گزارش کند.
