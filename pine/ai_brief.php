<?php
// ai_brief.php — формирует «человечный» обзор по шаблону без GPT
// GET: symbol=BINANCE:BTCUSDT&tf=5
// Возврат: {status:"ok", sections:{...}, text:"..."}  (UTF-8)

require_once __DIR__ . '/config.php';   // db(), json_ok(), json_err()
require_once __DIR__ . '/helpers.php';  // safe_str(), dt_ymdhms(), etc. — если есть

header('Content-Type: application/json; charset=utf-8');

try {
    $symbol = isset($_GET['symbol']) ? trim($_GET['symbol']) : 'BINANCE:BTCUSDT';
    $tf     = isset($_GET['tf']) ? trim($_GET['tf']) : '5';

    $pdo = db();

    // Берём последний снимок по тикеру/таймфрейму
    $stmt = $pdo->prepare("
        SELECT id, symbol, tf, ts, price,
               f_json, lv_json, pat_json
        FROM hud_snapshots
        WHERE symbol = :sym AND tf = :tf
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':sym' => $symbol, ':tf' => $tf]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_err("snapshot not found for {$symbol} tf={$tf}", 404);
        exit;
    }

    $price = (float)$row['price'];
    $ts    = (int)$row['ts'];

    // Разбираем JSON‑поля (безопасно)
    $f  = json_decode($row['f_json']  ?: "{}", true) ?: [];
    $lv = json_decode($row['lv_json'] ?: "[]",  true) ?: [];
    $pt = json_decode($row['pat_json'] ?: "[]", true) ?: [];

    // Извлечём минимум того, что точно есть в наших снапшотах
    $mm = isset($f['mm']) ? (string)$f['mm'] : 'neutral';       // режим рынка
    $mc = isset($f['mc']) ? (int)$f['mc'] : null;               // сила режима (0..10)
    // сюда при желании добавим RSI/стохастик/BBW и пр., когда Pine их начнёт слать стабильно
    // пример: $rsi = isset($f['rsi']) ? (float)$f['rsi'] : null;

    // Ключевые уровни (берём максимум 3)
    $levels = [];
    foreach ($lv as $i => $it) {
        if ($i >= 3) break;
        $levels[] = [
            't'  => isset($it['t'])  ? (string)$it['t']  : '',
            'lv' => isset($it['lv']) ? (float)$it['lv'] : null,
            'da' => isset($it['da']) ? (float)$it['da'] : null, // дистанция в ATR или пунктах
        ];
    }

    // Паттерны (берём максимум 3)
    $patterns = [];
    foreach ((array)$pt as $i => $pname) {
        if ($i >= 3) break;
        $patterns[] = (string)$pname;
    }

    // ---------- Сборка секций по вашему шаблону ----------
    // 1) Общая картина
    $mm_ru_map = [
        'trend'    => 'тренд',
        'range'    => 'флэт (диапазон)',
        'expansion'=> 'расширение',
        'neutral'  => 'нейтрально',
        'other'    => 'неопределённо'
    ];
    $mm_ru = isset($mm_ru_map[$mm]) ? $mm_ru_map[$mm] : $mm;

    $sec_overview = [];
    $sec_overview[] = "BTC/USDT на tf {$tf}м. Текущая цена: " . number_format($price, 1, '.', ' ');
    $sec_overview[] = "Режим рынка: {$mm_ru}" . ($mc !== null ? " (сила ~{$mc}/10)" : "") . ".";
    if (!empty($levels)) {
        $first = $levels[0];
        $sec_overview[] = "Ближайший уровень: {$first['t']} ≈ " . number_format($first['lv'], 0, '.', ' ');
    }
    if (!empty($patterns)) {
        $sec_overview[] = "Замечены паттерны: " . implode(', ', $patterns) . ".";
    }
    $overview = implode("\n", $sec_overview);

    // 2) Индикаторы (пока «легкая версия» — расширим, когда Pine начнёт слать)
    $ind_lines = [];
    // примеры: если появятся f['rsi'], f['k'], f['d'], f['bbw'], добавим сюда:
    if (isset($f['rsi']))      $ind_lines[] = "RSI: " . round($f['rsi'], 1);
    if (isset($f['k']))        $ind_lines[] = "Стохастик %K: " . round($f['k'], 1);
    if (isset($f['d']))        $ind_lines[] = "Стохастик %D: " . round($f['d'], 1);
    if (isset($f['bbw']))      $ind_lines[] = "Bollinger BandWidth: " . round($f['bbw'], 3);
    if (empty($ind_lines))     $ind_lines[] = "Пока нет стабильных индикаторов от Pine — используется только режим рынка и уровни.";

    $indicators = implode("\n", $ind_lines);

    // 3) Ключевые уровни
    $lvl_lines = [];
    foreach ($levels as $L) {
        $tag = $L['t'] ?: 'уровень';
        $lvl_lines[] = "- {$tag}: " . number_format($L['lv'], 0, '.', ' ') . (!is_null($L['da']) ? " (Δ≈{$L['da']})" : "");
    }
    if (empty($lvl_lines)) $lvl_lines[] = "Нет уровней в последних данных.";
    $levels_txt = implode("\n", $lvl_lines);

    // 4) Сценарии (очень аккуратные правила без ИИ)
    $long_lines = [];
    $short_lines = [];

    // эвристика: если режим «тренд» и близко сопротивление — предложить шорт‑скальп при отскоке; если «флэт» — оба сценария по границам
    if ($mm === 'trend') {
        // Если тренд, по умолчанию предлагаем торговать в сторону импульса (для простоты считаем «вниз» при pattern DT/DB можно расширить)
        // Здесь пока нейтрально: даём оба скальп‑сценария, но просим дождаться подтверждений
        $long_lines[]  = "Вход только при возврате выше ближайшего уровня и закреплении (подтверждение объемом/свечами).";
        $short_lines[] = "Скальп‑шорт от теста ближайшего сопротивления при слабых бычьих свечах.";
    } elseif ($mm === 'range' || $mm === 'neutral') {
        $long_lines[]  = "Покупка от нижней границы диапазона при разворотных свечах и подтверждении индикаторов.";
        $short_lines[] = "Продажа от верхней границы диапазона при отказе от пробоя.";
    } else {
        $long_lines[]  = "Консервативно дождаться подтверждений удержания локальной поддержки.";
        $short_lines[] = "Шорт только при явном отказе цены выше ближайшего сопротивления.";
    }

    // Тех. детали скальпа (универсальные)
    $long_lines[]  = "Стоп-лосс: сразу под локальной поддержкой/минимумом.";
    $short_lines[] = "Стоп-лосс: сразу над локальным сопротивлением/максимумом.";
    $long_lines[]  = "Цели (тейк): первая цель — ближайший уровень/округлые цены; фиксировать частями.";
    $short_lines[] = "Цели (тейк): первая цель — ближайший уровень/округлые цены; фиксировать частями.";

    $scenario_long  = implode("\n", $long_lines);
    $scenario_short = implode("\n", $short_lines);

    // 5) «Что бы сделал я»
    $final_lines = [];
    $final_lines[] = "Я бы дождался(ась) чётких подтверждений от цены у ближайших уровней.";
    $final_lines[] = "Работаем размером 5% от банкролла (по инструкции). Риск — стандартный для скальпа.";
    $final = implode("\n", $final_lines);

    // Собираем секции + плоский текст
    $sections = [
        'meta' => [
            'symbol' => $symbol,
            'tf'     => $tf,
            'time'   => isset($ts) ? $ts : null,
            'price'  => $price,
        ],
        'overview'   => $overview,
        'indicators' => $indicators,
        'levels'     => $levels_txt,
        'long'       => $scenario_long,
        'short'      => $scenario_short,
        'final'      => $final,
    ];

    // Склеенный текст для отображения/отправки
    $text =
        "Общая картина\n" . $sections['overview'] . "\n\n" .
        "Индикаторы\n"    . $sections['indicators'] . "\n\n" .
        "Ключевые уровни\n" . $sections['levels'] . "\n\n" .
        "Сценарий лонг\n" . $sections['long'] . "\n\n" .
        "Сценарий шорт\n" . $sections['short'] . "\n\n" .
        "Что бы сделал я\n" . $sections['final'];

    echo json_encode(['status' => 'ok', 'sections' => $sections, 'text' => $text], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e) {
    echo json_err($e->getMessage(), 500);
}
