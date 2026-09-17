-- Schéma SQLite (développement local). Idempotent.
CREATE TABLE IF NOT EXISTS settings (
  key   TEXT NOT NULL PRIMARY KEY,
  value TEXT
);

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'admin',
  created_at    TEXT NOT NULL,
  last_login_at TEXT
);

CREATE TABLE IF NOT EXISTS albums (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  title          TEXT NOT NULL,
  slug           TEXT NOT NULL UNIQUE,
  description    TEXT,
  event_date     TEXT,
  location       TEXT,
  category       TEXT,
  cover_photo_id INTEGER REFERENCES photos(id) ON DELETE SET NULL,
  published      INTEGER NOT NULL DEFAULT 0,
  photo_count    INTEGER NOT NULL DEFAULT 0,
  wp_post_id     INTEGER UNIQUE,
  legacy_url     TEXT,
  created_at     TEXT NOT NULL,
  updated_at     TEXT NOT NULL,
  deleted_at     TEXT
);
CREATE INDEX IF NOT EXISTS idx_albums_pub ON albums (published, deleted_at, event_date);
CREATE INDEX IF NOT EXISTS idx_albums_cat ON albums (category);

CREATE TABLE IF NOT EXISTS photos (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  album_id          INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
  filename          TEXT NOT NULL,
  storage           TEXT NOT NULL DEFAULT 'photos',
  storage_path      TEXT NOT NULL,
  original_filename TEXT,
  mime_type         TEXT,
  width             INTEGER,
  height            INTEGER,
  filesize          INTEGER,
  sort_order        INTEGER NOT NULL DEFAULT 0,
  wp_media_id       INTEGER,
  variants_status   TEXT NOT NULL DEFAULT 'ok',
  created_at        TEXT NOT NULL,
  deleted_at        TEXT
);
CREATE INDEX IF NOT EXISTS idx_photos_album ON photos (album_id, deleted_at, sort_order, id);
CREATE INDEX IF NOT EXISTS idx_photos_wp ON photos (wp_media_id);
CREATE INDEX IF NOT EXISTS idx_photos_variants ON photos (variants_status);

CREATE TABLE IF NOT EXISTS photo_variants (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  photo_id     INTEGER NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
  variant      TEXT NOT NULL,
  storage_path TEXT NOT NULL,
  width        INTEGER NOT NULL,
  height       INTEGER NOT NULL,
  filesize     INTEGER,
  UNIQUE (photo_id, variant)
);

CREATE TABLE IF NOT EXISTS uploads (
  id            TEXT NOT NULL PRIMARY KEY,
  album_id      INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
  user_id       INTEGER NOT NULL,
  fingerprint   TEXT NOT NULL,
  filename      TEXT NOT NULL,
  size          INTEGER NOT NULL,
  mime_type     TEXT,
  chunk_size    INTEGER NOT NULL,
  chunks_total  INTEGER NOT NULL,
  chunks_done   TEXT,
  status        TEXT NOT NULL DEFAULT 'pending',
  error         TEXT,
  created_at    TEXT NOT NULL,
  updated_at    TEXT NOT NULL,
  UNIQUE (album_id, fingerprint)
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  ip           TEXT NOT NULL,
  attempted_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_attempts ON login_attempts (ip, attempted_at)
