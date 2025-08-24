<?php
/**
 * PDO-коннектор + хелперы.
 * Безопасный require_once и единая точка входа для БД.
 */

declare(strict_types=1);

if (function_exists('db')) {
    return;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // <<< ВАЖНО: здесь ваши реальные значения (хост/база/пользователь/пароль) >>>
    $host = 'localhost';
    $port = 3306;
    $dbname = 'crypto_wp';
    $user = 'ddb19d';
    $pass = 'TwwtCNmRe4@x4r$%';
    // <<< ВАЖНО: ^^^ пароль вы заполняете сами ^^^

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function db_all(string $sql, array $params = []): array {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_row(string $sql, array $params = []): ?array {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_exec(string $sql, array $params = []): int {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
