<?php
// /api/hud_api_strict.php
declare(strict_types=1);
require __DIR__.'/db.php';

try {
    $sym   = safe_symbol(get_str('sym', 'BINANCE:BTCUSDT'));
    $tf    = max(1, get_int('tf', 5));
    $limit = max(1, min(50, get_int('limit', 3)));

    $sql = "SELECT id, snapshot_id, symbol, tf, ver, ts, t_utc, price, regime, bias, confidence, atr, result_json, analyzed_at
            FROM cbav_hud_analyses
            WHERE symbol = :sym AND tf = :tf
            ORDER BY ts DESC
            LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute([':sym' => $sym, ':tf' => $tf]);

    $data = [];
    while ($r = $st->fetch()) {
        $data[] = [
            'id'          => (int)$r['id'],
            'snapshot_id' => (int)$r['snapshot_id'],
            'symbol'      => $r['symbol'],
            'tf'          => (string)$r['tf'],
            'ver'         => (string)$r['ver'],
            'ts'          => (int)$r['ts'],
            't_utc'       => $r['t_utc'],
            'price'       => (float)$r['price'],
            'regime'      => $r['regime'],
            'bias'        => $r['bias'],
            'confidence'  => (int)$r['confidence'],
            'atr'         => is_null($r['atr']) ? null : (float)$r['atr'],
            'result_json' => $r['result_json'],
            'analyzed_at' => $r['analyzed_at'],
        ];
    }

    echo json_encode(['ok'=>true,'count'=>count($data),'data'=>$data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr($e->getMessage());
}
