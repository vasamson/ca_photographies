-- Schéma MySQL (EX2 / cPanel). Idempotent.
CREATE TABLE IF NOT EXISTS settings (
  `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(16) NOT NULL DEFAULT 'admin',
  created_at    DATETIME NOT NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS albums (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title          VARCHAR(255) NOT NULL,
  slug           VARCHAR(190) NOT NULL UNIQUE,
  description    TEXT NULL,
  event_date     DATE NULL,
  location       VARCHAR(255) NULL,
  category       VARCHAR(64) NULL,
  cover_photo_id INT UNSIGNED NULL,
  published      TINYINT(1) NOT NULL DEFAULT 0,
  photo_count    INT UNSIGNED NOT NULL DEFAULT 0,
  wp_post_id     INT UNSIGNED NULL UNIQUE,
  legacy_url     VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  deleted_at     DATETIME NULL,
  INDEX idx_albums_pub (published, deleted_at, event_date),
  INDEX idx_albums_cat (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photos (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  album_id          INT UNSIGNED NOT NULL,
  filename          VARCHAR(255) NOT NULL,
  storage           VARCHAR(16) NOT NULL DEFAULT 'photos',   -- photos | legacy
  storage_path      VARCHAR(500) NOT NULL,                   -- original, relatif à la racine du stockage
  original_filename VARCHAR(255) NULL,
  mime_type         VARCHAR(64) NULL,
  width             INT UNSIGNED NULL,
  height            INT UNSIGNED NULL,
  filesize          BIGINT UNSIGNED NULL,
  sort_order        INT NOT NULL DEFAULT 0,
  wp_media_id       INT UNSIGNED NULL,
  variants_status   VARCHAR(16) NOT NULL DEFAULT 'ok',       -- ok | pending | failed
  created_at        DATETIME NOT NULL,
  deleted_at        DATETIME NULL,
  INDEX idx_photos_album (album_id, deleted_at, sort_order, id),
  INDEX idx_photos_wp (wp_media_id),
  INDEX idx_photos_variants (variants_status),
  CONSTRAINT fk_photos_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_variants (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  photo_id     INT UNSIGNED NOT NULL,
  variant      VARCHAR(16) NOT NULL,                          -- thumb | w800 | w1600 | w2400 …
  storage_path VARCHAR(500) NOT NULL,
  width        INT UNSIGNED NOT NULL,
  height       INT UNSIGNED NOT NULL,
  filesize     BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_variant (photo_id, variant),
  CONSTRAINT fk_variants_photo FOREIGN KEY (photo_id) REFERENCES photos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
  id            CHAR(32) NOT NULL PRIMARY KEY,
  album_id      INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  fingerprint   CHAR(40) NOT NULL,
  filename      VARCHAR(255) NOT NULL,
  size          BIGINT UNSIGNED NOT NULL,
  mime_type     VARCHAR(64) NULL,
  chunk_size    INT UNSIGNED NOT NULL,
  chunks_total  INT UNSIGNED NOT NULL,
  chunks_done   MEDIUMTEXT NULL,                              -- JSON [0,1,2…]
  status        VARCHAR(16) NOT NULL DEFAULT 'pending',       -- pending | done | failed
  error         TEXT NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  UNIQUE KEY uq_upload (album_id, fingerprint),
  CONSTRAINT fk_uploads_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX idx_attempts (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE albums ADD CONSTRAINT fk_albums_cover FOREIGN KEY (cover_photo_id) REFERENCES photos(id) ON DELETE SET NULL
