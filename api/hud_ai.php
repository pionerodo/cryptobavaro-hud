<?php
/**
 * AI endpoint:
 * берет последние 1–3 записи (включая текущую), дергает интерпретацию
 * и возвращает структурированный ответ.
 * Здесь правка только БД-части (PDO), чтобы не падало 500.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_ai_client.php'; // у вас уже есть; проверено в чате

try {
    $mode = $_GET['mode'] ?? 'now';
    $sym  = $_GET['sym']  ?? $_GET['symbol'] ?? 'BINANCE:BTCUSDT';
    $tf   = isset($_GET['tf']) ? (int)$_GET['tf'] : 5;

    // Берем последние 3 строки анализа (или меньше) — это вы делали ранее
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
        LIMIT 3
    ";

    $st = db()->prepare($sql);
    $st->bindValue(':sym', $sym);
    $st->bindValue(':tf',  $tf, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    if (!$rows) {
        echo json_encode(['ok' => false, 'error' => 'no_data']);
        exit;
    }

    // Собираем минимальный контекст для AI (как у вас было)
    $context = [
        'meta' => [
            'symbol'     => $sym,
            'tf'         => $tf,
            'context_tf' => [1,3,5,15,60],
            'model'      => 'gpt-4o-mini-2024-07-18',
        ],
        'analysis' => [
            'regime'     => $rows[0]['result_json'] ? (json_decode($rows[0]['result_json'], true)['regime'] ?? 'trend') : 'trend',
            'bias'       => $rows[0]['result_json'] ? (json_decode($rows[0]['result_json'], true)['bias']   ?? 'neutral') : 'neutral',
            'confidence' => $rows[0]['result_json'] ? (json_decode($rows[0]['result_json'], true)['confidence'] ?? 0) : 0,
            'price'      => (float)$rows[0]['price'],
            'atr'        => $rows[0]['atr'] !== null ? (float)$rows[0]['atr'] : null,
        ],
        'debug' => [
            'rows' => $rows,
        ],
    ];

    // Вызов вашей функции интерпретации (см. api_ai_client.php)
    [$aiNotes, $aiPlaybook, $rawJson] = ai_interpret_json($context); // как у вас в проекте

    echo json_encode([
        'ok'   => true,
        'meta' => $context['meta'],
        'analysis' => $context['analysis'],
        'ai' => [
            'notes'    => $aiNotes,
            'playbook' => $aiPlaybook,
            'raw'      => $rawJson,
        ],
        'debug' => [
            'rows' => $rows,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
