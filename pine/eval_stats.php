<?php
// eval_stats.php — сводка по журналу тестирования за N дней
// параметры: symbol (опц.), tf (опц.), days (по умолчанию 30)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

function asFloat($v) { return $v !== null ? (float)$v : 0.0; }

try {
    $pdo   = db();
    $symbol = param_str('symbol', '');
    $tf     = param_str('tf', '');
    $days   = (int)($_GET['days'] ?? 30);
    if ($days <= 0 || $days > 365) $days = 30;

    // фильтр по времени — последние N дней по created_at
    // (если важнее evaluated_at — замените поле)
    $where = ["e.created_at >= (NOW() - INTERVAL :days DAY)"];
    $bind  = [':days' => $days];

    if ($symbol !== '') { $where[] = 's.symbol = :symbol'; $bind[':symbol'] = $symbol; }
    if ($tf     !== '') { $where[] = 's.tf     = :tf';     $bind[':tf']     = $tf;     }

    $whereSql = 'WHERE '.implode(' AND ', $where);

    // агрегаты по всему периоду
    $sqlSummary = "
        SELECT
            COUNT(*)                                        AS total,
            SUM(CASE WHEN e.hit = 1 THEN 1 ELSE 0 END)      AS wins,
            SUM(CASE WHEN e.hit = 0 THEN 1 ELSE 0 END)      AS losses,
            AVG(e.pnl_points)                                AS avg_pnl,
            AVG(e.max_drawdown)                              AS avg_dd
        FROM hud_eval e
        JOIN hud_snapshots s ON s.id = e.snapshot_id
        $whereSql
    ";
    $st = $pdo->prepare($sqlSummary);
    $st->execute($bind);
    $sum = $st->fetch(PDO::FETCH_ASSOC) ?: [
        'total'=>0,'wins'=>0,'losses'=>0,'avg_pnl'=>0,'avg_dd'=>0
    ];

    $total  = (int)$sum['total'];
    $wins   = (int)$sum['wins'];
    $losses = (int)$sum['losses'];
    $wr     = $total > 0 ? round($wins * 100.0 / $total, 2) : 0.0;

    // разбивка по дням
    $sqlByDay = "
        SELECT
            DATE(e.created_at)                               AS d,
            COUNT(*)                                         AS total,
            SUM(CASE WHEN e.hit = 1 THEN 1 ELSE 0 END)       AS wins,
            SUM(CASE WHEN e.hit = 0 THEN 1 ELSE 0 END)       AS losses,
            AVG(e.pnl_points)                                AS avg_pnl,
            AVG(e.max_drawdown)                              AS avg_dd
        FROM hud_eval e
        JOIN hud_snapshots s ON s.id = e.snapshot_id
        $whereSql
        GROUP BY DATE(e.created_at)
        ORDER BY DATE(e.created_at) ASC
    ";
    $st = $pdo->prepare($sqlByDay);
    $st->execute($bind);

    $byDays = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $t = (int)$r['total'];
        $w = (int)$r['wins'];
        $byDays[] = [
            'd'        => $r['d'],
            'total'    => $t,
            'wins'     => $w,
            'losses'   => (int)$r['losses'],
            'win_rate' => ($t > 0 ? round($w * 100.0 / $t, 2) : 0.0),
            'avg_pnl'  => asFloat($r['avg_pnl']),
            'avg_dd'   => asFloat($r['avg_dd']),
        ];
    }

    json_out([
        'status' => 'ok',
        'symbol' => ($symbol !== '' ? $symbol : null),
        'tf'     => ($tf !== '' ? $tf : null),
        'days'   => $days,
        'cutoff' => date('Y-m-d H:i:s', time() - $days * 86400),
        'summary'=> [
            'total'    => $total,
            'wins'     => $wins,
            'losses'   => $losses,
            'win_rate' => $wr,
            'avg_pnl'  => asFloat($sum['avg_pnl']),
            'avg_dd'   => asFloat($sum['avg_dd']),
        ],
        'by_days' => $byDays,
    ]);
}
catch (Throwable $e) {
    http_response_code(500);
    json_out(['status'=>'error', 'error'=>$e->getMessage(), 'code'=>500]);
}
