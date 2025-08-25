<?php
// /www/wwwroot/cryptobavaro.online/analyze.php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$start = microtime(true);

// --- простой роутинг --------------------------------------------------------
$mode  = $_GET['mode']  ?? 'probe';
$file  = $_GET['file']  ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit <= 0 || $limit > 1000) $limit = 50;

// --- безопасная карта логов -------------------------------------------------
$LOG_MAP = [
  // лог от TradingView вебхука
  'tv'    => '/www/wwwroot/cryptobavaro.online/logs/tv_webhook.log',
  // error‑лог nginx/php-fpm сайта
  'error' => '/www/wwwlogs/cryptobavaro.online.error.log',
];

// --- утилиты ----------------------------------------------------------------
function j($arr) {
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

function safe_tail(string $path, int $limit): array {
  if (!is_readable($path)) {
    return ['ok'=>false, 'warn'=>"file not found or not readable: $path"];
  }
  // портативный tail: читаем конец файла
  $fh = fopen($path, 'r');
  if (!$fh) return ['ok'=>false, 'warn'=>"cannot open: $path"];

  $buffer = '';
  $pos = -1;
  $lines = [];

  fseek($fh, 0, SEEK_END);
  $filesize = ftell($fh);

  while (count($lines) <= $limit && (-$pos) < $filesize) {
    fseek($fh, $pos, SEEK_END);
    $ch = fgetc($fh);
    if ($ch === "\n") {
      if ($buffer !== '') {
        $lines[] = strrev($buffer);
        $buffer = '';
      }
    } else {
      $buffer .= $ch;
    }
    $pos--;
  }
  if ($buffer !== '') $lines[] = strrev($buffer);
  fclose($fh);

  $lines = array_reverse(array_slice($lines, 0, $limit));
  return ['ok'=>true, 'lines'=>$lines];
}

// --- DB (для режима latest) -------------------------------------------------
function db() : PDO {
  // подключение из нашего db.php
  require_once __DIR__ . '/api/db.php';
  $pdo = db(); // в твоём db.php возвращается PDO в функции db()
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

// --- обработка --------------------------------------------------------------
try {
  if ($mode === 'probe') {
    j(['ok'=>true, 'msg'=>'PHP is alive', 'ts'=>gmdate('Y-m-d H:i:s\Z')]);
  }

  if ($mode === 'tail') {
    $key = strtolower($file);
    if (!isset($LOG_MAP[$key])) {
      j(['ok'=>false, 'error'=>'bad_file', 'detail'=>'file must be one of: '.implode(',', array_keys($LOG_MAP))]);
    }
    $path = $LOG_MAP[$key];
    $res = safe_tail($path, $limit);
    $res['elapsed_ms'] = (int) round((microtime(true)-$start)*1000);
    j($res);
  }

  if ($mode === 'latest') {
    $pdo = db();
    $sql = "SELECT id,snapshot_id,symbol,tf,ver,ts,FROM_UNIXTIME(ts/1000) t_utc,price,regime,bias,confidence,atr,result_json,analyzed_at
            FROM cbav_hud_analyses
            ORDER BY ts DESC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    j(['ok'=>true, 'data'=>$rows, 'elapsed_ms'=>(int) round((microtime(true)-$start)*1000)]);
  }

  // неизвестный режим
  j(['ok'=>false, 'error'=>'bad_mode', 'detail'=>'use mode=probe | tail | latest']);

} catch (Throwable $e) {
  j(['ok'=>false, 'error'=>'exception', 'detail'=>$e->getMessage()]);
}
