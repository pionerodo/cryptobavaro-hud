<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/api_ai_client.php'; // ← твой клиент, в нём должна быть ai_interpret_json($meta,$rows)

/**
 * Возвращает AI‑интерпретацию последних записей анализа.
 * GET:
 *   mode=now
 *   sym=BINANCE:BTCUSDT
 *   tf=5
 */

try {
    $mode = $_GET['mode'] ?? 'now';
    $sym  = $_GET['sym']  ?? '';
    $tf   = (int)($_GET['tf'] ?? 5);

    if (!$sym || !$tf) json_out(['ok'=>false,'error'=>'bad_input'], 400);

    // Берём свежие ряды
    $st = db()->prepare(
        "SELECT a.id, a.snapshot_id, a.symbol, a.tf, s.ver, a.ts, s.price, 
                a.regime, a.bias, a.confidence, a.atr, a.result_json, a.analyzed_at
           FROM cbav_hud_analyses a
           JOIN cbav_hud_snapshots s ON s.id=a.snapshot_id
          WHERE a.symbol=:sym AND a.tf=:tf
          ORDER BY a.ts DESC
          LIMIT 3"
    );
    $st->execute([':sym'=>$sym, ':tf'=>$tf]);
    $rows = $st->fetchAll();

    if (!$rows) {
        json_out(['ok'=>false,'error'=>'no_data'], 404);
    }

    // Метаданные для AI (контекст таймфреймов можешь расширить)
    $meta = [
        'symbol'     => $sym,
        'tf'         => $tf,
        'context_tf' => [1,3,5,15,60],
        'model'      => 'gpt-4o-mini-2024-07-18',
    ];

    // Твой клиент должен вернуть {"notes":"…","playbook":[…], "raw":"…"} или аналогично
    $ai = ai_interpret_json($meta, $rows);

    json_out([
        'ok'   => true,
        'meta' => $meta,
        'ai'   => $ai,
        'debug'=> [
            'rows'=>$rows,
        ],
    ]);
} catch (Throwable $e) {
    log_line('ai_api.log', 'ERR '.$e->getMessage());
    json_out(['ok'=>false,'error'=>'exception','detail'=>$e->getMessage()], 500);
}
