<?php
// narrative.php — текстовый разбор на русском с учётом последних N снапшотов
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

// ---------- утилиты ----------
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function base_symbol($s){
    $s = strtoupper(trim((string)$s));
    if (strpos($s, ':') !== false) $s = explode(':', $s, 2)[1];
    $s = preg_replace('/([.\-_]?P(?:ERP)?)$/', '', $s);
    $s = preg_replace('/\s+PERPETUAL.*$/', '', $s);
    $s = preg_replace('/\s+SWAP.*$/', '', $s);
    return preg_replace('/[^A-Z0-9]/', '', $s);
}
function norm_symbol_for_ui($s){ return 'BINANCE:' . base_symbol($s); }
function atr_simple(array $ohlc, int $len=14){
    // ohlc: [[o,h,l,c], ...] — вернём средний TR за len последних
    $n = count($ohlc); if ($n<2) return null;
    $sum=0; $cnt=0;
    for($i=max(1,$n-$len+1); $i<$n; $i++){
        $h=$ohlc[$i][1]; $l=$ohlc[$i][2]; $pc=$ohlc[$i-1][3];
        $tr = max($h-$l, abs($h-$pc), abs($l-$pc));
        $sum += $tr; $cnt++;
    }
    return $cnt>0 ? $sum/$cnt : null;
}
function slope(array $arr){
    // нормированный наклон (корреляция с индексом)
    $n = count($arr); if ($n<2) return 0.0;
    $x = range(0,$n-1);
    $mx = array_sum($x)/$n; $my = array_sum($arr)/$n;
    $num=0; $den=0;
    for($i=0;$i<$n;$i++){ $dx = $x[$i]-$mx; $dy=$arr[$i]-$my; $num+=$dx*$dy; $den+=$dx*$dx; }
    return $den>0 ? $num/$den : 0.0;
}

// ---------- вход ----------
$qsym     = $_GET['symbol'] ?? '';
$tf       = $_GET['tf'] ?? '5';
$lookback = max(12, min(200, (int)($_GET['lookback'] ?? 36))); // по умолчанию 36*5м ≈ 3 часа
if ($qsym==='') out(['status'=>'error','error'=>'symbol_required']);

$base = base_symbol($qsym);

