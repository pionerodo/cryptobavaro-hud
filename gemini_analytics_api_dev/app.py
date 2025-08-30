import json
import os
import traceback
import time
import threading
from flask import Flask, request, abort, jsonify, render_template_string
import pymysql.cursors
import pandas as pd
import google.generativeai as genai
import requests

app = Flask(__name__)

# --- 1. КОНФИГУРАЦИЯ ---
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM"
TELEGRAM_BOT_TOKEN = "8220978437:AAFu6-z7VQLnE6kIwRpdeuwiWBSGJoiv22k"
TELEGRAM_CHAT_ID = "7169230113"
DB_HOST = "localhost"
DB_USER = "gemini_dev"
DB_PASSWORD = "27C7fYRbhfcJhWB6"
DB_NAME = "gemini_tr_dev"

# Инициализация моделей Gemini
try:
    genai.configure(api_key=GEMINI_API_KEY)
    pro_model = genai.GenerativeModel('gemini-2.5-pro')
    flash_model = genai.GenerativeModel('gemini-2.5-flash')
except Exception as e:
    print(f"!!! ОШИБКА КОНФИГУРАЦИИ GEMINI: {e}")
    pro_model = None
    flash_model = None

# --- 2. СУПЕР-ПРОМПТ v1.5 (С ДАННЫМИ BINANCE) ---
SUPER_PROMPT = """
# РОЛЬ И ГЛАВНАЯ ЦЕЛЬ
Ты — элитный русскоязычный финансовый аналитик, специализирующийся на методологии Smart Money Concepts (SMC). Твоя главная задача — действовать как профессиональный трейдер: идентифицировать высоковероятностные сетапы, где потенциальная прибыль значительно превышает рассчитанный риск.

# КЛЮЧЕВЫЕ ПРИНЦИПЫ АНАЛИЗА
1.  **Контекст Старшего Таймфрейма (HTF) — ГЛАВНЫЙ ПРИОРИТЕТ:** Любой сетап на M5 должен строго соответствовать глобальному направлению со структуры M15. Если есть противоречие — сигнал игнорируется.
2.  **Конфлюенция (Совпадение факторов):** Сигнал считается надежным, только если он подтверждается **минимум тремя** аналитическими факторами.
3.  **Ликвидность:** Лучшие точки входа — это манипуляции (например, `SFP`), которые снимают ликвидность с очевидных максимумов или минимумов.

# АЛГОРИТМ АНАЛИЗА (ШАГ ЗА ШАГОМ)
### Шаг 1: Определи Рыночный Режим (по `adx_14`)
- adx > 25 -> "Тренд".
- adx < 20 -> "Флэт".

### Шаг 2: Определи Глобальное Направление (по `structure_m15`)
- В "Тренде": Ищи последние `BOS_Up` или `BOS_Down`.
- Во "Флэте": Ищи `CHoCH_Up` у нижней границы и `CHoCH_Down` у верхней.

### Шаг 3: Найди Точку Входа и Конфлюенцию
- Анализируй **самые последние строки** данных с графика.
- **Контекст сессии (`trading_session`):** `London`, `New York`, `NY-London Overlap` имеют высокий приоритет. Сигналы во время `Asia` и `Out of Session` имеют очень низкий приоритет.
- **Найди триггер (`sfp_m5`):** Ищи SFP, который соответствует твоему анализу.

### Шаг 4: Управление риском и целью
- **Stop Loss:** Рассчитай на основе `atr_14`: Лонг: `Цена входа - (1.5 * ATR)`, Шорт: `Цена входа + (1.5 * ATR)`.
- **"Умный" Take Profit:** Определи цель по следующему логическому уровню ликвидности (противоположный ордер-блок, значимый high/low). R:R не менее 1:2.

### Шаг 5: Финальная проверка по настроениям рынка (Order Flow)
- Проанализируй **самые последние данные из Binance**: `long_short_ratio` и `open_interest`.
- **`long_short_ratio`**: Если значение экстремальное (например, > 2.5 для лонгов или < 0.5 для шортов), это **красный флаг**. Это значит, "толпа" уже в позиции, и маркет-мейкер может пойти против нее.
- **`open_interest`**: Рост открытого интереса с ростом цены подтверждает тренд.
- **Правило вето:** Если технический сетап идеален, но данные о настроениях ему **сильно противоречат**, **пропусти сделку** или укажи на этот повышенный риск.

# ФОРМАТ ОТВЕТА (JSON)
Твой ответ **обязательно** должен быть ТОЛЬКО в формате JSON-объекта. Поле `"reason"` **обязательно** должно быть написано **на русском языке**, быть четко структурированным и разбитым на абзацы.
- Если найден сетап: `{"signal": "yes", "decision": "BUY" или "SELL", "reason": "...", "entry_price": ..., "stop_loss": ..., "take_profit": ..., "tp_reason": "..."}`
- Если сетап не найден: `{"signal": "no", "reason": "..."}`

# ВХОДНЫЕ ДАННЫЕ ДЛЯ АНАЛИЗА:
"""

