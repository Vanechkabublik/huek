-- Демо-данные (загружаются при APP_DEMO=1, если в БД ещё нет пользователей).
-- Пароли: admin@example.com / admin123, ivan@example.com и maria@example.com / user123
SET client_encoding = 'UTF8';
SET TIME ZONE 'Europe/Moscow';

INSERT INTO users (id, email, password_hash, name, phone, role, is_blocked, about, created_at) VALUES
(1, 'admin@example.com', '$2y$10$NBfC6N4GF4g1OGEhwWe4e.N7tSKPR5gGbKLVMGForxFU/llRSYsiq', 'Администратор', '+7 495 000-00-01', 'admin', 0, NULL, NOW() - INTERVAL '120 days'),
(2, 'ivan@example.com',  '$2y$10$zdwm3koYwINJdbiH4OVShOXUhvesC7jCYH6CnUCIxBp08kt.m79CS', 'Иван Петров',    '+7 900 123-45-67', 'user',  0, 'Продаю то, чем сам пользовался: машина, техника, щенки от породистых родителей. На связи с 9 до 21.', NOW() - INTERVAL '75 days'),
(3, 'maria@example.com', '$2y$10$zdwm3koYwINJdbiH4OVShOXUhvesC7jCYH6CnUCIxBp08kt.m79CS', 'Мария Соколова', '+7 911 765-43-21', 'user',  0, 'Недвижимость, мебель и вещи для дома. Отвечаю быстро, показываю по договорённости.', NOW() - INTERVAL '40 days');

INSERT INTO ads (id, user_id, category_id, title, description, price, city, status, rejection_reason, views, created_at, attributes) VALUES
(1, 2, (SELECT id FROM categories WHERE name = 'Транспорт'), 'Toyota Camry 2.5 AT, 2018 г., один владелец',
 'Продаю Toyota Camry в комплектации «Престиж». Куплена у официального дилера, один владелец, пробег 64 000 км — оригинал, подтверждаю историей обслуживания. Все ТО строго по регламенту. Два комплекта резины на дисках, коврики, зимний чехол. Не такси, не битая, не крашена. Торг при осмотре.',
 1850000, 'Москва', 'approved', NULL, 214, NOW() - INTERVAL '3 hours', '{"brand":"Toyota","model":"Camry","year":2018,"mileage":64000,"transmission":"Автомат","fuel":"Бензин","body":"Седан"}'::jsonb),
(2, 3, (SELECT id FROM categories WHERE name = 'Недвижимость'), '2-комнатная квартира, 54 м², рядом с метро',
 'Продаётся светлая двухкомнатная квартира 54 м² на 6 этаже кирпичного дома. Раздельные комнаты, большая кухня 10 м², застеклённый балкон. Свежий косметический ремонт, окна пластиковые, во дворе детская площадка и парковка. 7 минут пешком до метро. Один собственник, юридически чистая, возможна ипотека.',
 9200000, 'Санкт-Петербург', 'approved', NULL, 168, NOW() - INTERVAL '7 hours', '{"type":"Квартира","rooms":2,"area":54,"floor":6}'::jsonb),
(3, 2, (SELECT id FROM categories WHERE name = 'Электроника'), 'iPhone 14 Pro 256 ГБ, серый космос',
 'iPhone 14 Pro на 256 ГБ в цвете «серый космос». Состояние отличное, всегда в чехле и с защитным стеклом, царапин нет. Аккумулятор 92%. Полный комплект: коробка, кабель, документы, чек. Face ID, камеры, все датчики работают без нареканий. Покажу и проверю при встрече.',
 74900, 'Москва', 'approved', NULL, 132, NOW() - INTERVAL '1 days', '{"type":"Смартфон","brand":"Apple","model":"iPhone 14 Pro","condition":"Б/у","warranty":"Нет","memory":256}'::jsonb),
(4, 3, (SELECT id FROM categories WHERE name = 'Одежда'), 'Зимний пуховик женский, размер M',
 'Тёплый пуховик, натуральный пух, до −25 градусов. Размер M (44–46), рост 165–170. Носила один сезон, состояние как новое: молния, капюшон с мехом и карманы целые. Цвет — тёмно-розовый. Продаю, потому что купила другой.',
 4500, 'Казань', 'approved', NULL, 41, NOW() - INTERVAL '1 days', '{"type":"Верхняя одежда","size":"M","condition":"Б/у","gender":"Женская"}'::jsonb),
(5, 2, (SELECT id FROM categories WHERE name = 'Электроника'), 'Ноутбук ASUS ZenBook 14, 16 ГБ / 512 ГБ',
 'Ультрабук ASUS ZenBook 14: Ryzen 7, 16 ГБ ОЗУ, SSD 512 ГБ, OLED-экран 2.8K. Лёгкий (1,2 кг), держит около 10 часов. Идеально для работы и учёбы. Состояние хорошее, есть небольшая потёртость на крышке, на работе не сказывается. Зарядка и чехол в комплекте.',
 52000, 'Екатеринбург', 'approved', NULL, 87, NOW() - INTERVAL '2 days', '{"type":"Ноутбук","brand":"ASUS","model":"ZenBook 14","condition":"Б/у","warranty":"Нет","memory":512}'::jsonb),