try{
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);

    // берём побольше и фильтруем по base_symbol в PHP (как в latest_analysis.php v2.2)
    $st = $pdo->prepare("SELECT * FROM {$TB_SNAP} WHERE tf=:tf ORDER BY ts DESC LIMIT 500");
    $st->execute([':tf'=>$tf]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);

    // фильтруем свой символ
    $rows=[]; foreach($all as $r){ if (base_symbol($r['symbol'])===$base) $rows[]=$r; }
    if (!$rows) out(['status'=>'ok','found'=>false]);

    // сортируем по возрастанию времени и берём хвост lookback
    usort($rows, fn($a,$b)=>($a['ts']<=>$b['ts']));
    if (count($rows)>$lookback) $rows = array_slice($rows, -$lookback);

    // собираем OHLC, цены, признаки последней свечи
    $ohlc=[]; $closes=[]; $lastFeatures=[]; $lastLevels=[]; $lastTs=end($rows)['ts']; $lastSym=end($rows)['symbol'];
    foreach($rows as $r){
        $f = json_decode($r['features'], true) ?: [];
        $o = isset($f['open'])  ? (float)$f['open']  : (isset($r['price'])?(float)$r['price']:null);
        $h = isset($f['high'])  ? (float)$f['high']  : null;
        $l = isset($f['low'])   ? (float)$f['low']   : null;
        $c = isset($f['close']) ? (float)$f['close'] : (isset($r['price'])?(float)$r['price']:null);
        if ($o!==null && $h!==null && $l!==null && $c!==null){
            $ohlc[] = [$o,$h,$l,$c];
            $closes[] = $c;
        }
        $lastFeatures = $f; // на последней итерации — свежие фичи
        $lastLevels   = json_decode($r['levels'], true) ?: [];
    }

    if (count($ohlc)<4) out(['status'=>'ok','found'=>false,'reason'=>'not_enough_history']);

    // быстрые метрики на истории
    $ret = [];
    for($i=1;$i<count($closes);$i++){
        $ret[] = ($closes[$i]-$closes[$i-1]) / max(1e-9, $closes[$i-1]);
    }
    $avgRet = count($ret)? array_sum($ret)/count($ret) : 0.0;
    $lastC  = end($closes);
    $firstC = reset($closes);
    $chgTot = ($lastC-$firstC)/$firstC;

    // тело/тени последней свечи
    $L = end($ohlc); [$o,$h,$l,$c] = $L;
    $body = abs($c-$o); $range = max(1e-9, $h-$l);
    $upperW = $h - max($o,$c);
    $lowerW = min($o,$c) - $l;

    $atr14 = atr_simple($ohlc,14);
    $slp   = slope($closes); // наклон цены (линейная регрессия vs индекс)

    // возьмём последний анализ (probabilities/levels) из истории анализатора
    $st2 = $pdo->prepare("SELECT * FROM {$TB_ANALYZE} WHERE snapshot_id=:sid ORDER BY id DESC LIMIT 1");
    $st2->execute([':sid'=> end($rows)['id'] ]);
    $rowA = $st2->fetch(PDO::FETCH_ASSOC);
    $ana  = ($rowA && !empty($rowA['result_json'])) ? json_decode($rowA['result_json'], true) : [];

    // составляем контекст для GPT
    $ctx = [
        "symbol" => norm_symbol_for_ui($lastSym),
        "tf"     => $tf,
        "ts"     => (int)$lastTs,
        "price"  => $lastC,
        "history" => [
            "lookback" => $lookback,
            "close_sample" => array_slice($closes, -12), // короткая выборка для масштаба
            "total_return" => round($chgTot*100,2),
            "avg_return"   => round($avgRet*100,3),
            "slope_linreg" => round($slp,6),
            "atr14"        => $atr14
        ],
        "last_candle" => [
            "open"=>$o, "high"=>$h, "low"=>$l, "close"=>$c,
            "range"=>$range, "body"=>$body, "upper_wick"=>$upperW, "lower_wick"=>$lowerW,
            "body_pct_of_range" => $range>0 ? round($body/$range*100,1) : null
        ],
        "last_features" => $lastFeatures,
        "levels" => $lastLevels,
        "analysis" => $ana
    ];

    // --------- GPT вызов ---------
    // вернём аккуратный markdown + краткую выжимку в JSON (на всякий)
    $schema = [
        "type"=>"object",
        "properties"=>[
            "narrative_md"=>["type"=>"string", "description"=>"Русский структурированный разбор в Markdown."],
            "summary"=>[
                "type"=>"object",
                "properties"=>[
                    "outlook"=>["type"=>"string", "description"=>"Коротко: лонг/шорт/нейтрально"],
                    "horizon_bars"=>["type"=>"integer"],
                    "key_points"=>["type"=>"array","items"=>["type"=>"string"]],
                    "risks"=>["type"=>"array","items"=>["type"=>"string"]],
                    "invalidators"=>["type"=>"array","items"=>["type"=>"string"]]
                ],
                "required"=>["outlook","horizon_bars"]
            ]
        ],
        "required"=>["narrative_md"]
    ];

    $sys = "Ты — профессиональный трейдинг-аналитик. Отвечай только по-русски. " .
           "Дай лаконичный, структурированный разбор для скальпинга на 5м с контекстом 15м. " .
           "Не придумывай уровни — используй переданные. Без воды, по делу.";

    $prompt = "Данные для анализа (JSON):\n" .
              json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $payload = [
        "model" => $OPENAI_MODEL,
        "response_format" => ["type"=>"json_object"],
        "temperature" => 0.2,
        "max_tokens" => 900,
        "messages" => [
            ["role"=>"system","content"=>$sys],
            ["role"=>"system","content"=>"JSON Schema: ".json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
            ["role"=>"user","content"=>$prompt]
        ]
    ];

    $narr = ["narrative_md"=>"", "summary"=>null];
    if (!empty($OPENAI_API_KEY)){
        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>[
                "Authorization: Bearer {$OPENAI_API_KEY}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>45
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp && $http>=200 && $http<300){
            $jr  = json_decode($resp,true);
            $txt = $jr['choices'][0]['message']['content'] ?? '{}';
            $obj = json_decode($txt,true);
            if (is_array($obj)) $narr = array_merge($narr,$obj);
        } else {
            if ($err && $GLOBALS['DEBUG']) error_log("OpenAI ERR: $err");
        }
    }

    out([
        "status"=>"ok","found"=>true,
        "symbol"=>norm_symbol_for_ui($lastSym),
        "tf"=>$tf,
        "ts"=>(int)$lastTs,
        "narrative"=>$narr,
        "context_used"=>$ctx
    ]);

}catch(Throwable $e){
    if ($DEBUG) out(["status"=>"error","error"=>$e->getMessage()]);
    out(["status"=>"error"]);
}
