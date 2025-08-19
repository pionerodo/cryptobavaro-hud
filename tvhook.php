<?php
require_once __DIR__ . '/config.php';

// Анти‑кэш и JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $raw = file_get_contents('php://input');
    if (!$raw) throw new Exception('Empty body');

    $json = json_decode($raw, true);
    if (!is_array($json)) throw new Exception('Invalid JSON');

    // (опционально) проверка секрета
    if (TV_SECRET !== '' && ($json['secret'] ?? '') !== TV_SECRET) {
        throw new Exception('Invalid secret');
    }

    // Поддерживаем старые/новые ключи
    $id       = (string)($json['id'] ?? '');
    $type     = (string)($json['type'] ?? 'hud_snapshot');
    $symbol   = norm_symbol((string)($json['symbol'] ?? $json['sym'] ?? 'BTCUSDT'));
    $tf       = norm_tf(($json['tf'] ?? 5));
    $ts       = intval($json['ts'] ?? (int)(microtime(true)*1000));
    $price    = isset($json['price']) ? floatval($json['price']) : (isset($json['p']) ? floatval($json['p']) : null);

    // Компактные фичи (новая схема) — храним отдельно ради быстрых выборок, если есть
    $features = null;
    if (isset($json['f']) && is_array($json['f'])) {
        $features = $json['f'];
    } elseif (isset($json['features']) && is_array($json['features'])) {
        $features = $json['features'];
    }

    // Сохраняем
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO `".TBL_SNAPS."` (symbol, tf, ts, price, payload_json, features_json)
        VALUES (:symbol, :tf, :ts, :price, :payload, :features)
    ");
    $stmt->execute([
        ':symbol'   => $symbol,
        ':tf'       => $tf,
        ':ts'       => $ts,
        ':price'    => $price,
        ':payload'  => $raw,
        ':features' => $features ? json_encode($features, JSON_UNESCAPED_UNICODE) : null,
    ]);

    echo json_encode(['status'=>'ok','saved_id'=>$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
