<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

$raw = file_get_contents('php://input');
if (!$raw){ http_response_code(400); echo json_encode(['ok'=>0,'err'=>'empty']); exit; }
$data = json_decode($raw, true);
if (!$data){ http_response_code(400); echo json_encode(['ok'=>0,'err'=>'json']); exit; }

$ts   = intval($data['ts'] ?? 0);
$sym  = substr(($data['sym'] ?? $data['symbol'] ?? ''), 0, 64);
$tf   = substr((string)($data['tf'] ?? ''), 0, 8);
$pr   = floatval($data['p'] ?? $data['price'] ?? 0);
$tag  = substr((string)($data['id'] ?? $data['id_tag'] ?? 'hud'), 0, 32);
$ver  = substr((string)($data['ver'] ?? '10.4'), 0, 16);

if ($ts<=0 || $sym==='' || $tf===''){ http_response_code(400); echo json_encode(['ok'=>0,'err'=>'fields']); exit; }

$hasPayload = false;
$colq = $db->query("SHOW COLUMNS FROM cbav_hud_snapshots LIKE 'payload_json'");
if ($colq && $colq->num_rows > 0) $hasPayload = true;

if ($hasPayload){
  $stmt = $db->prepare("INSERT IGNORE INTO cbav_hud_snapshots(ts,symbol,tf,price,id_tag,payload_json,ver) VALUES (?,?,?,?,?,?,?)");
  $stmt->bind_param('issdsss', $ts,$sym,$tf,$pr,$tag,$raw,$ver);
  $ok = $stmt->execute();
  echo json_encode(['ok'=>$ok?1:0,'schema'=>'payload_json']); exit;
}

$features = json_encode($data['f']   ?? new stdClass(), JSON_UNESCAPED_UNICODE);
$levels   = json_encode($data['lv']  ?? [],             JSON_UNESCAPED_UNICODE);
$patterns = json_encode($data['pat'] ?? [],             JSON_UNESCAPED_UNICODE);

$stmt = $db->prepare("INSERT IGNORE INTO cbav_hud_snapshots(ts,symbol,tf,price,id_tag,features,levels,patterns,ver)
                      VALUES (?,?,?,?,?,?,?,?,?)");
$stmt->bind_param('issdsssss', $ts,$sym,$tf,$pr,$tag,$features,$levels,$patterns,$ver);
$ok = $stmt->execute();
echo json_encode(['ok'=>$ok?1:0,'schema'=>'features']);
?>
