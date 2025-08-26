<?php
// export_history.php — CSV экспорт истории
require __DIR__ . '/config.php';

$TB_HISTORY = isset($TB_HISTORY) ? $TB_HISTORY : 'cbav_analysis_history';

// --- входные параметры ---
$symbol = $_GET['symbol'] ?? '';     // BTCUSDT или BINANCE:BTCUSDT
$tf     = $_GET['tf'] ?? '';         // 5 / 15 / ...
$start  = $_GET['start'] ?? '';      // YYYY-MM-DD
$end    = $_GET['end'] ?? '';        // YYYY-MM-DD
$limit  = (int)($_GET['limit'] ?? 10000);

function base_from_symbol($s){
  $s = strtoupper(trim((string)$s));
  if (strpos($s, ':') !== false) $s = explode(':', $s, 2)[1];
  $s = preg_replace('/([.\-_]?P(?:ERP)?)$/', '', $s);
  $s = preg_replace('/\s+PERPETUAL.*$/', '', $s);
  return $s;
}
function norm_symbol($s){ return 'BINANCE:'.base_from_symbol($s); }

// --- DB ---
$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
$pdo = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

$where = [];
$params = [];

if ($symbol!=='') {
    $exact = norm_symbol($symbol);
    $where[] = "symbol = :sym";
    $params[':sym'] = $exact;
}
if ($tf!=='') {
    $where[] = "tf = :tf";
    $params[':tf'] = $tf;
}
if ($start!=='') {
    $where[] = "DATE(FROM_UNIXTIME(ts/1000)) >= :d1";
    $params[':d1'] = $start;
}
if ($end!=='') {
    $where[] = "DATE(FROM_UNIXTIME(ts/1000)) <= :d2";
    $params[':d2'] = $end;
}
$sql = "SELECT id, created_at, snapshot_id, symbol, tf, ts, price, regime, bias, confidence,
               p_trend, p_range, p_expansion, _source
        FROM {$TB_HISTORY}";
if ($where) $sql .= " WHERE ".implode(" AND ", $where);
$sql .= " ORDER BY ts DESC LIMIT :lim";

$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k, $v);
$st->bindValue(':lim', max(1,$limit), PDO::PARAM_INT);
$st->execute();

$filename = "history_".date('Ymd_His').".csv";
header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename={$filename}");

$out = fopen('php://output', 'w');
// BOM для Excel
fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, [
  'id','created_at','snapshot_id','symbol','tf','ts','datetime','price',
  'regime','bias','confidence','p_trend','p_range','p_expansion','source'
]);

while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $datetime = date('Y-m-d H:i:s', (int)($row['ts']/1000));
    fputcsv($out, [
        $row['id'],
        $row['created_at'],
        $row['snapshot_id'],
        $row['symbol'],
        $row['tf'],
        $row['ts'],
        $datetime,
        $row['price'],
        $row['regime'],
        $row['bias'],
        $row['confidence'],
        $row['p_trend'],
        $row['p_range'],
        $row['p_expansion'],
        $row['_source']
    ]);
}
fclose($out);
