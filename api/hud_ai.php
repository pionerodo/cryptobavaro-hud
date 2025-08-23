<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';               // твой существующий коннектор к MySQL
require_once __DIR__ . '/api_ai_client.php';    // клиент из п.2

$mode   = $_GET['mode']   ?? $_POST['mode']   ?? 'current';
$symbol = $_GET['symbol'] ?? $_POST['symbol'] ?? 'BINANCE:BTCUSDT';
$tf     = (int)($_GET['tf'] ?? $_POST['tf'] ?? 5);

// 1) Берём последний анализ для символа/таймфрейма
try {
    $db = db(); // из db.php
    $q  = $db->prepare("
        SELECT a.id, a.symbol, a.tf, a.ts, a.result_json, s.price
        FROM cbav_hud_analyses a
        JOIN cbav_hud_snapshots s ON s.id = a.snapshot_id
        WHERE a.symbol = ? AND a.tf = ?
        ORDER BY a.ts DESC
        LIMIT 1
    ");
    $q->bind_param('si', $symbol, $tf);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'no data']); exit;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db: '.$e->getMessage()]); exit;
}

// 2) Готовим промпт для ChatGPT
$tsUtc = (int)$row['ts']; // у тебя это millis UTC
$tsIso = gmdate('Y-m-d H:i:s', (int)floor($tsUtc/1000));

$system = [
    'role'    => 'system',
    'content' =>
        "You are a concise crypto trading assistant. ".
        "Work strictly with the given structured context and produce a short, actionable summary (HTML). ".
        "Avoid overconfidence; highlight key risks. Language: Russian."
];

$user = [
    'role'    => 'user',
    'content' =>
        "Контекст (UTC {$tsIso}):\n".
        "- symbol: {$row['symbol']}\n".
        "- timeframe: {$row['tf']}m\n".
        "- last_price: {$row['price']}\n".
        "- model_result_json: {$row['result_json']}\n\n".
        "Задача: дай краткий вывод (3-6 пунктов) + сценарии (long/short), уровни риска/стопов, ".
        "примерный горизонт (часы), что наблюдать. Верни аккуратный HTML без внешних стилей."
];

$ai = ai_chat([$system, $user], ['max_tokens' => 700]);
if (!$ai['ok']) {
    echo json_encode(['ok' => false, 'error' => $ai['error']]); exit;
}

// 3) Отдаём в Dashboard
echo json_encode([
    'ok'   => true,
    'html' => $ai['text'],
    'raw'  => [
        'symbol' => $row['symbol'],
        'tf'     => $row['tf'],
        'ts'     => $row['ts'],
    ],
], JSON_UNESCAPED_UNICODE);
