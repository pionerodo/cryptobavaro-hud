<?php
// ================== DB & API CONFIG ==================

// DB (ваши рабочие значения)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'crypto_wp');
define('DB_USER', 'ddb19d');
define('DB_PASS', 'TwwtCNmRe4@x4r$%');

// Таблицы (можно менять при желании)
define('TBL_SNAPS',    'tv_snaps');     // входящие снапшоты от Pine
define('TBL_ANALYSIS', 'tv_analysis');  // текстовый анализ (опционально)

// ТЗ сайта
date_default_timezone_set('UTC');

// TV webhooks
define('TV_SECRET', '');                // оставляем пустым на тестах

// OpenAI (пока отключено; ключ можно хранить здесь)
$OPENAI_API_KEY = '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';
$OPENAI_MODEL   = 'gpt-4o'; // оставим на будущее (сейчас сторонний анализ не вызываем)

// Универсальный коннектор PDO
function db() : PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC ]
    );
    return $pdo;
}

// Создание таблиц «на лету», если их нет (удобно на первом запуске)
function ensure_schema() {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `".TBL_SNAPS."` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `symbol` VARCHAR(64) NOT NULL,
          `tf` INT NOT NULL,
          `ts` BIGINT UNSIGNED NOT NULL,
          `price` DOUBLE NULL,
          `payload_json` LONGTEXT NOT NULL,
          `features_json` LONGTEXT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `sym_tf_ts` (`symbol`,`tf`,`ts`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `".TBL_ANALYSIS."` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `snapshot_id` BIGINT UNSIGNED NOT NULL,
          `analysis_json` LONGTEXT NOT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `snap_idx` (`snapshot_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}
ensure_schema();

// Утилиты нормализации
function norm_symbol(string $s) : string {
    $u = strtoupper(trim($s));
    $u = preg_replace('/^BINANCE:/', '', $u); // убираем префикс
    $u = preg_replace('/\.P$/', '', $u);      // убираем суффикс фьючерса
    return $u;
}
function norm_tf($tf) : int {
    if (is_numeric($tf)) return intval($tf);
    if (preg_match('/(\d+)/', (string)$tf, $m)) return intval($m[1]);
    return 5;
}
