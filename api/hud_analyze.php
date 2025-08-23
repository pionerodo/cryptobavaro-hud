<?php
/**
 * /api/hud_analyze.php
 * Picks the latest snapshot (or a specific snapshot_id), runs a lightweight rules-based
 * analysis (placeholder for AI), and stores the result to cbav_hud_analyses.
 *
 * Robust to schema differences: detects available columns and inserts only those.
 *
 * Usage examples:
 *   php hud_analyze.php
 *   php hud_analyze.php sym=BTCUSDT tf=5
 *   php hud_analyze.php snapshot_id=141
 *   https://.../api/hud_analyze.php?sym=BTCUSDT.P&tf=5
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php'; // expects $db (mysqli)

function arg($k, $def=null) {
    if (php_sapi_name() === 'cli') {
        global $argv;
        foreach ($argv as $a) {
            if (strpos($a, '=') !== false) {
                [$kk, $vv] = explode('=', $a, 2);
                if ($kk === $k) return $vv;
            }
        }
    }
    return $_GET[$k] ?? $def;
}

function symbol_normalize(string $s): string {
    $s = trim($s);
    if ($s === '') return $s;
    if (strpos($s, ':') !== false) return strtoupper($s);
    $u = strtoupper($s);
    $u = preg_replace('/(\.P|_PERP|PERP)$/', '', $u);
    if ($u === 'BTCUSDT' || $u === 'BTCUSDTP') return 'BINANCE:BTCUSDT';
    if (preg_match('/^[A-Z0-9]{3,}USDT$/', $u)) return 'BINANCE:' . $u;
    return $u;
}

$snapshot_id = (int) (arg('snapshot_id') ?? 0);
$tf = (int) (arg('tf') ?? 5);
$symIn = arg('sym') ?? arg('symbol') ?? 'BTCUSDT';
$symbol = symbol_normalize($symIn);

// 1) Fetch source snapshot
if ($snapshot_id > 0) {
    $q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE id=? LIMIT 1");
    $q->bind_param('i', $snapshot_id);
} else {
    $q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE symbol=? AND tf=? ORDER BY ts DESC LIMIT 1");
    $q->bind_param('si', $symbol, $tf);
}
$q->execute();
$src = $q->get_result()->fetch_assoc();
if (!$src) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'error'=>'snapshot_not_found', 'where'=>['symbol'=>$symbol,'tf'=>$tf,'snapshot_id'=>$snapshot_id]]);
    exit;
}

// 2) Very simple analysis stub (replace with AI later)
$price = (float)$src['price'];
$atr = 0.0;
if (!empty($src['features'])) {
    $jf = json_decode($src['features'], true);
    if (is_array($jf) && isset($jf['atr'])) $atr = (float)$jf['atr'];
}
$bias = 'neutral';
$conf = 43;
if ($atr > 0) {
    if ($price > (float)$src['price']) {
        $bias = 'long';
        $conf = 55;
    } else {
        $bias = 'short';
        $conf = 55;
    }
}

$result = [
    'regime'     => 'trend',
    'bias'       => $bias,
    'confidence' => $conf,
    'price'      => $price,
    'atr'        => $atr,
];

// 3) Insert into cbav_hud_analyses (only columns that exist)
$table = 'cbav_hud_analyses';
$cols = [];
$qr = $db->query("SHOW COLUMNS FROM `$table`");
if ($qr) {
    while ($r = $qr->fetch_assoc()) $cols[strtolower($r['Field'])] = true;
}
$insertCols = [];
$params = [];
$types = '';
function addField(&$insertCols, &$params, &$types, $name, $value, $typeChar, $cols) {
    if (isset($cols[strtolower($name)]) && $value !== null) {
        $insertCols[] = "`$name`";
        $params[] = $value;
        $types .= $typeChar;
    }
}

addField($insertCols, $params, $types, 'snapshot_id', (int)$src['id'], 'i', $cols);
addField($insertCols, $params, $types, 'analyzed_at', date('Y-m-d H:i:s'), 's', $cols);
addField($insertCols, $params, $types, 'symbol', $symbol, 's', $cols);
addField($insertCols, $params, $types, 'sym', $symbol, 's', $cols); // keep both the same everywhere
addField($insertCols, $params, $types, 'tf', (int)$src['tf'], 'i', $cols);
addField($insertCols, $params, $types, 'ver', (string)($src['ver'] ?? '10.4'), 's', $cols);
addField($insertCols, $params, $types, 'ts', (int)$src['ts'], 'i', $cols);
addField($insertCols, $params, $types, 'result_json', json_encode($result, JSON_UNESCAPED_UNICODE), 's', $cols);
addField($insertCols, $params, $types, 'notes', 'auto', 's', $cols);

$sql = "INSERT INTO `$table` (" . implode(',', $insertCols) . ") VALUES (" .
       implode(',', array_fill(0, count($insertCols), '?')) . ")";
$stmt = $db->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'prepare_failed','sql'=>$sql,'mysqli'=>$db->error]);
    exit;
}
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'execute_failed','mysqli'=>$stmt->error]);
    exit;
}
$analysisId = $stmt->insert_id;

echo json_encode([
    'ok' => true,
    'analysis_id' => $analysisId,
    'snapshot_id' => (int)$src['id'],
    'symbol' => $symbol,
    'tf' => (int)$src['tf'],
    'result' => $result,
]);
