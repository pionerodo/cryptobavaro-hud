<?php
// api/db.php — DB connector (UTC enforced)
// Loads WordPress DB credentials and opens mysqli connection.
// Sets MySQL session time_zone to UTC so all DATETIME/TIMESTAMP work in UTC.

header('Content-Type: application/json; charset=utf-8');

// Locate wp-config.php (one level up from /api)
$wp_config = dirname(__DIR__) . '/wp-config.php';
if (!file_exists($wp_config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'wp-config.php not found'], JSON_UNESCAPED_UNICODE);
    exit;
}
// Pull DB constants
include_once $wp_config;

$db = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connect error: ' . $db->connect_error], JSON_UNESCAPED_UNICODE);
    exit;
}
$db->set_charset('utf8mb4');

// 🔒 Force UTC for this session
$db->query("SET time_zone = '+00:00'");

// Small helpers
function db() { global $db; return $db; }
function jerr($code, $msg) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
