<?php
/**
 * HUD AI endpoint (универсальный, с маппингом mode→kind)
 * Возвращает структурированный JSON из таблицы cbav_hud_analyses.
 *
 * Поддерживаемые параметры:
 *   sym (или symbol)  — например: BINANCE:BTCUSDT
 *   tf                — например: 5  (число минут)
 *   kind              — analysis | corridor
 *   limit             — для некоторых режимов (по умолчанию 1)
 *
 * Обратная совместимость:
 *   mode=now      ≡ kind=analysis
 *   mode=window   ≡ kind=corridor
 *
 * Формат ответа (пример):
 * {
 *   "ok": true,
 *   "meta": {"symbol":"BINANCE:BTCUSDT","tf":"5"},
 *   "analysis": {...},
 *   "raw": {...}
 * }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

function jerr($msg, $detail = null, $http = 200) {
    http_response_code($http);
    echo json_encode([
        'ok'    => false,
        'error' => is_string($msg) ? $msg : 'exception',
        'detail'=> $detail ?? (is_string($msg) ? null : (string)$msg),
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function jout($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Входные параметры
$symbol = $_GET['sym']     ?? $_GET['symbol'] ?? null;
$tf     = $_GET['tf']      ?? null;              // ожидаем "5" (число минут)
$kind   = $_GET['kind']    ?? null;              // analysis | corridor
$mode   = $_GET['mode']    ?? null;              // устаревший переключатель
$limit  = (int)($_GET['limit'] ?? 1);
if ($limit < 1) $limit = 1;

// Обратная совместимость: mode → kind
if (!$kind && $mode) {
    if ($mode === 'now')    $kind = 'analysis';
    if ($mode === 'window') $kind = 'corridor';
}

// Значения по-умолчанию (как на дашборде)
if (!$symbol) $symbol = 'BINANCE:BTCUSDT';
if (!$tf)     $tf     = '5';
if (!$kind)   $kind   = 'analysis';

// БД
try {
    $pdo = db(); // в db.php должна быть функция db(): PDO
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    jerr('db', $e->getMessage());
}

// Выборка одной «последней» записи по symbol+tf
// Структура по таблице cbav_hud_analyses из паспорта проекта
$sql = "
    SELECT
        id, snapshot_id, symbol, tf, ver, ts, t_utc, price,
        regime, bias, confidence, atr, result_json, analyzed_at
    FROM cbav_hud_analyses
    WHERE symbol = :symbol AND tf = :tf
    ORDER BY ts DESC, id DESC
    LIMIT :lim
";

try {
    $st = $pdo->prepare($sql);
    $st->bindValue(':symbol', $symbol, PDO::PARAM_STR);
    $st->bindValue(':tf',      (string)$tf, PDO::PARAM_STR);
    $st->bindValue(':lim',     $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    jerr('db', $e->getMessage());
}

// Если нет данных — вернём пустой, но корректный ответ
if (!$rows) {
    jout([
        'ok'   => true,
        'meta' => ['symbol' => $symbol, 'tf' => (string)$tf],
        'analysis' => null,
        'raw'      => null,
        'note'     => 'no_data',
    ]);
}

// Берём первую (самую свежую) запись для карточек «Текущий анализ» и «Сигнал входа»
$latest = $rows[0];

// Готовим поля для «analysis» (полная строка) и «raw» (нормализованное кратко)
$analysis = [
    'id'          => (int)$latest['id'],
    'snapshot_id' => (int)$latest['snapshot_id'],
    'symbol'      => $latest['symbol'],
    'tf'          => (string)$latest['tf'],
    'ver'         => (string)$latest['ver'],
    'ts'          => (int)$latest['ts'],
    't_utc'       => (string)$latest['t_utc'],
    'price'       => is_null($latest['price']) ? null : (float)$latest['price'],
    'regime'      => $latest['regime'],
    'bias'        => $latest['bias'],
    'confidence'  => is_null($latest['confidence']) ? null : (int)$latest['confidence'],
    'atr'         => is_null($latest['atr']) ? null : (float)$latest['atr'],
    'result_json' => $latest['result_json'],
    'analyzed_at' => (string)$latest['analyzed_at'],
];

$raw = [
    'regime'     => $latest['regime'],
    'bias'       => $latest['bias'],
    'confidence' => is_null($latest['confidence']) ? null : (int)$latest['confidence'],
    'price'      => is_null($latest['price']) ? null : (float)$latest['price'],
    'atr'        => is_null($latest['atr']) ? null : (float)$latest['atr'],
];

// Итоговый ответ одинаковый по форме для обоих kind, т. к. на текущем этапе «коридор» и «анализ» читают одну и ту же «последнюю» запись.
// (Позже можно внедрить отдельную бизнес-логику для corridor, не ломая фронт.)
$out = [
    'ok'   => true,
    'meta' => ['symbol' => $symbol, 'tf' => (string)$tf, 'kind' => $kind],
    'analysis' => $analysis,
    'raw'      => $raw,
];

// Если запросили не одну, а «окно» из нескольких — приложим «data»
if ($limit > 1) {
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'id'          => (int)$r['id'],
            'snapshot_id' => (int)$r['snapshot_id'],
            'symbol'      => $r['symbol'],
            'tf'          => (string)$r['tf'],
            'ver'         => (string)$r['ver'],
            'ts'          => (int)$r['ts'],
            't_utc'       => (string)$r['t_utc'],
            'price'       => is_null($r['price']) ? null : (float)$r['price'],
            'regime'      => $r['regime'],
            'bias'        => $r['bias'],
            'confidence'  => is_null($r['confidence']) ? null : (int)$r['confidence'],
            'atr'         => is_null($r['atr']) ? null : (float)$r['atr'],
            'analyzed_at' => (string)$r['analyzed_at'],
        ];
    }
    $out['data'] = $data;
}

jout($out);
