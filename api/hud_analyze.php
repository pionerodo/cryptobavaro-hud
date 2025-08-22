<?php
/**
 * hud_analyze.php
 * Берёт последний снапшот из cbav_hud_snapshots по sym/tf,
 * делает быструю оценку вероятностей (эвристика) и записывает
 * итоговый markdown-анализ в cbav_hud_analyses (с версией из payload.ver).
 *
 * GET-параметры:
 *   sym=BTCUSDT   (по умолчанию BTCUSDT)
 *   tf=5          (по умолчанию 5)
 */

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';       // должен создавать $db = new mysqli(...)

/* -------- параметры -------- */
$sym = isset($_GET['sym']) && $_GET['sym'] !== '' ? $_GET['sym'] : 'BTCUSDT';
$tf  = isset($_GET['tf'])  && $_GET['tf']  !== '' ? $_GET['tf']  : '5';

/* -------- 1) берём последний снапшот -------- */
$q = $db->prepare("SELECT id, ts, payload_json FROM cbav_hud_snapshots WHERE sym=? AND tf=? ORDER BY ts DESC LIMIT 1");
$q->bind_param('ss', $sym, $tf);
$q->execute();
$res = $q->get_result();
$row = $res->fetch_assoc();

if (!$row) {
  echo json_encode(['ok'=>0,'err'=>'no snapshots for sym/tf','sym'=>$sym,'tf'=>$tf]);
  exit;
}

$payload = json_decode($row['payload_json'], true);
if (!$payload) {
  echo json_encode(['ok'=>0,'err'=>'bad json in snapshot','id'=>$row['id']]); exit;
}

/* -------- 2) распаковка полей из v10.4/v10.5 -------- */
// Версия протокола: в v10.5 кладём ver:"10.4" для стабильного парсера
$ver = isset($payload['ver']) ? (string)$payload['ver'] : '10.4';
$ts  = (int)($payload['ts'] ?? 0);
$price = (float)($payload['p'] ?? 0.0);
$f = isset($payload['f']) && is_array($payload['f']) ? $payload['f'] : [];

// Унификация фич (часть может отсутствовать в v10.4/v10.5 — обрабатываем мягко)
$bbRank = isset($f['bbr']) ? (float)$f['bbr'] : (isset($f['bbrank']) ? (float)$f['bbrank'] : 50.0);
$trendS = isset($f['ts'])  ? (int)$f['ts']  : 0;    // +1 / -1 / 0
$h1     = isset($f['h1'])  ? (int)$f['h1']  : 0;    // +1 / -1 / 0
$r1     = isset($f['r1'])  ? (float)$f['r1'] : null;
$r5     = isset($f['r5'])  ? (float)$f['r5'] : (isset($f['rsi']) ? (float)$f['rsi'] : null);
$r15    = isset($f['r15']) ? (float)$f['r15'] : null;
$r60    = isset($f['r60']) ? (float)$f['r60'] : null;
$vwDist = isset($f['vw'])  ? (float)$f['vw']  : (isset($f['dav']) ? (float)$f['dav'] : null);

// Уровни/паттерны/заметки — присутствуют в v10.4
$levels = isset($payload['lv']) && is_array($payload['lv']) ? $payload['lv'] : [];
$pats   = isset($payload['pat']) && is_array($payload['pat']) ? $payload['pat'] : [];
$note   = isset($f['note']) ? (string)$f['note'] : null;

/* -------- 3) эвристическая вероятность (до включения OpenAI) -------- */
function clamp01($x){ return $x < 0 ? 0 : ($x > 1 ? 1 : $x); }

$pLong = 0.50;  // базово нейтрально
$pShort= 0.50;

// трендовые факторы
$score = 0.0;
$score += ($trendS === 1) ? 0.15 : (($trendS === -1) ? -0.15 : 0.0);
$score += ($h1     === 1) ? 0.10 : (($h1     === -1) ? -0.10 : 0.0);

// bbRank (0..100): низкие значения — сжатие/диапазон, высокие — расширение
if ($bbRank <= 20) $score += 0.00;       // диапазон → нейтрально
elseif ($bbRank >= 80) $score += 0.00;   // расширение → нейтрально
else $score += 0.05 * (($trendS===1) ? 1 : (($trendS===-1) ? -1 : 0));

// RSI (5m) — мягкий осциллятор
if (!is_null($r5)) {
  if     ($r5 >= 60) $score += 0.05;
  elseif ($r5 <= 40) $score -= 0.05;
}

