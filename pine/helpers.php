<?php
// helpers.php — вспомогательные функции.
// Этот файл можно безопасно подключать после config.php:
// функции объявляются только если ещё не существуют.

if (!function_exists('json_out')) {
    function json_out(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('err')) {
    function err(string $message, int $code = 500): void {
        json_out(['status' => 'error', 'error' => $message, 'code' => $code], $code);
    }
}

if (!function_exists('ok')) {
    function ok(array $payload = [], int $status = 200): void {
        json_out(array_merge(['status' => 'ok'], $payload), $status);
    }
}

if (!function_exists('param')) {
    function param(string $name, $default = null) {
        return $_GET[$name] ?? $_POST[$name] ?? $default;
    }
}

if (!function_exists('param_str')) {
    function param_str(string $name, string $default = ''): string {
        $v = param($name, $default);
        return is_string($v) ? trim($v) : $default;
    }
}

if (!function_exists('param_int')) {
    function param_int(string $name, int $default = 0): int {
        return (int) param($name, $default);
    }
}

if (!function_exists('require_params')) {
    function require_params(array $names): void {
        foreach ($names as $n) {
            if (param($n, null) === null) {
                err("$n required", 400);
            }
        }
    }
}
// test change Fri Aug 22 02:51:37 AM UTC 2025
