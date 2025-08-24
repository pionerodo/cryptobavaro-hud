<?php
/**
 * Высокоуровневый endpoint ChatGPT:
 * GET: mode=now|edge, sym, tf, limit (для контекста)
 */
require_once __DIR__.'/hud_common.php';
require_once __DIR__.'/api_ai_client.php';

function fetch_context($db, $sym, $tf, $limit = 8) {
    // последние анализы
    $stmt = $db->prepare("
        SELECT ts, tf, sym, result_json
        FROM cbav_hud_analyses
        WHERE sym=? AND tf=?
        ORDER BY ts DESC
        LIMIT ?
    ");
    $stmt->bind_param('sii', $sym, $tf, $limit);
    $stmt->execute();
    $anal = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // последние снапшоты (price + выбранные фичи)
    $stmt = $db->prepare("
        SELECT ts, tf, symbol, price, features
        FROM cbav_hud_snapshots
        WHERE symbol=? AND tf=?
        ORDER BY ts DESC
        LIMIT ?
    ");
    $stmt->bind_param('sii', $sym, $tf, $limit);
    $stmt->execute();
    $snaps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [$anal, $snaps];
}

try {
    $mode = $_GET['mode'] ?? 'now';
    $sym  = isset($_GET['sym']) ? norm_symbol($_GET['sym']) : 'BINANCE:BTCUSDT';
    $tf   = isset($_GET['tf']) ? intval($_GET['tf']) : 5;
    $lim  = isset($_GET['limit']) ? max(3,min(12,intval($_GET['limit']))) : 8;

    $db = db_connect_via_wp();
    list($anal, $snaps) = fetch_context($db, $sym, $tf, $lim);

    $context = [
        'sym' => $sym, 'tf' => $tf,
        'analyses' => $anal,
        'snapshots'=> $snaps,
    ];

    if ($mode === 'now') {
        $prompt = json_encode([
            'task' => 'Сформируй краткий текущий разбор и прогноз на ближайший интервал времени. Ответ строго JSON.',
            'requirements' => [
                'fields' => ['summary','outlook','plan','risk','confidence'],
                'summary' => '1-2 фразы о текущем режиме/контексте',
                'outlook' => 'прогноз/ожидания на следующий интервал TF',
                'plan'    => 'короткий план действий: лонг/шорт/ждать; ключевые уровни',
                'risk'    => 'ключевые риски/инвалидация',
                'confidence' => '0..100'
            ],
            'data' => $context
        ], JSON_UNESCAPED_UNICODE);

        $json = ai_chat($prompt, "Ты — ассистент-аналитик крипторынка. Пиши компактно, строго JSON.", 0.2);
        json_out(['ok'=>true, 'mode'=>'now', 'sym'=>$sym,'tf'=>$tf, 'ai'=>json_decode($json,true)]);
        exit;
    }

    if ($mode === 'edge') {
        $prompt = json_encode([
            'task' => 'Определи, есть ли сейчас «коридор входа» с хорошим R/R. Ответ строго JSON.',
            'requirements' => [
                'fields' => ['has_edge','why','direction','entry_zone','invalid_level','take_profits','confidence'],
                'has_edge' => 'true/false',
                'direction'=> 'long|short|null',
                'entry_zone'=> 'диапазон цен или уровень',
                'invalid_level'=> 'где сценарий ломается',
                'take_profits'=> '1-2 цели',
                'confidence'=> '0..100'
            ],
            'data' => $context
        ], JSON_UNESCAPED_UNICODE);

        $json = ai_chat($prompt, "Ты — ассистент-трейдер. Отвечай только JSON, без преамбулы.", 0.25);
        json_out(['ok'=>true, 'mode'=>'edge', 'sym'=>$sym,'tf'=>$tf, 'ai'=>json_decode($json,true)]);
        exit;
    }

    throw new Exception("Unknown mode");
} catch (Throwable $e) {
    http_response_code(500);
    json_out(['ok'=>false, 'error'=>$e->getMessage()]);
}
