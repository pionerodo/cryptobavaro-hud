<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $pdo = db();

    $mode   = isset($_GET['mode'])   ? trim((string)$_GET['mode'])   : 'now';
    $symbol = isset($_GET['sym'])    ? trim((string)$_GET['sym'])    : 'BINANCE:BTCUSDT';
    $tf     = isset($_GET['tf'])     ? (int)$_GET['tf']              : 5;

    // Берём самую свежую запись анализа
    $sql = "
        SELECT
            a.id,
            a.snapshot_id,
            a.symbol,
            a.tf,
            a.ver,
            a.ts,
            FROM_UNIXTIME(a.ts/1000) AS t_utc,
            a.price,
            COALESCE(a.regime,      JSON_UNQUOTE(JSON_EXTRACT(a.result_json,  '$.regime')))      AS regime,
            COALESCE(a.bias,        JSON_UNQUOTE(JSON_EXTRACT(a.result_json,  '$.bias')))        AS bias,
            COALESCE(a.confidence,  JSON_EXTRACT(a.result_json,             '$.confidence'))     AS confidence,
            COALESCE(a.atr,         JSON_EXTRACT(a.result_json,             '$.atr'))            AS atr,
            a.result_json,
            a.analyzed_at
        FROM cbav_hud_analyses a
        WHERE a.symbol = :sym AND a.tf = :tf
        ORDER BY a.ts DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':sym', $symbol);
    $st->bindValue(':tf',  $tf, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok'=>false,'error'=>'no_data','detail'=>'нет записей анализа'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Возвращаем AI‑интерпретацию в уже согласованном формате
    $analysis = [
        'regime'     => $row['regime'],
        'bias'       => $row['bias'],
        'confidence' => (int)$row['confidence'],
        'price'      => (float)$row['price'],
        'atr'        => $row['atr'] !== null ? (float)$row['atr'] : null,
    ];

    // Заглушка: здесь может вызываться ваш клиент OpenAI.
    $ai = [
        'notes' => 'Краткая заметка по рынку на основе последнего анализа.',
        'playbook' => [
            [
                'dir'        => 'short',
                'setup'      => 'пробой диапазона',
                'entry'      => 'при пробое локальной поддержки',
                'invalidation'=> 'возврат выше пробитого уровня',
                'tp1'        => 'первая цель',
                'tp2'        => 'вторая цель',
                'confidence' => max(0, min(100, (int)$row['confidence'])),
                'why'        => ['режим/смещение', 'динамика', 'контекст'],
            ],
        ],
        'raw' => json_encode($analysis, JSON_UNESCAPED_UNICODE)
    ];

    echo json_encode([
        'ok'    => true,
        'meta'  => ['symbol'=>$symbol,'tf'=>$tf],
        'analysis' => $analysis,
        'ai'    => $ai
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
