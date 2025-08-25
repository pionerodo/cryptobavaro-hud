# CryptoBavaro HUD — Project Passport

Последнее обновление: 2025‑08‑24

## 0. Назначение

HUD‑конвейер принимает рыночные снимки (Pine/TradingView), сохраняет их в MySQL,
анализирует (AI) и отображает в dashboard. Поддерживается ручной вызов AI
(«быстрый разбор») и «сигнал входа (коридор)».

## 1. Архитектура (упрощённо)

TradingView (HUD v10.5)
      │  webhook (JSON, {{alert_message}})
      ▼
  api/hud_webhook.php  ──►  DB: crypto_wp.cbav_hud_snapshots
                                 │
                                 ├─► api/hud_analyze.php
                                 │     (берёт последний снапшот,
                                 │      пишет результат в cbav_hud_analyses)
                                 │
                                 ├─► api/hud_api_strict.php?sym=...&tf=...&limit=...
                                 │     (последние записи анализа)
                                 │
                                 └─► api/hud_ai.php?mode=now|hist&sym=...&tf=...
                                       (достаёт данные и вызывает OpenAI)

## 2. Важные пути

- API: /api/
- hud_webhook.php — приём TV webhook, insert в snapshots
- hud_analyze.php — анализ последнего snapshot, insert в analyses
- hud_api_strict.php — строгий API (последние анализы)
- hud_ai.php — вызов AI, генерация playbook
- api_ai_client.php — обёртка OpenAI
- db.php — PDO коннект

- Secrets:
  - api/openai.key — User Secret Key
  - logs/: tv_webhook.log, analyze.log

## 3. Схема БД

### cbav_hud_snapshots
id, received_at, id_tag, symbol, tf, ver, ts, price, features, levels, patterns, raw, tv_secret_ok, source_ip, user_agent
Индексы: ts, (symbol, tf, ts)

### cbav_hud_analyses
id, snapshot_id, analyzed_at, symbol, tf, ver, ts, t_utc, price, regime, bias, confidence, atr, sym, result_json, notes, prob_long, prob_short, summary_md, raw_json
Индексы: ts, (symbol, tf, ts), (snapshot_id)

## 4. REST‑эндпоинты

- /api/hud_webhook.php (POST JSON из Pine)
- /api/hud_analyze.php?sym=...&tf=5
- /api/hud_api_strict.php?sym=...&tf=5&limit=3
- /api/hud_ai.php?mode=now&sym=...&tf=5

## 5. Диагностика (CLI)

curl webhook, analyze, api_strict, hud_ai  
tail logs  

## 6. Безопасность

- секрет в Pine  
- openai.key права 600  
- лог‑файлы не публичные

## 7. TODO

- очистка старых записей  
- новостная компонента  
- ротация логов  
- визуальные графики в dashboard  
