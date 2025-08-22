<?php
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(400); echo json_encode(['ok'=>0,'err'=>'empty']); exit; }

$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['ok'=>0,'err'=>'json']); exit; }

$idTag = $data['id'] ?? '';
if ($idTag !== 'hud_v10_5') { /* допускаем и v10.4 при параллельных тестах */
  // не рвём запрос — просто пропускаем
}

$ts   = intval($data['ts'] ?? 0);
$sym  = substr($data['sym'] ?? '', 0, 24);
$tf   = substr($data['tf']  ?? '', 0, 8);
$pr   = floatval($data['p']  ?? 0);

if ($ts<=0 || $sym==='' || $tf==='') { http_response_code(400); echo json_encode(['ok'=>0,'err'=>'fields']); exit; }

require __DIR__.'/db.php'; // mysqli $db

$stmt = $db->prepare("INSERT IGNORE INTO cbav_hud_snapshots(ts,sym,tf,price,id_tag,payload_json) VALUES (?,?,?,?,?,?)");
$payload = $raw;
$stmt->bind_param('issdss', $ts, $sym, $tf, $pr, $idTag, $payload);
$ok = $stmt->execute();

echo json_encode(['ok' => $ok ? 1 : 0, 'dup' => $db->affected_rows === 0 ? 1 : 0]);
