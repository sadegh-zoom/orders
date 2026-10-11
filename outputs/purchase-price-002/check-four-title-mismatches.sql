-- فقط خواندنی؛ چهار قلمی که در پیش نمایش به علت تغییر عنوان رد شدند.
-- عنوان کامل فعلی و عنوان خروجی اکسل را کنار هم نشان می دهد.
SELECT p.file_row AS `ردیف اکسل`,
       p.order_id AS `شماره سفارش Central`,
       p.item_id AS `شناسه قلم Central`,
       p.source_item_id AS `شناسه قلم مبدأ فایل`,
       MAX(CASE WHEN im.meta_key = '_company_source_line_item_id'
                THEN im.meta_value END) AS `شناسه قلم مبدأ اکنون`,
       p.expected_title AS `عنوان قلم در فایل`,
       oi.order_item_name AS `عنوان کامل فعلی در Orders`,
       CHAR_LENGTH(p.expected_title) AS `طول عنوان فایل`,
       CHAR_LENGTH(oi.order_item_name) AS `طول عنوان فعلی`,
       SHA2(p.expected_title, 256) AS `هش عنوان فایل`,
       SHA2(oi.order_item_name, 256) AS `هش عنوان فعلی`,
       HEX(oi.order_item_name) AS `کد نویسه‌های عنوان فعلی`,
       p.price AS `قیمت خرید پیشنهادی`
FROM (
    SELECT 82 AS file_row, 30719 AS order_id, 3302319 AS item_id, 61892 AS source_item_id,
           'بازی EA Sports FC 26 نسخه استاندارد مخصوص PS5 - گارانتی اصالت و سلامت فیزیکی کالا' AS expected_title,
           3600000 AS price
    UNION ALL SELECT 86, 31079, 3303084, 62774,
           'بازی EA Sports FC 26 نسخه استاندارد مخصوص PS5 - گارانتی اصالت و سلامت فیزیکی کالا', 3600000
    UNION ALL SELECT 91, 33590, 3319950, 6174,
           'بازی EA Sports FC 26 نسخه استاندارد مخصوص PS5 - گارانتی اصالت و سلامت فیزیکی کالا', 3600000
    UNION ALL SELECT 94, 31389, 3303734, 63543,
           'بازی EA Sports FC 26 نسخه استاندارد مخصوص PS5 - گارانتی اصالت و سلامت فیزیکی کالا', 3600000
) p
LEFT JOIN `35pf_woocommerce_order_items` oi
  ON oi.order_item_id = p.item_id AND oi.order_id = p.order_id
 AND oi.order_item_type = 'line_item'
LEFT JOIN `35pf_woocommerce_order_itemmeta` im
  ON im.order_item_id = p.item_id
 AND im.meta_key = '_company_source_line_item_id'
GROUP BY p.file_row, p.order_id, p.item_id, p.source_item_id,
         p.expected_title, oi.order_item_name, p.price
ORDER BY p.file_row;
