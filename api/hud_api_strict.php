<?php
/**
 * Строгий API: возвращает последние записи анализа для указанного символа/TF.
 * Формат ответа совместим с тем, что вы уже используете в dashboard.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
    $sym   = $_GET['sym']   ?? $_GET['symbol'] ?? '';
    $tf    = isset($_GET['tf']) ? (int)$_GET['tf'] : 5;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 3;

    if ($sym === '' || $tf <= 0) {
        echo json_encode(['ok' => false, 'error' => 'bad_input']);
        exit;
    }

    // Выбор последних N анализов (таблицы по вашим скринам/чату)
    // cbav_hud_analyses: id, snapshot_id, result_json, analyzed_at
    // cbav_hud_snapshots: id, symbol, tf, ver, ts (ms), price, atr
    $sql = "
        SELECT
            a.id,
            a.snapshot_id,
            s.symbol,
            s.tf,
            s.ver,
            s.ts,
            FROM_UNIXTIME(s.ts/1000) AS t_utc,
            s.price,
            s.atr,
            a.result_json,
            a.analyzed_at
        FROM cbav_hud_analyses a
        JOIN cbav_hud_snapshots s ON s.id = a.snapshot_id
        WHERE s.symbol = :sym AND s.tf = :tf
        ORDER BY s.ts DESC
        LIMIT :limit
    ";

    $st = db()->prepare($sql);
    $st->bindValue(':sym', $sym);
    $st->bindValue(':tf',  $tf, PDO::PARAM_INT);
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll();

    echo json_encode([
        'ok'    => true,
        'count' => count($rows),
        'data'  => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
