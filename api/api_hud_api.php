<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

$cmd = $_GET['cmd'] ?? 'latest';
$sym = $_GET['sym'] ?? 'BTCUSDT';
$tf  = $_GET['tf']  ?? '5';

if ($cmd === 'latest') {
  $q = $db->prepare("SELECT ts,sym,tf,ver,prob_long,prob_short,summary_md
                     FROM cbav_hud_analyses
                     WHERE sym=? AND tf=?
                     ORDER BY ts DESC LIMIT 1");
  $q->bind_param('ss', $sym, $tf);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  echo json_encode(['ok'=>$r?1:0,'data'=>$r]); exit;
}

if ($cmd === 'window') {
  $n = max(1, min(200, intval($_GET['n'] ?? 50)));
  $q = $db->prepare("SELECT ts,sym,tf,ver,prob_long,prob_short
                     FROM cbav_hud_analyses
                     WHERE sym=? AND tf=?
                     ORDER BY ts DESC LIMIT ?");
  $q->bind_param('ssi', $sym, $tf, $n);
  $q->execute();
  $rs = $q->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode(['ok'=>1,'data'=>$rs]); exit;
}

echo json_encode(['ok'=>0,'err'=>'unknown cmd']);
?>
