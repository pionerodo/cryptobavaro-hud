<?php
// eval_mark.php — быстрая отметка результата по snapshot_id
// Пример вызова:
//   https://cryptobavaro.online/eval_mark.php?snapshot_id=1&result=win&comment=test
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/config.php';   // db(), ensure_schema()

// --- локальные хелперы на случай, если их нет в config.php ---
if (!function_exists('json_out')) {
    function json_out($arr, int $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('jerr')) {
    function jerr(string $msg, int $code = 400) { json_out(['status'=>'error','error'=>$msg], $code); }
}
if (!function_exists('jok')) {
    function jok(array $payload = []) { json_out(['status'=>'ok'] + $payload); }
}
// ----------------------------------------------------------------

try {
    ensure_schema();            // на всякий, не помешает
    $pdo = db();

    // 1) Параметры
    $sid = intval($_GET['snapshot_id'] ?? $_POST['snapshot_id'] ?? 0);
    $res = strtolower(trim($_GET['result'] ?? $_POST['result'] ?? ''));
    $comment = trim($_GET['comment'] ?? $_POST['comment'] ?? ''); // сейчас никуда не пишем

    if ($sid <= 0)                jerr('snapshot_id required', 400);
    if (!in_array($res, ['win','loss','skip'], true))
                                  jerr('result must be win|loss|skip', 400);

    // 2) Снимок существует?
    $st = $pdo->prepare('SELECT id FROM hud_snapshots WHERE id = ? LIMIT 1');
    $st->execute([$sid]);
    if (!$st->fetchColumn())      jerr('snapshot not found', 404);

    // 3) Готовим значения для hud_eval
    // hit: 1 — win, 0 — loss, NULL — skip
    $hit = null;
    if ($res === 'win')  $hit = 1;
    if ($res === 'loss') $hit = 0;

    // 4) Пишем в hud_eval
    $ins = $pdo->prepare(
        'INSERT INTO hud_eval (snapshot_id, hit, evaluated_at) VALUES (:sid, :hit, NOW())'
    );
    $ins->bindValue(':sid', $sid, PDO::PARAM_INT);
    if ($hit === null) $ins->bindValue(':hit', null, PDO::PARAM_NULL);
    else               $ins->bindValue(':hit', $hit, PDO::PARAM_INT);

    $ins->execute();
    $eid = intval($pdo->lastInsertId());

    // (опционально в будущем: сохранять $comment в отдельную таблицу/поле)

    jok(['saved_id' => $eid, 'snapshot_id' => $sid, 'result' => $res]);

} catch (Throwable $e) {
    jerr('FATAL: ' . $e->getMessage(), 500);
}
