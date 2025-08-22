<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

$sym = isset($_GET['sym']) && $_GET['sym'] !== '' ? $_GET['sym'] : 'BTCUSDT';
$tf  = isset($_GET['tf'])  && $_GET['tf']  !== '' ? $_GET['tf']  : '5';

function fetch_last_snapshot($db, $sym, $tf) {
  $q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE symbol=? AND tf=? ORDER BY ts DESC LIMIT 1");
  $q->bind_param('ss', $sym, $tf);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  if ($r) return $r;
  $q = $db->prepare("SELECT * FROM cbav_hud_snapshots WHERE symbol=? ORDER BY ts DESC LIMIT 1");
  $q->bind_param('s', $sym);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  if ($r) return $r;
  $q = $db->query("SELECT * FROM cbav_hud_snapshots ORDER BY ts DESC LIMIT 1");
  return $q->fetch_assoc();
}

$row = fetch_last_snapshot($db, $sym, $tf);
if (!$row) { echo json_encode(['ok'=>0,'err'=>'no snapshots']); exit; }

$ts     = (int)($row['ts'] ?? 0);
$price  = isset($row['price']) ? (float)$row['price'] : 0.0;
$symbol = $row['symbol'] ?? 'BTCUSDT';
$tfRow  = (string)($row['tf'] ?? $tf);
$verCol = $row['ver'] ?? null;

$payload = null;
if (!empty($row['payload_json'])) {
  $payload = json_decode($row['payload_json'], true);
}
if (!$payload) {
  $features = [];
  if (!empty($row['features'])) {
    $tmp = json_decode($row['features'], true);
    if (is_array($tmp)) $features = $tmp;
  }
  $levels = [];
  if (!empty($row['levels'])) {
    $tmp = json_decode($row['levels'], true);
    if (is_array($tmp)) $levels = $tmp;
  }
  $patterns = [];
  if (!empty($row['patterns'])) {
    $tmp = json_decode($row['patterns'], true);
    if (is_array($tmp)) $patterns = $tmp;
  }
  $payload = [
    'ver' => $verCol ?: '10.4',
    'id'  => $row['id_tag'] ?? 'hud_v10',
    't'   => 'hud',
    'sym' => $symbol,
    'tf'  => $tfRow,
    'ts'  => $ts,
    'p'   => $price,
    'f'   => $features,
    'lv'  => $levels,
    'pat' => $patterns,
  ];
}

$f       = isset($payload['f']) ? $payload['f'] : [];
$ver     = isset($payload['ver']) ? (string)$payload['ver'] : '10.4';
$bbRank  = isset($f['bbr']) ? (float)$f['bbr'] : (isset($f['bb_rank']) ? (float)$f['bb_rank'] : 50.0);
$trendS  = isset($f['ts'])  ? (int)$f['ts']   : 0;
$h1      = isset($f['h1'])  ? (int)$f['h1']   : 0;
$r1      = isset($f['r1'])  ? (float)$f['r1'] : null;
$r5      = isset($f['r5'])  ? (float)$f['r5'] : (isset($f['rsi']) ? (float)$f['rsi'] : null);
$r15     = isset($f['r15']) ? (float)$f['r15'] : null;
$r60     = isset($f['r60']) ? (float)$f['r60'] : null;
$vwDist  = isset($f['vw'])  ? (float)$f['vw']  : (isset($f['dav']) ? (float)$f['dav'] : null);
$levels  = $payload['lv'] ?? [];
$patterns= $payload['pat'] ?? [];
$note    = isset($f['note']) ? (string)$f['note'] : null;

function clamp01($x){ return $x<0?0:($x>1?1:$x); }
$score = 0.0;
$score += ($trendS===1) ? 0.15 : (($trendS===-1) ? -0.15 : 0.0);
$score += ($h1===1) ? 0.10 : (($h1===-1) ? -0.10 : 0.0);
if ($bbRank>20 && $bbRank<80) $score += 0.05 * (($trendS===1)?1:(($trendS===-1)?-1:0));
if (!is_null($r5)) { if ($r5>=60) $score += 0.05; elseif ($r5<=40) $score -= 0.05; }
$probLong  = round(clamp01(0.5 + $score) * 100, 2);
$probShort = round(100 - $probLong, 2);

function numfmt($x,$d=2){ return number_format((float)$x,$d,'.',''); }
function listLevelsMd($levels){
  if (!$levels) return "- Пока без детальных уровней.";
  $out=[]; $i=0;
  foreach($levels as $lv){ if($i>=5)break;
    $t=$lv['t']??($lv['type']??'lv'); $v=isset($lv['lv'])?$lv['lv']:($lv['level']??null);
    $da=isset($lv['da'])?$lv['da']:($lv['dist_atr']??null);
    $out[]="- {$t}: **".($v!==null?numfmt($v,2):'—')."**".($da!==null?" (ΔATR ".numfmt($da,2).")":"");
    $i++;
  }
  return implode("\n",$out);
}
$priceStr = numfmt($price,2);
$bbrStr   = numfmt($bbRank,2);
$r1s      = is_null($r1)  ? "—" : numfmt($r1,1);
$r5s      = is_null($r5)  ? "—" : numfmt($r5,1);
$r15s     = is_null($r15) ? "—" : numfmt($r15,1);
$r60s     = is_null($r60) ? "—" : numfmt($r60,1);
$vws      = is_null($vwDist) ? "—" : numfmt($vwDist,2);
$trendTxt = $trendS===1 ? "восходящий (HTF EMA 50>200)" : ($trendS===-1 ? "нисходящий (HTF EMA 50<200)" : "нейтральный");
$h1Txt    = $h1===1 ? "H1 EMA вверх" : ($h1===-1 ? "H1 EMA вниз" : "H1 EMA нейтрально");

$summary =
"# Общая картина\n".
"Цена BTC сейчас около **{$priceStr} USDT**. BB‑rank: **{$bbrStr}**; тренд HTF: **{$trendTxt}**; {$h1Txt}.\n\n".
"## Индикаторы\n".
"- RSI (1/5/15/60m): **{$r1s} / {$r5s} / {$r15s} / {$r60s}**\n".
"- Дистанция до дневного VWAP (ATR): **{$vws}**\n".
($note ? "- Заметки: {$note}\n" : "") . "\n".
"## Ключевые уровни\n".listLevelsMd($levels)."\n\n".
"## Сценарий для лонга / шорта\n".
"- Вероятность лонга: **{$probLong}%**, шорта: **{$probShort}%**.\n\n".
"## Что бы сделал я (итог)\n".
"- Ждём вход в коридор сделки и подтверждения на 5m.\n";

$stmt = $db->prepare(
 "INSERT INTO cbav_hud_analyses (ts,sym,tf,ver,prob_long,prob_short,summary_md,raw_json)
  VALUES (?,?,?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE ver=VALUES(ver),prob_long=VALUES(prob_long),
  prob_short=VALUES(prob_short),summary_md=VALUES(summary_md),raw_json=VALUES(raw_json)"
);
$raw_json = json_encode(['payload'=>$payload], JSON_UNESCAPED_UNICODE);
$stmt->bind_param('isssddss', $ts, $symbol, $tfRow, $ver, $probLong, $probShort, $summary, $raw_json);
$ok = $stmt->execute();

echo json_encode(['ok'=>$ok?1:0,'sym'=>$symbol,'tf'=>$tfRow,'ver'=>$ver,'prob_long'=>$probLong,'prob_short'=>$probShort]);
?>
