<?php
// hud_analyze.php — adaptive INSERT with snapshot_id (fixes ER_NO_DEFAULT_FOR_FIELD)
// Usage (CLI or web): php hud_analyze.php [or] /api/hud_analyze.php?sym=BTCUSDT&tf=5
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

function logmsg($s) {
    $log = '/www/wwwroot/cryptobavaro.online/logs/analyze.log';
    @file_put_contents($log, '[' . gmdate('c') . "] hud_analyze: $s\n", FILE_APPEND);
}

function table_columns(mysqli $db, string $table): array {
    $cols = [];
    if ($res = $db->query("SHOW COLUMNS FROM {$table}")) {
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        $res->free();
    }
    return $cols;
}

// Нормализация тикера из любых вариантов в формат, принятыый в БД снапшотов
function norm_sym(string $s): string {
    $u = strtoupper(trim($s));
    if ($u === '' || $u === 'BTCUSDT' || $u === 'BTCUSDT.P' || $u === 'USDT_PERP:BTCUSDT') return 'BINANCE:BTCUSDT';
    return $u;
}

// Входные параметры (опционально)
$sym_in = isset($_GET['sym']) ? $_GET['sym'] : (isset($argv[1]) ? $argv[1] : 'BTCUSDT.P');
$tf     = isset($_GET['tf'])  ? intval($_GET['tf']) : (isset($argv[2]) ? intval($argv[2]) : 5);
$sym_db = norm_sym($sym_in);

// 1) Берём последний снапшот по symbol+tf (как хранится в cbav_hud_snapshots)
$q = $db->prepare("SELECT id, ts, symbol, tf, ver, price, features FROM cbav_hud_snapshots WHERE symbol=? AND tf=? ORDER BY ts DESC LIMIT 1");
$q->bind_param('si', $sym_db, $tf);
$q->execute();
$snap = $q->get_result()->fetch_assoc();
$q->close();

if (!$snap) {
    logmsg("no snapshot found for sym={$sym_db}, tf={$tf}");
    echo json_encode(['ok'=>false, 'error'=>'no_snapshot']);
    exit;
}

// 2) Простейший анализ (заглушка — чтобы UI начал жить)
$analysis = [
    'regime'     => 'trend',
    'bias'       => 'neutral',
    'confidence' => 43,
    'price'      => floatval($snap['price'])
];

// 3) Адаптивная вставка в cbav_hud_analyses (с учётом реальной схемы)
$cols = table_columns($db, 'cbav_hud_analyses');
$use  = [];   // имена колонок
$bind = '';   // строка типов для bind_param
$vals = [];   // значения

// Критично: snapshot_id (чтобы не было ER_NO_DEFAULT_FOR_FIELD)
if (in_array('snapshot_id', $cols, true)) {
    $use[] = 'snapshot_id'; $bind .= 'i'; $vals[] = intval($snap['id']);
}

// sym / symbol — сколько есть, столько и пишем
if (in_array('sym', $cols, true))    { $use[] = 'sym';    $bind .= 's'; $vals[] = $sym_db; }
if (in_array('symbol', $cols, true)) { $use[] = 'symbol'; $bind .= 's'; $vals[] = $sym_db; }

if (in_array('tf', $cols, true))  { $use[] = 'tf';  $bind .= 'i'; $vals[] = intval($tf); }
if (in_array('ver', $cols, true)) { $use[] = 'ver'; $bind .= 's'; $vals[] = strval($snap['ver']); }
if (in_array('ts', $cols, true))  { $use[] = 'ts';  $bind .= 'i'; $vals[] = intval($snap['ts']); }

if (in_array('result_json', $cols, true)) { $use[] = 'result_json'; $bind .= 's'; $vals[] = json_encode($analysis, JSON_UNESCAPED_UNICODE); }
if (in_array('summary_md', $cols, true))  { $use[] = 'summary_md';  $bind .= 's'; $vals[] = "Auto-analysis v" . strval($snap['ver']) . " demo"; }
if (in_array('prob_long', $cols, true))   { $use[] = 'prob_long';   $bind .= 'd'; $vals[] = 0.50; }
if (in_array('prob_short', $cols, true))  { $use[] = 'prob_short';  $bind .= 'd'; $vals[] = 0.50; }
if (in_array('notes', $cols, true))       { $use[] = 'notes';       $bind .= 's'; $vals[] = 'ok'; }

if (empty($use)) {
    logmsg('No suitable columns to insert into cbav_hud_analyses');
    echo json_encode(['ok'=>false, 'error'=>'no_columns']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($use), '?'));
$sql = "INSERT INTO cbav_hud_analyses (" . implode(',', $use) . ") VALUES ($placeholders)";
$stmt = $db->prepare($sql);

// Совместимый bind_param
$params = array_merge([$bind], $vals);
$tmp = [];
foreach ($params as $key => $value) { $tmp[$key] = $value; }
$refs = [];
foreach ($tmp as $key => &$value) { $refs[$key] =& $value; }
call_user_func_array([$stmt, 'bind_param'], $refs);

if (!$stmt->execute()) {
    logmsg('INSERT failed: ' . $stmt->error);
    echo json_encode(['ok'=>false, 'error'=>'insert_failed', 'details'=>$stmt->error]);
    exit;
}

$analysis_id = $db->insert_id;
logmsg("inserted analysis id={$analysis_id} for snapshot_id=" . intval($snap['id']));
echo json_encode(['ok'=>true, 'analysis_id'=>$analysis_id, 'snapshot_id'=>intval($snap['id'])]);
?>
