<?php
/**
 * Общие утилиты: подключение к БД (WP), нормализация символов и UTC‑время.
 */
function db_connect_via_wp() {
    $wp = '/www/wwwroot/cryptobavaro.online/wp-config.php';
    if (!is_readable($wp)) throw new Exception("wp-config.php не найден: $wp");

    // Прочитаем константы DB_* напрямую
    $getConst = function($name) use ($wp) {
        return trim(shell_exec("php -r \"include '$wp'; echo constant('$name');\""));
    };
    $DB_NAME = $getConst('DB_NAME');
    $DB_USER = $getConst('DB_USER');
    $DB_PASS = $getConst('DB_PASSWORD');
    $DB_HOST = $getConst('DB_HOST');

    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($mysqli->connect_errno) throw new Exception("MySQL connect error: ".$mysqli->connect_error);
    $mysqli->set_charset('utf8mb4');
    // На всякий — UTC
    $mysqli->query("SET time_zone = '+00:00'");
    return $mysqli;
}

function norm_symbol($s) {
    $s = trim($s);
    // Разрешаем и "BINANCE:BTCUSDT", и "BTCUSDT", и "BTCUSDT.P"
    if (stripos($s, 'BINANCE:') !== 0) {
        $s = preg_replace('~\.P$~i', '', $s); // уберем .P
        $s = 'BINANCE:' . strtoupper($s);
    }
    return $s;
}

function json_out($obj) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
