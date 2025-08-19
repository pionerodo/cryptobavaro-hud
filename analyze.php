<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php'; // тут живёт db()

// ---------- утилиты ----------
function jexit($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function dberr($mysqli) {
    return $mysqli->connect_errno ? $mysqli->connect_error : $mysqli->error;
}
function ensureTables($m) {
    // таблица с анализами (если ещё нет)
    $sql = "CREATE TABLE IF NOT EXISTS tv_analyses (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              snapshot_id INT UNSIGNED NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              notes TEXT NULL,
              playbook TEXT NULL,
              raw_model TEXT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uniq_snapshot (snapshot_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $m->query($sql);
}
function sanitize_str($s, $max=480) {
    $s = trim((string)$s);
    if (mb_strlen($s,'UTF-8') > $max) $s = mb_substr($s,0,$max,'UTF-8') . '…';
    return $s;
}

// ---------- OpenAI ----------
function call_openai_json($model, $apiKey, $messages, $max_tokens=650, $temperature=0.2) {
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $max_tokens,
        // строгий JSON на выход
        'response_format' => ['type' => 'json_object'],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [null, 'cURL error: '.$err];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        return [null, "OpenAI HTTP $code: $resp"];
    }
    $data = json_decode($resp, true);
    if (!isset($data['choices'][0]['message']['content'])) {
        return [null, 'OpenAI response parse error'];
    }
    return [$data['choices'][0]['message']['content'], null];
}

function build_prompt_system() {
    return <<<SYS
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
- работаем скальпингом 5m (+контекст 15m), тикер — крипто фьючерс.
- не выдумывай уровни — опирайся на присланные box_top/box_bot, ob_bear/ob_bull, aVWAP, дневной VWAP.
- если сигналов мало — дай безопасный, консервативный сценарий.
- тон — деловой, без эмоций и без эмодзи, всё на русском.
Отвечай строго JSON: {"notes":"…","playbook":[…]}
SYS;
}

function build_prompt_user($snap) {
    // превращаем компактные ключи в подсказку для модели
    $map = [
        'atr'=>'ATR', 'bbw'=>'ширина BB', 'bbr'=>'ранг BB', 'rsi'=>'RSI',
        'k'=>'Stoch %K', 'd'=>'Stoch %D', 'mz'=>'MACD Z-score',
        'ts'=>'знак тренда HTF (EMA 50>200=1, < -1, 0=нейтр.)',
        'tr'=>'сила тренда (ΔEMA/ATR)', 'hs'=>'структура HTF (-1/0/1)',
        'aw'=>'aVWAP', 'at'=>'тип якоря aVWAP (1/-1)',
        'bt'=>'узкий бокс (1/0)', 'dbt'=>'дистанция до верха бокса в ATR',
        'dbb'=>'дистанция до низа бокса в ATR', 'dav'=>'дистанция до aVWAP в ATR',
        'nbt'=>'вблизи верха бокса', 'nbb'=>'вблизи низа бокса',
        'nbo'=>'вблизи бычьего OB', 'nba'=>'вблизи медвежьего OB',
        'mm'=>'режим рынка', 'mc'=>'уверенность (0..100)','mrs'=>'топ‑причины short',
        'sess'=>'сессия', 'vz'=>'волатильность объёма Z', 'vsp'=>'всплеск объёма',
        'sc'=>'сильная свеча', 'sst'=>'длина серии сильных свечей', 'sqs'=>'длина сжатия',
        'note'=>'автозаметки (кратко)'
    ];

    $levels = [];
    foreach(($snap['lv'] ?? []) as $lv){
        $levels[] = [
            'type'=>$lv['t'] ?? '',
            'level'=>$lv['lv'] ?? null,
            'dATR'=>$lv['da'] ?? null
        ];
    }
    $patterns = $snap['pat'] ?? [];

    $payload = [
        'symbol'  => $snap['symbol'] ?? '',
        'tf'      => $snap['tf'] ?? '',
        'price'   => $snap['p'] ?? null,
        'session' => $snap['f']['sess'] ?? '',
        'features'=> $snap['f'] ?? [],
        'features_legend' => $map,
        'levels'  => $levels,
        'patterns'=> $patterns
    ];
    return "Данные снапшота:\n".json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function run_analysis_for_snapshot($m, $snapRow) {
    global $OPENAI_API_KEY, $OPENAI_MODEL;

    // Вытаскиваем «компактный» пакет из tv_snapshots (как его пишет Pine)
    $payload = json_decode($snapRow['payload_json'] ?? '[]', true);
    // Дополнительно соберём то, что уже разложили по колонкам
    $snap = [
        'id'     => (int)$snapRow['id'],
        'symbol' => $snapRow['symbol'],
        'tf'     => $snapRow['tf'],
        'ts'     => (int)$snapRow['ts'],
        'p'      => (float)$snapRow['price'],
        'f'      => json_decode($snapRow['features_json'] ?? '{}', true),
        'lv'     => json_decode($snapRow['levels_json'] ?? '[]', true),
        'pat'    => json_decode($snapRow['patterns_json'] ?? '[]', true),
        'analysis'=> ['notes'=>'нет','playbook'=>[]]
    ];

    // Если ключа GPT нет — вернём пустышку, но сохраним «заглушку», чтобы фронт не ждал
    if (empty($OPENAI_API_KEY)) {
        $notes = 'GPT не подключён: добавь ключ в config.php';
        $play  = [];
    } else {
        $sys = build_prompt_system();
        $usr = build_prompt_user($snap);
        list($content, $err) = call_openai_json($OPENAI_MODEL, $OPENAI_API_KEY, [
            ['role'=>'system','content'=>$sys],
            ['role'=>'user','content'=>$usr]
        ], 700, 0.15);
        if ($err) {
            $notes = "Ошибка GPT: $err";
            $play  = [];
        } else {
            // Парсим JSON из ответа
            $j = json_decode($content, true);
            if (!is_array($j)) {
                $notes = "Не удалось распарсить ответ модели.";
                $play  = [];
            } else {
                $notes = sanitize_str($j['notes'] ?? '');
                $play  = $j['playbook'] ?? [];
                // лёгкая нормализация массива сценариев
                if (!is_array($play)) $play = [];
                // подрежем «why», цифры и т.п.
                foreach ($play as &$p) {
                    if (isset($p['why']) && is_string($p['why'])) {
                        $p['why'] = sanitize_str($p['why'], 280);
                    }
                    foreach (['setup','entry','invalidation','tp1','tp2'] as $k) {
                        if (isset($p[$k]) && is_string($p[$k])) $p[$k] = sanitize_str($p[$k], 120);
                    }
                    if (isset($p['confidence'])) {
                        $c = (int)$p['confidence'];
                        if ($c<0) $c=0; if ($c>100) $c=100;
                        $p['confidence']=$c;
                    }
                }
                unset($p);
            }
        }
    }

    // Сохраняем в БД
    $stmt = $m->prepare("INSERT INTO tv_analyses (snapshot_id, notes, playbook, raw_model) VALUES (?,?,?,?) 
                         ON DUPLICATE KEY UPDATE notes=VALUES(notes), playbook=VALUES(playbook), raw_model=VALUES(raw_model)");
    $playJson = json_encode($play, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $rawJson  = isset($content) ? $content : null;
    $stmt->bind_param('isss', $snap['id'], $notes, $playJson, $rawJson);
    if (!$stmt->execute()) {
        return ['ok'=>false, 'error'=>'DB write error: '.dberr($m)];
    }
    $analysis_id = $m->insert_id ?: (int)$m->query("SELECT id FROM tv_analyses WHERE snapshot_id=".(int)$snap['id'])->fetch_assoc()['id'];

    return [
        'ok'=>true,
        'snapshot_id'=>$snap['id'],
        'analysis_id'=>$analysis_id,
        'analysis'=>['notes'=>$notes, 'playbook'=>json_decode($playJson,true)],
    ];
}

// ---------- обработка запроса ----------
$limit = max(1, (int)($_GET['limit'] ?? 3));
$mode  = $_GET['mode'] ?? 'latest';   // 'latest' — анализируем последние без анализа; 'rebuild' — пересчитать по id
$id    = isset($_GET['id']) ? (int)$_GET['id'] : null;

$m = db();
ensureTables($m);

$out = ['status'=>'ok','items'=>[]];

if ($mode === 'rebuild' && $id) {
    // Берём конкретный снапшот и пересчитываем
    $q = $m->prepare("SELECT s.* FROM tv_snapshots s WHERE s.id=? LIMIT 1");
    $q->bind_param('i',$id);
    $q->execute();
    $res = $q->get_result();
    if ($row = $res->fetch_assoc()) {
        $out['items'][] = run_analysis_for_snapshot($m, $row);
    } else {
        $out['items'][] = ['ok'=>false,'error'=>'snapshot not found','snapshot_id'=>$id];
    }
    jexit($out);
}

// По умолчанию — обрабатываем последние «без анализа»
$sql = "SELECT s.*
        FROM tv_snapshots s
        LEFT JOIN tv_analyses a ON a.snapshot_id = s.id
        WHERE a.id IS NULL
        ORDER BY s.id DESC
        LIMIT ?";
$q = $m->prepare($sql);
$q->bind_param('i',$limit);
$q->execute();
$res = $q->get_result();

if ($res->num_rows === 0) {
    // Нечего анализировать — покажем последние наличие
    $out['items'] = [];
    jexit($out);
}

while ($row = $res->fetch_assoc()) {
    $out['items'][] = run_analysis_for_snapshot($m, $row);
}

jexit($out);