# --- 3. HTML ШАБЛОН ДЛЯ ДАШБОРДА ---
DASHBOARD_TEMPLATE = """
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Analyst Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #121212; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .dashboard { background-color: #1e1e1e; border-radius: 12px; padding: 2rem; width: 90%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #333; }
        h1 { text-align: center; color: #fff; margin-bottom: 0.5rem; }
        .timestamp { text-align: center; color: #888; margin-bottom: 2rem; font-size: 0.9rem; }
        .card { background-color: #2a2a2a; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card.buy { border-left: 5px solid #28a745; }
        .card.sell { border-left: 5px solid #dc3545; }
        .card.wait { border-left: 5px solid #ffc107; }
        .card h2 { margin-top: 0; font-size: 1.5rem; }
        .card.buy h2 { color: #28a745; }
        .card.sell h2 { color: #dc3545; }
        .card.wait h2 { color: #ffc107; }
        .reason { color: #ccc; line-height: 1.6; white-space: pre-wrap; }
        .details { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1.5rem; }
        .details div { background-color: #333; padding: 1rem; border-radius: 6px; }
        .details div strong { display: block; color: #888; font-size: 0.8rem; margin-bottom: 0.5rem; text-transform: uppercase; }
        .details div span { font-size: 1.2rem; font-weight: bold; }
        .loading { text-align: center; padding: 2rem; }
    </style>
</head>
<body>
    <div class="dashboard">
        <h1>AI-Аналитик</h1>
        <p class="timestamp" id="timestamp">Загрузка последнего анализа...</p>
        <div id="analysis-result">
            <div class="loading">Ожидание данных...</div>
        </div>
    </div>
    <script>
        function updateDashboard() {
            fetch('/get_latest_analysis')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('analysis-result').innerHTML = `<div class="card wait"><h2>Ошибка</h2><p class="reason">${data.error}</p></div>`;
                        return;
                    }
                    const resultDiv = document.getElementById('analysis-result');
                    const timestampP = document.getElementById('timestamp');
                    const date = new Date(data.created_at);
                    timestampP.textContent = `Последний анализ: ${date.toLocaleString('ru-RU')}`;
                    let html = '';
                    if (data.signal_found === 'yes') {
                        const decisionClass = data.decision.toLowerCase();
                        html = `
                            <div class="card ${decisionClass}">
                                <h2>СИГНАЛ: ${data.decision}</h2>
                                <p class="reason">${data.reason}</p>
                                <div class="details">
                                    <div><strong>Вход</strong><span>${data.entry_price}</span></div>
                                    <div><strong>Стоп-лосс</strong><span>${data.stop_loss}</span></div>
                                    <div><strong>Тейк-профит</strong><span>${data.take_profit}</span></div>
                                </div>
                                <div style="margin-top: 1rem;">
                                    <strong>Обоснование цели:</strong>
                                    <p class="reason" style="margin-top: 0.5rem;">${data.tp_reason || 'Не указано'}</p>
                                </div>
                            </div>
                        `;
                    } else {
                        html = `
                            <div class="card wait">
                                <h2>СИГНАЛ: ОЖИДАНИЕ</h2>
                                <p class="reason">${data.reason}</p>
                            </div>
                        `;
                    }
                    resultDiv.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error fetching analysis:', error);
                    document.getElementById('analysis-result').innerHTML = `<div class="card wait"><h2>Ошибка</h2><p class="reason">Не удалось загрузить данные. Проверьте консоль.</p></div>`;
                });
        }
        updateDashboard();
        setInterval(updateDashboard, 15000);
    </script>
</body>
</html>
"""

# --- 4. ФУНКЦИЯ ОТПРАВКИ В TELEGRAM ---
def send_telegram_notification(analysis_json):
    print("--- Отправка уведомления в Telegram ---")
    try:
        decision = analysis_json.get('decision', 'N/A')
        entry = analysis_json.get('entry_price', 'N/A')
        stop = analysis_json.get('stop_loss', 'N/A')
        take = analysis_json.get('take_profit', 'N/A')
        reason = analysis_json.get('reason', 'N/A')
        icon = "📈" if decision == "BUY" else "📉" if decision == "SELL" else "⏳"
        message = (
            f"{icon} *НОВЫЙ СИГНАЛ: {decision}*\n\n"
            f"*Причина:*\n{reason}\n\n"
            f"ENTRY: `{entry}`\n"
            f"STOP: `{stop}`\n"
            f"TAKE: `{take}`"
        )
        url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage"
        payload = {'chat_id': TELEGRAM_CHAT_ID, 'text': message, 'parse_mode': 'Markdown'}
        response = requests.post(url, json=payload, timeout=10)
        if response.status_code == 200:
            print("Уведомление в Telegram успешно отправлено.")
        else:
            print(f"!!! ОШИБКА отправки в Telegram: {response.text}")
    except Exception as e:
        print(f"!!! КРИТИЧЕСКАЯ ОШИБКА в send_telegram_notification: {e}")
        traceback.print_exc()

