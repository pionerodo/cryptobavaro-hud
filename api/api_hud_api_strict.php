<?php
// /api/hud_api_strict.php
// Returns latest analyses with strict UTC timing.
// Params: sym (optional, default 'BINANCE:BTCUSDT'), tf (optional, default 5), limit (optional, default 20)

header('Content-Type: application/json; charset=utf-8');

// Load DB creds from WordPress
$root = dirname(__DIR__);
$wp = $root . '/wp-config.php';
if (!file_exists($wp)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'wp-config.php not found']);
  exit;
}
require_once $wp;

$sym   = isset($_GET['sym']) && $_GET['sym'] !== '' ? $_GET['sym'] : 'BINANCE:BTCUSDT';
$tf    = isset($_GET['tf'])  && is_numeric($_GET['tf']) ? intval($_GET['tf']) : 5;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(200, intval($_GET['limit'])) : 20;

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$mysqli->set_charset('utf8mb4');
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect']);
  exit;
}

// Query strictly by utc from ts (ms) and avoid local time columns entirely
$sql = "
  SELECT
    a.id               AS analysis_id,
    a.snapshot_id,
    a.symbol,
    a.sym,
    a.tf,
    a.ver,
    s.ts               AS ts,            -- milliseconds UTC from snapshots
    FROM_UNIXTIME(s.ts/1000) AS t_utc,   -- MySQL datetime in UTC
    s.price,
    a.result_json,
    a.notes,
    a.prob_long,
    a.prob_short,
    a.summary_md
  FROM cbav_hud_analyses a
  JOIN cbav_hud_snapshots s ON s.id = a.snapshot_id
  WHERE a.sym = ? AND a.tf = ?
  ORDER BY s.ts DESC
  LIMIT ?
";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'stmt_prepare']);
  exit;
}
$stmt->bind_param('sii', $sym, $tf, $limit);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  // Ensure types
  $row['analysis_id'] = intval($row['analysis_id']);
  $row['snapshot_id'] = intval($row['snapshot_id']);
  $row['tf']          = intval($row['tf']);
  $row['ver']         = floatval($row['ver']);
  $row['ts']          = intval($row['ts']);
  $row['price']       = is_null($row['price']) ? null : floatval($row['price']);
  $row['prob_long']   = is_null($row['prob_long']) ? null : floatval($row['prob_long']);
  $row['prob_short']  = is_null($row['prob_short']) ? null : floatval($row['prob_short']);
  $out[] = $row;
}
$stmt->close();

echo json_encode(['ok'=>true, 'count'=>count($out), 'sym'=>$sym, 'tf'=>$tf, 'items'=>$out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
