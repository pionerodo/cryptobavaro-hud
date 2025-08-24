<?php
require_once __DIR__.'/db.php';

/**
 * Приём вебхука из TradingView.
 * Ожидаемый JSON:
 * {
 *   "sym":"BINANCE:BTCUSDT",   // или "symbol" / "symIn" (будет нормализован)
 *   "tf":5,
 *   "ts": 1756053020938,       // ms
 *   "p": 114999.9,
 *   "ver":"10.4"               // опционально
 * }
 */

try {
    $raw = file_get_contents('php://input');
    $j   = json_decode($raw, true);

    if (!is_array($j)) {
        json_out(['ok'=>false,'error'=>'bad_input','detail'=>'JSON parse error'], 400);
    }

    // нормализация ключей
    $symbol = $j['sym']    ?? $j['symbol'] ?? $j['symIn'] ?? '';
    $tf     = (int)($j['tf'] ?? 0);
    $ts     = (int)($j['ts'] ?? 0);
    $price  = (float)($j['p'] ?? 0);
    $ver    = (string)($j['ver'] ?? '');

    if (preg_match('~^[A-Z0-9_]+:~', $symbol) !== 1) {
        // если пришёл BTCUSDT.P — приведём к BINANCE:BTCUSDT
        if (preg_match('~^[A-Z0-9]+(?:\.P)?$~', $symbol)) {
            $symbol = 'BINANCE:' . preg_replace('~\.P$~','',$symbol);
        }
    }

    if (!$symbol || !$tf || !$ts || !$price) {
        json_out(['ok'=>false,'error'=>'bad_input','detail'=>[
            'symbol'=>$symbol,'tf'=>$tf,'ts'=>$ts,'price'=>$price
        ]], 400);
    }

    // запись в cbav_hud_snapshots
    $sql = "INSERT INTO cbav_hud_snapshots(symbol, tf, ts, t_utc, price, ver)
            VALUES(:symbol,:tf,:ts, FROM_UNIXTIME(:ts/1000), :price, :ver)";
    db_exec($sql, [
        ':symbol'=>$symbol,
        ':tf'=>$tf,
        ':ts'=>$ts,
        ':price'=>$price,
        ':ver'=>$ver,
    ]);

    log_line('tv_webhook.log', "ok: symbol=$symbol, tf=$tf, ts=$ts, price=$price, ver=$ver");

    json_out(['ok'=>true, 'saved'=>[
        'symbol'=>$symbol,'tf'=>$tf,'ts'=>$ts,'price'=>$price,'ver'=>$ver
    ]]);
} catch (Throwable $e) {
    log_line('tv_webhook.log', 'ERR '.$e->getMessage());
    json_out(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], 500);
}
