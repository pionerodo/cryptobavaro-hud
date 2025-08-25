<?php
/**
 * HUD AI endpoint
 * - kind=analysis | corridor  (default: analysis)
 * - sym=BINANCE:BTCUSDT
 * - tf=5
 * - limit=3  (сколько последних строк контекста из cbav_hud_analyses подтянуть)
 *
 * Ответ: JSON:
 * {
 *   ok: true,
 *   meta: {...},
 *   analysis: {...},     // краткая сводка по последней строке БД (для отладки/отображения)
 *   ai: {
 *     notes: "…",
 *     playbook: [ ... ],
 *     raw: "{...JSON от модели...}"
 *   },
 *   debug: { rows: [...] } // опционально
 * }
 */

header('Content-Type: application/json; charset=utf-8');

try {
    // ── подключение зависимостей
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/api_ai_client.php';

    // ── входные параметры
    $symbol = isset($_GET['sym']) ? trim($_GET['sym']) : 'BINANCE:BTCUSDT';
    $tf     = isset($_GET['tf'])  ? (int)$_GET['tf'] : 5;
    if ($tf <= 0) $tf = 5;

    $limit  = isset($_GET['limit']) ? max(1, min(10, (int)$_GET['limit'])) : 3;
    $kind   = isset($_GET['kind']) ? strtolower(trim($_GET['kind'])) : 'analysis';
    if (!in_array($kind, ['analysis','corridor'], true)) {
        $kind = 'analysis';
    }

    // ── читаем свежие строки контекста из БД (cbav_hud_analyses)
    $pdo = db(); // PDO
    $sql = "SELECT id, snapshot_id, symbol, tf, ver, ts, FROM_UNIXTIME(ts/1000) t_utc,
                   price, regime, bias, confidence, atr, result_json, analyzed_at
            FROM cbav_hud_analyses
            WHERE symbol = :symbol AND tf = :tf
            ORDER BY ts DESC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':symbol', $symbol);
    $stmt->bindValue(':tf', $tf, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            'ok'    => false,
            'error' => 'no_data',
            'detail'=> 'В таблице cbav_hud_analyses нет записей под заданные sym/tf.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── последняя запись как "текущая сводка" для UI
    $last = $rows[0];
    $meta = [
        'symbol'    => $symbol,
        'tf'        => $tf,
        'context_tf'=> [1,3,5,15,60],
        'model'     => 'gpt-4o-mini-2024-07-18',
    ];
    $analysis = [
        'regime'     => (string)($last['regime'] ?? ''),
        'bias'       => (string)($last['bias'] ?? ''),
        'confidence' => is_null($last['confidence']) ? null : (int)$last['confidence'],
        'price'      => is_null($last['price']) ? null : (float)$last['price'],
        'atr'        => is_null($last['atr']) ? null : (float)$last['atr'],
    ];

    // ── готовим компактный контекст для промта
    // (оставляем только понятные модели поля)
    $compact = array_map(function($r){
        return [
            't_utc'      => $r['t_utc'],
            'ts'         => (int)$r['ts'],
            'price'      => is_null($r['price']) ? null : (float)$r['price'],
            'atr'        => is_null($r['atr']) ? null : (float)$r['atr'],
            'regime'     => $r['regime'],
            'bias'       => $r['bias'],
            'confidence' => is_null($r['confidence']) ? null : (int)$r['confidence'],
            'ver'        => $r['ver'],
        ];
    }, $rows);

    $contextJson = json_encode([
        'symbol' => $symbol,
        'tf'     => $tf,
        'rows'   => $compact
    ], JSON_UNESCAPED_UNICODE);

    // ── system-подсказка (общая для обоих режимов)
    $system = [
        'role'    => 'system',
        'content' =>
            "Ты — русскоязычный трейдинг-аналитик.\n".
            "Всегда отвечай СТРОГО в формате JSON и ничего больше (без пояснений, без преамбул).\n".
            "Никаких эмодзи, никаких лишних фраз. Коротко, по делу, деловой тон.\n".
            "Если входные данные недостаточны — всё равно дай максимально консервативный и безопасный план."
    ];

    // ── промт пользователя (ветвление: analysis / corridor)
    if ($kind === 'analysis') {
        $userPrompt =
            "Контекст (символ/ТФ и последние записи анализа):\n".
            $contextJson."\n\n".
            "Задача:\n".
            "1) Сформируй короткие заметки о рынке (один абзац, ~400–450 символов максимум).\n".
            "2) Сформируй плейбук (до 4 сценариев), каждый — объект:\n".
            "   dir: \"long\"|\"short\",\n".
            "   setup: коротко (например: «отбой от aVWAP», «пробой диапазона», «ретест уровня»),\n".
            "   entry: зона входа/условие,\n".
            "   invalidation: где идея ломается (стоп/условие),\n".
            "   tp1: реалистичная цель-1,\n".
            "   tp2: цель-2,\n".
            "   confidence: 0..100,\n".
            "   why: 3–5 очень коротких аргументов через «;».\n\n".
            "Важное:\n".
            "- Работаем скальпингом **5m** (контекст 1m/3m/5m/15m/60m).\n".
            "- Не выдумывай уровни — опирайся на присланные box_top/box_bot, ob_bear/ob_bull, aVWAP, дневной VWAP, если они есть в данных.\n".
            "- Если сигналов мало — дай безопасный, консервативный сценарий.\n".
            "- Всё строго на русском.\n\n".
            "Ответь строго JSON: {\"notes\":\"…\",\"playbook\":[…]}";
    } else { // corridor
        $userPrompt =
            "Контекст (символ/ТФ и последние записи анализа):\n".
            $contextJson."\n\n".
            "Задача («Сигнал входа / коридор сделки»):\n".
            "- Дай короткие заметки (один абзац, до 250–300 символов) о текущем моменте.\n".
            "- Сформируй строго 1–2 максимально практичных сценария для работы в коридоре/диапазоне, каждый — объект:\n".
            "   dir: \"long\"|\"short\",\n".
            "   setup: («ложный пробой коридора», «внутри-бар у границы», «ретест mid/EVWAP» и т.п.),\n".
            "   entry: точка/зона входа (условно и кратко),\n".
            "   invalidation: где идея ломается (стоп/условие),\n".
            "   tp1: близкая цель,\n".
            "   tp2: цель-2 (если уместно),\n".
            "   confidence: 0..100,\n".
            "   why: 3–5 очень коротких аргументов через «;» (например: «сжатая волатильность; тест границы; сигнал объёма»).\n\n".
            "Важное:\n".
            "- Работаем скальпингом **5m** (контекст 1m/3m/5m/15m/60m).\n".
            "- Не выдумывай уровни — опирайся на присланные box_top/box_bot, ob_bear/ob_bull, aVWAP, дневной VWAP, если они есть.\n".
            "- Цель — аккуратная игра *внутри диапазона* (консервативные входы, чёткие условия).\n".
            "- Всё строго на русском.\n\n".
            "Ответь строго JSON: {\"notes\":\"…\",\"playbook\":[…]}";
    }

    // ── вызов модели
    $messages = [
        $system,
        ['role' => 'user', 'content' => $userPrompt]
    ];
    $aiJson = ai_interpret_json($messages); // возвращает уже декодированный PHP-объект/массив или бросает исключение

    // аккуратно подготовим вывод
    $out = [
        'ok'   => true,
        'meta' => $meta,
        'analysis' => $analysis,
        'ai' => [
            'notes'    => (string)($aiJson['notes'] ?? ''),
            'playbook' => is_array($aiJson['playbook'] ?? null) ? $aiJson['playbook'] : [],
            // сырой текст для дебага
            'raw'      => json_encode($aiJson, JSON_UNESCAPED_UNICODE)
        ],
        'debug' => [
            'rows' => $rows
        ]
    ];

    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'     => false,
        'error'  => 'exception',
        'detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
