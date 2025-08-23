<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php'; // $db mysqli

function normalize_symbol($s){
  if (!$s) return null;
  $s = strtoupper($s);
  $s = preg_replace('/\.P$/','',$s);
  if (strpos($s, ':') === false) { $s = 'BINANCE:'.$s; }
  return $s;
}

$sym_q = isset($_GET['sym']) && $_GET['sym'] !== '' ? $_GET['sym'] : 'BTCUSDT';
$tf_q  = isset($_GET['tf'])  && $_GET['tf']  !== '' ? $_GET['tf']  : '5';
$symbol_norm = normalize_symbol($sym_q);

$db->set_charset('utf8mb4');

// 1) fetch latest snapshot by normalized or raw symbol
$q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE (symbol=? OR symbol=?) AND tf=? ORDER BY ts DESC LIMIT 1");
$q->bind_param('sss', $symbol_norm, $sym_q, $tf_q);
$q->execute();
$sn = $q->get_result()->fetch_assoc();
$q->close();

if (!$sn){
  // fallback: just latest
  $q = $db->query("SELECT * FROM cbav_hud_snapshots ORDER BY ts DESC LIMIT 1");
  $sn = $q->fetch_assoc();
}

if (!$sn){ echo json_encode(["ok"=>0,"err"=>"no snapshots"]); exit; }

// Fake analysis result (placeholder, replace with AI call later)
$prob_long = 0.00; $prob_short = 0.00;
$result = [
  "regime" => "trend",
  "bias" => "neutral",
  "confidence" => 43
];

// Adaptive insert into cbav_hud_analyses
$cols = [];
$res = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='cbav_hud_analyses'");
while($row = $res->fetch_assoc()){ $cols[$row['COLUMN_NAME']] = true; }
$res->free();

$data = [
  "snapshot_id" => $sn["id"],
  "analyzed_at" => date("Y-m-d H:i:s"),
  "symbol"      => $sn["symbol"],
  "tf"          => $sn["tf"],
  "ver"         => isset($sn["ver"])? $sn["ver"] : "10.4",
  "ts"          => $sn["ts"],
  "sym"         => $sn["symbol"],
  "result_json" => json_encode($result, JSON_UNESCAPED_UNICODE),
  "prob_long"   => $prob_long,
  "prob_short"  => $prob_short,
];

$insCols = []; $placeholders = []; $types = ""; $values = [];
foreach($data as $k=>$v){
  if (isset($cols[$k])) {
    $insCols[] = $k;
    $placeholders[] = "?";
    if (is_int($v)) $types .= "i";
    else if (is_float($v)) $types .= "d";
    else $types .= "s";
    $values[] = $v;
  }
}

$sql = "INSERT INTO cbav_hud_analyses (" . implode(",", $insCols) . ") VALUES (" . implode(",", $placeholders) . ")";
$stmt = $db->prepare($sql);
if (!$stmt){ echo json_encode(["ok"=>0,"err"=>"prepare failed","sql"=>$sql]); exit; }

$stmt->bind_param($types, ...$values);
$ok = $stmt->execute();
if (!$ok){ echo json_encode(["ok"=>0,"err"=>"execute failed","sql"=>$sql, "errm"=>$stmt->error]); exit; }

$id = $stmt->insert_id;
$stmt->close();

echo json_encode(["ok"=>1, "analysis_id"=>$id, "used_cols"=>$insCols, "symbol_used"=>$sn["symbol"], "tf"=>$sn["tf"]]);
