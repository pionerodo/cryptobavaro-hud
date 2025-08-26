<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php'; // expects $db = new mysqli(...)

// --- small logger
$logf = __DIR__ . '/../logs/tv_webhook.log';
@mkdir(dirname($logf), 0777, true);
function log_line($s){ global $logf; @file_put_contents($logf, "[".date('c')."] ".$s."\n", FILE_APPEND); }

// Read raw body (TradingView sends application/json)
$raw = file_get_contents('php://input');
if (!$raw) { log_line("empty body"); http_response_code(400); echo json_encode(["ok"=>0,"err"=>"empty body"]); exit; }

// TradingView can wrap JSON as plain text; try to decode strictly
$j = json_decode($raw, true);
if (!is_array($j)) {
  // sometimes payload is like: "{\"id\":...}" inside a string; try second pass
  $raw2 = trim($raw, "'\"");
  $j = json_decode($raw2, true);
}

if (!is_array($j)) {
  log_line("bad json: " . substr($raw,0,200));
  http_response_code(400);
  echo json_encode(["ok"=>0,"err"=>"bad json", "raw_head"=>substr($raw,0,200)]);
  exit;
}

// Helpers
function arr_get($a,$k,$def=null){ return isset($a[$k]) ? $a[$k] : $def; }
function normalize_symbol($s){
  if (!$s) return null;
  $s = strtoupper($s);
  $s = str_replace('BYBIT:','BINANCE:', $s); // fallback unify to BINANCE for our pipeline
  // drop suffix .P if present
  $s = preg_replace('/\.P$/','',$s);
  // add exchange prefix if missing
  if (strpos($s, ':') === false) { $s = 'BINANCE:'.$s; }
  return $s;
}

// Extract fields
$id_tag  = arr_get($j,'id');
$ver     = arr_get($j,'ver');
$symbol0 = arr_get($j,'sym', arr_get($j,'symbol'));
$symbol  = normalize_symbol($symbol0);
$tf      = strval(arr_get($j,'tf', arr_get($j,'interval')));
$ts      = intval(arr_get($j,'ts'));
$price   = floatval(arr_get($j,'p', arr_get($j,'price', 0)));
$features= json_encode(arr_get($j,'f', []), JSON_UNESCAPED_UNICODE);
$levels  = json_encode(arr_get($j,'levels', []), JSON_UNESCAPED_UNICODE);
$patterns= json_encode(arr_get($j,'patterns', []), JSON_UNESCAPED_UNICODE);

// Defensive defaults
if (!$id_tag) $id_tag = 'hud_unknown';
if ($ts <= 0) $ts = intval(microtime(true)*1000);

// Determine existing columns to avoid Unknown column errors
$db->set_charset('utf8mb4');
$cols = [];
$res = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='cbav_hud_snapshots'");
while($row = $res->fetch_assoc()){ $cols[$row['COLUMN_NAME']] = true; }
$res->free();

$data = [
  'id_tag'   => $id_tag,
  'ver'      => $ver,
  'symbol'   => $symbol,
  'tf'       => $tf,
  'ts'       => $ts,
  'price'    => $price,
  'features' => $features,
  'levels'   => $levels,
  'patterns' => $patterns,
];

// Filter by existing columns
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

if (empty($insCols)) {
  log_line("no matching columns in cbav_hud_snapshots");
  echo json_encode(["ok"=>0,"err"=>"no matching columns"]);
  exit;
}

$sql = "INSERT INTO cbav_hud_snapshots (" . implode(",", $insCols) . ") VALUES (" . implode(",", $placeholders) . ")";
$stmt = $db->prepare($sql);
if (!$stmt){ log_line("prepare failed: ".$db->error); echo json_encode(["ok"=>0,"err"=>"prepare failed","sql"=>$sql]); exit; }

$stmt->bind_param($types, ...$values);
$ok = $stmt->execute();
if (!$ok){ log_line("execute failed: ".$stmt->error); echo json_encode(["ok"=>0,"err"=>"execute failed","sql"=>$sql]); exit; }

$id = $stmt->insert_id;
$stmt->close();

log_line("ok id=$id sym0=$symbol0 norm=$symbol id_tag=$id_tag tf=$tf ts=$ts");
echo json_encode(["ok"=>1, "id"=>$id, "used_cols"=>$insCols, "symbol"=>$symbol, "symbol_src"=>$symbol0, "id_tag"=>$id_tag, "ver"=>$ver]);
