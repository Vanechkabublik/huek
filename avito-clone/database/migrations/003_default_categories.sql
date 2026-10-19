-- 003: категории по умолчанию (если их ещё нет) и набор характеристик для каждой категории.
-- Характеристики привязываются по названию категории — на уже существующих БД тоже сработает.

INSERT INTO categories (name, icon)
SELECT v.name, v.icon FROM (VALUES
    ('Транспорт', '🚗'),
    ('Недвижимость', '🏠'),
    ('Электроника', '📱'),
    ('Одежда', '👕'),
    ('Работа', '💼'),
    ('Услуги', '🛠️'),
    ('Животные', '🐶'),
    ('Для дома', '🛋️'),
    ('Спорт и хобби', '⚽')
) AS v(name, icon)
WHERE NOT EXISTS (SELECT 1 FROM categories);

-- Транспорт
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'brand', 'Марка', 'select', NULL, '["Toyota", "Lada (ВАЗ)", "Kia", "Hyundai", "Volkswagen", "BMW", "Mercedes-Benz", "Audi", "Ford", "Nissan", "Skoda", "Renault", "Mazda", "Honda", "Chevrolet", "Другая"]'::jsonb, NULL, NULL, 1, 1, 10
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'model', 'Модель', 'text', NULL, '[]'::jsonb, NULL, NULL, 1, 0, 20
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'year', 'Год выпуска', 'number', NULL, '[]'::jsonb, 1950, 2030, 1, 1, 30
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'mileage', 'Пробег', 'number', 'км', '[]'::jsonb, 0, 2000000, 0, 1, 40
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'transmission', 'Коробка передач', 'select', NULL, '["Механика", "Автомат", "Робот", "Вариатор"]'::jsonb, NULL, NULL, 0, 1, 50
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'fuel', 'Топливо', 'select', NULL, '["Бензин", "Дизель", "Гибрид", "Электро", "Газ"]'::jsonb, NULL, NULL, 0, 1, 60
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'body', 'Тип кузова', 'select', NULL, '["Седан", "Хэтчбек", "Универсал", "Внедорожник", "Купе", "Минивэн", "Пикап", "Другой"]'::jsonb, NULL, NULL, 0, 1, 70
FROM categories c WHERE c.name = 'Транспорт'
ON CONFLICT (category_id, code) DO NOTHING;

-- Недвижимость
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'type', 'Тип', 'select', NULL, '["Квартира", "Дом", "Комната", "Участок", "Коммерческая"]'::jsonb, NULL, NULL, 1, 1, 10
FROM categories c WHERE c.name = 'Недвижимость'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'rooms', 'Комнат', 'number', NULL, '[]'::jsonb, 0, 20, 0, 1, 20
FROM categories c WHERE c.name = 'Недвижимость'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'area', 'Площадь', 'number', 'м²', '[]'::jsonb, 1, 100000, 1, 1, 30
FROM categories c WHERE c.name = 'Недвижимость'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'floor', 'Этаж', 'number', NULL, '[]'::jsonb, 0, 200, 0, 0, 40
FROM categories c WHERE c.name = 'Недвижимость'
ON CONFLICT (category_id, code) DO NOTHING;

-- Электроника
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'type', 'Тип техники', 'select', NULL, '["Смартфон", "Ноутбук", "Планшет", "Телевизор", "Наушники", "Фото и видео", "Игровая консоль", "Другое"]'::jsonb, NULL, NULL, 1, 1, 10
FROM categories c WHERE c.name = 'Электроника'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'brand', 'Бренд', 'text', NULL, '[]'::jsonb, NULL, NULL, 1, 0, 20
FROM categories c WHERE c.name = 'Электроника'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'model', 'Модель', 'text', NULL, '[]'::jsonb, NULL, NULL, 0, 0, 30
FROM categories c WHERE c.name = 'Электроника'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'condition', 'Состояние', 'select', NULL, '["Новое", "Б/у"]'::jsonb, NULL, NULL, 1, 1, 40
FROM categories c WHERE c.name = 'Электроника'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'warranty', 'Гарантия', 'select', NULL, '["Есть", "Нет"]'::jsonb, NULL, NULL, 0, 1, 50
FROM categories c WHERE c.name = 'Электроника'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'memory', 'Память', 'number', 'ГБ', '[]'::jsonb, 1, 100000, 0, 1, 60
FROM categories c WHERE c.name = 'Электроника'
ON CONFLICT (category_id, code) DO NOTHING;

