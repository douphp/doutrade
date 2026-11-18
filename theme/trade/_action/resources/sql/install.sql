-- trade 主题安装演示数据（仅 theme='trade' 作用域，可重复执行）
-- 由 _init/data/upgrade.sql 迁移生成（box / fragment 已并入 data 表，禁止 DDL）

DELETE FROM dou_file
WHERE module = 'data'
  AND item_id IN (
    SELECT id FROM (SELECT id FROM dou_data WHERE theme = 'trade' AND data_group <> 'miniprogram') t
  );
DELETE FROM dou_data WHERE theme = 'trade' AND data_group <> 'miniprogram';

INSERT INTO dou_data
(parent_code, theme, data_group, data_item, name, code, image, text, link, is_class, is_locked, sort)
VALUES
('', 'trade', 'index', '', '顶部宣传标语', 'welcome', '', 'FREE express international delivery + EASY returns', '', 1, 1, 50),
('welcome', 'trade', 'index', '', 'New Products ON The Shelves', 'new_product', '', 'New products are on the shelves every week', '热门商品 !', 0, 1, 50),
('', 'trade', 'index', '', '关于我们 三图 a', 'about_a', 'czvxtzr.file', '', '', 1, 1, 50),
('about_a', 'trade', 'index', '', '关于我们 三图 b', 'about_b', 'q6pce8m.file', '', '', 0, 1, 50),
('about_a', 'trade', 'index', '', '关于我们 三图 c', 'about_c', '1hgqkiq.file', '', '', 0, 1, 50),
('about_a', 'trade', 'index', '', 'Our Competitive Advantages', 'about_text', '', 'We have established a robust network that spans across multiple continents. Our platform is accessible to customers in over [X] countries, enabling us to tap into diverse markets with varying consumer preferences. This extensive reach allows us to offer a wide range of products that cater to different cultural tastes and demands.', '', 0, 1, 50),
('', 'trade', 'index', '', '物流支付', 'express_payment', '', '', '', 1, 1, 50),
('express_payment', 'trade', 'index', '', 'LOGO', 'express_payment_1', 'hfb2q8a.file', '', '', 0, 1, 50),
('express_payment', 'trade', 'index', '', 'LOGO', 'express_payment_2', 'tsbenw2.file', '', '', 0, 1, 50),
('express_payment', 'trade', 'index', '', 'LOGO', 'express_payment_3', 'fh838xm.file', '', '', 0, 1, 50),
('express_payment', 'trade', 'index', '', 'LOGO', 'express_payment_4', 'mkwi9ky.file', '', '', 0, 1, 50),
('express_payment', 'trade', 'index', '', 'LOGO', 'express_payment_5', 'z5yqcyd.file', '', '', 0, 1, 50),
('express_payment', 'trade', 'index', '', 'LOGO', 'express_payment_6', 'cde79u2.file', '', '', 0, 1, 50);

INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'hfb2q8a.file', 'images/data/trade/1_1744685099559423.png', 'data', d.id, 'main', 537, 0, FROM_UNIXTIME(1744686312), FROM_UNIXTIME(1744685099)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'express_payment_1';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'tsbenw2.file', 'images/data/trade/2_1744685106122285.png', 'data', d.id, 'main', 747, 0, FROM_UNIXTIME(1744686319), FROM_UNIXTIME(1744685106)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'express_payment_2';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'fh838xm.file', 'images/data/trade/3_1744685112225839.png', 'data', d.id, 'main', 1098, 0, FROM_UNIXTIME(1744686328), FROM_UNIXTIME(1744685112)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'express_payment_3';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'mkwi9ky.file', 'images/data/trade/4_1744685118228467.png', 'data', d.id, 'main', 806, 0, FROM_UNIXTIME(1744686347), FROM_UNIXTIME(1744685118)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'express_payment_4';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'z5yqcyd.file', 'images/data/trade/5_1744685140946346.png', 'data', d.id, 'main', 997, 0, FROM_UNIXTIME(1744686359), FROM_UNIXTIME(1744685140)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'express_payment_5';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'cde79u2.file', 'images/data/trade/6_1744685146395836.png', 'data', d.id, 'main', 1039, 0, FROM_UNIXTIME(1744686366), FROM_UNIXTIME(1744685146)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'express_payment_6';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'czvxtzr.file', 'images/data/trade/about_a.jpg', 'data', d.id, 'main', 62979, 0, FROM_UNIXTIME(1745399319), FROM_UNIXTIME(1745399319)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'about_a';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT 'q6pce8m.file', 'images/data/trade/about_b.jpg', 'data', d.id, 'main', 45699, 0, FROM_UNIXTIME(1745399341), FROM_UNIXTIME(1745399341)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'about_b';
INSERT INTO dou_file (number, file, module, item_id, type, size, thumb_size, last_used_at, created_at)
SELECT '1hgqkiq.file', 'images/data/trade/about_c.jpg', 'data', d.id, 'main', 120195, 0, FROM_UNIXTIME(1745399352), FROM_UNIXTIME(1745399352)
FROM dou_data d WHERE d.theme = 'trade' AND d.code = 'about_c';
