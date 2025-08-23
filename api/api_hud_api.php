<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php'; // $db mysqli

$cmd = isset($_GET['cmd']) ? $_GET['cmd'] : 'latest';
$sym = isset($_GET['sym']) ? $_GET['sym'] : 'BTCUSDT';
$tf  = isset($_GET['tf'])  ? $_GET['tf']  : '5';

$db->set_charset('utf8mb4');

if ($cmd === 'latest'){
  // latest by symbol if column exists
  $colCheck = $db->query("SHOW COLUMNS FROM cbav_hud_analyses LIKE 'symbol'")->num_rows > 0 ? 'symbol' : 'sym';
  $stmt = $db->prepare("SELECT * FROM cbav_hud_analyses WHERE $colCheck=? AND tf=? ORDER BY id DESC LIMIT 1");
  $stmt->bind_param('ss', $sym, $tf);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  if (!$r) {
    $r = $db->query("SELECT * FROM cbav_hud_analyses ORDER BY id DESC LIMIT 1")->fetch_assoc();
  }
  if (!$r){ echo json_encode(["ok"=>0,"err"=>"no analysis"]); exit; }
  echo json_encode(["ok"=>1, "data"=>$r]);
  exit;
}

echo json_encode(["ok"=>0, "err"=>"unknown cmd"]);
