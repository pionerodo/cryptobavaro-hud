<?php
require_once __DIR__.'/hud_common.php';
/**
 * Строгий API для Dashboard: отдаем последние записи из таблиц строго по UTC ts.
 * GET: sym, tf, limit
 */
try {
    $sym = isset($_GET['sym']) ? norm_symbol($_GET['sym']) : 'BINANCE:BTCUSDT';
    $tf  = isset($_GET['tf'])  ? intval($_GET['tf']) : 5;
    $lim = isset($_GET['limit']) ? max(1, min(200, intval($_GET['limit']))) : 20;

    $db = db_connect_via_wp();

    // Анализы
    $stmt = $db->prepare("
        SELECT id, snapshot_id, analyzed_at, symbol, tf, ver, ts, sym, result_json
        FROM cbav_hud_analyses
        WHERE sym=? AND tf=?
        ORDER BY ts DESC
        LIMIT ?
    ");
    $stmt->bind_param('sii', $sym, $tf, $lim);
    $stmt->execute();
    $anal = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Снапшоты
    $stmt = $db->prepare("
        SELECT id, received_at, id_tag, symbol, tf, ver, ts, price, features
        FROM cbav_hud_snapshots
        WHERE symbol=? AND tf=?
        ORDER BY ts DESC
        LIMIT ?
    ");
    $stmt->bind_param('sii', $sym, $tf, $lim);
    $stmt->execute();
    $snaps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json_out(['ok'=>true, 'sym'=>$sym,'tf'=>$tf,'analyses'=>$anal,'snapshots'=>$snaps]);
} catch (Throwable $e) {
    http_response_code(500);
    json_out(['ok'=>false, 'error'=>$e->getMessage()]);
}