// преобразуем в вероятности
$pLong = clamp01(0.5 + $score);
$pShort= 1.0 - $pLong;

// в проценты
$probLong  = round($pLong*100, 2);
$probShort = round($pShort*100, 2);

/* -------- 4) markdown по нашему шаблону -------- */
function numfmt($x, $dec=2){ return number_format((float)$x, $dec, '.', ''); }
function listLevelsMd($levels){
  if (!$levels || !is_array($levels)) return "- Пока без детальных уровней (подключим расширенный снапшот).";
  // берём до 5 уровней
  $out = [];
  $i = 0;
  foreach ($levels as $lv) {
    if ($i>=5) break;
    $t = isset($lv['t'])  ? $lv['t'] : 'lv';
    $v = isset($lv['lv']) ? numfmt($lv['lv'], 2) : '—';
    $da= isset($lv['da']) ? numfmt($lv['da'], 2) : null;
    $out[] = "- {$t}: **{$v}**".(!is_null($da) ? " (ΔATR {$da})" : "");
    $i++;
  }
  return implode("\n", $out);
}
function patsMd($pats){
  if (!$pats || !is_array($pats)) return "—";
  return implode(", ", array_slice($pats, 0, 6));
}

$priceStr = numfmt($price, 2);
$bbrStr   = is_null($bbRank) ? "—" : numfmt($bbRank, 2);
$r1s = is_null($r1)  ? "—" : numfmt($r1,1);
$r5s = is_null($r5)  ? "—" : numfmt($r5,1);
$r15s= is_null($r15) ? "—" : numfmt($r15,1);
$r60s= is_null($r60) ? "—" : numfmt($r60,1);
$vws = is_null($vwDist) ? "—" : numfmt($vwDist,2);

$trendTxt = $trendS===1 ? "восходящий (HTF EMA 50>200)"
         : ($trendS===-1 ? "нисходящий (HTF EMA 50<200)" : "нейтральный");

$h1Txt = $h1===1 ? "H1 EMA вверх" : ($h1===-1 ? "H1 EMA вниз" : "H1 EMA нейтрально");

$summary =
"# Общая картина\n".
"Цена BTC сейчас около **{$priceStr} USDT**. Режим по BB‑rank: **{$bbrStr}**; тренд HTF: **{$trendTxt}**; {$h1Txt}.\n\n".
"## Индикаторы\n".
"- RSI (1m/5m/15m/60m): **{$r1s} / {$r5s} / {$r15s} / {$r60s}**\n".
"- Дистанция до дневного VWAP (в ATR): **{$vws}**\n".
($note ? "- Заметки: {$note}\n" : "") . "\n".
"## Ключевые уровни\n".
listLevelsMd($levels)."\n\n".
"## Сценарий для лонга / шорта\n".
"- Вероятность лонга: **{$probLong}%**, шорта: **{$probShort}%**.\n".
"- Подтверждения для входа: удержание локального уровня/реакция на VWAP; свечные сигналы на 5m; отсутствие экстремального расширения BB.\n\n".
"## Что бы сделал я (итог)\n".
"- Ждём вход в «коридор сделки» по твоим правилам страницы HUD.\n".
"- После включения новостей/sentiment будем повышать точность до цели 65%/70%.\n";

/* -------- 5) запись в cbav_hud_analyses -------- */
$stmt = $db->prepare(
  "INSERT INTO cbav_hud_analyses (ts,sym,tf,ver,prob_long,prob_short,summary_md,raw_json)
   VALUES (?,?,?,?,?,?,?,?)
   ON DUPLICATE KEY UPDATE
     ver=VALUES(ver),
     prob_long=VALUES(prob_long),
     prob_short=VALUES(prob_short),
     summary_md=VALUES(summary_md),
     raw_json=VALUES(raw_json)"
);
$raw_json = json_encode(['features'=>$f, 'levels'=>$levels, 'pats'=>$pats], JSON_UNESCAPED_UNICODE);
$stmt->bind_param('isssddss', $ts, $sym, $tf, $ver, $probLong, $probShort, $summary, $raw_json);
$ok = $stmt->execute();

echo json_encode(['ok'=>$ok?1:0,'sym'=>$sym,'tf'=>$tf,'ver'=>$ver,'prob_long'=>$probLong,'prob_short'=>$probShort]);
