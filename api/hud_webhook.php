<?php
// /api/hud_webhook.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ---- конфиг простого лога
$LOG_FILE = __DIR__ . '/../logs/tv_webhook.log';   // => /www/wwwroot/cryptobavaro.online/logs/tv_webhook.log
@is_dir(dirname($LOG_FILE)) || @mkdir(dirname($LOG_FILE), 0755, true);
function wlog($msg){ global $LOG_FILE; @file_put_contents($LOG_FILE, "[".date('c')."] ".$msg."\n", FILE_APPEND); }

// ---- DB
require __DIR__ . '/db.php'; // использует текущие креды WP
$db->set_charset('utf8mb4');

// ---- читаем JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { wlog("bad json: ".$raw); http_response_code(400); echo json_encode(['ok'=>false,'err'=>'bad json']); exit; }

// ожидаем минимум:
$idTag = (string)($data['id'] ?? 'hud_v10_5');
$ver   = (string)($data['ver'] ?? '10.4');
$type  = (string)($data['t']   ?? 'hud');
$symIn = (string)($data['sym'] ?? 'BTCUSDT.P');
$tf    = (int)($data['tf']     ?? 5);
$ts    = (int)($data['ts']     ?? 0);
$price = (float)($data['p']    ?? 0);
$feat  = $data['f'] ?? [];

// ---- нормализация символа
function normalize_symbol(string $s): string {
    $s = trim($s);
    // BTCUSDT.P -> BINANCE:BTCUSDT
    if (preg_match('/^[A-Z0-9]+\.P$/', $s)) {
        $base = substr($s, 0, -2);
        return "BINANCE:{$base}";
    }
    // если уже в формате EXCHANGE:SYMBOL — оставляем
    if (strpos($s, ':') !== false) return $s;
    // иначе делаем по умолчанию BINANCE:<asset>
    return "BINANCE:".$s;
}
$symbolNormalized = normalize_symbol($symIn);

// ---- подготовка полезных полей
$featuresJson = json_encode($feat, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

// ---- утилита: проверка наличия колонки
function table_has_col(mysqli $db, string $table, string $col): bool {
    $res = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $res && $res->num_rows > 0;
}

// ---- адаптивная вставка в cbav_hud_snapshots
$tableSnaps = 'cbav_hud_snapshots';
$colSymSnaps = table_has_col($db, $tableSnaps, 'sym') ? 'sym' : 'symbol'; // у тебя это будет 'symbol'

$sql = "INSERT INTO `$tableSnaps`
        (received_at, id_tag, `$colSymSnaps`, tf, ver, ts, price, features)
        VALUES (CURRENT_TIMESTAMP, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $db->prepare($sql);
if (!$stmt) { wlog("prepare snaps failed: ".$db->error . " | SQL: ".$sql); http_response_code(500); echo json_encode(['ok'=>false,'err'=>'prepare snaps']); exit; }

$stmt->bind_param(
    'ssisdss',
    $idTag,
    $symbolNormalized,
    $tf,
    $ver,
    $ts,
    $price,
    $featuresJson
);
if (!$stmt->execute()) {
    wlog("exec snaps failed: ".$stmt->error);
    http_response_code(500);
    echo json_encode(['ok'=>false,'err'=>'exec snaps']);
    exit;
}
$snapshotId = $stmt->insert_id;
$stmt->close();

// можно сразу вернуть ok
wlog("ok: snapshot_id=$snapshotId symIn=$symIn -> {$symbolNormalized}, tf=$tf, ver=$ver, ts=$ts, price=$price");
echo json_encode(['ok'=>true,'snapshot_id'=>$snapshotId], JSON_UNESCAPED_UNICODE);