(6, 3, (SELECT id FROM categories WHERE name = 'Для дома'), 'Угловой диван-кровать, ткань велюр',
 'Угловой диван с механизмом «дельфин» и ящиком для белья. Спальное место 150×200, ткань велюр графитового цвета, не выцвела, без пятен. Можно ставить углом влево или вправо. Самовывоз, помогу погрузить. В городе есть доставка за отдельную плату.',
 18000, 'Санкт-Петербург', 'approved', NULL, 63, NOW() - INTERVAL '3 days', '{"type":"Мебель","condition":"Б/у","material":"Велюр"}'::jsonb),
(7, 2, (SELECT id FROM categories WHERE name = 'Животные'), 'Щенок лабрадора, девочка, 2 месяца',
 'Продаются щенки лабрадора-ретривера, возраст 2 месяца, окрас палевый. Привиты по возрасту, обработаны от глистов, есть ветеринарный паспорт и документы РКФ. Родители с отличным характером, живут с нами в доме. Приезжайте знакомиться, мама рядом.',
 25000, 'Москва', 'approved', NULL, 305, NOW() - INTERVAL '4 days', '{"kind":"Собаки","breed":"Лабрадор","age":2,"gender":"Девочка","vaccinated":"Да"}'::jsonb),
(8, 3, (SELECT id FROM categories WHERE name = 'Услуги'), 'Ремонт квартир под ключ — цена за м²',
 'Выполняем ремонт квартир и домов под ключ: черновые и чистовые работы, электрика, сантехника, стяжка, плитка, поклейка обоев, натяжные потолки. Своя бригада, гарантия на работы 2 года, договор и смета заранее. Бесплатный выезд замерщика. Цена указана за квадратный метр.',
 3500, 'Казань', 'approved', NULL, 29, NOW() - INTERVAL '6 days', '{"service_type":"Ремонт квартир под ключ","experience":8,"place":"Выезд к клиенту"}'::jsonb),
(9, 2, (SELECT id FROM categories WHERE name = 'Работа'), 'Менеджер по продажам в офис, график 5/2',
 'В компанию требуется менеджер по продажам: работа с входящими обращениями, ведение клиентской базы, заключение договоров. Опыт приветствуется, но возьмём и без опыта — научим. Официальное трудоустройство, оклад + проценты, офис в центре, дружная команда и корпоративное обучение.',
 80000, 'Москва', 'pending', NULL, 0, NOW() - INTERVAL '40 minutes', '{"employment":"Полная","schedule":"5/2","experience":"Без опыта"}'::jsonb),
(10, 3, (SELECT id FROM categories WHERE name = 'Спорт и хобби'), 'Горный велосипед Stels, рама 19", 21 скорость',
 'Горный велосипед в хорошем состоянии: алюминиевая рама 19", дисковые тормоза, передняя амортизационная вилка, 21 скорость. Катались аккуратно, недавно сделано ТО — замена цепи и тросиков. Крылья и фонарик в подарок.',
 22000, 'Санкт-Петербург', 'pending', NULL, 0, NOW() - INTERVAL '2 hours', '{"kind":"Велосипеды","brand":"Stels","condition":"Б/у"}'::jsonb),
(11, 2, (SELECT id FROM categories WHERE name = 'Электроника'), 'СРОЧНО!!! ПРОДАМ IPHONE ДЁШЕВО!!!',
 'Продаю айфон очень дёшево, пишите в личку, всё расскажу. Предоплата на карту, отправлю почтой в любой город, по телефону не отвечаю, только сообщения.',
 5000, 'Москва', 'rejected', 'Заголовок написан капслоком, в описании нет модели и состояния устройства, а условия (предоплата, без звонков) похожи на мошенничество. Опишите конкретную модель, укажите состояние и добавьте реальные фото — и отправьте объявление на проверку снова.', 17, NOW() - INTERVAL '5 days', '{}'::jsonb),
(12, 3, (SELECT id FROM categories WHERE name = 'Услуги'), 'Онлайн-ставки и казино без верификации',
 'Помогаю быстро заработать на ставках и в онлайн-казино, гарантированный выигрыш. Присылайте деньги — вернём вдвое. Подробности в личных сообщениях.',
 1000, 'Казань', 'blocked', 'Объявление нарушает правила сайта: запрещена реклама азартных игр и услуг с признаками финансового мошенничества.', 6, NOW() - INTERVAL '8 days', '{}'::jsonb);

