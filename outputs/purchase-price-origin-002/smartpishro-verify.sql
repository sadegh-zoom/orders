-- فقط خواندنی؛ کنترل قیمت خرید همهٔ 65 قلم مبدأ در smartpishro (site2).
WITH proposed AS (
SELECT 32 AS file_row, 44711 AS source_order_id, 4184 AS source_item_id, 20554 AS central_order_id, 3175673 AS central_item_id, 882200 AS target_price, 'ثبت جدید Orders' AS price_origin
UNION ALL SELECT 75, 48417, 4730, 33220, 3319107, 1000000, 'ثبت جدید Orders'
UNION ALL SELECT 7, 50040, 4988, 20745, 3176547, 650000, 'ثبت جدید Orders'
UNION ALL SELECT 4, 50749, 5038, 20884, 3202962, 560000, 'ثبت جدید Orders'
UNION ALL SELECT 167, 51363, 5292, 33360, 3319425, 13300000, 'ثبت جدید Orders'
UNION ALL SELECT 171, 52468, 5698, 33472, 3319685, 1789000, 'ثبت جدید Orders'
UNION ALL SELECT 51, 52734, 5811, 33501, 3319751, 860000, 'ثبت جدید Orders'
UNION ALL SELECT 87, 52937, 5885, 33520, 3319797, 445000, 'ثبت جدید Orders'
UNION ALL SELECT 88, 52937, 5886, 33520, 3319798, 445000, 'ثبت جدید Orders'
UNION ALL SELECT 89, 52964, 5915, 33529, 3319818, 9500000, 'ثبت جدید Orders'
UNION ALL SELECT 91, 53394, 6174, 33590, 3319950, 3600000, 'ثبت جدید Orders'
UNION ALL SELECT 174, 53717, 6392, 33649, 3320073, 19600000, 'ثبت جدید Orders'
UNION ALL SELECT 123, 53949, 6583, 33701, 3320186, 4700000, 'ثبت جدید Orders'
UNION ALL SELECT 176, 54115, 6602, 33707, 3320199, 15500000, 'ثبت جدید Orders'
UNION ALL SELECT 124, 54232, 6625, 33715, 3320212, 4700000, 'ثبت جدید Orders'
UNION ALL SELECT 96, 54395, 6741, 33739, 3320278, 10500000, 'ثبت جدید Orders'
UNION ALL SELECT 126, 54739, 6851, 33765, 3320344, 4200000, 'ثبت جدید Orders'
UNION ALL SELECT 128, 55467, 7097, 33819, 3320489, 8900000, 'ثبت جدید Orders'
UNION ALL SELECT 99, 56024, 7268, 33850, 3320558, 8150000, 'ثبت جدید Orders'
UNION ALL SELECT 100, 56039, 7284, 33865, 3320595, 939000, 'ثبت جدید Orders'
UNION ALL SELECT 177, 56042, 7300, 33868, 3320604, 9100000, 'ثبت جدید Orders'
UNION ALL SELECT 179, 56161, 7533, 33925, 3320732, 19600000, 'ثبت جدید Orders'
UNION ALL SELECT 180, 56458, 7560, 33932, 3320749, 18800000, 'ثبت جدید Orders'
UNION ALL SELECT 103, 56543, 7770, 33975, 3320852, 2057505, 'ثبت جدید Orders'
UNION ALL SELECT 132, 56549, 7792, 33981, 3320868, 3000000, 'ثبت جدید Orders'
UNION ALL SELECT 133, 56552, 7802, 33984, 3320875, 2275000, 'ثبت جدید Orders'
UNION ALL SELECT 39, 56561, 7836, 23718, 3280177, 560000, 'ثبت جدید Orders'
UNION ALL SELECT 134, 56562, 7840, 33994, 3320903, 7300000, 'ثبت جدید Orders'
UNION ALL SELECT 135, 56585, 7871, 34000, 3320917, 13900000, 'ثبت جدید Orders'
UNION ALL SELECT 52, 56627, 7919, 23781, 3280596, 2000000, 'ثبت جدید Orders'
UNION ALL SELECT 54, 57222, 8105, 23982, 3281934, 860000, 'ثبت جدید Orders'
UNION ALL SELECT 137, 57395, 8298, 34095, 3321165, 9200000, 'ثبت جدید Orders'
UNION ALL SELECT 181, 57571, 8378, 34113, 3321208, 4751000, 'ثبت جدید Orders'
UNION ALL SELECT 57, 57584, 8408, 24183, 3283461, 560000, 'ثبت جدید Orders'
UNION ALL SELECT 138, 57640, 8505, 34147, 3321289, 6300000, 'ثبت جدید Orders'
UNION ALL SELECT 182, 57717, 8769, 34221, 3321447, 11700000, 'ثبت جدید Orders'
UNION ALL SELECT 58, 57763, 8864, 24361, 3284614, 4675000, 'ثبت جدید Orders'
UNION ALL SELECT 59, 57777, 8906, 24371, 3284665, 2000000, 'ثبت جدید Orders'
UNION ALL SELECT 60, 57820, 9016, 24414, 3284862, 860000, 'ثبت جدید Orders'
UNION ALL SELECT 61, 57842, 9062, 24433, 3284950, 590000, 'ثبت جدید Orders'
UNION ALL SELECT 62, 57848, 9087, 24443, 3284998, 860000, 'ثبت جدید Orders'
UNION ALL SELECT 184, 57867, 9167, 34319, 3321677, 13900000, 'ثبت جدید Orders'
UNION ALL SELECT 139, 57874, 9219, 34326, 3321693, 6600000, 'ثبت جدید Orders'
UNION ALL SELECT 63, 57910, 9325, 24527, 3285250, 860000, 'ثبت جدید Orders'
UNION ALL SELECT 185, 57918, 9359, 34370, 3321787, 44000000, 'ثبت جدید Orders'
UNION ALL SELECT 186, 57927, 9398, 34379, 3321805, 5150000, 'ثبت جدید Orders'
UNION ALL SELECT 110, 57957, 9507, 34409, 3321870, 989000, 'ثبت جدید Orders'
UNION ALL SELECT 111, 58048, 9644, 34444, 3321950, 989000, 'ثبت جدید Orders'
UNION ALL SELECT 143, 58256, 9837, 34491, 3322061, 4500000, 'ثبت جدید Orders'
UNION ALL SELECT 113, 58263, 9848, 34495, 3322072, 14600000, 'ثبت جدید Orders'
UNION ALL SELECT 188, 58290, 9882, 34503, 3322094, 7490000, 'ثبت جدید Orders'
UNION ALL SELECT 64, 58294, 9901, 34507, 3322105, 560000, 'ثبت جدید Orders'
UNION ALL SELECT 65, 58302, 9936, 34515, 3322124, 4675000, 'ثبت جدید Orders'
UNION ALL SELECT 189, 58306, 9952, 34519, 3322136, 5150000, 'ثبت جدید Orders'
UNION ALL SELECT 66, 58308, 9958, 34521, 3322140, 4675000, 'ثبت جدید Orders'
UNION ALL SELECT 67, 58313, 9982, 34526, 3322151, 860000, 'ثبت جدید Orders'
UNION ALL SELECT 115, 58328, 10045, 34541, 3322187, 989000, 'ثبت جدید Orders'
UNION ALL SELECT 68, 58506, 10205, 34599, 3324570, 2800000, 'Orders قدیمی'
UNION ALL SELECT 69, 58554, 10270, 34615, 3324634, 900000, 'Orders قدیمی'
UNION ALL SELECT 141, 58749, 10374, 34677, 3324863, 7900000, 'Orders قدیمی'
UNION ALL SELECT 70, 58875, 10427, 34711, 3325164, 1450000, 'Orders قدیمی'
UNION ALL SELECT 72, 58888, 10501, 34730, 3325238, 9600000, 'Orders قدیمی'
UNION ALL SELECT 117, 58897, 10532, 34742, 3325294, 2057505, 'Orders قدیمی'
UNION ALL SELECT 73, 58903, 10552, 34753, 3325341, 900000, 'Orders قدیمی'
UNION ALL SELECT 74, 58912, 10589, 34779, 3325468, 590000, 'Orders قدیمی'
), live AS (
    SELECT p.*, oi.order_item_id AS found_item_id,
           (SELECT COUNT(*) FROM `mskh_woocommerce_order_itemmeta` pm
             WHERE pm.order_item_id = p.source_item_id AND pm.meta_key = '_zb_torob_purchase_price') AS purchase_meta_count,
           (SELECT MAX(pm.meta_value) FROM `mskh_woocommerce_order_itemmeta` pm
             WHERE pm.order_item_id = p.source_item_id AND pm.meta_key = '_zb_torob_purchase_price') AS current_purchase
    FROM proposed p
    LEFT JOIN `mskh_woocommerce_order_items` oi ON oi.order_item_id = p.source_item_id
 AND oi.order_id = p.source_order_id
 AND oi.order_item_type = 'line_item'
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
