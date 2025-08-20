<?php
require_once __DIR__ . '/config.php';

// Анти‑кэш
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Плейбук — такой же, как в analyze.php (держим в одном месте, можно вынести в helper)
function playbook_from_features(array $f) : array {
    $mm   = $f['mm'] ?? 'neutral';
    $ts   = intval($f['ts'] ?? 0);
    $tr   = floatval($f['tr'] ?? 0);
    $dav  = floatval($f['dav'] ?? 9);
    $sqs  = intval($f['sqs'] ?? 0);
    $bbr  = floatval($f['bbr'] ?? 50);
    $k    = floatval($f['k'] ?? 50);
    $nearTop = intval($f['nbt'] ?? 0) === 1;
    $nearBot = intval($f['nbb'] ?? 0) === 1;
    $res = [];

    if ($mm === 'trend' && $tr >= 0.8 && $dav <= 0.3) {
        if ($ts === 1) {
            $res[] = ['name'=>'Продолжение тренда (лонг)','dir'=>'long','entry'=>'возврат к aVWAP/микро‑откат',
                      'sl'=>'ниже локального минимума или 1.2×ATR','tp'=>'TP1=1R/box_top (50%), TP2=2R, трейл по aVWAP−0.3×ATR','confidence'=>min(95, 60 + intval(($tr-0.8)*50))];
        } elseif ($ts === -1) {
            $res[] = ['name'=>'Продолжение тренда (шорт)','dir'=>'short','entry'=>'тест сверху aVWAP/микро‑откат',
                      'sl'=>'выше локального максимума или 1.2×ATR','tp'=>'TP1=1R/box_bot (50%), TP2=2R, трейл по aVWAP+0.3×ATR','confidence'=>min(95, 60 + intval(($tr-0.8)*50))];
        }
    }
    if ($mm === 'range' || ($bbr <= 20)) {
        if ($nearTop) $res[] = ['name'=>'Флэт: от верхней границы (шорт)','dir'=>'short','entry'=>'паттерн слабости у box_top','sl'=>'за box_top (0.7–1.0×ATR)','tp'=>'середина бокса → box_bot (частями)','confidence'=>55+($k>80?10:0)];
        if ($nearBot) $res[] = ['name'=>'Флэт: от нижней границы (лонг)','dir'=>'long','entry'=>'паттерн силы у box_bot','sl'=>'за box_bot (0.7–1.0×ATR)','tp'=>'середина бокса → box_top (частями)','confidence'=>55+($k<20?10:0)];
    }
    if ($sqs >= 5 && $bbr <= 20) {
        $res[] = ['name'=>'Прорыв после сжатия','dir'=>'both','entry'=>'ретест пробитой границы','sl'=>'за уровень (0.8×ATR)','tp'=>'1R, 2R, далее трейл по свингам','confidence'=>65];
    }
    return $res;
}

try {
    $symbol = isset($_GET['symbol']) ? norm_symbol($_GET['symbol']) : null;
    $tf     = isset($_GET['tf']) ? norm_tf($_GET['tf']) : null;
    if (!$symbol || !$tf) throw new Exception('symbol & tf are required');

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT * FROM `".TBL_SNAPS."`
        WHERE symbol=:s AND tf=:tf
        ORDER BY ts DESC LIMIT 1
    ");
    $stmt->execute([':s'=>$symbol, ':tf'=>$tf]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['status'=>'ok','found'=>false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode($row['payload_json'], true) ?: [];
    $p = $row['price'] ?? null;
    if (!$p) $p = $payload['p'] ?? $payload['price'] ?? null;

    $f  = $payload['f'] ?? $payload['features'] ?? [];
    $lv = $payload['lv'] ?? $payload['levels'] ?? [];
    $pat= $payload['pat'] ?? $payload['patterns'] ?? [];

    $resp = [
      'status'     => 'ok',
      'found'      => true,
      'snapshot_id'=> intval($row['id']),
      'symbol'     => 'BINANCE:'.$row['symbol'], // для UI
      'tf'         => strval($row['tf']),
      'ts'         => intval($row['ts']),
      'p'          => $p,
      'f'          => $f,
      'lv'         => $lv,
      'pat'        => $pat,
      'analysis'   => [
        'notes'    => $f['note'] ?? '',
        'playbook' => playbook_from_features($f)
      ]
    ];
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
