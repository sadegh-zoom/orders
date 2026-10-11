-- فقط خواندنی، روی دیتابیس zoombazar با پیشوند wp_ اجرا شود.
-- متای زمان تغییر قیمت خرید را برای چهار قلم دارای اختلاف نمایش می‌دهد.
-- اگر زمان ثبت نشده باشد، این کوئری علت تاریخی اختلاف را مشخص نمی‌کند.
SELECT oi.order_id AS `شناسه سفارش مبدأ`,
       oi.order_item_id AS `شناسه قلم مبدأ`,
       oi.order_item_name AS `عنوان قلم`,
       MAX(CASE WHEN im.meta_key = '_zb_torob_purchase_price'
                THEN im.meta_value END) AS `قیمت خرید فعلی`,
       MAX(CASE WHEN im.meta_key = '_zb_torob_purchase_price_updated_at'
                THEN im.meta_value END) AS `زمان ثبت‌شده برای تغییر قیمت خرید`
FROM `wp_woocommerce_order_items` oi
LEFT JOIN `wp_woocommerce_order_itemmeta` im
  ON im.order_item_id = oi.order_item_id
 AND im.meta_key IN ('_zb_torob_purchase_price', '_zb_torob_purchase_price_updated_at')
WHERE (oi.order_id = 410243 AND oi.order_item_id = 65899)
   OR (oi.order_id = 410124 AND oi.order_item_id = 65759)
   OR (oi.order_id = 411098 AND oi.order_item_id = 66534)
   OR (oi.order_id = 411477 AND oi.order_item_id = 66919)
GROUP BY oi.order_id, oi.order_item_id, oi.order_item_name
ORDER BY oi.order_item_id;
