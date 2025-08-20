<?php
require_once __DIR__ . '/config.php';

// ===== Анти‑кэш + JSON =====
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// ===== Утилиты =====
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

    // 1) Продолжение тренда
    if ($mm === 'trend' && $tr >= 0.8 && $dav <= 0.3) {
        if ($ts === 1) {
            $res[] = [
              'name'=>'Продолжение тренда (лонг)','dir'=>'long',
              'entry'=>'возврат к aVWAP/после микро-отката',
              'sl'=>'ниже локального минимума или 1.2×ATR',
              'tp'=>'TP1=1R/box_top (50%), TP2=2R, далее трейл по aVWAP−0.3×ATR',
              'confidence'=>min(95, 60 + intval(($tr-0.8)*50))
            ];
        } elseif ($ts === -1) {
            $res[] = [
              'name'=>'Продолжение тренда (шорт)','dir'=>'short',
              'entry'=>'тест сверху aVWAP/после микро-отката',
              'sl'=>'выше локального максимума или 1.2×ATR',
              'tp'=>'TP1=1R/box_bot (50%), TP2=2R, далее трейл по aVWAP+0.3×ATR',
              'confidence'=>min(95, 60 + intval(($tr-0.8)*50))
            ];
        }
    }

    // 2) Флэт: отбой
    if ($mm === 'range' || ($bbr <= 20)) {
        if ($nearTop) {
            $res[] = [
              'name'=>'Флэт: от верхней границы (шорт)','dir'=>'short',
              'entry'=>'паттерн слабости у box_top',
              'sl'=>'за box_top (0.7–1.0×ATR)',
              'tp'=>'середина бокса → box_bot (частями)',
              'confidence'=> 55 + ($k>80 ? 10:0)
            ];
        }
        if ($nearBot) {
            $res[] = [
              'name'=>'Флэт: от нижней границы (лонг)','dir'=>'long',
              'entry'=>'паттерн силы у box_bot',
              'sl'=>'за box_bot (0.7–1.0×ATR)',
              'tp'=>'середина бокса → box_top (частями)',
              'confidence'=> 55 + ($k<20 ? 10:0)
            ];
        }
    }

    // 3) Прорыв после сжатия
    if ($sqs >= 5 && $bbr <= 20) {
        $res[] = [
          'name'=>'Прорыв после сжатия','dir'=>'both',
          'entry'=>'ретест пробитой границы бокса',
          'sl'=>'за уровень (0.8×ATR)',
          'tp'=>'1R, 2R, далее трейл по свингам',
          'confidence'=>65
        ];
    }
    return $res;
}

function row_to_snapshot(array $row) : array {
    $payload = json_decode($row['payload_json'], true) ?: [];
    // Нормализуем источники полей
    $p  = $row['price'] ?? null;
    if (!$p) $p = $payload['p'] ?? $payload['price'] ?? null;

    $f  = $payload['f'] ?? $payload['features'] ?? [];
    $lv = $payload['lv'] ?? $payload['levels'] ?? [];
    $pat= $payload['pat'] ?? $payload['patterns'] ?? [];

    return [
        'snapshot_id' => intval($row['id']),
        'symbol'      => $row['symbol'],
        'tf'          => strval($row['tf']),
        'ts'          => intval($row['ts']),
        'p'           => $p,
        'f'           => $f,
        'lv'          => $lv,
        'pat'         => $pat
    ];
}

try {
    $mode   = $_GET['mode'] ?? 'latest';
    $limit  = max(1, min(50, intval($_GET['limit'] ?? 5)));
    $symbol = isset($_GET['symbol']) ? norm_symbol($_GET['symbol']) : null;
    $tf     = isset($_GET['tf']) ? norm_tf($_GET['tf']) : null;

    $pdo = db();
    if ($mode === 'latest') {
        $sql = "SELECT * FROM `".TBL_SNAPS."` ";
        $w   = [];
        $p   = [];
        if ($symbol) { $w[] = "symbol=:s"; $p[':s'] = $symbol; }
        if ($tf)     { $w[] = "tf=:tf";    $p[':tf'] = $tf; }
        if ($w) $sql .= "WHERE ".implode(' AND ', $w).' ';
        $sql .= "ORDER BY ts DESC LIMIT :lim";
        $stmt = $pdo->prepare($sql);
        foreach ($p as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $snap = row_to_snapshot($r);
            $f = is_array($snap['f']) ? $snap['f'] : [];
            $items[] = [
              'snapshot_id' => $snap['snapshot_id'],
              'analysis_id' => null,
              'analysis'    => [
                'notes'    => $f['note'] ?? '',
                'playbook' => playbook_from_features($f)
              ]
            ];
        }
        echo json_encode(['status'=>'ok','items'=>$items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'history') {
        if (!$symbol || !$tf) throw new Exception('symbol & tf required for history');
        $stmt = $pdo->prepare("
          SELECT * FROM `".TBL_SNAPS."`
          WHERE symbol=:s AND tf=:tf
          ORDER BY ts DESC LIMIT :lim
        ");
        $stmt->bindValue(':s',  $symbol);
        $stmt->bindValue(':tf', $tf, PDO::PARAM_INT);
        $stmt->bindValue(':lim',$limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $items=[];
        foreach ($rows as $r){
            $snap = row_to_snapshot($r);
            $f = is_array($snap['f']) ? $snap['f'] : [];
            $items[] = [
               'snapshot_id'=>$snap['snapshot_id'],
               'analysis_id'=>null,
               'analysis'=>[
                 'notes'=>$f['note'] ?? '',
                 'playbook'=>playbook_from_features($f)
               ]
            ];
        }
        echo json_encode(['status'=>'ok','items'=>$items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Unknown mode');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
