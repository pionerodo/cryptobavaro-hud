<?php
// /api/db.php — единая точка входа PDO + хелперы

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;

    // TODO: укажи свои доступы
    $host = '127.0.0.1';
    $name = 'crypto_wp';
    $user = 'ddb19d';
    $pass = 'TwwtCNmRe4@x4r$%';

    $dsn  = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $opt  = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opt);
    return $pdo;
}

function jerr(string $msg, int $code = 500): void {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'exception', 'detail' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_str(string $key, string $def = ''): string {
    $v = $_GET[$key] ?? $_POST[$key] ?? $def;
    $v = trim((string)$v);
    return $v;
}

function get_int(string $key, int $def = 0): int {
    $v = (int)($_GET[$key] ?? $_POST[$key] ?? $def);
    return $v;
}

function safe_symbol(string $s): string {
    // допустим только буквы, двоеточие и дефис
    $s = strtoupper($s);
    return preg_replace('/[^A-Z0-9:\-]/', '', $s);
}
