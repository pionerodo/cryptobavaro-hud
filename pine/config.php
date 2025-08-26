<?php
// ============================================================================
//  config.php  — общее подключение и утилиты
//  (для latest_analysis.php, analyze.php, tvhook.php, eval_mark.php)
// ============================================================================

// ----- БАЗА ДАННЫХ -----------------------------------------------------------
const DB_HOST = '127.0.0.1';        // TCP, чтобы не требовался unix-socket
const DB_NAME = 'crypto_wp';
const DB_USER = 'ddb19d';           // <-- оставил как у тебя
const DB_PASS = 'TwwtCNmRe4@x4r$%';  // <-- ВСТАВЬ ПАРОЛЬ!

// ----- ТАБЛИЦЫ (единые константы во всех скриптах) ---------------------------
const TBL_SNAPS         = 'hud_snapshots';        // снимки (pine -> БД)
const TBL_ANALYSIS      = 'hud_analysis';         // краткая аналитика/заметки
const TBL_ANALYSIS_HIST = 'hud_analysis_history'; // история правок анализа
const TBL_TV_RAW        = 'tv_snaps_raw';         // «сырьё» из твхук для отладки

// ----- ЛОГ -------------------------------------------------------------------
const LOG_FILE = '/www/wwwlogs/analyze.log';

// ----- Тонкие настройки PHP --------------------------------------------------
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ============================================================================
//  PDO singleton
// ============================================================================
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
    return $pdo;
}

// ============================================================================
//  Простые ответы JSON
// ============================================================================
function json_ok(array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => 'ok'], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $code = 500): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode([
        'status' => 'error',
        'error'  => $msg,
        'code'   => $code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
//  Логгер
// ============================================================================
function log_line(string $line): void {
    // Пишем построчно, чтобы не падать, если каталога нет
    @file_put_contents(LOG_FILE, '['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND);
}

// ============================================================================
//  (Опционально) Проверка и создание схемы, если ещё не создана
//  — безопасна, использует IF NOT EXISTS
// ============================================================================
function ensure_schema(): void {
    $sql = [];

    // --- hud_snapshots
    $sql[] = "
        CREATE TABLE IF NOT EXISTS `".TBL_SNAPS."` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `symbol` VARCHAR(64)  NOT NULL,
          `tf`     VARCHAR(8)   NOT NULL,
          `ts`     BIGINT       NOT NULL,
          `price`  DECIMAL(18,2) NOT NULL,
          `f_json` LONGTEXT     NOT NULL,
          `lv_json` LONGTEXT    NOT NULL,
          `pat_json` LONGTEXT   NOT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_sym_tf_ts` (`symbol`, `tf`, `ts`),
          KEY `idx_ts` (`ts`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    // --- hud_analysis
    $sql[] = "
        CREATE TABLE IF NOT EXISTS `".TBL_ANALYSIS."` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `snapshot_id` BIGINT UNSIGNED NOT NULL,
          `notes` LONGTEXT NOT NULL,
          `playbook_json` LONGTEXT NOT NULL,
          `bias` VARCHAR(16) NOT NULL DEFAULT 'neutral',
          `confidence` TINYINT NOT NULL DEFAULT 0,
          `source` VARCHAR(32) NOT NULL DEFAULT 'heuristic',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_snapshot` (`snapshot_id`),
          CONSTRAINT `fk_hud_analysis_snapshot`
            FOREIGN KEY (`snapshot_id`) REFERENCES `".TBL_SNAPS."`(`id`)
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    // --- hud_analysis_history (аудит правок)
    $sql[] = "
        CREATE TABLE IF NOT EXISTS `".TBL_ANALYSIS_HIST."` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `snapshot_id` BIGINT UNSIGNED NOT NULL,
          `notes` LONGTEXT NOT NULL,
          `playbook_json` LONGTEXT NOT NULL,
          `bias` VARCHAR(16) NOT NULL,
          `confidence` TINYINT NOT NULL,
          `source` VARCHAR(32) NOT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_hist_snapshot` (`snapshot_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    // --- tv_snaps_raw (сырьё из твхук для отладки)
    $sql[] = "
        CREATE TABLE IF NOT EXISTS `".TBL_TV_RAW."` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `symbol` VARCHAR(64) NOT NULL,
          `tf` VARCHAR(8) NOT NULL,
          `ts` BIGINT NOT NULL,
          `label` VARCHAR(32) NOT NULL,
          `price` DECIMAL(18,2) NULL,
          `raw_json` LONGTEXT NULL,
          `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_sym_tf_ts` (`symbol`,`tf`,`ts`),
          KEY `idx_received` (`received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo = db();
    foreach ($sql as $q) {
        $pdo->exec($q);
    }
}

// ============================================================================
//  Небольшие хелперы для наших эндпойнтов
// ============================================================================

// Возвращает последний снимок по символу/ТФ
function get_latest_snapshot(string $symbol, string $tf): ?array {
    $q = db()->prepare("
        SELECT id, symbol, tf, ts, price, f_json, lv_json, pat_json, created_at
          FROM ".TBL_SNAPS."
         WHERE symbol = :s AND tf = :tf
         ORDER BY ts DESC, id DESC
         LIMIT 1
    ");
    $q->execute([':s' => $symbol, ':tf' => $tf]);
    $row = $q->fetch();
    return $row ?: null;
}

// Сохранить «сырьё» из твхук (для отладки)
function raw_insert(array $r): void {
    $q = db()->prepare("
        INSERT INTO ".TBL_TV_RAW." (symbol, tf, ts, label, price, raw_json)
        VALUES (:symbol, :tf, :ts, :label, :price, :raw)
        ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            price = VALUES(price),
            raw_json = VALUES(raw_json)
    ");
    $q->execute([
        ':symbol' => $r['symbol'],
        ':tf'     => $r['tf'],
        ':ts'     => $r['ts'],
        ':label'  => $r['label'],
        ':price'  => $r['price'] ?? null,
        ':raw'    => json_encode($r, JSON_UNESCAPED_UNICODE),
    ]);
}

// ---------- JSON helpers (унифицируем вывод) ----------
if (!function_exists('json_out')) {
    function json_out(array $payload, int $code = 200): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($code);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_ok')) {
    function json_ok(array $payload = []): void {
        json_out(['status' => 'ok'] + $payload, 200);
    }
}
if (!function_exists('json_err')) {
    function json_err(string $msg, int $code = 500): void {
        json_out(['status' => 'error', 'error' => $msg], $code);
    }
}

// ============================================================================
//  Автосоздание схемы (безопасно) — можно выключить после первого запуска
// ============================================================================
ensure_schema();
