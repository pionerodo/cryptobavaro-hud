<?php
require_once __DIR__.'/db.php';

/**
 * Отдаёт последние записи из cbav_hud_analyses для заданных sym/tf.
 * GET: sym=BINANCE:BTCUSDT&tf=5&limit=3
 */

try {
    $sym   = $_GET['sym']   ?? '';
    $tf    = (int)($_GET['tf']    ?? 5);
    $limit = (int)($_GET['limit'] ?? 3);
    if ($limit <= 0 || $limit > 50) $limit = 3;

    if (!$sym || !$tf) json_out(['ok'=>false,'error'=>'bad_input'], 400);

    $sql =
      "SELECT id, snapshot_id, symbol, tf, ver, ts, 
              FROM_UNIXTIME(ts/1000) t_utc, price, regime, bias, confidence, atr, result_json, analyzed_at
         FROM (
                SELECT a.id, a.snapshot_id, a.symbol, a.tf, s.ver, a.ts, s.price,
                       a.regime, a.bias, a.confidence, a.atr, a.result_json, a.analyzed_at
                  FROM cbav_hud_analyses a
                  JOIN cbav_hud_snapshots s ON s.id=a.snapshot_id
                 WHERE a.symbol=:sym AND a.tf=:tf
                 ORDER BY a.ts DESC
                 LIMIT :lim
              ) t
        ORDER BY ts DESC";

    $st = db()->prepare($sql);
    $st->bindValue(':sym', $sym, PDO::PARAM_STR);
    $st->bindValue(':tf',  $tf,  PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    json_out(['ok'=>true,'count'=>count($rows),'data'=>$rows]);
} catch (Throwable $e) {
    log_line('strict_api.log', 'ERR '.$e->getMessage());
    json_out(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], 500);
}
