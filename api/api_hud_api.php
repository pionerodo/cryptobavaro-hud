<?php
// /api/hud_api.php  — REST for Dashboard
// Safe, adaptive to actual DB schema (works even if some columns are absent).
// Usage:
//   /api/hud_api.php?cmd=latest&sym=BTCUSDT&tf=5
//   /api/hud_api.php?cmd=history&sym=BTCUSDT&tf=5&limit=50
//   /api/hud_api.php?cmd=ping

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

function jexit($arr) {
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function has_col($db, $table, $col) {
  $q = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $q->bind_param('ss', $table, $col);
  $q->execute();
  $q->bind_result($cnt);
  $q->fetch();
  $q->close();
  return ($cnt > 0);
}

// Normalize symbol similar to webhook/analyzer
function norm_symbol($s) {
  $s = strtoupper(trim($s));
  $s = str_replace(array('.', 'PERP'), '', $s);
  if ($s === 'BTCUSDT' || $s === 'BINANCE:BTCUSDT') return 'BINANCE:BTCUSDT';
  if ($s === 'BTCUSDTP' || $s === 'BTCUSDT_P' || $s === 'BTCUSDT_P') return 'BINANCE:BTCUSDT';
  // Fallback — return as-is if already has exchange prefix
  if (strpos($s, ':') !== false) return $s;
  return 'BINANCE:' . $s;
}

$cmd = isset($_GET['cmd']) ? $_GET['cmd'] : 'latest';
if ($cmd === 'ping') jexit(['ok'=>true, 'ts'=>time()]);

$sym = isset($_GET['sym']) && $_GET['sym']!=='' ? $_GET['sym'] : 'BTCUSDT';
$tf  = isset($_GET['tf']) && $_GET['tf']!=='' ? intval($_GET['tf']) : 5;
$symbolNorm = norm_symbol($sym);

// Resolve columns
$tableA = 'cbav_hud_analyses';
$tableS = 'cbav_hud_snapshots';

$has_sym      = has_col($db, $tableA, 'sym');
$has_symbol   = has_col($db, $tableA, 'symbol');
$has_result   = has_col($db, $tableA, 'result_json');
$has_summary  = has_col($db, $tableA, 'summary_md');
$has_probL    = has_col($db, $tableA, 'prob_long');
$has_probS    = has_col($db, $tableA, 'prob_short');
$has_notes    = has_col($db, $tableA, 'notes');
$has_ts       = has_col($db, $tableA, 'ts');
$has_ver      = has_col($db, $tableA, 'ver');
$has_analyzed = has_col($db, $tableA, 'analyzed_at');

$selSymbol = $has_sym ? 'a.sym' : ($has_symbol ? 'a.symbol' : 'NULL');
$selTs     = $has_ts ? 'a.ts' : 's.ts';
$selVer    = $has_ver ? 'a.ver' : 'NULL';
$selAnalyzedAt = $has_analyzed ? 'a.analyzed_at' : 'NULL';

$sel = "a.id, a.snapshot_id, {$selSymbol} AS symbol, a.tf, {$selVer} AS ver, {$selTs} AS ts";
if ($has_result)  $sel .= ", a.result_json";
if ($has_summary) $sel .= ", a.summary_md";
if ($has_probL)   $sel .= ", a.prob_long";
if ($has_probS)   $sel .= ", a.prob_short";
if ($has_notes)   $sel .= ", a.notes";
$sel .= ", {$selAnalyzedAt} AS analyzed_at, s.price";

$where = ($has_sym ? "a.sym=?" : ($has_symbol ? "a.symbol=?" : "1=1")) . " AND a.tf=?";
$sqlBase = "FROM {$tableA} a LEFT JOIN {$tableS} s ON s.id=a.snapshot_id WHERE {$where}";

if ($cmd === 'latest') {
  $sql = "SELECT {$sel} {$sqlBase} ORDER BY {$selTs} DESC, a.id DESC LIMIT 1";
  $q = $db->prepare($sql);
  if ($has_sym || $has_symbol) $q->bind_param('si', $symbolNorm, $tf); else $q->bind_param('i', $tf);
  $q->execute();
  $res = $q->get_result();
  if (!$res || $res->num_rows === 0) jexit(['ok'=>true, 'data'=>null, 'message'=>'no rows']);
  $row = $res->fetch_assoc();
  // Parse result_json if present
  if (isset($row['result_json']) && $row['result_json']) {
    $row['result'] = json_decode($row['result_json'], true);
  }
  jexit(['ok'=>true, 'data'=>$row]);
}

if ($cmd === 'history') {
  $limit = isset($_GET['limit']) ? max(1, min(500, intval($_GET['limit']))) : 50;
  $sql = "SELECT {$sel} {$sqlBase} ORDER BY {$selTs} DESC, a.id DESC LIMIT {$limit}";
  $q = $db->prepare($sql);
  if ($has_sym || $has_symbol) $q->bind_param('si', $symbolNorm, $tf); else $q->bind_param('i', $tf);
  $q->execute();
  $res = $q->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    if (isset($r['result_json']) && $r['result_json']) {
      $r['result'] = json_decode($r['result_json'], true);
    }
    $rows[] = $r;
  }
  jexit(['ok'=>true, 'data'=>$rows]);
}

jexit(['ok'=>false, 'error'=>'unknown cmd']);
