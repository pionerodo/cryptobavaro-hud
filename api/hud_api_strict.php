cat > api/hud_api_strict.php <<'PHP'
<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$sym  = $_GET['sym']  ?? 'BINANCE:BTCUSDT';
$tf   = (int)($_GET['tf'] ?? 5);
$limit= (int)($_GET['limit'] ?? 50);

$mysqli = db();
$stmt = $mysqli->prepare("
    SELECT id, snapshot_id, symbol, sym, tf, ver, ts,
           JSON_EXTRACT(result_json,'$.confidence') AS conf
    FROM cbav_hud_analyses
    WHERE sym=? AND tf=?
    ORDER BY ts DESC
    LIMIT ?
");
$stmt->bind_param('sii', $sym, $tf, $limit);
$stmt->execute();
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['ok'=>true, 'items'=>$res], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
PHP
