-- 001_init.sql  (HUD storage)
-- DB: crypto_wp   Charset: utf8mb4

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS hud_snapshots (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  symbol       VARCHAR(64)  NOT NULL,
  tf           VARCHAR(8)   NOT NULL,
  ts           BIGINT       NOT NULL,               -- unix ms
  price        DECIMAL(18,2) NOT NULL,
  f_json       LONGTEXT     NOT NULL,               -- compact features JSON (F из pine)
  lv_json      LONGTEXT     NOT NULL,               -- уровни (массив)
  pat_json     LONGTEXT     NOT NULL,               -- паттерны (массив)
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sym_tf_ts (symbol, tf, ts),
  KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hud_analysis (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_id   BIGINT UNSIGNED NOT NULL,
  notes         LONGTEXT     NOT NULL,              -- текстовые заметки (короткие)
  playbook_json LONGTEXT     NOT NULL,              -- плейбук (массив шагов)
  bias          VARCHAR(16)  NOT NULL DEFAULT 'neutral',
  confidence    TINYINT      NOT NULL DEFAULT 0,    -- 0..100
  source        VARCHAR(32)  NOT NULL DEFAULT 'heuristic',  -- heuristic|gpt
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_snapshot (snapshot_id),
  CONSTRAINT fk_hud_analysis_snapshot
    FOREIGN KEY (snapshot_id) REFERENCES hud_snapshots(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
