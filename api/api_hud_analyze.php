<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

/** helpers **/
function has_col($db, $table, $col) {
  $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->bind_param('ss', $table, $col);
  $stmt->execute();
  $stmt->bind_result($cnt);
  $stmt->fetch();
  $stmt->close();
  return $cnt > 0;
}
function latest_snapshot($db, $sym, $tf) {
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
function numfmt($x,$d=2){ return number_format((float)$x,$d,'.',''); }

$sym = isset($_GET['sym']) && $_GET['sym'] !== '' ? $_GET['sym'] : 'BTCUSDT';
$tf  = isset($_GET['tf'])  && $_GET['tf']  !== '' ? $_GET['tf']  : '5';

$row = latest_snapshot($db, $sym, $tf);
if (!$row) { echo json_encode(['ok'=>0,'err'=>'no snapshots']); exit; }

$ts     = (int)($row['ts'] ?? 0);
$price  = isset($row['price']) ? (float)$row['price'] : 0.0;
$symbol = $row['symbol'] ?? $sym;
$tfRow  = (string)($row['tf'] ?? $tf);
$verCol = $row['ver'] ?? null;

/** Reconstruct payload from columns if compact JSON not present */
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

/** features extraction */
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

/** simple probability model */
function clamp01($x){ return $x<0?0:($x>1?1:$x); }
$score = 0.0;
$score += ($trendS===1) ? 0.15 : (($trendS===-1) ? -0.15 : 0.0);
$score += ($h1===1) ? 0.10 : (($h1===-1) ? -0.10 : 0.0);
if ($bbRank>20 && $bbRank<80) $score += 0.05 * (($trendS===1)?1:(($trendS===-1)?-1:0));
if (!is_null($r5)) { if ($r5>=60) $score += 0.05; elseif ($r5<=40) $score -= 0.05; }
$probLong  = round(clamp01(0.5 + $score) * 100, 2);
$probShort = round(100 - $probLong, 2);

/** summary markdown */
function listLevelsMd($levels){
  if (!$levels) return "- Пока без детальных уровней.";
  $out=[]; $i=0;
  foreach($levels as $lv){ if($i>=5)break;
    $t=$lv['t']??($lv['type']??'lv'); $v=isset($lv['lv'])?$lv['lv']:($lv['level']??null);
    $da=isset($lv['da'])?$lv['da']:($lv['dist_atr']??null);
    $out[]="- {$t}: **".($v!==null?number_format((float)$v,2,'.',''):'—')."**".($da!==null?" (ΔATR ".number_format((float)$da,2,'.','').")":"");
    $i++;
  }
  return implode("\n",$out);
}
$summary =
  "# Общая картина\n".
  "Цена BTC сейчас около **".numfmt($price,2)." USDT**. BB‑rank: **".numfmt($bbRank,2)."**; " .
  "тренд HTF: ".($trendS===1?"восходящий (HTF EMA 50>200)":($trendS===-1?"нисходящий (HTF EMA 50<200)":"нейтральный"))."; ".
  ($h1===1?"H1 EMA вверх":($h1===-1?"H1 EMA вниз":"H1 EMA нейтрально")).".\n\n".
  "## Индикаторы\n".
  "- RSI (1/5/15/60m): **".(is_null($r1)?'—':numfmt($r1,1))." / ".(is_null($r5)?'—':numfmt($r5,1))." / ".(is_null($r15)?'—':numfmt($r15,1))." / ".(is_null($r60)?'—':numfmt($r60,1))."**\n".
  "- Дистанция до дневного VWAP (ATR): **".(is_null($vwDist)?'—':numfmt($vwDist,2))."**\n".
  ($note ? "- Заметки: {$note}\n" : "")."\n".
  "## Ключевые уровни\n".listLevelsMd($levels)."\n\n".
  "## Сценарий для лонга / шорта\n".
  "- Вероятность лонга: **{$probLong}%**, шорта: **{$probShort}%**.\n\n".
  "## Что бы сделал я (итог)\n".
  "- Ждём вход в коридор сделки и подтверждения на 5m.\n";

/** Adaptive INSERT into cbav_hud_analyses */
$table = 'cbav_hud_analyses';
$col_sym = has_col($db,$table,'sym') ? 'sym' : (has_col($db,$table,'symbol') ? 'symbol' : null);
if ($col_sym === null) { echo json_encode(['ok'=>0,'err'=>'analyses table has no sym/symbol']); exit; }

$cols = ['ts','tf',$col_sym];
$vals = ['ts'=>$ts, 'tf'=>$tfRow, $col_sym=>$symbol];
$types = 'iss';

if (has_col($db,$table,'ver'))        { $cols[]='ver';        $vals['ver']=$ver;            $types.='s'; }
if (has_col($db,$table,'prob_long'))  { $cols[]='prob_long';  $vals['prob_long']=$probLong; $types.='d'; }
if (has_col($db,$table,'prob_short')) { $cols[]='prob_short'; $vals['prob_short']=$probShort;$types.='d'; }
if (has_col($db,$table,'summary_md')) { $cols[]='summary_md'; $vals['summary_md']=$summary; $types.='s'; }
elseif (has_col($db,$table,'notes'))  { $cols[]='notes';      $vals['notes']=$summary;      $types.='s'; }
if (has_col($db,$table,'raw_json'))   { $cols[]='raw_json';   $vals['raw_json']=json_encode(['payload'=>$payload],JSON_UNESCAPED_UNICODE); $types.='s'; }
elseif (has_col($db,$table,'result_json')) { $cols[]='result_json'; $vals['result_json']=json_encode(['payload'=>$payload],JSON_UNESCAPED_UNICODE); $types.='s'; }

$col_list = implode(',', $cols);
$placeholders = implode(',', array_fill(0, count($cols), '?'));

$sql = "INSERT INTO {$table} ({$col_list}) VALUES ({$placeholders})";
$stmt = $db->prepare($sql);
$bind_vals = array_values($vals);
$bind = [];
$bind[] = $types;
foreach ($bind_vals as $i => $v) { $bind[] = &$bind_vals[$i]; }
call_user_func_array([$stmt, 'bind_param'], $bind);

$ok = $stmt->execute();
echo json_encode([
  'ok'=>$ok?1:0,
  'sym'=>$symbol,'tf'=>$tfRow,'ver'=>$ver,
  'prob_long'=>$probLong,'prob_short'=>$probShort,
  'used_cols'=>$cols
], JSON_UNESCAPED_UNICODE);
?>
