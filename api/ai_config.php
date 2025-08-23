<?php
/**
 * Конфиг для доступа к OpenAI.
 * Файл не отдаёт контент в браузер (он исполняемый PHP).
 * Права можно поставить 640.
 */

if (!defined('AI_CONFIG_OK')) { define('AI_CONFIG_OK', true); }

define('OPENAI_API_KEY', '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'); // <-- ВСТАВЬ сюда ключ
define('OPENAI_MODEL',  'gpt-4o-mini');                  // можно gpt-4.1-mini, gpt-4o-mini и т.п.
