<?php
// /api/hud_ai.php
// Lightweight heuristic "AI" summary for the latest analysis row (no external APIs).
// Params: sym (default BINANCE:BTCUSDT), tf (default 5)

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$wp = $root . '/wp-config.php';
if (!file_exists($wp)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'wp-config.php not found']);
  exit;
}
require_once $wp;

$sym = isset($_GET['sym']) && $_GET['sym'] !== '' ? $_GET['sym'] : 'BINANCE:BTCUSDT';
$tf  = isset($_GET['tf'])  && is_numeric($_GET['tf']) ? intval($_GET['tf']) : 5;

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$mysqli->set_charset('utf8mb4');
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect']);
  exit;
}

$sql = "
  SELECT a.id AS analysis_id, a.snapshot_id, a.symbol, a.sym, a.tf, a.ver,
         s.ts, FROM_UNIXTIME(s.ts/1000) AS t_utc, s.price,
         a.result_json
  FROM cbav_hud_analyses a
  JOIN cbav_hud_snapshots s ON s.id = a.snapshot_id
  WHERE a.sym = ? AND a.tf = ?
  ORDER BY s.ts DESC
  LIMIT 1
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('si', $sym, $tf);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
  echo json_encode(['ok'=>false, 'error'=>'no_data']);
  exit;
}

// Parse result_json safely
$jr = json_decode($row['result_json'], true);
$regime = $jr['regime'] ?? 'unknown';
$bias   = $jr['bias'] ?? 'neutral';
$conf   = isset($jr['confidence']) ? intval($jr['confidence']) : null;
$atr    = $jr['atr'] ?? null;

$sentences = [];

// Trend / regime
if ($regime === 'trend') { $sentences[] = 'Рынок в трендовом режиме.'; }
elseif ($regime === 'range') { $sentences[] = 'Рынок торгуется в диапазоне.'; }
else { $sentences[] = 'Режим рынка определить сложно.'; }

// Bias
if ($bias === 'long') { $sentences[] = 'Преимущество у покупателей (лонг‑байас).'; }
elseif ($bias === 'short') { $sentences[] = 'Преимущество у продавцов (шорт‑байас).'; }
else { $sentences[] = 'Явного перекоса нет (нейтрально).'; }

// Confidence
if (!is_null($conf)) {
  if ($conf >= 66)      $sentences[] = 'Уверенность высокая.';
  elseif ($conf >= 40)  $sentences[] = 'Уверенность средняя.';
  else                  $sentences[] = 'Уверенность низкая.';
}

// ATR
if (!is_null($atr)) {
  $sentences[] = 'Текущая волатильность (ATR) ≈ ' . $atr . '.';
}

$advice = 'Действия: ';
if ($bias === 'long' && $regime === 'trend') {
  $advice .= 'рассматривать покупки по откатам, контролировать риск.';
} elseif ($bias === 'short' && $regime === 'trend') {
  $advice .= 'рассматривать продажи по откатам, контролировать риск.';
} else {
  $advice .= 'избегать агрессивных входов, дождаться ясного импульса/пробоя.';
}
$sentences[] = $advice;

$out = [
  'ok' => true,
  'sym' => $sym,
  'tf'  => $tf,
  'snapshot_id' => intval($row['snapshot_id']),
  'ts'  => intval($row['ts']),
  't_utc' => $row['t_utc'],
  'price' => is_null($row['price']) ? null : floatval($row['price']),
  'summary' => implode(' ', $sentences),
  'raw' => $jr
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
