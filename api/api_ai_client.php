<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_config.php';
if (!defined('AI_CONFIG_OK') || !defined('OPENAI_API_KEY') || strlen(OPENAI_API_KEY) < 10) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'AI config missing']);
    exit;
}

/**
 * Простой вызов Chat Completions API.
 *
 * @param array $messages  Массив сообщений [{role, content}, ...]
 * @param array $opts      ['model' => '...', 'temperature' => 0.2, 'max_tokens' => 800] и т.д.
 * @return array           ['ok'=>true,'text'=>'...'] или ['ok'=>false,'error'=>'...']
 */
function ai_chat(array $messages, array $opts = []): array
{
    $model = $opts['model'] ?? (defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini');
    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $opts['temperature'] ?? 0.2,
        'max_tokens'  => $opts['max_tokens']  ?? 800,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENAI_API_KEY,
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'error' => "curl error: $err"];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => "http $code: $raw"];
    }
    $json = json_decode($raw, true);
    $text = $json['choices'][0]['message']['content'] ?? '';

    if ($text === '') {
        return ['ok' => false, 'error' => 'empty response'];
    }
    return ['ok' => true, 'text' => $text];
}
