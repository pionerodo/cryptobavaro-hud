<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $pdo = db(); // PDO

    // Параметры
    $symbol = isset($_GET['sym']) ? trim((string)$_GET['sym']) : 'BINANCE:BTCUSDT';
    $tf     = isset($_GET['tf'])  ? (int)$_GET['tf'] : 5;

    // Берём свежий снимок цены из cbav_hud_snapshots
    $sqlSnap = "
        SELECT id AS snapshot_id, symbol, tf, ver, ts, price
        FROM cbav_hud_snapshots
        WHERE symbol = :sym AND tf = :tf
        ORDER BY ts DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sqlSnap);
    $st->bindValue(':sym', $symbol);
    $st->bindValue(':tf', $tf, PDO::PARAM_INT);
    $st->execute();
    $snap = $st->fetch(PDO::FETCH_ASSOC);

    if (!$snap) {
        echo json_encode(['ok'=>false,'error'=>'no_snapshot','detail'=>'Нет свежих снимков для указанного symbol/tf']);
        exit;
    }

    // Здесь у вас может быть любая логика аналитики.
    // Для совместимости кладём результат в result_json,
    // а плоские поля (regime/bias/confidence/atr) не требуем в таблице.
    $ai = [
        'regime'     => 'trend',
        'bias'       => 'neutral',
        'confidence' => 50,
        'price'      => (float)$snap['price'],
        'atr'        => null,
    ];
    $resultJson = json_encode($ai, JSON_UNESCAPED_UNICODE);

    // INSERT только в поля, которые точно есть у нас по схеме:
    // id/snapshot_id/symbol/tf/ver/ts/price/result_json/analyzed_at
    // Если вы добавляли колонки regime/bias/confidence/atr — триггер COALESCE
    // в SELECT позволит использовать их, но здесь они не обязательны.
    $sqlIns = "
        INSERT INTO cbav_hud_analyses
            (snapshot_id, symbol, tf, ver, ts, price, result_json, analyzed_at)
        VALUES
            (:snapshot_id, :symbol, :tf, :ver, :ts, :price, :result_json, NOW())
    ";
    $ins = $pdo->prepare($sqlIns);
    $ins->bindValue(':snapshot_id', (int)$snap['snapshot_id'], PDO::PARAM_INT);
    $ins->bindValue(':symbol',      $snap['symbol']);
    $ins->bindValue(':tf',          (int)$snap['tf'],        PDO::PARAM_INT);
    $ins->bindValue(':ver',         $snap['ver']);
    $ins->bindValue(':ts',          (int)$snap['ts'],        PDO::PARAM_INT);
    $ins->bindValue(':price',       (float)$snap['price']);
    $ins->bindValue(':result_json', $resultJson);
    $ins->execute();

    echo json_encode(['ok'=>true,'saved'=>$ai], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'exception',
        'detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
