<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

function analyses_sym_col($db){
  $q = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='cbav_hud_analyses' AND COLUMN_NAME IN ('sym','symbol') LIMIT 1");
  if ($q && $row = $q->fetch_assoc()) return $row['COLUMN_NAME'];
  return 'sym'; // fallback
}
$symcol = analyses_sym_col($db);

$cmd = $_GET['cmd'] ?? 'latest';
$sym = $_GET['sym'] ?? 'BTCUSDT';
$tf  = $_GET['tf']  ?? '5';

if ($cmd === 'latest') {
  $sql = "SELECT ts,{$symcol} AS sym,tf,ver,prob_long,prob_short,summary_md
          FROM cbav_hud_analyses
          WHERE {$symcol}=? AND tf=?
          ORDER BY ts DESC LIMIT 1";
  $q = $db->prepare($sql);
  $q->bind_param('ss', $sym, $tf);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  echo json_encode(['ok'=>$r?1:0,'data'=>$r]); exit;
}

if ($cmd === 'window') {
  $n = max(1, min(200, intval($_GET['n'] ?? 50)));
  $sql = "SELECT ts,{$symcol} AS sym,tf,ver,prob_long,prob_short
          FROM cbav_hud_analyses
          WHERE {$symcol}=? AND tf=?
          ORDER BY ts DESC LIMIT ?";
  $q = $db->prepare($sql);
  $q->bind_param('ssi', $sym, $tf, $n);
  $q->execute();
  $rs = $q->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode(['ok'=>1,'data'=>$rs]); exit;
}

echo json_encode(['ok'=>0,'err'=>'unknown cmd']);
?>
