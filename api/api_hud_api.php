<?php
// hud_api.php — минимальный API для Dashboard (latest by sym/tf)
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

function table_columns(mysqli $db, string $table): array {
    $cols = [];
    if ($res = $db->query("SHOW COLUMNS FROM {$table}")) {
        while ($r = $res->fetch_assoc()) $cols[] = $r['Field'];
        $res->free();
    }
    return $cols;
}

function norm_sym(string $s): string {
    $u = strtoupper(trim($s));
    if ($u === '' || $u === 'BTCUSDT' || $u === 'BTCUSDT.P' || $u === 'USDT_PERP:BTCUSDT') return 'BINANCE:BTCUSDT';
    return $u;
}

$cmd   = isset($_GET['cmd']) ? $_GET['cmd'] : 'latest';
$sym   = norm_sym(isset($_GET['sym']) ? $_GET['sym'] : 'BTCUSDT');
$tf    = isset($_GET['tf']) ? intval($_GET['tf']) : 5;

if ($cmd === 'latest') {
    $cols = table_columns($db, 'cbav_hud_analyses');
    $colSym = in_array('sym', $cols, true) ? 'sym' : (in_array('symbol', $cols, true) ? 'symbol' : null);
    if (!$colSym) { echo json_encode(['ok'=>false, 'error'=>'no_sym_column']); exit; }

    $q = $db->prepare("SELECT id, snapshot_id, {$colSym} AS sym, tf, ver, ts, analyzed_at, result_json, summary_md, prob_long, prob_short
                       FROM cbav_hud_analyses WHERE {$colSym}=? AND tf=? ORDER BY ts DESC, id DESC LIMIT 1");
    $q->bind_param('si', $sym, $tf);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if (!$row) { echo json_encode(['ok'=>false, 'error'=>'not_found']); exit; }

    $payload = ['ok'=>true, 'row'=>$row];
    if (!empty($row['result_json'])) {
        $decoded = json_decode($row['result_json'], true);
        if (is_array($decoded)) $payload['analysis'] = $decoded;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false, 'error'=>'unknown_cmd']);
?>
