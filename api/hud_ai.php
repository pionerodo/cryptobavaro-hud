<?php
// /api/hud_ai.php
declare(strict_types=1);

// Ответ всегда JSON
header('Content-Type: application/json; charset=utf-8');

// Mягкий CORS для локальных тестов/панели
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // === БАЗОВЫЕ ПОДКЛЮЧЕНИЯ ===
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/api_ai_client.php'; // здесь функция ai_interpret_json()

    // === ХЕЛПЕРЫ ===
    $ok = function (array $data) {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    $fail = function (string $code, string $detail = '') {
        echo json_encode([
            'ok'     => false,
            'error'  => $code,
            'detail' => $detail,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };

    // Защита от фаталов — вернём JSON-ошибку
    set_exception_handler(function (Throwable $e) use ($fail) {
        $fail('exception', $e->getMessage());
    });
    set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($fail) {
        if (error_reporting() === 0) { return false; }
        $fail('exception', "$errstr @ $errfile:$errline");
    });

    // === ПАРАМЕТРЫ ЗАПРОСА ===
    $mode = $_GET['mode'] ?? 'now';          // now | entry
    $symbol = trim((string)($_GET['sym'] ?? $_GET['symbol'] ?? 'BINANCE:BTCUSDT'));
    $tf = (int)($_GET['tf'] ?? 5);

    // нормализуем "короткий" сим
    $symSafe = preg_replace('~[^A-Z0-9:_\.]~', '', strtoupper($symbol));
    $symShort = preg_replace('~^[A-Z]+:~', '', $symSafe); // BINANCE:BTCUSDT -> BTCUSDT

    if ($tf <= 0) $tf = 5;

    /** @var PDO $db */
    $db = db(); // из db.php

    // === ВЫТЯГИВАЕМ ПОСЛЕДНИЙ SNAPSHOT И ANALYSIS ===

    // Последний снапшот
    $sqlSnap = "
        SELECT id, symbol, sym, tf, ts, FROM_UNIXTIME(ts/1000) t_utc, price
        FROM cbav_hud_snapshots
        WHERE tf = :tf AND (symbol = :symbol OR sym = :sym)
        ORDER BY ts DESC
        LIMIT 1
    ";
    $st = $db->prepare($sqlSnap);
    $st->execute([
        ':tf' => $tf,
        ':symbol' => $symSafe,
        ':sym' => $symShort,
    ]);
    $snap = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$snap) {
        // нет снапшотов — это крайний случай
        $snap = [
            'id' => null,
            'symbol' => $symSafe,
            'sym' => $symShort,
            'tf' => $tf,
            'ts' => null,
            't_utc' => null,
            'price' => null,
        ];
    }

    // Последний анализ (наш «жёсткий» агрегат из cbav_hud_analyses)
    $sqlAn = "
        SELECT 
            id, snapshot_id, symbol, sym, tf, ver, ts, FROM_UNIXTIME(ts/1000) t_utc,
            price, regime, bias, confidence, atr
        FROM cbav_hud_analyses
        WHERE tf = :tf AND (symbol = :symbol OR sym = :sym)
        ORDER BY ts DESC
        LIMIT 1
    ";
    $st = $db->prepare($sqlAn);
    $st->execute([
        ':tf' => $tf,
        ':symbol' => $symSafe,
        ':sym' => $symShort,
    ]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$a) {
        // Нет записей анализа — вернём болванку, чтобы AI всё равно отработал на текущем price
        $a = [
            'id' => null,
            'snapshot_id' => $snap['id'] ?? null,
            'symbol' => $symSafe,
            'sym' => $symShort,
            'tf' => $tf,
            'ver' => '10.4',
            'ts' => $snap['ts'] ?? null,
            't_utc' => $snap['t_utc'] ?? null,
            'price' => $snap['price'] ?? null,
            'regime' => 'trend',
            'bias' => 'neutral',
            'confidence' => 50,
            'atr' => null,
        ];
    }

    // === МЕТА + ЧТО ПЕРЕДАДИМ В AI ===
    $meta = [
        'symbol' => $symSafe,
        'sym'    => $symShort,
        'tf'     => $tf,
        'elapsed_ms' => null, // заполним чуть ниже
    ];

    // Техническое измерение времени работы AI
    $t0 = microtime(true);

    // Что отдаём в AI: только то, что нужно для промта
    $analysis_for_ai = [
        'regime'     => (string)$a['regime'],
        'bias'       => (string)$a['bias'],
        'confidence' => (int)$a['confidence'],
        'price'      => is_null($a['price']) ? null : (float)$a['price'],
        'atr'        => is_null($a['atr']) ? null : (float)$a['atr'],
    ];

    // В зависимости от $mode можно менять инструкцию. 
    // Здесь используем одну универсальную функцию ai_interpret_json() из api_ai_client.php,
    // где уже зашит наш согласованный промт «аналитик + плейбук».
    $ai = ai_interpret_json($meta, $analysis_for_ai, $mode); // вернёт ['notes'=>..., 'playbook'=>[...], 'raw'=>...]

    $meta['elapsed_ms'] = (int)round( (microtime(true) - $t0) * 1000 );

    // === ФИНАЛЬНЫЙ ОТВЕТ ===
    $ok([
        'ok'   => true,
        'meta' => $meta,
        'snapshot' => [
            'id'    => $snap['id'],
            'ts'    => $snap['ts'],
            't_utc' => $snap['t_utc'],
            'price' => is_null($snap['price']) ? null : (float)$snap['price'],
        ],
        'analysis' => [
            'id'         => $a['id'],
            'regime'     => (string)$a['regime'],
            'bias'       => (string)$a['bias'],
            'confidence' => (int)$a['confidence'],
            'price'      => is_null($a['price']) ? null : (float)$a['price'],
            'atr'        => is_null($a['atr']) ? null : (float)$a['atr'],
        ],
        'ai' => $ai,
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'exception',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
