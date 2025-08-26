<?php
/**
 * db.php — единое PDO‑подключение и мини-хелперы.
 * Заполни DSN/логин/пароль под свою БД (пароль оставь пустым — ты впишешь).
 */

if (!defined('CBAV_DB_ONCE')) define('CBAV_DB_ONCE', 1);

function db_cfg(): array {
    // ⚠️ ВПИШИ СВОЁ:
    return [
        'dsn'  => 'mysql:host=127.0.0.1;dbname=crypto_wp;charset=utf8mb4',
        'user' => 'ddb19d',
        'pass' => 'TwwtCNmRe4@x4r$%', // <-- сюда твой пароль
        'opt'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ];
}

/** @return PDO */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c   = db_cfg();
    $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], $c['opt']);
    return $pdo;
}

/** sugar */
function db_all(string $sql, array $args = []): array {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}
function db_row(string $sql, array $args = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch();
    return $r === false ? null : $r;
}
function db_exec(string $sql, array $args = []): int {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/** JSON вывод с правильными заголовками */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

/** простой логгер (опционально) */
function log_line(string $file, string $line): void {
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents("$dir/$file", '['.gmdate('Y-m-d H:i:s').'Z] '.$line.PHP_EOL, FILE_APPEND);
}
