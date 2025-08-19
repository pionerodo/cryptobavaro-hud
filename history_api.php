<?php
// history_api.php — JSON для графиков/дашборда
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$TB_HISTORY = isset($TB_HISTORY) ? $TB_HISTORY : 'cbav_analysis_history';

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function base_from_symbol($s){
  $s = strtoupper(trim((string)$s));
  if (strpos($s, ':') !== false) $s = explode(':', $s, 2)[1];
  $s = preg_replace('/([.\-_]?P(?:ERP)?)$/', '', $s);
  $s = preg_replace('/\s+PERPETUAL.*$/', '', $s);
  return $s;
}
function norm_symbol($s){ return 'BINANCE:'.base_from_symbol($s); }

try{
    $symbol = $_GET['symbol'] ?? '';
    $tf     = $_GET['tf'] ?? '';
    $limit  = (int)($_GET['limit'] ?? 500); // по умолчанию 500 последних точек

    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

    $where = [];
    $params = [];
    if ($symbol!=='') { $where[]="symbol=:sym"; $params[':sym']=norm_symbol($symbol); }
    if ($tf!=='')     { $where[]="tf=:tf";      $params[':tf']=$tf; }

    $sql = "SELECT ts, price, regime, bias, confidence, p_trend, p_range, p_expansion, _source
            FROM {$TB_HISTORY}";
    if ($where) $sql .= " WHERE ".implode(" AND ", $where);
    $sql .= " ORDER BY ts DESC LIMIT :lim";

    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k,$v);
    $st->bindValue(':lim', max(1,$limit), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // перевернём в хронологию (старые → новые), удобно для графиков
    $rows = array_reverse($rows);

    out(["status"=>"ok","count"=>count($rows),"items"=>$rows]);
} catch(Throwable $e){
    out(["status"=>"error","error"=>$e->getMessage()]);
}
