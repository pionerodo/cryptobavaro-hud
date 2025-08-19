<?php
// history.php — выдаёт историю анализов + CSV экспорт
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function base_symbol($s){
  $s = strtoupper(trim((string)$s));
  if (strpos($s, ':') !== false) $s = explode(':', $s, 2)[1];
  $s = preg_replace('/([.\-_]?P(?:ERP)?)$/', '', $s);
  $s = preg_replace('/\s+PERPETUAL.*$/', '', $s);
  $s = preg_replace('/\s+SWAP.*$/', '', $s);
  return preg_replace('/[^A-Z0-9]/', '', $s);
}
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

$qsym   = $_GET['symbol'] ?? '';
$tf     = $_GET['tf'] ?? '';
$mode   = $_GET['mode'] ?? 'json';
$limit  = max(10, min(1000, (int)($_GET['limit'] ?? 200)));
$start  = isset($_GET['start']) ? (int)$_GET['start'] : 0;
$end    = isset($_GET['end'])   ? (int)$_GET['end']   : 0;

try{
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

  $where = "1=1";
  $p = [];
  if ($tf!==''){ $where .= " AND s.tf=:tf"; $p[':tf']=$tf; }
  if ($qsym!==''){ $where .= " AND s.symbol LIKE :sym"; $p[':sym']='%'.base_symbol($qsym).'%'; }
  if ($start>0){ $where .= " AND s.ts >= :st"; $p[':st']=$start; }
  if ($end>0){ $where .= " AND s.ts <= :en"; $p[':en']=$end; }

  $sql = "SELECT s.id snapshot_id, s.symbol, s.tf, s.ts, s.price, a.id analysis_id, a.result_json
          FROM {$TB_SNAP} s
          LEFT JOIN {$TB_ANALYZE} a ON a.snapshot_id=s.id
          WHERE $where
          ORDER BY s.ts DESC
          LIMIT $limit";
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if ($mode==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="analysis_history.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['ts','symbol','tf','price','regime','bias','confidence','trend','range','expansion']);
    foreach($rows as $r){
      $a = $r['result_json'] ? json_decode($r['result_json'], true) : [];
      $p = $a['probabilities'] ?? [];
      fputcsv($out, [
        $r['ts'], $r['symbol'], $r['tf'], $r['price'],
        $a['regime'] ?? '', $a['bias'] ?? '', $a['confidence'] ?? '',
        $p['trend'] ?? '', $p['range'] ?? '', $p['expansion'] ?? ''
      ]);
    }
    exit;
  }

  out(['status'=>'ok','items'=>$rows]);

}catch(Throwable $e){
  out(['status'=>'error','error'=>$e->getMessage()]);
}
