<?php
/**
 * HUD AI endpoint
 * GET params:
 *   - mode: now | entry   (default: now)
 *   - sym:  tradingview symbol like BINANCE:BTCUSDT (default: BINANCE:BTCUSDT)
 *   - tf:   timeframe minutes (int, default: 5)
 *
 * Response:
 *   { ok, meta, analysis, ai, debug? }
 */

header('Content-Type: application/json; charset=utf-8');
mb_internal_encoding('UTF-8');

$startedAt = microtime(true);

try {
    require_once __DIR__ . '/db.php';
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'db_include', 'detail' => $e->getMessage()]);
    exit;
}

try {
    require_once __DIR__ . '/api_ai_client.php'; // должен содержать ai_interpret_json()
} catch (Throwable $e) {
    // Не фаталим — ниже будет фолбэк без AI.
}

/* ---------- helpers ---------- */

function p($key, $default = null) {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
function jok($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function jerr($msg, $detail = '') {
    jok(['ok' => false, 'error' => $msg, 'detail' => $detail]);
}

/* ---------- input ---------- */

$mode = strtolower(p('mode', 'now'));               // now | entry
$symbol = p('sym', 'BINANCE:BTCUSDT');              // как в БД
$tf = (int)p('tf', 5);
if ($tf <= 0) $tf = 5;

/* ---------- fetch last rows from analyses ---------- */

try {
    // Последние 3 записи анализа для контекста + явный последний для текущего среза.
    $rows = db_all(
        "SELECT id, snapshot_id, symbol, tf, ver, ts, FROM_UNIXTIME(ts/1000) t_utc,
                price, regime, bias, confidence, atr, result_json, analyzed_at
         FROM cbav_hud_analyses
         WHERE symbol = ? AND tf = ?
         ORDER BY ts DESC
         LIMIT 3",
        [$symbol, $tf]
    );

    $last = $rows[0] ?? null;
    if (!$last) {
        jerr('no_data', 'cbav_hud_analyses is empty for given sym/tf');
    }
} catch (Throwable $e) {
    jerr('db', $e->getMessage());
}

/* ---------- prepare meta/analysis ---------- */

$meta = [
    'symbol'     => $symbol,
    'tf'         => $tf,
    'context_tf' => [1, 3, 5, 15, 60],
    'model'      => 'gpt-4o-mini-2024-07-18',
    'elapsed_ms' => null,
];

$analysis = [
    'regime'     => $last['regime'] ?? null,
    'bias'       => $last['bias'] ?? null,
    'confidence' => isset($last['confidence']) ? (int)$last['confidence'] : null,
    'price'      => isset($last['price']) ? (float)$last['price'] : null,
    'atr'        => isset($last['atr']) ? (float)$last['atr'] : null,
];

/* ---------- build compact raw context for the prompt ---------- */

$hist = [];
foreach ($rows as $r) {
    $hist[] = [
        't_utc'      => $r['t_utc'],
        'price'      => $r['price'],
        'regime'     => $r['regime'],
        'bias'       => $r['bias'],
        'confidence' => (int)$r['confidence'],
        'atr'        => $r['atr'],
    ];
}

/* ---------- prompt per our agreed spec ---------- */

$prompt_notes_and_playbook = <<<TXT
Ты — русскоязычный трейдинг-аналитик. Задача: из коротких рыночных признаков сделать
1) краткие заметки (один абзац, максимум ~400–450 символов)
2) плейбук (до 4 сценариев) — массив объектов:
   dir: "long"|"short",
   setup: коротко («отбой от aVWAP», «пробой диапазона», «ретест уровня»),
   entry: зона входа/условие,
   invalidation: где идея ломается (стоп/условие),
   tp1: реалистичная цель-1,
   tp2: цель-2,
   confidence: 0..100,
   why: 3–5 очень коротких аргументов через «;».
Важное:
- работаем на 5m (+контекст 1m/3m/15m/60m), тикер — крипто фьючерс.
- не выдумывай уровни — опирайся на присланные box_top/box_bot, ob_bear/ob_bull, aVWAP, дневной VWAP (если они есть в данных).
- если сигналов мало — дай безопасный, консервативный сценарий.
- тон — деловой, без эмоций и без эмодзи, всё на русском.
Отвечай строго JSON: {"notes":"…","playbook":[…]}
Данные (свежие сверху):
TXT;

$raw_for_llm = [
    'symbol'  => $symbol,
    'tf'      => $tf,
    'latest'  => $analysis,
    'history' => $hist,
    // при желании сюда можно добавить уровни ob_bull/ob_bear/box_top/box_bot и т.п.
];

$ai_block = null;
$raw_dump = null;

/* ---------- call LLM if available ---------- */

try {
    if (function_exists('ai_interpret_json')) {
        // Небольшая страховка: превращаем массив в компактный текст для промпта.
        $data_txt = json_encode($raw_for_llm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $prompt = $prompt_notes_and_playbook . "\n" . $data_txt;

        // Схему оставим свободной: функция у вас уже валидирует JSON-ответ модели.
        $resp = ai_interpret_json($prompt, [
            'force_json' => true,
            'max_tokens' => 500,
            'temperature'=> 0.3,
        ]);

        // ожидаем {"notes":"…","playbook":[…]}
        if (isset($resp['notes']) && isset($resp['playbook'])) {
            $ai_block = $resp;
            $raw_dump = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            // не упадём — вернём «пустой» результат
            $ai_block = [
                'notes'    => 'Сигналы слабые; сохраняем осторожность. Дождаться ясного импульса или ретеста уровня.',
                'playbook' => [],
            ];
            $raw_dump = json_encode($ai_block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    } else {
        // Фолбэк: базовый, без вызова модели
        $dir = ($analysis['bias'] === 'short') ? 'short' : 'long';
        $ai_block = [
            'notes' => 'Краткая заметка по рынку на основе последних признаков анализа.',
            'playbook' => [[
                'dir'         => $dir,
                'setup'       => 'пробой диапазона',
                'entry'       => 'при пробое локальной поддержки',
                'invalidation'=> 'возврат выше пробитого уровня',
                'tp1'         => 'первая цель',
                'tp2'         => 'вторая цель',
                'confidence'  => max(0, min(100, (int)$analysis['confidence'])),
                'why'         => ['режим: '.$analysis['regime'], 'bias: '.$analysis['bias'], 'осторожно при низкой уверенности'],
            ]],
        ];
        $raw_dump = json_encode($ai_block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    // Не валим весь эндпоинт
    $ai_block = [
        'notes'    => 'Не удалось получить расширенный анализ — используем базовый сценарий.',
        'playbook' => [],
    ];
    $raw_dump = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------- unify response ---------- */

$meta['elapsed_ms'] = (int)round((microtime(true) - $startedAt) * 1000);

$response = [
    'ok'      => true,
    'meta'    => $meta,
    'analysis'=> $analysis,
    'ai'      => [
        'notes'    => $ai_block['notes'] ?? '',
        'playbook' => $ai_block['playbook'] ?? [],
        'raw'      => $raw_dump,
    ],
];

// Для режима «entry» оставим тот же формат (дашборду удобнее единый контракт)
if ($mode === 'entry') {
    $response['meta']['mode'] = 'entry';
}

// Небольшой блок для дебага (удобно на dashboard)
$response['debug'] = [
    'rows' => $rows,
];

jok($response);

/* ---------- global catch ---------- */
} catch (Throwable $e) {
    jerr('exception', $e->getMessage());
}
