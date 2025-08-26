<?php
/**
 * Мини‑клиент ChatGPT.
 * Достаёт ключ из окружения OPENAI_API_KEY или из файла /www/wwwroot/cryptobavaro.online/OPENAI_API_KEY.txt
 * Модель по умолчанию: gpt-4.1-mini (можно изменить через окружение OPENAI_MODEL).
 */
function load_openai_key() {
    $k = getenv('OPENAI_API_KEY');
    if ($k && strlen(trim($k)) > 20) return trim($k);
    $fallback = '/www/wwwroot/cryptobavaro.online/OPENAI_API_KEY.txt';
    if (is_readable($fallback)) {
        $k = trim(file_get_contents($fallback));
        if ($k && strlen($k) > 20) return $k;
    }
    throw new Exception("OPENAI_API_KEY не найден ни в окружении, ни в $fallback");
}

function ai_chat($prompt, $system = "Ты — трейдинг-ассистент. Пиши коротко и по делу. Отвечай JSON.", $temperature = 0.2) {
    $apiKey = load_openai_key();
    $model  = getenv('OPENAI_MODEL');
    if (!$model) $model = 'gpt-4.1-mini';

    $url = 'https://api.openai.com/v1/chat/completions';
    $payload = [
        'model' => $model,
        'temperature' => $temperature,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $prompt],
        ],
        'response_format' => ['type' => 'json_object']
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 40,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300) throw new Exception("OpenAI HTTP $code: $resp");

    $data = json_decode($resp, true);
    $text = $data['choices'][0]['message']['content'] ?? '';
    return $text;
}
