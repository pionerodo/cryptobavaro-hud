<?php
// api/hud_analyze.php — run AI analysis for a snapshot and store into cbav_hud_analyses
// This version aligns ALL time to UTC:
//  - MySQL session is UTC (via api/db.php)
//  - analyzed_at is written with gmdate(...) (UTC)
//  - ts is stored from snapshot (already in ms UTC)

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

// ---- Helpers ----
function normalize_symbol($s) {
    $s = trim((string)$s);
    if ($s === '') return 'BINANCE:BTCUSDT';
    // If already in vendor:symbol form — keep
    if (strpos($s, ':') !== false) return strtoupper($s);
    // TradingView paper suffix -> BINANCE: spot form
    $s = strtoupper($s);
    $s = str_replace('.P', '', $s);
    if ($s === 'BTCUSDT') return 'BINANCE:BTCUSDT';
    return 'BINANCE:' . $s;
}
function json_reply($arr) {
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- Input ----
$snapshot_id = isset($_GET['snapshot_id']) ? intval($_GET['snapshot_id']) : 0;
$symParam    = isset($_GET['sym']) ? $_GET['sym'] : '';
$tfParam     = isset($_GET['tf']) ? intval($_GET['tf']) : 0;

$db = db();

// ---- Select snapshot ----
if ($snapshot_id > 0) {
    $q = $db->prepare("SELECT id, symbol, tf, ver, ts, price, features FROM cbav_hud_snapshots WHERE id=?");
    $q->bind_param('i', $snapshot_id);
    $q->execute();
    $snap = $q->get_result()->fetch_assoc();
    if (!$snap) jerr(404, 'Snapshot not found: ' . $snapshot_id);
} else {
    // Optional exact filter by symbol/tf if provided
    $clauses = [];
    $types = '';
    $vals = [];

    if ($symParam !== '') {
        $symN = normalize_symbol($symParam);
        $clauses[] = "symbol=?";
        $types    .= 's';
        $vals[]    = $symN;
    }
    if ($tfParam > 0) {
        $clauses[] = "tf=?";
        $types    .= 'i';
        $vals[]    = $tfParam;
    }
    $sql = "SELECT id, symbol, tf, ver, ts, price, features
            FROM cbav_hud_snapshots " . (count($clauses) ? ('WHERE ' . implode(' AND ', $clauses)) : '') . "
            ORDER BY id DESC LIMIT 1";
    $q = $db->prepare($sql);
    if ($types !== '') { $q->bind_param($types, ...$vals); }
    $q->execute();
    $snap = $q->get_result()->fetch_assoc();
    if (!$snap) jerr(404, 'No snapshots found');
    $snapshot_id = intval($snap['id']);
}

$symbol = $snap['symbol'];
$tf     = intval($snap['tf']);
$ver    = (string)$snap['ver'];
$ts     = intval($snap['ts']);
$price  = floatval($snap['price']);

$symbolNorm = normalize_symbol($symbol);

// ---- Dummy AI analysis (placeholder) ----
// You can swap this block for real LLM call later.
$features = @json_decode($snap['features'], true);
$atr = null;
if (is_array($features) && isset($features['atr'])) {
    $atr = floatval($features['atr']);
}
$result = [
    'regime'      => 'trend',
    'bias'        => 'neutral',
    'confidence'  => 55,
    'atr'         => $atr,
];
$result_json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ---- Write analysis in UTC ----
$analyzed_at = gmdate('Y-m-d H:i:s'); // UTC
$notes       = 'auto';
$prob_long   = 0.00;
$prob_short  = 0.00;
$summary_md  = null;

$sql = "INSERT INTO cbav_hud_analyses
          (snapshot_id, analyzed_at, symbol, tf, ver, ts, sym, result_json, notes, prob_long, prob_short, summary_md)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
$stmt = $db->prepare($sql);
if (!$stmt) jerr(500, 'Prepare failed: ' . $db->error);

// i s s i s i s s s d d s
$stmt->bind_param(
    'issisisssdds',
    $snapshot_id,
    $analyzed_at,
    $symbolNorm,  // symbol
    $tf,
    $ver,
    $ts,
    $symbolNorm,  // sym
    $result_json,
    $notes,
    $prob_long,
    $prob_short,
    $summary_md
);

if (!$stmt->execute()) {
    jerr(500, 'Execute failed: ' . $stmt->error);
}

$analysis_id = $stmt->insert_id;

json_reply([
    'ok'          => true,
    'analysis_id' => $analysis_id,
    'snapshot_id' => $snapshot_id,
    'symbol'      => $symbolNorm,
    'tf'          => $tf,
    'result'      => $result,
    'price'       => $price,
    'atr'         => $atr
]);
?>
