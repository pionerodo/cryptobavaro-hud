<?php
// analyze.php — режимы:
// 1) ?mode=latest&limit=N — отдать N последних снапшотов с (возможным) анализом
// 2) (позже) режим обработки необработанных снапов — сейчас отключён, чтобы не мешать.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php'; // db(): PDO, настройки

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $T_SNAP = 'cbav_hud_snapshots';
    $T_AN   = 'cbav_hud_analyses';

    $mode  = isset($_GET['mode']) ? $_GET['mode'] : 'latest';
    $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 3;

    if ($mode === 'latest') {
        $sql = "
            SELECT 
                s.id            AS snapshot_id,
                s.symbol,
                s.tf,
                s.ts,
                s.price,
                s.f_json,
                s.lv_json,
                s.pat_json,
                a.id            AS analysis_id,
                a.notes,
                a.playbook_json,
                a.bias,
                a.confidence,
                a.source
            FROM `{$T_SNAP}` AS s
            LEFT JOIN `{$T_AN}` AS a ON a.snapshot_id = s.id
            ORDER BY s.ts DESC
            LIMIT :lim
        ";
        $st = $pdo->prepare($sql);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'snapshot_id' => intval($row['snapshot_id']),
                'analysis_id' => $row['analysis_id'] !== null ? intval($row['analysis_id']) : null,
                'analysis'    => [
                    'notes'    => $row['notes'] ?? 'нет',
                    'playbook' => json_decode($row['playbook_json'] ?? '[]', true),
                    'bias'     => $row['bias'] ?? 'neutral',
                    'conf'     => intval($row['confidence'] ?? 0),
                    'source'   => $row['source'] ?? 'heuristic',
                ],
            ];
        }

        echo json_encode(['status' => 'ok', 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // на будущее — режим обработки снапов можно включить тут
    echo json_encode(['status' => 'error', 'error' => 'unknown mode', 'code' => 400], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'code' => 500], JSON_UNESCAPED_UNICODE);
}
