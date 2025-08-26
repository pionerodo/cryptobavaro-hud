# -*- coding: utf-8 -*-

from flask import Flask, request, jsonify, abort, Response
from flask_cors import CORS
import json
import logging
import mysql.connector
from mysql.connector import Error
import requests
import datetime
import subprocess
import os
import time

# --- КОНФИГУРАЦИЯ ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Не забудь вставить твой ключ
# ИЗМЕНЕНИЕ: Указываем правильную модель gemini-2.5-flash
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent"
PROJECT_PATH = '/www/wwwroot/cryptobavaro.online/gemini_analytics_api'

# --- ИНИЦИАЛИЗАЦИЯ ---
logging.basicConfig(filename='webhook.log', level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
app = Flask(__name__)
CORS(app)

# --- ФУНКЦИИ-ПРОМПТЫ ДЛЯ GEMINI ---
def format_corridor_prompt(market_data):
    """Формирует промпт для автоматического 'Сигнала входа'."""
    return f"""
Ты — русскоязычный трейдинг-аналитик.
Контекст: последние данные по символу {market_data['symbol']} на 5-минутном ТФ.
- Цена закрытия: {market_data['close_price']}
- RSI(14) на M5: {market_data['rsi']:.2f}
- Положение цены относительно EMA(200) на H1: {'выше' if market_data['close_price'] > market_data['h1_ema200'] else 'ниже'}
- Глобальный тренд на H4: {market_data['h4_trend']}
- Ключевые уровни Пивот: S1={market_data['pivot_s1']}, P={market_data['pivot_p']}, R1={market_data['pivot_r1']}
- Уровень VWAP: {market_data['vwap']:.2f}
Задача («Сигнал входа / коридор сделки»):
- Дай короткие заметки (один абзац, до 250–300 символов).
- Сформируй строго 1–2 максимально практичных сценария для работы в коридоре/диапазоне.
Важное:
- Ответ должен содержать только JSON-объект с полями "notes" (string) и "playbook" (array of objects). Без лишнего текста, объяснений и markdown.
"""

def format_manual_analysis_prompt(market_data):
    """Формирует промпт для ручного 'Текущего анализа'."""
    return f"""
Ты — русскоязычный трейдинг-аналитик.
Контекст: последние данные по символу {market_data['symbol']} на 5-минутном ТФ.
- Цена закрытия: {market_data['close_price']}
- RSI(14) на M5: {market_data['rsi']:.2f}
- Положение цены относительно EMA(200) на H1: {'выше' if market_data['close_price'] > market_data['h1_ema200'] else 'ниже'}
- Глобальный тренд на H4: {market_data['h4_trend']}
- Ключевые уровни Пивот: S1={market_data['pivot_s1']}, P={market_data['pivot_p']}, R1={market_data['pivot_r1']}
- Уровень VWAP: {market_data['vwap']:.2f}
Задача: из коротких рыночных признаков сделать:
1) краткие заметки (один абзац, максимум ~400–450 символов);
2) плейбук (до 4 сценариев) — массив объектов.
Важное:
- Не выдумывай уровни — опирайся на присланные.
- Если сигналов мало — дай безопасный, консервативный сценарий.
- Тон — деловой, без эмоций, без эмодзи, всё на русском.
- Ответ должен содержать только JSON-объект с полями "notes" (string) и "playbook" (array of objects). Без лишнего текста, объяснений и markdown.
"""

# --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---
def get_gemini_analysis(prompt):
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    payload = json.dumps({"contents": [{"parts": [{"text": prompt}]}],"generationConfig": {"response_mime_type": "application/json"}})
    retries = 5
    delay = 2
    for i in range(retries):
        try:
            response = requests.post(GEMINI_API_URL, headers=headers, params=params, data=payload, timeout=60)
            if response.status_code == 429:
                logging.warning(f"Получен статус 429 (Too Many Requests). Попытка {i+1}/{retries}. Ждем {delay} сек...")
                time.sleep(delay)
                delay *= 2
                continue
            response.raise_for_status()
            result = response.json()
            content = result['candidates'][0]['content']['parts'][0]['text']
            logging.info("Получен ответ от Gemini.")
            return json.loads(content)
        except requests.exceptions.RequestException as e:
            logging.error(f"Ошибка при запросе к Gemini API: {e}")
            time.sleep(delay)
            delay *= 2
        except (KeyError, IndexError, json.JSONDecodeError) as e:
            logging.error(f"Ошибка парсинга ответа от Gemini: {e}")
            logging.error(f"Сырой ответ: {response.text if 'response' in locals() else 'No response'}")
            return None
    logging.error("Не удалось получить ответ от Gemini после нескольких попыток.")
    return None

def save_market_data(data):
    last_id = None
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        query = """INSERT INTO market_data (symbol, event_timestamp, open_price, high_price, low_price, close_price, volume, ema21, ema50, macd_line, macd_signal, macd_hist, rsi, stoch_k, stoch_d, bb_upper, bb_middle, bb_lower, atr, vwap, pivot_p, pivot_r1, pivot_s1, h1_ema200, h1_rsi, h4_trend) VALUES (%(symbol)s, %(timestamp)s, %(open)s, %(high)s, %(low)s, %(close)s, %(volume)s, %(ema21)s, %(ema50)s, %(macd_line)s, %(macd_signal)s, %(macd_hist)s, %(rsi)s, %(stoch_k)s, %(stoch_d)s, %(bb_upper)s, %(bb_middle)s, %(bb_lower)s, %(atr)s, %(vwap)s, %(pivot_p)s, %(pivot_r1)s, %(pivot_s1)s, %(h1_ema200)s, %(h1_rsi)s, %(h4_trend)s)"""
        params = {"symbol": data.get("symbol"), "timestamp": data.get("timestamp"), **data.get("price_data_m5", {}), **data.get("indicators_m5", {}), **data.get("levels_m5", {}), **data.get("context_htf", {})}
        cursor.execute(query, params)
        conn.commit()
        last_id = cursor.lastrowid
        logging.info(f"Рыночные данные сохранены с ID: {last_id}")
    except Error as e:
        logging.error(f"Ошибка при сохранении рыночных данных: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
    return last_id

def get_latest_market_data():
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM market_data ORDER BY id DESC LIMIT 1")
        return cursor.fetchone()
    except Error as e:
        logging.error(f"Ошибка при получении данных из MySQL: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
    return None

def save_gemini_analysis(market_data_id, analysis_data):
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        query = """INSERT INTO gemini_analysis (market_data_id, analysis_type, notes, playbook) VALUES (%s, %s, %s, %s)"""
        params = (market_data_id, "Сигнал входа", analysis_data.get('notes'), json.dumps(analysis_data.get('playbook'), ensure_ascii=False))
        cursor.execute(query, params)
        conn.commit()
        logging.info(f"Анализ Gemini для market_data_id {market_data_id} сохранен.")
    except Error as e:
        logging.error(f"Ошибка при сохранении анализа Gemini: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

# --- МАРШРУТЫ API ---
@app.route('/gemini_analytics_api/get_latest_analysis', methods=['GET'])
def get_latest_analysis():
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM gemini_analysis ORDER BY id DESC LIMIT 1")
        latest_analysis = cursor.fetchone()
        if latest_analysis:
            if isinstance(latest_analysis.get('created_at'), datetime.datetime):
                latest_analysis['created_at'] = latest_analysis['created_at'].isoformat()
            if isinstance(latest_analysis.get('playbook'), str):
                latest_analysis['playbook'] = json.loads(latest_analysis['playbook'])
            return jsonify(latest_analysis)
        else:
            return jsonify({"error": "No analysis found"}), 404
    except Error as e:
        logging.error(f"Ошибка при получении анализа из БД: {e}")
        return jsonify({"error": "Database error"}), 500
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

@app.route('/gemini_analytics_api/get_manual_analysis', methods=['GET'])
def get_manual_analysis():
    try:
        latest_market_data = get_latest_market_data()
        if latest_market_data:
            prompt = format_manual_analysis_prompt(latest_market_data)
            analysis = get_gemini_analysis(prompt)
            if analysis:
                return jsonify(analysis)
            else:
                return jsonify({"error": "Failed to get analysis from Gemini"}), 500
        else:
            return jsonify({"error": "No market data found"}), 404
    except Exception as e:
        logging.error(f"Критическая ошибка в get_manual_analysis: {e}", exc_info=True)
        return jsonify({"error": "Internal server error"}), 500

@app.route('/gemini_analytics_api/webhook', methods=['POST'])
def tradingview_webhook():
    raw_data = request.get_data(as_text=True)
    if not raw_data: abort(400)
    try:
        data = json.loads(raw_data)
        market_data_id = save_market_data(data)
        if market_data_id:
            latest_market_data = get_latest_market_data()
            if latest_market_data:
                prompt = format_corridor_prompt(latest_market_data)
                analysis = get_gemini_analysis(prompt)
                if analysis:
                    save_gemini_analysis(market_data_id, analysis)
        return jsonify({"status": "success"}), 200
    except Exception as e:
        logging.error(f"Критическая ошибка в webhook: {e}", exc_info=True)
        abort(500)

@app.route('/gemini_analytics_api/run_backtest')
def run_backtest():
    start_date = request.args.get('start_date')
    end_date = request.args.get('end_date')
    if not start_date or not end_date:
        def error_stream():
            yield f"data: {json.dumps({'log': 'Ошибка: Даты не указаны.', 'type': 'loss'})}\n\n"
        return Response(error_stream(), mimetype='text/event-stream')

    def generate():
        venv_python_path = os.path.join(PROJECT_PATH, '58c0af3bed89ba2482d01178345656cb_venv/bin/python3')
        script_path = os.path.join(PROJECT_PATH, 'backtester.py')
        command = [venv_python_path, script_path, start_date, end_date]
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1, encoding='utf-8')
        
        for line in process.stdout:
            try:
                line_type_str = line.split(']')[0][1:].strip().upper()
                message_str = line.split(']', 1)[1].strip()
                data_to_send = {}
                if line_type_str == 'LOG':
                    data_to_send = {"log": message_str, "type": "info"}
                elif line_type_str in ['WIN', 'LOSS']:
                    data_to_send = {"log": message_str, "type": line_type_str.lower()}
                elif line_type_str == 'STATS':
                    stats_data = json.loads(message_str.replace("'", "\""))
                    data_to_send = {"stats": stats_data}
                
                if data_to_send:
                    yield f"data: {json.dumps(data_to_send, ensure_ascii=False)}\n\n"
            except Exception:
                continue
    return Response(generate(), mimetype='text/event-stream')

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
