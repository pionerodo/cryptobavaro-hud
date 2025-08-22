<?php
/**
 * TV Webhook -> DB
 * Принимает снапшоты из Pine и сохраняет в БД:
 *  - tv_snaps_raw  (сырые запросы, уникальность по symbol/tf/ts)
 *  - hud_snapshots (агрегированные поля для дашборда)
 *
 * Требования к схеме (из 001_init.sql):
 *  tv_snaps_raw(
 *    id BIGINT PK AI,
 *    symbol VARCHAR(64), tf VARCHAR(8), ts BIGINT, label VARCHAR(32), price DECIMAL(18,2),
 *    raw_json LONGTEXT, received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *    UNIQUE KEY uniq_sym_tf_ts(symbol, tf, ts)
 *  )
 *
 *  hud_snapshots(
 *    id BIGINT PK AI,
 *    symbol VARCHAR(64), tf VARCHAR(8), ts BIGINT, price DECIMAL(18,2),
 *    f_json LONGTEXT, lv_json LONGTEXT, pat_json LONGTEXT,
 *    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *    KEY idx_sym_tf_ts(symbol, tf, ts), KEY idx_ts(ts)
 *  )
 */

require_once __DIR__ . '/config.php'; // в config.php уже есть db() и json_out()
if (!function_exists('json_out')) {
  // на случай старой версии config.php
  function json_out($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

function body_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') {
    json_out(['status'=>'error','error'=>'empty body'], 400);
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    json_out(['status'=>'error','error'=>'invalid json'], 400);
  }
  return [$data, $raw];
}

/** Быстрый тип/диапазонный каст */
function s($v): string { return is_string($v) ? trim($v) : (string)$v; }
function n($v) { return is_numeric($v) ? 0 + $v : null; }

try {
  [$in, $raw] = body_json();

  // поддерживаем только снапшоты
  $type = strtolower(s($in['type'] ?? ''));
  if ($type !== 'hud_snapshot') {
    json_out(['status'=>'error','error'=>'unsupported type','type'=>$type], 400);
  }

  // обязательные
  $symbol = s($in['symbol'] ?? '');
  $tf     = s($in['tf'] ?? '');
  $ts     = n($in['ts'] ?? null);
  $price  = n($in['price'] ?? null);

  if ($symbol === '' || $tf === '' || $ts === null || $price === null) {
    json_out(['status'=>'error','error'=>'missing required: symbol/tf/ts/price'], 400);
  }

  // необязательные семантические поля
  $label    = s($in['id'] ?? 'hud');              // метка версии из Pine
  $features = $in['features'] ?? [];              // { mm, mc, ... }
  $levels   = $in['levels']   ?? [];              // [ {t, lv, da}, ... ]
  $patterns = $in['patterns'] ?? [];              // [ "DT", ... ]
  $analysis = $in['analysis'] ?? ['notes'=>'', 'playbook'=>[]];

  // Нормализация feature/level/pattern
  // features: оставим только то, чем реально пользуемся сейчас
  $mm = isset($features['mm']) ? s($features['mm']) : null; // режим рынка
  $mc = isset($features['mc']) ? (int)$features['mc'] : null; // уверенность 0..100

  // сериализация в компактный JSON
  $f_json   = json_encode(['mm'=>$mm, 'mc'=>$mc], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $lv_json  = json_encode($levels,   JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $pat_json = json_encode($patterns, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  // --- БД
  $pdo = db();
  $pdo->beginTransaction();

  // 1) Сырьё: tv_snaps_raw (уникальность по symbol/tf/ts)
  $sqlRaw = "
    INSERT INTO tv_snaps_raw (symbol, tf, ts, label, price, raw_json)
    VALUES (:symbol, :tf, :ts, :label, :price, :raw_json)
    ON DUPLICATE KEY UPDATE
      label = VALUES(label),
      price = VALUES(price),
      raw_json = VALUES(raw_json),
      received_at = CURRENT_TIMESTAMP
  ";
  $stmt = $pdo->prepare($sqlRaw);
  $stmt->execute([
    ':symbol'   => $symbol,
    ':tf'       => $tf,
    ':ts'       => $ts,
    ':label'    => $label,
    ':price'    => $price,
    ':raw_json' => $raw,
  ]);

  // 2) HUD снапшоты: insert или update по (symbol, tf, ts)
  $sqlFind = "SELECT id FROM hud_snapshots WHERE symbol=:symbol AND tf=:tf AND ts=:ts LIMIT 1";
  $stmt = $pdo->prepare($sqlFind);
  $stmt->execute([':symbol'=>$symbol, ':tf'=>$tf, ':ts'=>$ts]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $id = (int)$row['id'];
    $sqlUpd = "
      UPDATE hud_snapshots
      SET price=:price, f_json=:f_json, lv_json=:lv_json, pat_json=:pat_json
      WHERE id=:id
    ";
    $pdo->prepare($sqlUpd)->execute([
      ':price'   => $price,
      ':f_json'  => $f_json,
      ':lv_json' => $lv_json,
      ':pat_json'=> $pat_json,
      ':id'      => $id,
    ]);
    $mode = 'update';
  } else {
    $sqlIns = "
      INSERT INTO hud_snapshots (symbol, tf, ts, price, f_json, lv_json, pat_json)
      VALUES (:symbol, :tf, :ts, :price, :f_json, :lv_json, :pat_json)
    ";
    $pdo->prepare($sqlIns)->execute([
      ':symbol'  => $symbol,
      ':tf'      => $tf,
      ':ts'      => $ts,
      ':price'   => $price,
      ':f_json'  => $f_json,
      ':lv_json' => $lv_json,
      ':pat_json'=> $pat_json,
    ]);
    $id = (int)$pdo->lastInsertId();
    $mode = 'insert';
  }

  $pdo->commit();

  // лёгкий лог (можно обнулить кроном раз в неделю)
  @file_put_contents(
    '/www/wwwlogs/analyze.log',
    sprintf("[%s] %s %s tf=%s ts=%s price=%.2f id=%d mode=%s\n",
      date('Y-m-d H:i:s'),
      'tvhook', $symbol, $tf, $ts, $price, $id, $mode
    ),
    FILE_APPEND
  );

  json_out([
    'status' => 'ok',
    'mode'   => $mode,
    'id'     => $id,
    'symbol' => $symbol,
    'tf'     => $tf,
    'ts'     => $ts,
    'price'  => $price,
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
  json_out([
    'status' => 'error',
    'error'  => $e->getMessage(),
  ], 500);
}
