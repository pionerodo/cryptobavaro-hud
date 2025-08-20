<?php
// latest_analysis.php — отдать последний снапшот + анализ для символа/TF

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php'; // здесь db(): PDO
// Если у вас другой путь к конфигу — поправьте подключение.

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // === НАСТРОЙКИ ТАБЛИЦ (под ваш префикс) ===
    $T_SNAP = 'cbav_hud_snapshots';
    $T_AN   = 'cbav_hud_analyses';

    // === ВХОДНЫЕ ПАРАМЕТРЫ ===
    $symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';
    $tf     = isset($_GET['tf']) ? trim($_GET['tf']) : '';

    if ($symbol === '' || $tf === '') {
        echo json_encode(['status' => 'error', 'error' => 'symbol and tf required', 'code' => 400], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // === ЗАПРОС ПО ПОСЛЕДНЕМУ СНАПШОТУ ===
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
        WHERE s.symbol = :sym AND s.tf = :tf
        ORDER BY s.ts DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':sym' => $symbol, ':tf' => $tf]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['status' => 'ok', 'found' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Разобрать вложенные JSON
    $features = json_decode($row['f_json'] ?? '[]', true);
    $levels   = json_decode($row['lv_json'] ?? '[]', true);
    $pats     = json_decode($row['pat_json'] ?? '[]', true);

    $analysis = [
        'notes'    => $row['notes']         ?? 'нет',
        'playbook' => json_decode($row['playbook_json'] ?? '[]', true),
        'bias'     => $row['bias']          ?? 'neutral',
        'conf'     => intval($row['confidence'] ?? 0),
        'source'   => $row['source']        ?? 'heuristic',
    ];

    $out = [
        'status'      => 'ok',
        'found'       => true,
        'snapshot_id' => intval($row['snapshot_id']),
        'symbol'      => $row['symbol'],
        'tf'          => $row['tf'],
        'ts'          => intval($row['ts']),
        'p'           => floatval($row['price']),
        'f'           => $features,
        'lv'          => $levels,
        'pat'         => $pats,
        'analysis'    => $analysis,
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage(), 'code' => 500], JSON_UNESCAPED_UNICODE);
}
