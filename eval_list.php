<?php
// eval_list.php — список пометок (журнала тестирования)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

try {
    $pdo = db();

    // входные параметры
    $symbol = param_str('symbol', '');
    $tf     = param_str('tf', '');
    $limit  = (int)($_GET['limit'] ?? 50);
    if ($limit <= 0 || $limit > 500) $limit = 50;

    // формируем where динамически, но с bind
    $where = [];
    $bind  = [];

    if ($symbol !== '') {
        $where[]       = 's.symbol = :symbol';
        $bind[':symbol'] = $symbol;
    }
    if ($tf !== '') {
        $where[]     = 's.tf = :tf';
        $bind[':tf'] = $tf;
    }
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $sql = "
        SELECT
            e.id,
            e.snapshot_id,
            s.symbol,
            s.tf,
            s.ts,
            s.price,
            e.bias_pred,
            e.conf_pred,
            e.hit,               -- 1=win, 0=loss
            e.max_drawdown,
            e.pnl_points,
            e.evaluated_at,
            e.created_at
        FROM hud_eval e
        JOIN hud_snapshots s ON s.id = e.snapshot_id
        $whereSql
        ORDER BY e.id DESC
        LIMIT :lim
    ";

    $st = $pdo->prepare($sql);
    foreach ($bind as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'id'           => (int)$r['id'],
            'snapshot_id'  => (int)$r['snapshot_id'],
            'symbol'       => $r['symbol'],
            'tf'           => $r['tf'],
            'ts'           => (int)$r['ts'],
            'price'        => isset($r['price']) ? (float)$r['price'] : null,
            'bias_pred'    => (string)$r['bias_pred'],
            'conf_pred'    => (int)$r['conf_pred'],
            'result'       => ((int)$r['hit'] === 1 ? 'win' : 'loss'),
            'hit'          => (int)$r['hit'],
            'max_dd'       => (float)$r['max_drawdown'],
            'pnl_points'   => (float)$r['pnl_points'],
            'evaluated_at' => $r['evaluated_at'],
            'created_at'   => $r['created_at'],
        ];
    }

    json_out([
        'status' => 'ok',
        'count'  => count($rows),
        'items'  => $rows,
    ]);
}
catch (Throwable $e) {
    http_response_code(500);
    json_out(['status'=>'error', 'error'=>$e->getMessage(), 'code'=>500]);
}
