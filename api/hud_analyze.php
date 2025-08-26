<?php
/**
 * HUD analyzer
 * Берёт последний snapshot по symbol/tf и создаёт запись в cbav_hud_analyses.
 * Безопасно работает даже если AI-клиент недоступен: заполняет baseline-результат.
 *
 * GET:
 *   sym=BINANCE:BTCUSDT   (по умолчанию)
 *   tf=5                  (строкой, "5" | "5m" и т.п. — нормализуем до "5")
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$startTs = microtime(true);

function out($arr, int $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function log_analyze(string $line): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/analyze.log';
    @file_put_contents($path, '['.gmdate('Y-m-d H:i:s\Z')."] $line\n", FILE_APPEND);
}

require_once __DIR__ . '/db.php'; // должен экспортировать функцию db() или db_config + pdo()
$pdo = db();
if (!$pdo instanceof PDO) {
    log_analyze('ERR no PDO instance from db.php');
    out(['ok' => false, 'error' => 'db'], 500);
}

/** --- входные параметры --- */
$symbol = $_GET['sym'] ?? $_GET['symbol'] ?? 'BINANCE:BTCUSDT';
$tfRaw  = $_GET['tf']   ?? '5';

/** нормализация TF -> только цифры, хранить строкой */
if (preg_match('~^\s*(\d+)~', (string)$tfRaw, $m)) {
    $tf = $m[1];
} else {
    $tf = '5';
}

/** страховка по symbol */
$symbol = trim($symbol);
$symbol = preg_replace('~[^A-Za-z0-9:_\.\/-]~', '', $symbol);
if ($symbol === '') $symbol = 'BINANCE:BTCUSDT';

/** короткое символьное имя без префикса "EXCHANGE:" -> в колонку sym */
$symShort = preg_replace('~^[A-Z]+:~', '', $symbol);

/** --- 1) берём последний snapshot по symbol/tf --- */
$sqlLastSnap = "
    SELECT id, received_at, id_tag, symbol, tf, ver, ts, price, features, raw
    FROM cbav_hud_snapshots
    WHERE symbol = :symbol AND tf = :tf
    ORDER BY ts DESC
    LIMIT 1
";
$st = $pdo->prepare($sqlLastSnap);
$st->execute([':symbol' => $symbol, ':tf' => (string)$tf]);
$snap = $st->fetch(PDO::FETCH_ASSOC);

if (!$snap) {
    log_analyze("WARN no snapshot for {$symbol}/{$tf}");
    out([
        'ok'    => false,
        'error' => 'no_snapshot',
        'meta'  => ['symbol' => $symbol, 'tf' => $tf]
    ], 404);
}

/** --- 2) готовим данные для анализа/вставки --- */
$tsMs   = (int)$snap['ts'];                 // миллисекунды
$tUtc   = gmdate('Y-m-d H:i:s', (int)floor($tsMs/1000)); // в datetime UTC
$price  = $snap['price'] !== null ? (string)$snap['price'] : null; // DECIMAL как строка

// Базовый payload для AI (если есть)
$payload = [
    'symbol' => $symbol,
    'sym'    => $symShort,
    'tf'     => (string)$tf,
    'ver'    => (string)($snap['ver'] ?? ''),
    'ts'     => $tsMs,
    't_utc'  => $tUtc,
    'price'  => $price,
    'features' => $snap['features'] ?? null,
];

// Базовый результат (на случай отсутствия AI)
$result = [
    'regime'     => 'trend',
    'bias'       => 'neutral',
    'confidence' => 50,
    'price'      => $price !== null ? (float)$price : null,
    'atr'        => null,
];

// Пробуем AI-клиент (если доступен)
try {
    @require_once __DIR__ . '/api_ai_client.php'; // может определить ai_interpret_json(...)
    if (function_exists('ai_interpret_json')) {
        $ai = ai_interpret_json([
            'mode'    => 'entry',          // или 'now' — как нужно
            'meta'    => ['symbol' => $symbol, 'tf' => (int)$tf],
            'snapshot'=> [
                'ts'      => $tsMs,
                't_utc'   => $tUtc,
                'price'   => $price,
                'ver'     => $snap['ver'] ?? null,
                'features'=> $snap['features'] ?? null,
            ]
        ]);
        // ожидаем, что вернётся массив с ключами regime|bias|confidence|price|atr либо notes/playbook
        if (is_array($ai)) {
            // аккуратно перенесём известные поля, остальное сложим в result_json целиком
            foreach (['regime','bias','confidence','price','atr'] as $k) {
                if (array_key_exists($k, $ai)) $result[$k] = $ai[$k];
            }
            $result_json = json_encode($ai, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $result_json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    } else {
        // AI нет — пишем только базовый результат
        $result_json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    log_analyze('AI error: '.$e->getMessage());
    $result_json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Вытащим плоские поля из $result для колонок анализа */
$regime     = isset($result['regime']) ? (string)$result['regime'] : null;
$bias       = isset($result['bias']) ? (string)$result['bias'] : null;
$confidence = isset($result['confidence']) ? (int)$result['confidence'] : null;
$atr        = isset($result['atr']) ? (is_numeric($result['atr']) ? (float)$result['atr'] : null) : null;

/** --- 3) вставляем в cbav_hud_analyses --- */
$sqlIns = "
INSERT INTO cbav_hud_analyses
    (snapshot_id, symbol, sym, tf, ver, ts, t_utc, price, regime, bias, confidence, atr, result_json)
VALUES
    (:snapshot_id, :symbol, :sym, :tf, :ver, :ts, :t_utc, :price, :regime, :bias, :confidence, :atr, :result_json)
";
$st = $pdo->prepare($sqlIns);
try {
    $st->execute([
        ':snapshot_id' => (int)$snap['id'],
        ':symbol'      => $symbol,
        ':sym'         => $symShort,                  // <-- ОБЯЗАТЕЛЬНО пишем sym
        ':tf'          => (string)$tf,
        ':ver'         => (string)($snap['ver'] ?? ''),
        ':ts'          => $tsMs,
        ':t_utc'       => $tUtc,
        ':price'       => $price,                     // <-- ОБЯЗАТЕЛЬНО пишем price
        ':regime'      => $regime,
        ':bias'        => $bias,
        ':confidence'  => $confidence,
        ':atr'         => $atr,
        ':result_json' => $result_json,
    ]);
} catch (Throwable $e) {
    log_analyze('DB INSERT error: '.$e->getMessage());
    out(['ok' => false, 'error' => 'exception', 'detail' => $e->getMessage()], 500);
}

$insertId = (int)$pdo->lastInsertId();

/** --- 4) ответ --- */
$out = [
    'ok'   => true,
    'meta' => [
        'symbol' => $symbol,
        'sym'    => $symShort,
        'tf'     => (int)$tf,
        'elapsed_ms' => (int)round((microtime(true)-$startTs)*1000),
    ],
    'snapshot' => [
        'id'    => (int)$snap['id'],
        'ts'    => $tsMs,
        't_utc' => $tUtc,
        'price' => $price !== null ? (float)$price : null,
    ],
    'analysis' => [
        'id'         => $insertId,
        'regime'     => $regime,
        'bias'       => $bias,
        'confidence' => $confidence,
        'atr'        => $atr,
    ],
    'raw' => json_decode($result_json, true),
];

out($out);
