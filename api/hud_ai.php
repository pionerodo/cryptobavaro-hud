<?php
// /api/hud_ai.php
declare(strict_types=1);
require __DIR__.'/db.php';

try {
    $sym = safe_symbol(get_str('sym', 'BINANCE:BTCUSDT'));
    $tf  = max(1, get_int('tf', 5));
    $kind = get_str('kind', 'analysis'); // для совместимости с фронтом, но логика одна

    $sql = "SELECT id, snapshot_id, symbol, tf, ver, ts, t_utc, price, regime, bias, confidence, atr, result_json, analyzed_at
            FROM cbav_hud_analyses
            WHERE symbol = :sym AND tf = :tf
            ORDER BY ts DESC
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([':sym' => $sym, ':tf' => $tf]);
    $row = $st->fetch();

    if (!$row) {
        echo json_encode(['ok'=>true,'meta'=>['symbol'=>$sym,'tf'=>(string)$tf,'kind'=>$kind],'analysis'=>null,'ai'=>null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Нормализуем типы
    $row['tf']         = (string)$row['tf'];
    $row['ts']         = (int)$row['ts'];
    $row['price']      = (float)$row['price'];
    $row['confidence'] = (int)$row['confidence'];
    $row['atr']        = is_null($row['atr']) ? null : (float)$row['atr'];

    // AI: пытаемся распарсить result_json
    $aiBlock = null;
    if (!empty($row['result_json'])) {
        $tmp = json_decode($row['result_json'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            $aiBlock = $tmp;
        } else {
            $aiBlock = ['raw' => $row['result_json']];
        }
    }

    // В ответ кладём всё как есть + meta
    $resp = [
        'ok' => true,
        'meta' => [
            'symbol' => $sym,
            'tf'     => (string)$tf,
            'kind'   => $kind,
        ],
        'analysis' => [
            'id'          => (int)$row['id'],
            'snapshot_id' => (int)$row['snapshot_id'],
            'symbol'      => $row['symbol'],
            'tf'          => (string)$row['tf'],
            'ver'         => (string)$row['ver'],
            'ts'          => (int)$row['ts'],
            't_utc'       => $row['t_utc'],
            'price'       => (float)$row['price'],
            'regime'      => $row['regime'],
            'bias'        => $row['bias'],
            'confidence'  => (int)$row['confidence'],
            'atr'         => $row['atr'],
            'analyzed_at' => $row['analyzed_at'],
        ],
        'ai' => $aiBlock
    ];

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr($e->getMessage());
}
