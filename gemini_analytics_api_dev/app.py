import json
import os
import traceback
import time
import threading
from flask import Flask, request, abort, jsonify, render_template_string
import pymysql.cursors
import pandas as pd
import google.generativeai as genai
from binance.client import Client

app = Flask(__name__)

# --- 1. КОНФИГУРАЦИЯ (без изменений) ---
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM"
BINANCE_API_KEY = "ulDnOwGQa5oMLG9ZGRDOHobyh5Q4TNKwzHzuD6MMQsx97niR8terJ7HtMCaLO76r"
BINANCE_API_SECRET = "9e68PpKpJbYc6jq5Es1gDQKAcOfGzJlCIlKUQDJ8yedkiCqTtqrtxVz7hWGBZrjA"
DB_HOST = "localhost"
DB_USER = "gemini_dev"
DB_PASSWORD = "27C7fYRbhfcJhWB6"
DB_NAME = "gemini_tr_dev"

# Инициализация клиентов
try:
    genai.configure(api_key=GEMINI_API_KEY)
    model = genai.GenerativeModel('gemini-2.5-pro')
except Exception as e:
    print(f"!!! ОШИБКА КОНФИГУРАЦИИ GEMINI: {e}")
    model = None

binance_client = Client(BINANCE_API_KEY, BINANCE_API_SECRET)

# --- 2. СУПЕР-ПРОМПТ (без изменений) ---
SUPER_PROMPT = """..."""

# --- 3. HTML ШАБЛОН (без изменений) ---
DASHBOARD_TEMPLATE = """..."""

# --- 4. ФОНОВЫЙ СБОРЩИК ДАННЫХ С BINANCE (ИСПРАВЛЕНО) ---
def fetch_binance_data_periodically():
    symbol = 'BTCUSDT'
    while True:
        try:
            print(f"--- [Binance v7.3] Запрос данных для {symbol} ---")
            
            # --- ИЗМЕНЕНО: Используем альтернативные названия методов ---
            # 1. Получаем Long/Short Ratio
            ls_ratio_data = binance_client.futures_global_long_short_account_ratio(symbol=symbol, period='5m')
            latest_ls_ratio = float(ls_ratio_data[-1]['longShortRatio'])
            
            # 2. Получаем Open Interest
            oi_data = binance_client.futures_open_interest(symbol=symbol)
            open_interest_value = float(oi_data['openInterest'])
            
            print(f"[Binance v7.3] L/S Ratio: {latest_ls_ratio}, Open Interest: {open_interest_value}")
            
            # 3. Сохраняем в БД
            connection = get_db_connection()
            with connection.cursor() as cursor:
                sql = "INSERT INTO `market_sentiment` (symbol, long_short_ratio, open_interest) VALUES (%s, %s, %s)"
                cursor.execute(sql, (symbol, latest_ls_ratio, open_interest_value))
            connection.close()
            print("[Binance v7.3] Данные успешно сохранены.")

        except Exception as e:
            print(f"!!! [Binance v7.3] ОШИБКА СБОРА ДАННЫХ: {e}")
            traceback.print_exc() # Печатаем полный путь ошибки для диагностики
        
        time.sleep(300)

# --- 5. ОСНОВНЫЕ ФУНКЦИИ И МАРШРУТЫ (без изменений) ---
# ... (Весь остальной код остается прежним) ...
def get_db_connection():
    return pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASSWORD, database=DB_NAME, cursorclass=pymysql.cursors.DictCursor, autocommit=True)

def run_analysis(connection, market_data_id):
    print("--- Запуск анализа ---")
    if not model:
        print("Модель Gemini не инициализирована.")
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
                "\n\n--- Данные с графика ---\n" +
                df_market.to_string() +
                "\n\n--- Данные о настроениях с Binance ---\n" +
                df_sentiment.to_string()
            )
            
            response = model.generate_content(final_prompt)
            cleaned_response_text = response.text.replace('```json', '').replace('```', '').strip()
            analysis_json = json.loads(cleaned_response_text)
            
            sql_insert = "INSERT INTO `analysis_results` (market_data_id, signal_found, decision, reason, entry_price, stop_loss, take_profit, tp_reason) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)"
            cursor.execute(sql_insert, (market_data_id, analysis_json.get('signal'), analysis_json.get('decision'), analysis_json.get('reason'), analysis_json.get('entry_price'), analysis_json.get('stop_loss'), analysis_json.get('take_profit'), analysis_json.get('tp_reason')))
            print("Результат анализа успешно сохранен в БД.")

    except Exception as e:
        print(f"!!! ОШИБКА АНАЛИЗА: {e}")
        traceback.print_exc()

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

if __name__ == '__main__':
    binance_thread = threading.Thread(target=fetch_binance_data_periodically)
    binance_thread.daemon = True
    binance_thread.start()
    app.run(host='0.0.0.0', port=5001, debug=False)
