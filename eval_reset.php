<?php
// /www/wwwroot/cryptobavaro.online/eval_reset.php
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/config.php';   // db(), константы TBL_*, json_out/json_ok/json_err

try {
    $pdo = db();

    // Параметры
    $confirm = isset($_GET['confirm']) ? strtoupper(trim($_GET['confirm'])) : '';
    $symbol  = isset($_GET['symbol']) ? trim($_GET['symbol']) : '';
    $tf      = isset($_GET['tf']) ? trim($_GET['tf']) : '';

    // Ничего не делаем без confirm
    if ($confirm !== 'ALL' && $confirm !== 'YES') {
        json_err("Set confirm=ALL (optionally with symbol= and tf=) to reset eval log", 400);
    }

    // Базовый SQL: удаляем из hud_eval по связыванию со снимками
    // Если не задано symbol/tf — удаляем всё
    if ($symbol === '' && $tf === '') {
        $pdo->exec("TRUNCATE TABLE hud_eval");
        json_ok(['mode' => 'truncate', 'scope' => 'all']);
    }

    // Удаление выборочно по символу/ТФ
    $conds = [];
    $params = [];
    if ($symbol !== '') { $conds[] = 's.symbol = :symbol'; $params[':symbol'] = $symbol; }
    if ($tf !== '')     { $conds[] = 's.tf     = :tf';     $params[':tf']     = $tf;     }
    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

    // Удаляем те строки hud_eval, snapshot_id которых попадают под фильтр
    $sql = "
        DELETE e FROM hud_eval e
        INNER JOIN hud_snapshots s ON s.id = e.snapshot_id
        $where
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $affected = $st->rowCount();

    json_ok(['mode' => 'delete', 'scope' => ['symbol'=>$symbol, 'tf'=>$tf], 'deleted' => $affected]);

} catch (Throwable $e) {
    json_err("FATAL: ".$e->getMessage(), 500);
}
