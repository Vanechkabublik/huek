-- Схема БД доски объявлений (MySQL 8 / utf8mb4).
-- Выполняется автоматически при первом старте контейнера db.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Пользователи --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name          VARCHAR(100) NOT NULL,
    phone         VARCHAR(32)  NULL,                          -- показывается по кнопке «Показать телефон»
    role          ENUM('user','admin') NOT NULL DEFAULT 'user',
    is_blocked    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Категории -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name  VARCHAR(80)  NOT NULL,
    icon  VARCHAR(16)  NOT NULL DEFAULT '📦',                 -- эмодзи для полосы категорий
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Объявления ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ads (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    category_id      INT UNSIGNED NULL,                        -- NULL, если категорию удалили
    title            VARCHAR(120) NOT NULL,
    description      TEXT         NOT NULL,
    price            BIGINT UNSIGNED NOT NULL DEFAULT 0,       -- в рублях; 0 = «Бесплатно»
    city             VARCHAR(80)  NOT NULL,
    status           ENUM('pending','approved','rejected','blocked') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT         NULL,                        -- причина от модератора
    views            INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ads_status_created (status, created_at),
    KEY idx_ads_user_status (user_id, status),
    KEY idx_ads_category (category_id),
    KEY idx_ads_city (city),
    KEY idx_ads_price (price),
    CONSTRAINT fk_ads_user     FOREIGN KEY (user_id)     REFERENCES users (id)      ON DELETE CASCADE,
    CONSTRAINT fk_ads_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Фото объявлений (первое по id — обложка) ----------------------------------
CREATE TABLE IF NOT EXISTS ad_images (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id    INT UNSIGNED NOT NULL,
    filename VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ad_images_ad (ad_id),
    CONSTRAINT fk_ad_images_ad FOREIGN KEY (ad_id) REFERENCES ads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сообщения покупателей продавцам -------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ad_id        INT UNSIGNED NOT NULL,
    from_user_id INT UNSIGNED NOT NULL,
    to_user_id   INT UNSIGNED NOT NULL,
    body         TEXT         NOT NULL,
    is_read      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_to (to_user_id, is_read, created_at),
    KEY idx_messages_from (from_user_id, created_at),
    CONSTRAINT fk_messages_ad   FOREIGN KEY (ad_id)        REFERENCES ads (id)   ON DELETE CASCADE,
    CONSTRAINT fk_messages_from FOREIGN KEY (from_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_to   FOREIGN KEY (to_user_id)   REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
