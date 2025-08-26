<?php
declare(strict_types=1);

/**
 * TradingView → Webhook → cbav_hud_snapshots
 *
 * Ожидаем JSON примерно такого вида (из Pine):
 * {
 *   "id": "hud_v10_5",
 *   "ver": "10.4",
 *   "t": "hud",
 *   "symIn": "BTCUSDT.P",
 *   "tf": 5,
 *   "ts": 1756053020938,
 *   "p": 114999.9,
 *   "features": { ... }          // опционально
 * }
 */

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$logDir = $root . '/logs';
$logFile = $logDir . '/tv_webhook.log';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

// простая функция логирования
function tvlog(string $level, string $msg): void
{
    global $logFile;
    $ts = gmdate('Y-m-d H:i:s\Z');
    @file_put_contents($logFile, "[$ts] $level $msg" . PHP_EOL, FILE_APPEND);
}

try {
    require_once __DIR__ . '/db.php'; // ВАЖНО: только require_once

    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        tvlog('ERR', 'empty body');
        echo json_encode(['ok' => false, 'error' => 'bad_input', 'detail' => 'empty body']);
        exit;
    }

    // TradingView по умолчанию шлёт JSON-строку
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        tvlog('ERR', 'json decode failed: ' . substr($raw, 0, 800));
        echo json_encode(['ok' => false, 'error' => 'bad_input', 'detail' => 'invalid JSON']);
        exit;
    }

    // минимальная валидация
    $idTag   = isset($j['id'])   ? (string)$j['id']   : (isset($j['id_tag']) ? (string)$j['id_tag'] : null);
    $ver     = isset($j['ver'])  ? (string)$j['ver']  : null;
    $symIn   = isset($j['symIn'])? (string)$j['symIn']: (isset($j['sym']) ? (string)$j['sym'] : null);
    $tf      = isset($j['tf'])   ? (int)$j['tf']      : null;
    $ts      = isset($j['ts'])   ? (int)$j['ts']      : null;  // ms unix
    $price   = isset($j['p'])    ? (float)$j['p']     : (isset($j['price']) ? (float)$j['price'] : null);
    $features= isset($j['features']) ? $j['features'] : null;

    // обязательные
    $miss = [];
    foreach (['idTag'=>'id','ver'=>'ver','symIn'=>'symIn','tf'=>'tf','ts'=>'ts','price'=>'p'] as $k => $src) {
        if (!isset($$k)) $miss[] = $src;
    }
    if ($miss) {
        tvlog('ERR', 'missing fields: ' . implode(',', $miss));
        echo json_encode(['ok' => false, 'error' => 'bad_input', 'detail' => 'missing: '.implode(',', $miss)]);
        exit;
    }

    // нормализуем символ (BTCUSDT.P → BINANCE:BTCUSDT)
    $symbol = $symIn;
    if (preg_match('~^([A-Z]+)USDT\.P$~', $symIn, $m)) {
        $symbol = 'BINANCE:' . $m[1] . 'USDT';
    } elseif (strpos($symIn, ':') === false) {
        // если нам прислали просто 'BTCUSDT' — тоже префиксуем
        $symbol = 'BINANCE:' . strtoupper($symIn);
    }

    // превращаем features в JSON или NULL
    $featuresJson = null;
    if (is_array($features) || is_object($features)) {
        $featuresJson = json_encode($features, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    // сырой заголовок User-Agent / IP
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // ВАЖНО: вставляем ТОЛЬКО колонки, которые реально есть в cbav_hud_snapshots
    // Структура (по вашим скринам): received_at, id_tag, symbol, tf, ver, ts, price, features, levels, patterns, raw, tv_secret_ok, source_ip, user_agent
    $sql = "INSERT INTO cbav_hud_snapshots
            (received_at, id_tag, symbol, tf, ver, ts, price, features, raw, source_ip, user_agent)
            VALUES (NOW(), :id_tag, :symbol, :tf, :ver, :ts, :price, :features, :raw, :ip, :ua)";

    $pdo = db(); // из db.php — возвращает PDO
    $st = $pdo->prepare($sql);
    $st->bindValue(':id_tag',   $idTag,   PDO::PARAM_STR);
    $st->bindValue(':symbol',   $symbol,  PDO::PARAM_STR);
    $st->bindValue(':tf',       $tf,      PDO::PARAM_INT);
    $st->bindValue(':ver',      $ver,     PDO::PARAM_STR);
    $st->bindValue(':ts',       $ts,      PDO::PARAM_INT);
    $st->bindValue(':price',    $price);  // decimal — без явного типа
    $st->bindValue(':features', $featuresJson, $featuresJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':raw',      $raw,     PDO::PARAM_STR);
    $st->bindValue(':ip',       $ip,      $ip ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->bindValue(':ua',       $ua,      $ua ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $st->execute();

    tvlog('OK', sprintf('insert snapshot id_tag=%s symIn=%s → %s, tf=%s, ts=%s, price=%.8f',
        $idTag, $symIn, $symbol, (string)$tf, (string)$ts, (float)$price
    ));

    echo json_encode(['ok' => true, 'saved' => [
        'symbol' => $symbol, 'tf' => $tf, 'ver' => $ver, 'ts' => $ts, 'price' => $price
    ]]);
} catch (Throwable $e) {
    tvlog('ERR', 'exception: '.$e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'exception', 'detail' => $e->getMessage()]);
}
