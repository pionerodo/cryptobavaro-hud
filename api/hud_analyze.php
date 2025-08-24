<?php
require_once __DIR__.'/db.php';

/**
 * Берёт последний снапшот по ?sym=&tf= и пишет «анализ» в cbav_hud_analyses.
 * Логику анализа можно углублять — здесь минимально достаточный пайплайн.
 *
 * GET:
 *   sym=BINANCE:BTCUSDT
 *   tf=5
 */

try {
    $sym = $_GET['sym'] ?? '';
    $tf  = (int)($_GET['tf'] ?? 5);

    if (!$sym || !$tf) json_out(['ok'=>false,'error'=>'bad_input'], 400);

    $row = db_row(
        "SELECT id, symbol, tf, ts, price
           FROM cbav_hud_snapshots
          WHERE symbol=:s AND tf=:tf
          ORDER BY ts DESC
          LIMIT 1",
        [':s'=>$sym, ':tf'=>$tf]
    );
    if (!$row) json_out(['ok'=>false,'error'=>'no_snapshot'], 404);

    // Примитивная «оценка» (пока просто заглушка — можно расширить)
    $regime     = 'trend';
    $bias       = 'neutral';
    $confidence = 50;
    $atr        = null;

    $resultJson = json_encode([
        'regime'=>$regime,
        'bias'=>$bias,
        'confidence'=>$confidence,
        'price'=>(float)$row['price'],
        'atr'=>$atr
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    db_exec(
        "INSERT INTO cbav_hud_analyses
            (snapshot_id, symbol, tf, ts, regime, bias, confidence, atr, result_json, analyzed_at)
         VALUES
            (:sid, :sym, :tf, :ts, :regime, :bias, :conf, :atr, :rj, NOW())",
        [
            ':sid'=>$row['id'],
            ':sym'=>$row['symbol'],
            ':tf' =>$row['tf'],
            ':ts' =>$row['ts'],
            ':regime'=>$regime,
            ':bias'=>$bias,
            ':conf'=>$confidence,
            ':atr'=>$atr,
            ':rj'=>$resultJson,
        ]
    );

    json_out(['ok'=>true, 'analyzed'=>[
        'snapshot_id'=>$row['id'],
        'symbol'=>$row['symbol'],
        'tf'=>$row['tf'],
        'ts'=>$row['ts'],
    ]]);
} catch (Throwable $e) {
    log_line('analyze.log', 'ERR '.$e->getMessage());
    json_out(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], 500);
}
