<?php
// latest_analysis.php — отдать самый свежий снапшот + аналитику

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

/* ---- локальный JSON‑хелпер (на случай, если в config.php его нет) ---- */
if (!function_exists('json_out')) {
    function json_out(array $payload, int $code = 200): void {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    $pdo = db(); // берём PDO из config.php
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Входные параметры
    $symbol = isset($_GET['symbol']) ? trim((string)$_GET['symbol']) : '';
    $tf     = isset($_GET['tf'])     ? trim((string)$_GET['tf'])     : '';

    if ($symbol === '' || $tf === '') {
        json_out(['status' => 'error', 'error' => 'symbol and tf are required'], 400);
    }

    // Берём самый свежий снапшот
    $q = $pdo->prepare("
        SELECT s.id, s.symbol, s.tf, s.ts, s.price,
               s.f_json, s.lv_json, s.pat_json
          FROM hud_snapshots s
         WHERE s.symbol = :sym AND s.tf = :tf
         ORDER BY s.ts DESC
         LIMIT 1
    ");
    $q->execute([':sym' => $symbol, ':tf' => $tf]);
    $snap = $q->fetch(PDO::FETCH_ASSOC);

    if (!$snap) {
        json_out(['status' => 'ok', 'found' => false]);
    }

    // Пытаемся подтянуть аналитику к этому снапу
    $qa = $pdo->prepare("
        SELECT id, notes, playbook_json AS playbook, bias, confidence, source, created_at
          FROM hud_analysis
         WHERE snapshot_id = :sid
         ORDER BY id DESC
         LIMIT 1
    ");
    $qa->execute([':sid' => (int)$snap['id']]);
    $an = $qa->fetch(PDO::FETCH_ASSOC);

    // Собираем ответ
    $out = [
        'status'      => 'ok',
        'found'       => true,
        'snapshot_id' => (int)$snap['id'],
        'symbol'      => $snap['symbol'],
        'tf'          => $snap['tf'],
        'ts'          => (int)$snap['ts'],
        'p'           => (float)$snap['price'],
        // Внутри БД у нас лежат JSON‑строки — вернём распарсенные структуры
        'f'           => $snap['f_json']   !== null ? json_decode($snap['f_json'],  true) : null,
        'lv'          => $snap['lv_json']  !== null ? json_decode($snap['lv_json'], true) : null,
        'pat'         => $snap['pat_json'] !== null ? json_decode($snap['pat_json'], true) : null,
        'analysis'    => $an ? [
            'id'        => (int)$an['id'],
            'notes'     => (string)$an['notes'],
            'playbook'  => $an['playbook'] ? json_decode($an['playbook'], true) : [],
            'bias'      => (string)$an['bias'],
            'confidence'=> (int)$an['confidence'],
            'source'    => (string)$an['source'],
            'at'        => (string)$an['created_at'],
        ] : ['notes' => 'нет', 'playbook' => []],
    ];

    json_out($out);
} catch (Throwable $e) {
    json_out([
        'status' => 'error',
        'error'  => $e->getMessage()
    ], 500);
}
