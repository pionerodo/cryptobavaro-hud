<?php
/**
 * Adaptive analyzer inserter for CryptoBavaro HUD.
 * - Picks the latest snapshot for given sym/tf (or overall if not provided)
 * - Detects existing column names in cbav_hud_analyses (sym/symbol, ver, result_json/summary_md/raw_json)
 * - Inserts a minimal analysis row with snapshot_id so schema constraints are satisfied
 *
 * Usage:
 *   CLI: php hud_analyze.php sym=BTCUSDT tf=5
 *   Web: /api/hud_analyze.php?sym=BTCUSDT&tf=5
 */
header('Content-Type: application/json; charset=utf-8');

// ----------------- Helpers -----------------
function arg_get($key, $default=null) {
  // From GET
  if (isset($_GET[$key]) && $_GET[$key] !== '') return $_GET[$key];
  // From CLI "key=value"
  global $argv;
  if (isset($argv) && is_array($argv)) {
    foreach ($argv as $a) {
      if (strpos($a, '=') !== false) {
        list($k,$v) = explode('=', $a, 2);
        if ($k === $key) return $v;
      }
    }
  }
  return $default;
}

function fatal($msg, $ctx = []) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$msg, 'ctx'=>$ctx], JSON_UNESCAPED_UNICODE);
  exit;
}

function has_col($db, $table, $col) {
  $res = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $res->bind_param('s', $col);
  $res->execute();
  $r = $res->get_result();
  return $r && $r->num_rows > 0;
}

// ----------------- DB connect -----------------
require __DIR__ . '/db.php'; // must set $db or constants

if (!isset($db) || !($db instanceof mysqli)) {
  // try WP constants if available
  if (defined('DB_HOST')) {
    $host = DB_HOST;
    $user = defined('DB_USER') ? DB_USER : (defined('DB_USERNAME') ? DB_USERNAME : '');
    $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
    $name = defined('DB_NAME') ? DB_NAME : '';
    $db = @new mysqli($host, $user, $pass, $name);
  }
}

if (!($db instanceof mysqli) || $db->connect_errno) {
  fatal('DB connect failed', ['errno'=>($db?$db->connect_errno:null), 'error'=>($db?$db->connect_error:null)]);
}
$db->set_charset('utf8mb4');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ----------------- Params -----------------
$symIn = arg_get('sym', arg_get('symbol', ''));
$tf = intval(arg_get('tf', 5));

// Normalize incoming symbols the same way webhook does
function normalize_symbol($s) {
  $s = trim($s);
  if ($s === '') return $s;
  // Map BTCUSDT.P and BTCUSDT -> BINANCE:BTCUSDT
  if (preg_match('/^([A-Z]+USDT)(?:\.P)?$/', $s, $m)) {
    return 'BINANCE:' . $m[1];
  }
  // Already BINANCE:XXX
  if (strpos($s, 'BINANCE:') === 0) return $s;
  return $s;
}

$normSym = normalize_symbol($symIn);

// ----------------- Choose snapshot -----------------
function fetch_last_snapshot($db, $normSym, $tf) {
  // exact match by symbol & tf
  if ($normSym !== '') {
    $q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE symbol=? AND tf=? ORDER BY ts DESC, id DESC LIMIT 1");
    $q->bind_param('si', $normSym, $tf);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) return $row;

    // only symbol
    $q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE symbol=? ORDER BY ts DESC, id DESC LIMIT 1");
    $q->bind_param('s', $normSym);
    $q->execute();
    $r = $q->get_result();
    if ($row = $r->fetch_assoc()) return $row;
  }

  // Any latest
  $r = $db->query("SELECT * FROM cbav_hud_snapshots ORDER BY ts DESC, id DESC LIMIT 1");
  return $r->fetch_assoc();
}

$snap = fetch_last_snapshot($db, $normSym, $tf);
if (!$snap) fatal('No snapshots found');

// ----------------- Detect analysis table schema -----------------
$table = 'cbav_hud_analyses';
$has_sym    = has_col($db, $table, 'sym');
$has_symbol = has_col($db, $table, 'symbol');
$has_ver    = has_col($db, $table, 'ver');
$has_ts     = has_col($db, $table, 'ts');
$has_prob_l = has_col($db, $table, 'prob_long');
$has_prob_s = has_col($db, $table, 'prob_short');
$has_notes  = has_col($db, $table, 'notes');
$has_summary= has_col($db, $table, 'summary_md');
$has_raw    = has_col($db, $table, 'raw_json');
$has_result = has_col($db, $table, 'result_json');

// Build column list
$cols = ['snapshot_id','tf'];
$vals = [$snap['id'], intval($snap['tf'])];
$types = 'ii';

if ($has_ts)    { $cols[]='ts';    $vals[] = intval($snap['ts']); $types.='i'; }
if ($has_ver)   { $cols[]='ver';   $vals[] = (string)($snap['ver'] ?? '10.4'); $types.='s'; }

// Prefer to fill both 'sym' and 'symbol' if exist. Use the same normalized value.
$norm = (string)$snap['symbol'];
if ($has_sym)    { $cols[]='sym';    $vals[] = $norm; $types.='s'; }
if ($has_symbol) { $cols[]='symbol'; $vals[] = $norm; $types.='s'; }

// Minimal "analysis" payload – stub; UI needs something JSON-like
$payload = [
  'regime'     => 'trend',
  'bias'       => 'neutral',
  'confidence' => 43,
  'meta'       => ['source'=>'hud_analyze.php', 'ts'=>$snap['ts'], 'tf'=>intval($snap['tf'])]
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);

// Where to store JSON? Prefer result_json; fall back to raw_json or notes/summary_md.
if ($has_result) { $cols[]='result_json'; $vals[]=$json; $types.='s'; }
elseif ($has_raw){ $cols[]='raw_json';    $vals[]=$json; $types.='s'; }
elseif ($has_summary){ $cols[]='summary_md'; $vals[]=$json; $types.='s'; }
elseif ($has_notes){ $cols[]='notes'; $vals[]=$json; $types.='s'; }

if ($has_prob_l) { $cols[]='prob_long';  $vals[]=0.00; $types.='d'; }
if ($has_prob_s) { $cols[]='prob_short'; $vals[]=0.00; $types.='d'; }

// ----------------- INSERT -----------------
$colList = '`' . implode('`,`', $cols) . '`';
$qs = implode(',', array_fill(0, count($cols), '?'));
$sql = "INSERT INTO `$table` ($colList) VALUES ($qs)";
$stmt = $db->prepare($sql);

// bind dynamically
$stmt->bind_param($types, ...$vals);
$stmt->execute();

$aid = $stmt->insert_id;
echo json_encode(['ok'=>true, 'analysis_id'=>$aid, 'snapshot_id'=>intval($snap['id'])], JSON_UNESCAPED_UNICODE);