# --- 5. ОСНОВНЫЕ ФУНКЦИИ И МАРШРУТЫ ---
def get_db_connection():
    return pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASSWORD, database=DB_NAME, cursorclass=pymysql.cursors.DictCursor, autocommit=True)

def run_analysis(connection, market_data_id):
    print("--- Запуск анализа через Gemini Pro ---")
    if not pro_model:
        print("Модель Gemini Pro не инициализирована.")
        return
    try:
        with connection.cursor() as cursor:
            sql_market = "SELECT * FROM `market_data` WHERE `created_at` >= NOW() - INTERVAL 12 HOUR ORDER BY `created_at` ASC"
            cursor.execute(sql_market)
            market_history = cursor.fetchall()
            if not market_history: return
            df_market = pd.DataFrame(market_history)
            
            sql_sentiment = "SELECT * FROM `market_sentiment` WHERE `created_at` >= NOW() - INTERVAL 12 HOUR ORDER BY `created_at` ASC"
            cursor.execute(sql_sentiment)
            sentiment_history = cursor.fetchall()
            df_sentiment = pd.DataFrame(sentiment_history) if sentiment_history else pd.DataFrame()

            final_prompt = (
                SUPER_PROMPT +
                "\n\n--- Данные с графика ---\n" + df_market.to_string() +
                "\n\n--- Данные о настроениях с Binance ---\n" + df_sentiment.to_string()
            )
            
            response = pro_model.generate_content(final_prompt)
            cleaned_response_text = response.text.replace('```json', '').replace('```', '').strip()
            analysis_json = json.loads(cleaned_response_text)
            
            sql_insert = "INSERT INTO `analysis_results` (market_data_id, signal_found, decision, reason, entry_price, stop_loss, take_profit, tp_reason) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)"
            cursor.execute(sql_insert, (market_data_id, analysis_json.get('signal'), analysis_json.get('decision'), analysis_json.get('reason'), analysis_json.get('entry_price'), analysis_json.get('stop_loss'), analysis_json.get('take_profit'), analysis_json.get('tp_reason')))
            print("Результат анализа успешно сохранен в БД.")

            if analysis_json.get('signal') == 'yes':
                send_telegram_notification(analysis_json)

    except Exception as e:
        print(f"!!! ОШИБКА АНАЛИЗА: {e}")
        traceback.print_exc()

def to_sql_float(value):
    try:
        if isinstance(value, str) and value.strip().lower() == 'none': return None
        return float(value)
    except (ValueError, TypeError): return None

def to_sql_string(value):
    if value is None: return None
    val_str = str(value).strip()
    if val_str and val_str.lower() != 'none': return val_str
    return None
    
@app.route('/webhook', methods=['POST'])
def webhook():
    connection = None
    try:
        data_str = request.get_data(as_text=True)
        data = json.loads(data_str)
        
        payload = (
            to_sql_string(data.get('symbol')), data.get('event_timestamp'), to_sql_float(data.get('close_price')),
            to_sql_string(data.get('trading_session')), to_sql_string(data.get('structure_m15')),
            to_sql_string(data.get('sfp_m5')), to_sql_float(data.get('bullish_ob_m5')),
            to_sql_float(data.get('bearish_ob_m5')), to_sql_float(data.get('adx_14')),
            to_sql_float(data.get('atr_14'))
        )
        
        connection = get_db_connection()
        with connection.cursor() as cursor:
            sql = "INSERT INTO `market_data` (`symbol`, `event_timestamp`, `close_price`, `trading_session`, `structure_m15`, `sfp_m5`, `bullish_ob_m5`, `bearish_ob_m5`, `adx_14`, `atr_14`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"
            cursor.execute(sql, payload)
            market_data_id = cursor.lastrowid
            print(f"Данные сохранены в market_data с ID: {market_data_id}")
        
        run_analysis(connection, market_data_id)
        
        return 'Webhook received and processed!', 200

    except Exception as e:
        print(f"!!! КРИТИЧЕСКАЯ ОШИБКА WEBHOOK: {e}")
        traceback.print_exc()
        abort(500)
    finally:
        if connection:
            connection.close()

@app.route('/')
def dashboard():
    return render_template_string(DASHBOARD_TEMPLATE)

@app.route('/get_latest_analysis')
def get_latest_analysis():
    connection = get_db_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM `analysis_results` ORDER BY id DESC LIMIT 1")
            result = cursor.fetchone()
            if result:
                return jsonify(result)
            else:
                return jsonify({"error": "Анализов еще нет."}), 404
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=False)
