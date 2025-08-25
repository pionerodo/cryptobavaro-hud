<?php
/**
 * Lightweight helper page:
 * - mode=tail&file={tv|analyze|error}&limit=50  — быстрый просмотр логов
 * - mode=latest[&limit=N]                        — последние N анализов (JSON)
 * - mode=probe                                   — быстрая проверка что PHP жив
 *
 * Файл не пишет в БД и не ожидает payload_json.
 * Никаких ошибок notice/warning наружу не льёт.
 */

declare(strict_types=1);

// --- базовые настройки
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

$mode  = $_GET['mode']  ?? 'tail';
$limit = (int)($_GET['limit'] ?? 50);
$limit = ($limit > 0 && $limit <= 1000) ? $limit : 50;

/**
 * Безопасное чтение "последних N строк" файла без tail/exec.
 */
function tail_file_lines(string $path, int $lines = 200): string
{
    if (!is_readable($path)) {
        return "[warn] file not found or not readable: {$path}\n";
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return "[warn] unable to open file: {$path}\n";
    }

    $buffer   = '';
    $chunk    = 4096;
    $pos      = -1;
    $lineCnt  = 0;

    fseek($fh, 0, SEEK_END);
    $filesize = ftell($fh);

    while ($pos > -$filesize) {
        $pos -= $chunk;
        if (fseek($fh, $pos, SEEK_END) !== 0) {
            fseek($fh, 0, SEEK_SET);
            $read = $filesize;
        } else {
            $read = $chunk;
        }
        $buf   = fread($fh, $read);
        $buffer = $buf . $buffer;
        $lineCnt = substr_count($buffer, "\n");

        if ($lineCnt >= $lines + 1) {
            // обрежем до последних N строк
            $parts = explode("\n", $buffer);
            $buffer = implode("\n", array_slice($parts, -$lines)) . "\n";
            break;
        }

        if (ftell($fh) === 0) {
            // достигли начала файла
            break;
        }
    }

    fclose($fh);
    return $buffer;
}

switch ($mode) {
    // ------------------------------------------------------------
    // Логи: /analyze.php?mode=tail&file={tv|analyze|error}&limit=50
    // ------------------------------------------------------------
    case 'tail': {
        header('Content-Type: text/plain; charset=utf-8');

        $map = [
            'tv'      => '/www/wwwlogs/tv_webhook.log',
            'analyze' => '/www/wwwlogs/analyze.log',
            'error'   => '/www/wwwlogs/cryptobavaro.online.error.log',
        ];

        $fileKey = $_GET['file'] ?? 'analyze';
        $path    = $map[$fileKey] ?? $map['analyze'];

        echo tail_file_lines($path, $limit);
        exit;
    }

    // ------------------------------------------------------------
    // Последние анализы из БД: /analyze.php?mode=latest[&limit=N]
    // ------------------------------------------------------------
    case 'latest': {
        header('Content-Type: application/json; charset=utf-8');

        // аккуратно подключаем общий db.php
        $dbFile = __DIR__ . '/api/db.php';
        if (!is_readable($dbFile)) {
            echo json_encode([
                'ok'    => false,
                'error' => 'db.php_not_found',
                'hint'  => 'Ожидался файл ' . $dbFile,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once $dbFile;

        try {
            // последние записи строго по ts
            $rows = db_all(
                "SELECT id, snapshot_id, symbol, tf, ver, ts, 
                        FROM_UNIXTIME(ts/1000) t_utc, price, regime, bias, confidence, atr,
                        result_json, analyzed_at 
                 FROM cbav_hud_analyses 
                 ORDER BY ts DESC 
                 LIMIT ?", 
                [$limit]
            );

            echo json_encode([
                'ok'   => true,
                'data' => $rows,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode([
                'ok'    => false,
                'error' => 'exception',
                'detail'=> $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ------------------------------------------------------------
    // Быстрая проверка: /analyze.php?mode=probe
    // ------------------------------------------------------------
    case 'probe': {
        header('Content-Type: text/plain; charset=utf-8');
        echo "[ok] PHP is alive @ " . gmdate('Y-m-d H:i:s') . "Z\n";
        exit;
    }

    // ------------------------------------------------------------
    // Значение по умолчанию — показываем хвост analyze.log
    // ------------------------------------------------------------
    default: {
        header('Content-Type: text/plain; charset=utf-8');
        echo tail_file_lines('/www/wwwlogs/analyze.log', $limit);
        exit;
    }
}