INSERT INTO ad_images (ad_id, filename) VALUES
(1, 'seed_car1.svg'),
(1, 'seed_car2.svg'),
(1, 'seed_car3.svg'),
(2, 'seed_apartment1.svg'),
(2, 'seed_apartment2.svg'),
(3, 'seed_phone1.svg'),
(3, 'seed_phone2.svg'),
(4, 'seed_jacket.svg'),
(5, 'seed_laptop.svg'),
(6, 'seed_sofa.svg'),
(7, 'seed_dog.svg'),
(8, 'seed_tools.svg'),
(9, 'seed_work.svg'),
(10, 'seed_bike.svg'),
(11, 'seed_phone2.svg'),
(12, 'seed_tools.svg');

-- Диалоги и сообщения (часть прочитана, часть нет)
INSERT INTO conversations (ad_id, buyer_id, seller_id, created_at, last_message_at) VALUES
(1, 3, 2, NOW() - INTERVAL '3 hours',  NOW() - INTERVAL '25 minutes'),
(7, 3, 2, NOW() - INTERVAL '1 day',    NOW() - INTERVAL '5 hours'),
(2, 2, 3, NOW() - INTERVAL '6 hours',  NOW() - INTERVAL '2 hours');

INSERT INTO chat_messages (conversation_id, sender_id, body, created_at, read_at)
SELECT c.id, m.sender, m.body, m.created_at, m.read_at FROM (VALUES
 (1, 3, 'Здравствуйте! Машина ещё в продаже? Можно посмотреть в субботу?', NOW() - INTERVAL '3 hours',  NOW() - INTERVAL '2 hours 50 minutes'),
 (1, 2, 'Добрый день! Да, в продаже. В субботу после 12:00 удобно?',        NOW() - INTERVAL '2 hours 40 minutes', NOW() - INTERVAL '2 hours 30 minutes'),
 (1, 3, 'Отлично, подъеду к часу. Торг возможен при осмотре?',               NOW() - INTERVAL '25 minutes', NULL),
 (2, 3, 'Добрый день! Подскажите, щенки ещё есть? Какого окраса остались?', NOW() - INTERVAL '1 day',    NOW() - INTERVAL '23 hours'),
 (2, 2, 'Здравствуйте! Осталась одна девочка, палевая. Могу прислать видео.', NOW() - INTERVAL '5 hours', NULL),
 (3, 2, 'Здравствуйте! Квартира ещё актуальна? Возможна ли ипотека?',        NOW() - INTERVAL '6 hours', NOW() - INTERVAL '5 hours'),
 (3, 3, 'Да, актуальна, ипотека возможна — документы чистые.',               NOW() - INTERVAL '2 hours', NULL)
) AS m(conv, sender, body, created_at, read_at)
JOIN (VALUES (1, 1, 3), (2, 7, 3), (3, 2, 2)) AS k(conv, ad_id, buyer_id) ON k.conv = m.conv
JOIN conversations c ON c.ad_id = k.ad_id AND c.buyer_id = k.buyer_id
ORDER BY m.created_at;

-- Отзывы
INSERT INTO reviews (seller_id, author_id, rating, body, created_at, updated_at) VALUES
(2, 3, 5, 'Всё как в описании, договорились быстро и без лишних вопросов. Рекомендую!', NOW() - INTERVAL '10 days', NOW() - INTERVAL '10 days'),
(3, 2, 4, 'Хорошая квартира, продавец отвечает оперативно. Показ прошёл вовремя.',      NOW() - INTERVAL '4 days',  NOW() - INTERVAL '4 days');

-- Избранное
INSERT INTO favorites (user_id, ad_id, created_at) VALUES
(2, 2, NOW() - INTERVAL '5 hours'), (2, 4, NOW() - INTERVAL '1 day'),
(3, 1, NOW() - INTERVAL '3 hours'), (3, 3, NOW() - INTERVAL '2 days'), (3, 7, NOW() - INTERVAL '1 day');

-- Статистика: уникальные просмотры за последние дни (по числу просмотров объявления)
WITH v AS (
    SELECT a.id AS ad_id, 'vseed' || a.id || '_' || g.n AS viewer_key,
           NOW() - LEAST(NOW() - a.created_at, INTERVAL '13 days') * random() AS ts
    FROM ads a
    CROSS JOIN LATERAL generate_series(1, a.views) AS g(n)
    WHERE a.status = 'approved'
)
INSERT INTO ad_views (ad_id, viewer_key, first_seen, last_seen)
SELECT ad_id, viewer_key, ts, ts FROM v;

INSERT INTO ad_contacts (ad_id, viewer_key, type, created_at)
SELECT a.id, 'vseed' || a.id || '_' || g.n, 'phone', NOW() - LEAST(NOW() - a.created_at, INTERVAL '10 days') * random()
FROM ads a CROSS JOIN LATERAL generate_series(1, GREATEST(1, a.views / 12)) AS g(n)
WHERE a.status = 'approved';

-- Выравниваем счётчики после вставки с явными id
SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT MAX(id) FROM users));
SELECT setval(pg_get_serial_sequence('ads', 'id'),   (SELECT MAX(id) FROM ads));