-- Одежда
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'type', 'Вид', 'select', NULL, '["Верхняя одежда", "Обувь", "Платья и юбки", "Брюки и джинсы", "Футболки и рубашки", "Аксессуары", "Другое"]'::jsonb, NULL, NULL, 0, 1, 10
FROM categories c WHERE c.name = 'Одежда'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'size', 'Размер', 'text', NULL, '[]'::jsonb, NULL, NULL, 1, 0, 20
FROM categories c WHERE c.name = 'Одежда'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'brand', 'Бренд', 'text', NULL, '[]'::jsonb, NULL, NULL, 0, 0, 30
FROM categories c WHERE c.name = 'Одежда'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'condition', 'Состояние', 'select', NULL, '["Новое", "Б/у"]'::jsonb, NULL, NULL, 1, 1, 40
FROM categories c WHERE c.name = 'Одежда'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'gender', 'Пол', 'select', NULL, '["Женская", "Мужская", "Детская"]'::jsonb, NULL, NULL, 0, 1, 50
FROM categories c WHERE c.name = 'Одежда'
ON CONFLICT (category_id, code) DO NOTHING;

-- Работа
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'employment', 'Занятость', 'select', NULL, '["Полная", "Частичная", "Удалённая", "Подработка", "Стажировка"]'::jsonb, NULL, NULL, 1, 1, 10
FROM categories c WHERE c.name = 'Работа'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'schedule', 'График', 'select', NULL, '["5/2", "2/2", "Сменный", "Гибкий"]'::jsonb, NULL, NULL, 0, 1, 20
FROM categories c WHERE c.name = 'Работа'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'experience', 'Опыт работы', 'select', NULL, '["Без опыта", "1–3 года", "3–5 лет", "Более 5 лет"]'::jsonb, NULL, NULL, 0, 1, 30
FROM categories c WHERE c.name = 'Работа'
ON CONFLICT (category_id, code) DO NOTHING;

-- Услуги
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'service_type', 'Вид услуги', 'text', NULL, '[]'::jsonb, NULL, NULL, 1, 0, 10
FROM categories c WHERE c.name = 'Услуги'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'experience', 'Опыт', 'number', 'лет', '[]'::jsonb, 0, 60, 0, 1, 20
FROM categories c WHERE c.name = 'Услуги'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'place', 'Где оказывается', 'select', NULL, '["Выезд к клиенту", "У исполнителя", "Онлайн"]'::jsonb, NULL, NULL, 0, 1, 30
FROM categories c WHERE c.name = 'Услуги'
ON CONFLICT (category_id, code) DO NOTHING;

-- Животные
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'kind', 'Вид', 'select', NULL, '["Собаки", "Кошки", "Птицы", "Грызуны", "Рыбки", "Другие"]'::jsonb, NULL, NULL, 1, 1, 10
FROM categories c WHERE c.name = 'Животные'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'breed', 'Порода', 'text', NULL, '[]'::jsonb, NULL, NULL, 0, 0, 20
FROM categories c WHERE c.name = 'Животные'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'age', 'Возраст', 'number', 'мес.', '[]'::jsonb, 0, 600, 0, 1, 30
FROM categories c WHERE c.name = 'Животные'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'gender', 'Пол', 'select', NULL, '["Мальчик", "Девочка"]'::jsonb, NULL, NULL, 0, 1, 40
FROM categories c WHERE c.name = 'Животные'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'vaccinated', 'Привит', 'select', NULL, '["Да", "Нет"]'::jsonb, NULL, NULL, 0, 0, 50
FROM categories c WHERE c.name = 'Животные'
ON CONFLICT (category_id, code) DO NOTHING;

-- Для дома
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'type', 'Тип', 'select', NULL, '["Мебель", "Бытовая техника", "Посуда", "Декор", "Освещение", "Сад и огород", "Инструменты", "Другое"]'::jsonb, NULL, NULL, 0, 1, 10
FROM categories c WHERE c.name = 'Для дома'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'condition', 'Состояние', 'select', NULL, '["Новое", "Б/у"]'::jsonb, NULL, NULL, 1, 1, 20
FROM categories c WHERE c.name = 'Для дома'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'material', 'Материал', 'text', NULL, '[]'::jsonb, NULL, NULL, 0, 0, 30
FROM categories c WHERE c.name = 'Для дома'
ON CONFLICT (category_id, code) DO NOTHING;

-- Спорт и хобби
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'kind', 'Вид', 'select', NULL, '["Велосипеды", "Фитнес", "Туризм", "Зимний спорт", "Игры", "Музыкальные инструменты", "Книги", "Коллекционирование", "Другое"]'::jsonb, NULL, NULL, 0, 1, 10
FROM categories c WHERE c.name = 'Спорт и хобби'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'brand', 'Бренд', 'text', NULL, '[]'::jsonb, NULL, NULL, 0, 0, 20
FROM categories c WHERE c.name = 'Спорт и хобби'
ON CONFLICT (category_id, code) DO NOTHING;
INSERT INTO category_attributes (category_id, code, label, type, unit, options, min_value, max_value, required, filterable, sort_order)
SELECT c.id, 'condition', 'Состояние', 'select', NULL, '["Новое", "Б/у"]'::jsonb, NULL, NULL, 1, 1, 30
FROM categories c WHERE c.name = 'Спорт и хобби'
ON CONFLICT (category_id, code) DO NOTHING;
