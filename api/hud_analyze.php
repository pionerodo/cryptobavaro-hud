<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/db.php';

/* 1) Берем последний снапшот по BTCUSDT / 5 (или параметрами sym/tf) */
$sym = $_GET['sym'] ?? 'BTCUSDT';
$tf  = $_GET['tf']  ?? '5';
$q   = $db->prepare("SELECT id, ts, payload_json FROM cbav_hud_snapshots WHERE sym=? AND tf=? ORDER BY ts DESC LIMIT 1");
$q->bind_param('ss', $sym, $tf);
$q->execute();
$res = $q->get_result();
$row = $res->fetch_assoc();
if (!$row) { echo json_encode(['ok'=>0,'err'=>'no snapshots']); exit; }

$payload = json_decode($row['payload_json'], true);
$features = $payload['f'] ?? [];

/* 2) Готовим промпт для OpenAI (либо используем эвристику, если ключа нет) */
$haveKey = !empty(getenv('OPENAI_API_KEY'));

function heuristic($f) {
  // очень грубая эвристика на старте (чтобы не блокировать пайплайн)
  $r5  = floatval($f['r5'] ?? 50);
  $h1  = intval($f['h1'] ?? 0);
  $ts  = intval($f['ts'] ?? 0);
  $bbr = floatval($f['bbr'] ?? 50);

  $pLong = 50; $pShort = 50;
  if ($ts==1 && $h1==1 && $r5>45 && $bbr<80) { $pLong = 65; $pShort = 35; }
  if ($ts==-1 && $h1==-1 && $r5<55 && $bbr<80){ $pLong = 35; $pShort = 65; }
  if ($bbr>=80) { $pLong = 50; $pShort=50; } // фаза расширения — нейтральнее
  return [$pLong, $pShort];
}

[$probLong, $probShort] = heuristic($features);

/* 3) Формируем текст по нашему шаблону (markdown) */
$price = number_format($payload['p'] ?? 0, 2, '.', '');
$summary = "# Общая картина\n".
"Цена BTC сейчас около **{$price} USDT**. ".
"Режим рынка по BB-percentile: **".($features['bbr'] ?? '—')."**; H1 EMA: **".($features['h1'] ?? 0)."**, HTF EMA: **".($features['ts'] ?? 0)."**.\n\n".
"## Индикаторы\n".
"- RSI (1/5/15/60m): ".($features['r1']??'—')." / ".($features['r5']??'—')." / ".($features['r15']??'—')." / ".($features['r60']??'—')."\n".
"- BB rank: ".($features['bbr']??'—')."; Дневной VWAP dist (ATR): ".($features['vw']??'—')."\n\n".
"## Ключевые уровни\n- Будут добавлены на следующем шаге из расширенного снапшота (box/OB/SFP).\n\n".
"## Сценарий для лонга / шорта\n- Вероятность лонга: **{$probLong}%**; шорта: **{$probShort}%**.\n".
"- Условия входа и стоп/тейк формируются после подключения уровней.\n\n".
"## Что бы сделал я (итог)\n".
"- Оценка нейтральна на старте тестов; дождаться сигналов входа (коридор).";

/* 4) Записываем в cbav_hud_analyses */
$ver = "hud_v10.5";
$ts  = intval($payload['ts'] ?? 0);
$stmt = $db->prepare("INSERT INTO cbav_hud_analyses(ts,sym,tf,ver,prob_long,prob_short,summary_md,raw_json)
                      VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE ver=VALUES(ver),prob_long=VALUES(prob_long),prob_short=VALUES(prob_short),summary_md=VALUES(summary_md),raw_json=VALUES(raw_json)");
$raw_json = json_encode(['features'=>$features], JSON_UNESCAPED_UNICODE);
$stmt->bind_param('isssddss', $ts, $sym, $tf, $ver, $probLong, $probShort, $summary, $raw_json);
$ok = $stmt->execute();
echo json_encode(['ok'=>$ok?1:0]);
