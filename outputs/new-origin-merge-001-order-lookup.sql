-- گزارش فقط خواندنی برای دیتابیس Central در orders.zoombazar.com
-- ورودی: ۱۸۵ شماره سفارش یکتا از ستون B فایل «new-origin-merge-001.xlsx»؛ ردیف‌های تکراری فقط یک‌بار بررسی می‌شوند.
-- هر ردیف خروجی یک قلم سفارش است. شماره های پیدا نشده هم با ستون های خالی می آیند.
-- قیمت خرید از متای ثبت شده روی همان قلم است؛ مبلغ فروش پس از تخفیف و بدون مالیات است.
-- تاریخ ثبت در این گزارش UTC است. اگر پیشوند جدول ها فرق دارد، 35pf_ را تغییر دهید.
-- در بازسازی Central، سفارش آرشیوی به trash می رود و متای فعال مبدأ از آن حذف می شود.
-- برای چنین سفارش هایی، سایت و شناسه مبدأ از _company_rebuild_archive بازیابی می شود.
-- وضعیت پیش از trash از _wp_trash_meta_status و در صورت نبود از آرشیو بازسازی خوانده می شود.
-- ستون های متنی جداول HPOS و قدیمی ممکن است collation متفاوت داشته باشند؛
-- در خروجی CTEهای مشترک، همگی به utf8mb4_unicode_ci یکسان سازی شده اند.
WITH
input_numbers AS (
SELECT 18156 AS order_number
    UNION ALL
SELECT 18413
    UNION ALL
SELECT 18595
    UNION ALL
SELECT 18770
    UNION ALL
SELECT 18862
    UNION ALL
SELECT 18969
    UNION ALL
SELECT 19533
    UNION ALL
SELECT 19535
    UNION ALL
SELECT 19540
    UNION ALL
SELECT 19602
    UNION ALL
SELECT 19749
    UNION ALL
SELECT 19779
    UNION ALL
SELECT 19807
    UNION ALL
SELECT 19811
    UNION ALL
SELECT 19829
    UNION ALL
SELECT 19873
    UNION ALL
SELECT 19954
    UNION ALL
SELECT 20056
    UNION ALL
SELECT 20147
    UNION ALL
SELECT 20211
    UNION ALL
SELECT 20258
    UNION ALL
SELECT 20282
    UNION ALL
SELECT 20305
    UNION ALL
SELECT 20306
    UNION ALL
SELECT 20554
    UNION ALL
SELECT 20745
    UNION ALL
SELECT 20824
    UNION ALL
SELECT 20884
    UNION ALL
SELECT 20948
    UNION ALL
SELECT 20979
    UNION ALL
SELECT 21045
    UNION ALL
SELECT 21242
    UNION ALL
SELECT 21327
    UNION ALL
SELECT 21918
    UNION ALL
SELECT 21942
    UNION ALL
SELECT 22407
    UNION ALL
SELECT 22602
    UNION ALL
SELECT 22796
    UNION ALL
SELECT 22828
    UNION ALL
SELECT 22967
    UNION ALL
SELECT 23504
    UNION ALL
SELECT 23590
    UNION ALL
SELECT 23718
    UNION ALL
SELECT 23781
    UNION ALL
SELECT 23911
    UNION ALL
SELECT 23982
    UNION ALL
SELECT 23992
    UNION ALL
SELECT 24122
    UNION ALL
SELECT 24183
    UNION ALL
SELECT 24361
    UNION ALL
SELECT 24371
    UNION ALL
SELECT 24414
    UNION ALL
SELECT 24433
    UNION ALL
SELECT 24443
    UNION ALL
SELECT 24527
    UNION ALL
SELECT 34507
    UNION ALL
SELECT 34515
    UNION ALL
SELECT 34521
    UNION ALL
SELECT 34526
    UNION ALL
SELECT 34599
    UNION ALL
SELECT 34615
    UNION ALL
SELECT 34711
    UNION ALL
SELECT 34730
    UNION ALL
SELECT 34753
    UNION ALL
SELECT 34779
    UNION ALL
SELECT 48417
    UNION ALL
SELECT 51363
    UNION ALL
SELECT 52468
    UNION ALL
SELECT 52734
    UNION ALL
SELECT 52937
    UNION ALL
SELECT 52964
    UNION ALL
SELECT 53394
    UNION ALL
SELECT 53717
    UNION ALL
SELECT 53949
    UNION ALL
SELECT 54115
    UNION ALL
SELECT 54232
    UNION ALL
SELECT 54395
    UNION ALL
SELECT 54739
    UNION ALL
SELECT 55467
    UNION ALL
SELECT 56024
    UNION ALL
SELECT 56039
    UNION ALL
SELECT 56042
    UNION ALL
SELECT 56161
    UNION ALL
SELECT 56458
    UNION ALL
SELECT 56543
    UNION ALL
SELECT 56549
    UNION ALL
SELECT 56552
    UNION ALL
SELECT 56562
    UNION ALL
SELECT 56585
    UNION ALL
SELECT 57395
    UNION ALL
SELECT 57571
    UNION ALL
SELECT 57640
    UNION ALL
SELECT 57717
    UNION ALL
SELECT 57867
    UNION ALL
SELECT 57874
    UNION ALL
SELECT 57918
    UNION ALL
SELECT 57927
    UNION ALL
SELECT 57957
    UNION ALL
SELECT 58048
    UNION ALL
SELECT 58256
    UNION ALL
SELECT 58263
    UNION ALL
SELECT 58290
    UNION ALL
SELECT 58306
    UNION ALL
SELECT 58328
    UNION ALL
SELECT 58749
    UNION ALL
SELECT 58897
    UNION ALL
SELECT 381822
    UNION ALL
SELECT 385396
    UNION ALL
SELECT 386259
    UNION ALL
SELECT 386290
    UNION ALL
SELECT 386960
    UNION ALL
SELECT 388031
    UNION ALL
SELECT 389769
    UNION ALL
SELECT 390068
    UNION ALL
SELECT 390970
    UNION ALL
SELECT 390977
    UNION ALL
SELECT 392846
    UNION ALL
SELECT 397600
    UNION ALL
SELECT 397619
    UNION ALL
SELECT 397996
    UNION ALL
SELECT 398156
    UNION ALL
SELECT 398700
    UNION ALL
SELECT 398847
    UNION ALL
SELECT 398914
    UNION ALL
SELECT 400363
    UNION ALL
SELECT 400963
    UNION ALL
SELECT 401141
    UNION ALL
SELECT 401176
    UNION ALL
SELECT 401254
    UNION ALL
SELECT 401289
    UNION ALL
SELECT 401641
    UNION ALL
SELECT 402363
    UNION ALL
SELECT 402400
    UNION ALL
SELECT 402411
    UNION ALL
SELECT 402420
    UNION ALL
SELECT 402481
    UNION ALL
SELECT 402674
    UNION ALL
SELECT 402712
    UNION ALL
SELECT 402797
    UNION ALL
SELECT 403297
    UNION ALL
SELECT 403588
    UNION ALL
SELECT 404346
    UNION ALL
SELECT 404602
    UNION ALL
SELECT 404615
    UNION ALL
SELECT 404643
    UNION ALL
SELECT 405058
    UNION ALL
SELECT 405244
    UNION ALL
SELECT 405396
    UNION ALL
SELECT 405440
    UNION ALL
SELECT 405517
    UNION ALL
SELECT 406018
    UNION ALL
SELECT 406090
    UNION ALL
SELECT 406254
    UNION ALL
SELECT 406312
    UNION ALL
SELECT 406319
    UNION ALL
SELECT 406345
    UNION ALL
SELECT 406406
    UNION ALL
SELECT 406417
    UNION ALL
SELECT 406443
    UNION ALL
SELECT 406505
    UNION ALL
SELECT 407250
    UNION ALL
SELECT 408241
    UNION ALL
SELECT 408452
    UNION ALL
SELECT 409169
    UNION ALL
SELECT 409176
    UNION ALL
SELECT 409624
    UNION ALL
SELECT 409632
    UNION ALL
SELECT 409634
    UNION ALL
SELECT 409635
    UNION ALL
SELECT 410097
    UNION ALL
SELECT 410643
    UNION ALL
SELECT 410772
    UNION ALL
SELECT 411151
    UNION ALL
SELECT 411290
    UNION ALL
SELECT 411410
    UNION ALL
SELECT 411749
    UNION ALL
SELECT 411866
    UNION ALL
SELECT 411888
    UNION ALL
SELECT 412136
    UNION ALL
SELECT 412367
    UNION ALL
SELECT 412382
    UNION ALL
SELECT 412400
    UNION ALL
SELECT 412861
    UNION ALL
SELECT 412929
    UNION ALL
SELECT 413025
),
storage_mode AS (
    SELECT EXISTS (
        SELECT 1 FROM `35pf_options`
        WHERE option_name = 'woocommerce_custom_orders_table_enabled'
          AND option_value = 'yes'
    ) AS hpos
),
hpos_orders AS (
    SELECT o.id AS central_order_id,
           CONVERT(o.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS order_status,
           o.date_created_gmt AS created_gmt
    FROM input_numbers x
    JOIN `35pf_wc_orders` o ON o.id = x.order_number AND o.type = 'shop_order'
    WHERE (SELECT hpos FROM storage_mode) = 1
    UNION
    SELECT o.id, CONVERT(o.status USING utf8mb4) COLLATE utf8mb4_unicode_ci, o.date_created_gmt
    FROM input_numbers x
    JOIN `35pf_wc_orders_meta` sm
      ON sm.meta_key = '_company_source_order_id'
     AND CAST(sm.meta_value AS UNSIGNED) = x.order_number
    JOIN `35pf_wc_orders` o ON o.id = sm.order_id AND o.type = 'shop_order'
    WHERE (SELECT hpos FROM storage_mode) = 1
),
legacy_orders AS (
    SELECT p.ID AS central_order_id,
           CONVERT(p.post_status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS order_status,
           p.post_date_gmt AS created_gmt
    FROM input_numbers x
    JOIN `35pf_posts` p ON p.ID = x.order_number AND p.post_type = 'shop_order'
    WHERE (SELECT hpos FROM storage_mode) = 0
    UNION
    SELECT p.ID, CONVERT(p.post_status USING utf8mb4) COLLATE utf8mb4_unicode_ci, p.post_date_gmt
    FROM input_numbers x
    JOIN `35pf_postmeta` sm
      ON sm.meta_key = '_company_source_order_id'
     AND CAST(sm.meta_value AS UNSIGNED) = x.order_number
    JOIN `35pf_posts` p ON p.ID = sm.post_id AND p.post_type = 'shop_order'
    WHERE (SELECT hpos FROM storage_mode) = 0
),
orders_found AS (
    SELECT * FROM hpos_orders
    UNION ALL
    SELECT * FROM legacy_orders
),
meta_rows AS (
    SELECT m.order_id,
           CONVERT(m.meta_key USING utf8mb4) COLLATE utf8mb4_unicode_ci AS meta_key,
           CONVERT(m.meta_value USING utf8mb4) COLLATE utf8mb4_unicode_ci AS meta_value
    FROM orders_found o
    JOIN `35pf_wc_orders_meta` m ON m.order_id = o.central_order_id
    WHERE (SELECT hpos FROM storage_mode) = 1
      AND m.meta_key IN ('_company_source_order_id', '_company_source_order_number', '_company_source_store', '_company_rebuild_archive', '_wp_trash_meta_status')
    UNION ALL
    SELECT m.post_id,
           CONVERT(m.meta_key USING utf8mb4) COLLATE utf8mb4_unicode_ci,
           CONVERT(m.meta_value USING utf8mb4) COLLATE utf8mb4_unicode_ci
    FROM orders_found o
    JOIN `35pf_postmeta` m ON m.post_id = o.central_order_id
    WHERE (SELECT hpos FROM storage_mode) = 0
      AND m.meta_key IN ('_company_source_order_id', '_company_source_order_number', '_company_source_store', '_company_rebuild_archive', '_wp_trash_meta_status')
),
raw_mapping AS (
    SELECT o.central_order_id, o.order_status, o.created_gmt,
           MAX(CASE WHEN m.meta_key = '_company_source_order_id' THEN CAST(m.meta_value AS UNSIGNED) END) AS source_order_id,
           MAX(CASE WHEN m.meta_key = '_company_source_order_number' THEN m.meta_value END) AS source_order_number,
           MAX(CASE WHEN m.meta_key = '_company_source_store' THEN m.meta_value END) AS source_site,
           MAX(CASE WHEN m.meta_key = '_company_rebuild_archive' THEN m.meta_value END) AS rebuild_archive,
           MAX(CASE WHEN m.meta_key = '_wp_trash_meta_status' THEN m.meta_value END) AS trash_previous_status
    FROM orders_found o
    LEFT JOIN meta_rows m ON m.order_id = o.central_order_id
    GROUP BY o.central_order_id, o.order_status, o.created_gmt
),
order_mapping AS (
    SELECT central_order_id, order_status, created_gmt,
           COALESCE(source_order_id,
               CASE WHEN LOCATE('s:9:"source_id";i:', rebuild_archive) > 0
                    THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(rebuild_archive, 's:9:"source_id";i:', -1), ';', 1) AS UNSIGNED)
               END) AS source_order_id,
           source_order_number,
           COALESCE(NULLIF(source_site, ''),
               CASE WHEN LOCATE('s:5:"store";s:', rebuild_archive) > 0
                    THEN SUBSTRING_INDEX(
                             SUBSTRING_INDEX(
                                 SUBSTRING_INDEX(rebuild_archive, 's:5:"store";s:', -1),
                                 '";', 1),
                             ':"', -1)
               END) AS source_site,
           CASE WHEN rebuild_archive IS NOT NULL THEN 'آرشیو بازسازی'
                WHEN source_order_id IS NOT NULL AND NULLIF(source_site, '') IS NOT NULL THEN 'متای فعال'
                ELSE 'ثبت نشده' END AS mapping_source,
           COALESCE(NULLIF(trash_previous_status, ''),
               CASE WHEN LOCATE('s:6:"status";s:', rebuild_archive) > 0
                    THEN SUBSTRING_INDEX(
                             SUBSTRING_INDEX(
                                 SUBSTRING_INDEX(rebuild_archive, 's:6:"status";s:', -1),
                                 '";', 1),
                             ':"', -1)
               END) AS previous_status
    FROM raw_mapping
),
matched AS (
    SELECT x.order_number AS input_order_number, o.*,
           CASE WHEN x.order_number = o.central_order_id THEN 'central'
                ELSE 'source' END AS input_order_type
    FROM input_numbers x
    JOIN order_mapping o
      ON x.order_number = o.central_order_id
      OR x.order_number = o.source_order_id
),
match_counts AS (
    SELECT input_order_number, COUNT(*) AS matching_central_orders
    FROM matched
    GROUP BY input_order_number
),
line_items AS (
    SELECT m.central_order_id, i.order_item_id, i.order_item_name
    FROM matched m
    JOIN `35pf_woocommerce_order_items` i
      ON i.order_id = m.central_order_id AND i.order_item_type = 'line_item'
    GROUP BY m.central_order_id, i.order_item_id, i.order_item_name
),
item_values AS (
    SELECT li.central_order_id, li.order_item_id, li.order_item_name,
           MAX(CASE WHEN im.meta_key = '_company_source_line_item_id'
                    THEN CAST(im.meta_value AS UNSIGNED) END) AS source_line_item_id,
           MAX(CASE WHEN im.meta_key = '_qty' THEN CAST(im.meta_value AS DECIMAL(20,4)) END) AS quantity,
           MAX(CASE WHEN im.meta_key = '_line_total' THEN CAST(im.meta_value AS DECIMAL(20,2)) END) AS sales_line_total,
           MAX(CASE WHEN im.meta_key = '_line_subtotal' THEN CAST(im.meta_value AS DECIMAL(20,2)) END) AS sales_line_subtotal,
           MAX(CASE WHEN im.meta_key = '_zb_torob_purchase_price' AND im.meta_value REGEXP '^[0-9]+(\\.[0-9]+)?$'
                    THEN CAST(im.meta_value AS DECIMAL(20,2)) END) AS purchase_price_per_item,
           MAX(CASE WHEN im.meta_key = '_company_assigned_seller_name' THEN NULLIF(im.meta_value, '') END) AS agent_name,
           MAX(CASE WHEN im.meta_key = '_company_assigned_seller_login' THEN NULLIF(im.meta_value, '') END) AS agent_login
    FROM line_items li
    LEFT JOIN `35pf_woocommerce_order_itemmeta` im ON im.order_item_id = li.order_item_id
      AND im.meta_key IN ('_company_source_line_item_id', '_qty', '_line_total', '_line_subtotal', '_zb_torob_purchase_price',
                          '_company_assigned_seller_name', '_company_assigned_seller_login')
    GROUP BY li.central_order_id, li.order_item_id, li.order_item_name
)
SELECT x.order_number AS `شماره سفارش در فایل`,
       CASE WHEN m.central_order_id IS NULL THEN 'پیدا نشد'
            WHEN mc.matching_central_orders > 1 THEN 'چند تطبیق؛ نیازمند بررسی'
            WHEN m.mapping_source = 'آرشیو بازسازی' THEN 'Central آرشیوی'
            WHEN m.input_order_type = 'central' THEN 'Central'
            ELSE 'مبدأ' END AS `نوع تطبیق`,
       m.central_order_id AS `شماره سفارش Central`,
       m.source_order_id AS `شناسه سفارش مبدأ`,
       COALESCE(NULLIF(m.source_order_number, ''), CAST(m.source_order_id AS CHAR)) AS `شماره سفارش مبدأ`,
       m.source_site AS `سایت مبدأ`,
       m.mapping_source AS `منبع اطلاعات مبدأ`,
       REPLACE(m.order_status, 'wc-', '') AS `وضعیت سفارش`,
       CASE REPLACE(m.order_status, 'wc-', '')
            WHEN 'pending' THEN 'در انتظار پرداخت'
            WHEN 'processing' THEN 'در حال انجام'
            WHEN 'on-hold' THEN 'در انتظار بررسی'
            WHEN 'completed' THEN 'تکمیل‌شده'
            WHEN 'cancelled' THEN 'لغوشده'
            WHEN 'refunded' THEN 'بازپرداخت‌شده'
            WHEN 'failed' THEN 'ناموفق'
            WHEN 'trash' THEN 'زباله‌دان'
            ELSE REPLACE(m.order_status, 'wc-', '')
       END AS `وضعیت سفارش (فارسی)`,
       REPLACE(m.previous_status, 'wc-', '') AS `وضعیت پیش از trash`,
       CASE REPLACE(m.previous_status, 'wc-', '')
            WHEN 'pending' THEN 'در انتظار پرداخت'
            WHEN 'processing' THEN 'در حال انجام'
            WHEN 'on-hold' THEN 'در انتظار بررسی'
            WHEN 'completed' THEN 'تکمیل‌شده'
            WHEN 'cancelled' THEN 'لغوشده'
            WHEN 'refunded' THEN 'بازپرداخت‌شده'
            WHEN 'failed' THEN 'ناموفق'
            ELSE REPLACE(m.previous_status, 'wc-', '')
       END AS `وضعیت پیش از trash (فارسی)`,
       m.created_gmt AS `تاریخ ثبت سفارش UTC`,
       iv.order_item_id AS `شناسه قلم Central`,
       iv.source_line_item_id AS `شناسه قلم مبدأ`,
       iv.order_item_name AS `نام قلم کالا`,
       iv.quantity AS `تعداد`,
       iv.agent_name AS `agent`,
       iv.agent_login AS `شناسه agent`,
       iv.purchase_price_per_item AS `قیمت خرید هر قلم`,
       CASE WHEN iv.quantity > 0 THEN ROUND(iv.sales_line_total / iv.quantity, 2) END AS `قیمت فروش هر قلم پس از تخفیف`,
       CASE WHEN iv.quantity > 0 THEN ROUND(iv.sales_line_subtotal / iv.quantity, 2) END AS `قیمت فروش هر قلم پیش از تخفیف`,
       iv.sales_line_total AS `مبلغ فروش ردیف`
FROM input_numbers x
LEFT JOIN matched m ON m.input_order_number = x.order_number
LEFT JOIN match_counts mc ON mc.input_order_number = x.order_number
LEFT JOIN item_values iv ON iv.central_order_id = m.central_order_id
ORDER BY x.order_number, m.central_order_id, iv.order_item_id;
