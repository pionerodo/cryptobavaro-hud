<?php
/**
 * HUD AI endpoint
 * GET params:
 *   mode=now|entry   (default: now)
 *   sym=BINANCE:BTCUSDT
 *   tf=5
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

function jexit(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = db(); // из api/db.php
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'db', 'detail' => $e->getMessage()], 500);
}

$mode   = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'now';
$symbol = isset($_GET['sym'])  ? trim((string)$_GET['sym']) : 'BINANCE:BTCUSDT';
$tf     = isset($_GET['tf'])   ? trim((string)$_GET['tf'])  : '5';

if ($symbol === '' || $tf === '') {
    jexit(['ok' => false, 'error' => 'bad_input', 'detail' => 'Empty sym or tf'], 400);
}

// Забираем последнюю(ие) записи анализа для symbol/tf
function fetch_latest_analyses(PDO $pdo, string $symbol, string $tf, int $limit = 1): array {
    $sql = "
        SELECT
            id, snapshot_id, symbol, tf, ver, ts,
            FROM_UNIXTIME(ts/1000) AS t_utc,
            price, regime, bias, confidence, atr,
            result_json, analyzed_at
        FROM cbav_hud_analyses
        WHERE symbol = :symbol AND tf = :tf
        ORDER BY ts DESC
        LIMIT :lim
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':symbol', $symbol, PDO::PARAM_STR);
    $st->bindValue(':tf', $tf, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    if ($mode === 'entry') {
        // «Коридор входа» — на текущем этапе просто возвращаем последнюю запись
        // (страница строит карточки, а логику «коридора» можем доработать позже).
        $rows = fetch_latest_analyses($pdo, $symbol, $tf, 1);
        jexit([
            'ok'   => true,
            'meta' => ['symbol' => $symbol, 'tf' => $tf],
            'data' => $rows,
        ]);
    }

    // mode=now (по умолчанию)
    $rows = fetch_latest_analyses($pdo, $symbol, $tf, 1);
    if (!$rows) {
        jexit(['ok' => false, 'error' => 'not_found', 'detail' => 'No analyses yet for symbol/tf']);
    }

    $last = $rows[0];
    // Попробуем распаковать result_json как «сырой» расчёт для удобства фронта
    $raw = null;
    if (!empty($last['result_json'])) {
        $raw = json_decode((string)$last['result_json'], true);
        if (!is_array($raw)) $raw = null;
    }

    jexit([
        'ok'   => true,
        'meta' => ['symbol' => $symbol, 'tf' => $tf],
        'analysis' => [
            'id'          => (int)$last['id'],
            'snapshot_id' => (int)$last['snapshot_id'],
            'symbol'      => $last['symbol'],
            'tf'          => $last['tf'],
            'ver'         => $last['ver'],
            'ts'          => (int)$last['ts'],
            't_utc'       => $last['t_utc'],
            'price'       => isset($last['price']) ? (float)$last['price'] : null,
            'regime'      => $last['regime'],
            'bias'        => $last['bias'],
            'confidence'  => isset($last['confidence']) ? (int)$last['confidence'] : null,
            'atr'         => isset($last['atr']) ? (float)$last['atr'] : null,
            'analyzed_at' => $last['analyzed_at'],
        ],
        'raw' => $raw,
    ]);
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'exception', 'detail' => $e->getMessage()], 500);
}
