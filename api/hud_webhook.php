<?php
/**
 * /api/hud_webhook.php
 * TradingView → Webhook endpoint.
 * Accepts JSON from Pine v10.5+ and saves a compact snapshot into MySQL.
 * Also writes a short line to /www/wwwroot/cryptobavaro.online/logs/tv_webhook.log
 *
 * Safety/robustness:
 * - Normalizes symbols (BTCUSDT.P, BTCUSDTPERP → BINANCE:BTCUSDT).
 * - Works if TV sends either "sym" or "symbol" (takes both, prefers 'sym').
 * - Ignores extra fields. Optional blocks "f", "levels", "patterns" are stored when present.
 * - Returns JSON {ok:true,snapshot_id:n}.
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php'; // expects $db = new mysqli(...)

function symbol_normalize(string $s): string {
    $s = trim($s);
    if ($s === '') return $s;

    // If already in TRADINGVIEW "EXCHANGE:SYMBOL" form — keep.
    if (strpos($s, ':') !== false) return strtoupper($s);

    $u = strtoupper($s);

    // Common perpetual suffixes
    $u = preg_replace('/(\.P|_PERP|PERP)$/', '', $u);

    // Known single symbols → assume BINANCE spot symbol
    if ($u === 'BTCUSDT' || $u === 'BTCUSDTP') {
        return 'BINANCE:BTCUSDT';
    }
    // Fallback: if it looks like XXXUSDT — prefix BINANCE:
    if (preg_match('/^[A-Z0-9]{3,}USDT$/', $u)) {
        return 'BINANCE:' . $u;
    }
    return $u; // last resort
}

// 1) Read body (TradingView sends JSON)
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);

// Allow simple key=value debug via GET when needed (non-TV)
if (!$body || !is_array($body)) {
    $body = [
        'id'  => $_GET['id']  ?? null,
        'ver' => $_GET['ver'] ?? null,
        't'   => $_GET['t']   ?? 'hud',
        'sym' => $_GET['sym'] ?? ($_GET['symbol'] ?? null),
        'tf'  => isset($_GET['tf']) ? (int)$_GET['tf'] : null,
        'ts'  => isset($_GET['ts']) ? (int)$_GET['ts'] : null,
        'p'   => isset($_GET['p'])  ? (float)$_GET['p'] : null,
    ];
}

$symIn = $body['sym'] ?? ($body['symbol'] ?? null);
$symbol = symbol_normalize((string)$symIn);

// Required fields
$tf = isset($body['tf']) ? (int)$body['tf'] : null;
$ts = isset($body['ts']) ? (int)$body['ts'] : null;
$price = isset($body['p']) ? (float)$body['p'] : null;
$ver = isset($body['ver']) ? (string)$body['ver'] : null;
$id_tag = isset($body['id']) ? (string)$body['id'] : null;

// Optional blobs
$features = isset($body['f']) ? json_encode($body['f'], JSON_UNESCAPED_UNICODE) : null;
$levels   = isset($body['levels']) ? json_encode($body['levels'], JSON_UNESCAPED_UNICODE) : null;
$patterns = isset($body['patterns']) ? json_encode($body['patterns'], JSON_UNESCAPED_UNICODE) : null;

if (!$symbol || !$tf || !$ts || $price === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_input', 'detail' => compact('symbol','tf','ts','price')]);
    exit;
}

// 2) Insert into cbav_hud_snapshots (use only columns that exist)
$table = 'cbav_hud_snapshots';

// Fetch column names
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

addField($insertCols, $params, $types, 'received_at', date('Y-m-d H:i:s'), 's', $cols);
addField($insertCols, $params, $types, 'id_tag', $id_tag ?? 'hud_v10_5', 's', $cols);
addField($insertCols, $params, $types, 'symbol', $symbol, 's', $cols);
addField($insertCols, $params, $types, 'tf', $tf, 'i', $cols);
addField($insertCols, $params, $types, 'ver', $ver, 's', $cols);
addField($insertCols, $params, $types, 'ts', $ts, 'i', $cols);
addField($insertCols, $params, $types, 'price', $price, 'd', $cols);
addField($insertCols, $params, $types, 'features', $features, 's', $cols);
addField($insertCols, $params, $types, 'levels', $levels, 's', $cols);
addField($insertCols, $params, $types, 'patterns', $patterns, 's', $cols);

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
$snapshotId = $stmt->insert_id;

// 3) Log single line
$logLine = sprintf(
    "[%s] ok: snapshot_id=%d symIn=%s -> %s, tf=%s, ver=%s, ts=%s, price=%s\n",
    gmdate('Y-m-d\TH:i:s\+00:00'),
    $snapshotId, (string)$symIn, $symbol, (string)$tf, (string)$ver, (string)$ts, (string)$price
);
@file_put_contents('/www/wwwroot/cryptobavaro.online/logs/tv_webhook.log', $logLine, FILE_APPEND);

echo json_encode(['ok' => true, 'snapshot_id' => $snapshotId]);
