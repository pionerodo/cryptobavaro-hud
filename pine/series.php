<?php
// series.php — серия OHLCV для мини‑чарта
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function base_symbol($s){
  $s = strtoupper(trim((string)$s));
  if (strpos($s, ':') !== false) $s = explode(':', $s, 2)[1];
  $s = preg_replace('/([.\-_]?P(?:ERP)?)$/', '', $s);
  $s = preg_replace('/\s+PERPETUAL.*$/', '', $s);
  $s = preg_replace('/\s+SWAP.*$/', '', $s);
  return preg_replace('/[^A-Z0-9]/', '', $s);
}

$qsym   = $_GET['symbol'] ?? '';
$tf     = $_GET['tf'] ?? '5';
$limit  = max(20, min(400, (int)($_GET['limit'] ?? 200)));

if ($qsym==='') out(['status'=>'error','error'=>'symbol_required']);
$base = base_symbol($qsym);

try{
  $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

  $st = $pdo->prepare("SELECT * FROM {$TB_SNAP} WHERE tf=:tf ORDER BY ts DESC LIMIT 1200");
  $st->execute([':tf'=>$tf]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  $out  = [];
  foreach($rows as $r){
    if (base_symbol($r['symbol'])!==$base) continue;
    $f = json_decode($r['features'], true) ?: [];
    $o = $f['open']  ?? null;
    $h = $f['high']  ?? null;
    $l = $f['low']   ?? null;
    $c = $f['close'] ?? ($r['price'] ?? null);
    $v = $f['volume'] ?? ($f['vol'] ?? null);
    if ($o!==null && $h!==null && $l!==null && $c!==null){
      $out[] = [(int)$r['ts'], (float)$o,(float)$h,(float)$l,(float)$c, $v!==null?(float)$v:null];
    }
    if (count($out)>=$limit) break;
  }
  $out = array_reverse($out);
  out(['status'=>'ok','items'=>$out]);

}catch(Throwable $e){
  out(['status'=>'error','error'=>$e->getMessage()]);
}
