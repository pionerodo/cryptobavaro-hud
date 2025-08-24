<?php
/**
 * db.php — единая точка подключения к MySQL + хелперы.
 * - Безопасно подключается многократно (require_once).
 * - Singleton: одно соединение на процесс.
 * - Хелперы: db(), db_row(), db_all(), db_exec().
 *
 * Заполните ниже константы (или переопределите в api/db.local.php).
 */

declare(strict_types=1);

// ---- Базовые настройки (можно переопределить в api/db.local.php)
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'crypto_wp');       // имя вашей БД
if (!defined('DB_USER')) define('DB_USER', 'ddb19d');  // пользователь
if (!defined('DB_PASS')) define('DB_PASS', 'TwwtCNmRe4@x4r$%');  // ПАРОЛЬ ВСТАВИТЕ СЮДА
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_TIMEZONE')) define('DB_TIMEZONE', '+00:00');  // сервер держим в UTC

// Локальный оверрайд (не обязателен): api/db.local.php
$__db_local = __DIR__ . '/db.local.php';
if (is_file($__db_local)) {
    /** @noinspection PhpIncludeInspection */
    require_once $__db_local;
}

// ---- Вспомогательные предохранители (не даём объявить функции повторно)

if (!function_exists('pdo')) {
    /**
     * Возвращает singleton PDO (同一プロセスで1回だけ作成).
     */
    function pdo(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Установим тайм‑зону на уровне соединения (UTC)
        $stmt = $pdo->prepare("SET time_zone = :tz");
        $stmt->execute([':tz' => DB_TIMEZONE]);

        return $pdo;
    }
}

if (!function_exists('db')) {
    /**
     * Синоним pdo() для совместимости со старым кодом.
     */
    function db(): PDO {
        return pdo();
    }
}

if (!function_exists('db_row')) {
    /**
     * Вернуть одну строку или null.
     */
    function db_row(string $sql, array $params = []): ?array {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}

if (!function_exists('db_all')) {
    /**
     * Вернуть массив строк.
     */
    function db_all(string $sql, array $params = []): array {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('db_exec')) {
    /**
     * INSERT/UPDATE/DELETE — вернуть число затронутых строк.
     */
    function db_exec(string $sql, array $params = []): int {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
