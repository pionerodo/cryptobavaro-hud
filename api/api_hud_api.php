<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

function pick_sym_col($db){
  $q = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='cbav_hud_analyses' AND COLUMN_NAME IN ('sym','symbol') LIMIT 1");
  if ($q && $row = $q->fetch_assoc()) return $row['COLUMN_NAME'];
  return 'sym';
}
$symcol = pick_sym_col($db);

$cmd = $_GET['cmd'] ?? 'latest';
$sym = $_GET['sym'] ?? 'BTCUSDT';
$tf  = $_GET['tf']  ?? '5';

if ($cmd === 'latest') {
  $sql = "SELECT ts, {$symcol} AS sym, tf,
                 IFNULL(ver,'10.4') AS ver,
                 IFNULL(prob_long,  NULL) AS prob_long,
                 IFNULL(prob_short, NULL) AS prob_short,
                 COALESCE(summary_md, notes) AS summary_md
          FROM cbav_hud_analyses
          WHERE {$symcol}=? AND tf=?
          ORDER BY ts DESC LIMIT 1";
  $q = $db->prepare($sql);
  $q->bind_param('ss', $sym, $tf);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  echo json_encode(['ok'=>$r?1:0,'data'=>$r], JSON_UNESCAPED_UNICODE); exit;
}

if ($cmd === 'window') {
  $n = max(1, min(200, intval($_GET['n'] ?? 50)));
  $sql = "SELECT ts, {$symcol} AS sym, tf,
                 IFNULL(ver,'10.4') AS ver,
                 IFNULL(prob_long,  NULL) AS prob_long,
                 IFNULL(prob_short, NULL) AS prob_short
          FROM cbav_hud_analyses
          WHERE {$symcol}=? AND tf=?
          ORDER BY ts DESC LIMIT ?";
  $q = $db->prepare($sql);
  $q->bind_param('ssi', $sym, $tf, $n);
  $q->execute();
  $rs = $q->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode(['ok'=>1,'data'=>$rs], JSON_UNESCAPED_UNICODE); exit;
}

echo json_encode(['ok'=>0,'err'=>'unknown cmd']);
?>
