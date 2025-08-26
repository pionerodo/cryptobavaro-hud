<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $pdo = db();

    $symbol = isset($_GET['sym']) ? trim((string)$_GET['sym']) : 'BINANCE:BTCUSDT';
    $tf     = isset($_GET['tf'])  ? (int)$_GET['tf'] : 5;
    $limit  = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 3;

    // Берём последние записи из cbav_hud_analyses.
    // Поля regime/bias/confidence/atr читаем из колонки, если она есть,
    // иначе — из JSON result_json.
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
        LIMIT :lim
    ";

    $st = $pdo->prepare($sql);
    $st->bindValue(':sym', $symbol);
    $st->bindValue(':tf',  $tf, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'count'=>count($rows),'data'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
