<?php
/**
 * Service helper:
 *   ?mode=probe
 *   ?mode=latest&limit=3
 *   ?mode=tail&file=tv|error&limit=50
 */

declare(strict_types=1);

function text(string $s, int $code=200): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $s;
    exit;
}
function jexit(array $payload, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function tailLines(string $file, int $limit=100): string {
    if (!is_file($file) || !is_readable($file)) {
        return "[warn] file not found or not readable: {$file}\n";
    }
    // Быстрый tail без внешних утилит
    $f = new SplFileObject($file, 'r');
    $f->seek(PHP_INT_MAX);
    $last = $f->key();
    $from = max(0, $last - $limit);
    $buf = '';
    for ($i=$from; $i<=$last; $i++) {
        $f->seek($i);
        $buf .= $f->current();
    }
    return $buf;
}

$mode  = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'probe';
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50;

if ($mode === 'probe') {
    text("[ok] PHP is alive @ ".gmdate('Y-m-d H:i:s')."Z\n");
}

// latest — последние записи из cbav_hud_analyses
if ($mode === 'latest') {
    require_once __DIR__.'/api/db.php';
    try {
        $pdo = db();
        $st = $pdo->prepare("
            SELECT
                id, snapshot_id, symbol, tf, ver, ts,
                FROM_UNIXTIME(ts/1000) AS t_utc,
                price, regime, bias, confidence, atr,
                result_json, analyzed_at
            FROM cbav_hud_analyses
            ORDER BY ts DESC
            LIMIT :lim
        ");
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        jexit(['ok'=>true, 'data'=>$rows]);
    } catch (Throwable $e) {
        jexit(['ok'=>false, 'error'=>'exception', 'detail'=>$e->getMessage()], 500);
    }
}

// tail — чтение логов (tv|error)
if ($mode === 'tail') {
    $alias = isset($_GET['file']) ? strtolower(trim((string)$_GET['file'])) : 'tv';

    // Под твою структуру:
    $filemap = [
        'tv'    => '/www/wwwroot/cryptobavaro.online/logs/tv_webhook.log',
        'error' => '/www/wwwlogs/cryptobavaro.online.error.log', // если нужен error.log — при необходимости поправим
    ];
    $path = $filemap[$alias] ?? $filemap['tv'];

    text(tailLines($path, $limit));
}

text("[warn] unknown mode\n", 400);
