-- فقط خواندنی؛ روی دیتابیس orders.zoombazar.com اجرا شود.
-- اگر پیشوند جداول متفاوت است، 35pf_ را با پیشوند درست جایگزین کنید.
-- وضعیت مطلوب: printed-send (پرینت گرفته شد).
WITH expected AS (
SELECT 'پست' AS file_group, 'site1' AS store_id, 412387 AS source_order_id
UNION ALL
SELECT 'پست', 'site1', 412382
UNION ALL
SELECT 'پست', 'site1', 412376
UNION ALL
SELECT 'پست', 'site1', 412375
UNION ALL
SELECT 'پست', 'site1', 412320
UNION ALL
SELECT 'پست', 'site1', 412262
UNION ALL
SELECT 'پست', 'site1', 412142
UNION ALL
SELECT 'پست', 'site1', 412096
UNION ALL
SELECT 'پست', 'site1', 412011
UNION ALL
SELECT 'پست', 'site1', 412010
UNION ALL
SELECT 'پست', 'site1', 411998
UNION ALL
SELECT 'پست', 'site1', 411985
UNION ALL
SELECT 'پست', 'site1', 411977
UNION ALL
SELECT 'پست', 'site1', 411967
UNION ALL
SELECT 'پست', 'site1', 412378
UNION ALL
SELECT 'پست', 'site2', 58352
UNION ALL
SELECT 'پست', 'site2', 58347
UNION ALL
SELECT 'پست', 'site2', 58345
UNION ALL
SELECT 'پست', 'site2', 58322
UNION ALL
SELECT 'پست', 'site2', 58319
UNION ALL
SELECT 'پست', 'site2', 58318
UNION ALL
SELECT 'پست', 'site2', 58311
UNION ALL
SELECT 'پست', 'site2', 58309
UNION ALL
SELECT 'پست', 'site2', 58308
UNION ALL
SELECT 'پست', 'site2', 58257
UNION ALL
SELECT 'پست', 'site2', 58256
UNION ALL
SELECT 'پست', 'site2', 58246
UNION ALL
SELECT 'پست', 'site2', 58231
UNION ALL
SELECT 'پست', 'site2', 58174
UNION ALL
SELECT 'پست', 'site2', 58149
UNION ALL
SELECT 'پست', 'site2', 58141
UNION ALL
SELECT 'پست', 'site2', 58048
UNION ALL
SELECT 'پست', 'site2', 57987
UNION ALL
SELECT 'پست', 'site2', 57980
UNION ALL
SELECT 'پست', 'site2', 57977
UNION ALL
SELECT 'پست', 'site2', 58388
UNION ALL
SELECT 'پست', 'site2', 58350
UNION ALL
SELECT 'پست', 'site2', 58342
UNION ALL
SELECT 'پست', 'site2', 58302
UNION ALL
SELECT 'تهران', 'site1', 412404
UNION ALL
SELECT 'تهران', 'site1', 412400
UNION ALL
SELECT 'تهران', 'site1', 412395
UNION ALL
SELECT 'تهران', 'site1', 412377
UNION ALL
SELECT 'تهران', 'site1', 412368
UNION ALL
SELECT 'تهران', 'site1', 412302
UNION ALL
SELECT 'تهران', 'site1', 412195
UNION ALL
SELECT 'تهران', 'site2', 58294
UNION ALL
SELECT 'تهران', 'site2', 58290
UNION ALL
SELECT 'تهران', 'site2', 58289
UNION ALL
SELECT 'تهران', 'site2', 58263
UNION ALL
SELECT 'تهران', 'site2', 58262
UNION ALL
SELECT 'تهران', 'site2', 58250
UNION ALL
SELECT 'تهران', 'site2', 58248
UNION ALL
SELECT 'تهران', 'site2', 58161
UNION ALL
SELECT 'تهران', 'site2', 58159
UNION ALL
SELECT 'تهران', 'site2', 57989
UNION ALL
SELECT 'تهران', 'site2', 57973
UNION ALL
SELECT 'تهران', 'site2', 57874
UNION ALL
SELECT 'تیپاکس', 'site1', 412397
UNION ALL
SELECT 'تیپاکس', 'site1', 412393
UNION ALL
SELECT 'تیپاکس', 'site1', 412367
UNION ALL
SELECT 'تیپاکس', 'site2', 58331
UNION ALL
SELECT 'تیپاکس', 'site2', 58328
UNION ALL
SELECT 'تیپاکس', 'site2', 58313
UNION ALL
SELECT 'تیپاکس', 'site2', 58306
UNION ALL
SELECT 'تیپاکس', 'site2', 58097
UNION ALL
SELECT 'تیپاکس', 'site2', 58074
), storage_mode AS (
    SELECT EXISTS (
        SELECT 1 FROM `35pf_options`
        WHERE option_name = 'woocommerce_custom_orders_table_enabled'
          AND option_value = 'yes'
    ) AS hpos
), found AS (
    SELECT s.meta_value AS store_id, CAST(i.meta_value AS UNSIGNED) AS source_order_id,
           o.id AS orders_id, o.status AS order_status
    FROM `35pf_wc_orders_meta` i
    JOIN `35pf_wc_orders_meta` s
      ON s.order_id = i.order_id AND s.meta_key = '_company_source_store'
    JOIN `35pf_wc_orders` o ON o.id = i.order_id AND o.type = 'shop_order'
    WHERE (SELECT hpos FROM storage_mode) = 1
      AND i.meta_key = '_company_source_order_id'
    UNION ALL
    SELECT s.meta_value AS store_id, CAST(i.meta_value AS UNSIGNED) AS source_order_id,
           p.ID AS orders_id, p.post_status AS order_status
    FROM `35pf_postmeta` i
    JOIN `35pf_postmeta` s
      ON s.post_id = i.post_id AND s.meta_key = '_company_source_store'
    JOIN `35pf_posts` p ON p.ID = i.post_id AND p.post_type = 'shop_order'
    WHERE (SELECT hpos FROM storage_mode) = 0
      AND i.meta_key = '_company_source_order_id'
)
SELECT e.file_group, e.store_id, e.source_order_id,
       COUNT(DISTINCT f.orders_id) AS orders_count,
       GROUP_CONCAT(DISTINCT f.orders_id ORDER BY f.orders_id SEPARATOR ', ') AS orders_ids,
       GROUP_CONCAT(DISTINCT f.order_status ORDER BY f.order_status SEPARATOR ', ') AS statuses,
       CASE
         WHEN COUNT(DISTINCT f.orders_id) = 0 THEN 'MISSING'
         WHEN COUNT(DISTINCT f.orders_id) > 1 THEN 'DUPLICATE'
         WHEN SUM(CASE WHEN REPLACE(f.order_status, 'wc-', '') = 'printed-send' THEN 1 ELSE 0 END) = 1 THEN 'OK_PRINTED'
         ELSE 'FOUND_OTHER_STATUS'
       END AS result
FROM expected e
LEFT JOIN found f
  ON f.store_id = e.store_id AND f.source_order_id = e.source_order_id
GROUP BY e.file_group, e.store_id, e.source_order_id
ORDER BY FIELD(e.file_group, 'پست', 'تهران', 'تیپاکس'), e.store_id, e.source_order_id DESC;
