<?php
declare(strict_types=1);

/**
 * db.php — единая библиотека работы с MySQL (PDO).
 * Без глобальных переменных. Повторное подключение файла — безопасно.
 *
 * Использование:
 *   require_once __DIR__ . '/db.php';
 *   $row = db_row('SELECT NOW() now_local, UTC_TIMESTAMP() now_utc');
 *   $all = db_all('SELECT * FROM cbav_hud_snapshots ORDER BY id DESC LIMIT 3');
 *   $id  = db_insert('INSERT INTO table(col) VALUES(?)', [$val]);
 */

if (defined('CBAV_DB_LOADED')) {
    // уже подключено ранее
    return;
}
define('CBAV_DB_LOADED', true);

// ─────────────────────────────────────────────────────────────────────────────
// 1) Настройки доступа (замени значения под свои)
// ─────────────────────────────────────────────────────────────────────────────
const DB_HOST     = '127.0.0.1';          // или localhost / адрес сервера MySQL
const DB_PORT     = 3306;                 // порт MySQL
const DB_NAME     = 'crypto_wp';          // имя БД (по нашим таблицам так и называется)
const DB_USER     = 'ddb19d';     // имя пользователя MySQL
const DB_PASS     = 'TwwtCNmRe4@x4r$%';         // ← сюда поставь пароль (Я ВРЕМЕННОЕ ЗНАЧЕНИЕ!)
const DB_CHARSET  = 'utf8mb4';
const DB_TIMEZONE = '+00:00';             // храним всё в UTC

// ─────────────────────────────────────────────────────────────────────────────
// 2) Внутренняя «ленивая» фабрика PDO.
// ─────────────────────────────────────────────────────────────────────────────
/** @return PDO */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset='.DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // ошибки — исключениями
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // fetch — ассоц. массивы
        PDO::ATTR_EMULATE_PREPARES   => false,                    // нативные prepared
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES ".DB_CHARSET.", time_zone='".DB_TIMEZONE."'",
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3) Утилиты: один ряд, все ряды, exec/insert, и «пульс» БД.
// ─────────────────────────────────────────────────────────────────────────────
/** Вернуть одну строку или null */
function db_row(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Вернуть все строки (всегда массив) */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Выполнить запрос (INSERT/UPDATE/DELETE) — вернуть count затронутых строк */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** INSERT с получением lastInsertId() как int */
function db_insert(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) db()->lastInsertId();
}

/** Время БД (локальное и UTC) — быстро проверить коннект и TZ */
function db_now(): array
{
    return db_row('SELECT NOW() AS now_local, UTC_TIMESTAMP() AS now_utc') ?? [];
}

// ─────────────────────────────────────────────────────────────────────────────
// 4) Совместимость со старым кодом (если где‑то звали не те хелперы)
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('db_query')) {
    /** Алиас на db_all для «исторического» вызова */
    function db_query(string $sql, array $params = []): array { return db_all($sql, $params); }
}
