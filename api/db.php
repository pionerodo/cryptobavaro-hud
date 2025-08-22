<?php
// api/db.php — подключение к БД. Пытаемся взять креды из wp-config.php,
// чтобы не дублировать пароли WordPress.

function wp_creds_from_config($path){
  if (!file_exists($path)) return null;
  $txt = file_get_contents($path);
  $creds = [];
  foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST'] as $k){
    if (preg_match("/define\\(\\s*'".$k."'\\s*,\\s*'([^']*)'\\s*\\)\\s*;/", $txt, $m)){
      $creds[$k] = $m[1];
    }
  }
  return (count($creds)===4) ? $creds : null;
}

$root = dirname(__DIR__);                    // /www/wwwroot/cryptobavaro.online
$wp   = $root . '/wp-config.php';
$C    = wp_creds_from_config($wp);

$dbname = $C['DB_NAME']     ?? 'crypto_wp';
$dbuser = $C['DB_USER']     ?? 'root';
$dbpass = $C['DB_PASSWORD'] ?? '';
$dbhost = $C['DB_HOST']     ?? '127.0.0.1';

$db = @new mysqli($dbhost, $dbuser, $dbpass, $dbname);
if ($db->connect_errno){
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>0,'err'=>'db connect','code'=>$db->connect_errno,'msg'=>$db->connect_error]);
  exit;
}
$db->set_charset('utf8mb4');
