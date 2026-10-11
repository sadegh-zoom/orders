-- فقط خواندنی؛ برای بررسی سه ردیف نامنطبق در دیتابیس Orders/Central.
-- هیچ قیمت یا متایی را تغییر نمی‌دهد. قیمت‌های فایل عیناً، بدون تبدیل واحد، درج شده‌اند.
WITH file_cases AS (
    SELECT 128 AS file_row, 55467 AS file_order_number, 33819 AS central_order_id,
           '2834/65' AS file_model,
           8900 AS file_purchase, 11115 AS file_sale
    UNION ALL
    SELECT 95, 406345, 31405,
           'کیف کنسول بازی god of war',
           850000, 1671500
    UNION ALL
    SELECT 161, 401176, 30254,
           'brr454',
           5500, 7500
), order_lines AS (
    SELECT f.central_order_id, oi.order_item_id, oi.order_item_name
    FROM file_cases f
    JOIN `35pf_woocommerce_order_items` oi
      ON oi.order_id = f.central_order_id
     AND oi.order_item_type = 'line_item'
), line_counts AS (
    SELECT central_order_id, COUNT(*) AS item_count
    FROM order_lines
    GROUP BY central_order_id
), item_meta AS (
    SELECT l.order_item_id,
           MAX(CASE WHEN im.meta_key = '_company_source_line_item_id'
                    THEN im.meta_value END) AS source_item_id,
           MAX(CASE WHEN im.meta_key = '_qty'
                    THEN CAST(im.meta_value AS DECIMAL(20,4)) END) AS quantity,
           MAX(CASE WHEN im.meta_key = '_line_total'
                    THEN CAST(im.meta_value AS DECIMAL(20,2)) END) AS sale_line_after_discount,
           MAX(CASE WHEN im.meta_key = '_line_subtotal'
                    THEN CAST(im.meta_value AS DECIMAL(20,2)) END) AS sale_line_before_discount,
           MAX(CASE WHEN im.meta_key = '_company_assigned_seller_name'
                    THEN NULLIF(im.meta_value, '') END) AS assigned_agent,
           MAX(CASE WHEN im.meta_key = '_company_tamin_agent_name'
                    THEN NULLIF(im.meta_value, '') END) AS tamin_agent,
           MAX(CASE WHEN im.meta_key = '_zb_torob_purchase_price'
                    THEN im.meta_value END) AS purchase_in_orders
    FROM order_lines l
    LEFT JOIN `35pf_woocommerce_order_itemmeta` im
      ON im.order_item_id = l.order_item_id
     AND im.meta_key IN (
          '_company_source_line_item_id', '_qty', '_line_total', '_line_subtotal',
          '_company_assigned_seller_name', '_company_tamin_agent_name',
          '_zb_torob_purchase_price'
     )
    GROUP BY l.order_item_id
)
SELECT f.file_row AS `ردیف فایل`,
       f.file_order_number AS `شماره سفارش فایل`,
       f.file_model AS `مدل فایل`,
       f.file_purchase AS `قیمت خرید فایل`,
       f.file_sale AS `قیمت فروش فایل`,
       f.central_order_id AS `شماره سفارش Central`,
       COALESCE(c.item_count, 0) AS `تعداد اقلام سفارش`,
       l.order_item_id AS `شناسه قلم Central`,
       im.source_item_id AS `شناسه قلم مبدأ`,
       l.order_item_name AS `نام قلم Orders`,
       im.quantity AS `تعداد از این قلم`,
       COALESCE(im.assigned_agent, im.tamin_agent) AS `agent`,
       im.purchase_in_orders AS `قیمت خرید Orders`,
       CASE WHEN im.quantity > 0
            THEN ROUND(im.sale_line_after_discount / im.quantity, 2) END AS `قیمت فروش هر قلم Orders`,
       CASE WHEN im.quantity > 0
            THEN ROUND(im.sale_line_before_discount / im.quantity, 2) END AS `قیمت فروش قبل تخفیف Orders`,
       CASE WHEN c.item_count = 1
                  AND COALESCE(im.assigned_agent, im.tamin_agent) IS NOT NULL
            THEN 'بله' ELSE 'خیر' END AS `تک قلم با agent`,
       CASE WHEN im.quantity > 0 AND (
                 f.file_sale = ROUND(im.sale_line_after_discount / im.quantity, 2)
              OR f.file_sale * 1000 = ROUND(im.sale_line_after_discount / im.quantity, 2)
            ) THEN 'برابر'
            WHEN l.order_item_id IS NULL THEN 'قلم پیدا نشد'
            ELSE 'متفاوت' END AS `مقایسه قیمت فروش`
FROM file_cases f
LEFT JOIN line_counts c ON c.central_order_id = f.central_order_id
LEFT JOIN order_lines l ON l.central_order_id = f.central_order_id
LEFT JOIN item_meta im ON im.order_item_id = l.order_item_id
ORDER BY f.file_row, l.order_item_id;
