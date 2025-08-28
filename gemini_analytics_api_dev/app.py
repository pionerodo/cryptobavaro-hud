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

# --- КОНФИГУРАЦИЯ ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini_dev',
    'password': '27C7fYRbhfcJhWB6',
    'database': 'gemini_tr_dev'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Твой ключ
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent"
# ИЗМЕНЕНИЕ: Путь к проекту теперь указывает на тестовую папку
PROJECT_PATH = '/www/wwwroot/cryptobavaro.online/gemini_analytics_api_dev'

# --- ИНИЦИАЛИЗАЦИЯ ---
# ИЗМЕНЕНИЕ: Лог-файл теперь будет в папке dev
logging.basicConfig(filename=os.path.join(PROJECT_PATH, 'webhook.log'), level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
app = Flask(__name__)
CORS(app)

# --- ПРОМПТЫ v2.1 (без изменений) ---
def format_corridor_prompt_v2_1(market_data):
    return f"""
Ты — системный сканер торговых сетапов. Твоя задача — находить качественные внутридневные сетапы с горизонтом реализации от 15 до 120 минут.
Контекст:
- Текущие рыночные индикаторы: { {k: v for k, v in market_data.items() if k not in ['symbol', 'price_dynamics_2h']} }
- Динамика цен за последние 2 часа (price_dynamics_2h): {market_data.get('price_dynamics_2h')}. Проанализируй этот массив, чтобы понять импульс, недавние пробои уровней и формирование локальной структуры.
Правила:
1. Генерируй сетап только если вероятность его успеха выше 65%. Если уверенность ниже, верни пустой "playbook".
2. Ответ должен быть в строгом JSON-формате со следующими полями: "bias", "playbook".
3. Объект в "playbook" должен содержать: "dir", "setup", "trigger", "entry_price", "stop_loss", "take_profit_1", "probability", "rationale".
Ответ: Верни только JSON.
"""

def format_manual_analysis_prompt_v2_1(market_data):
    return f"""
Ты — системный трейдинг-аналитик. Твоя задача: На основе предоставленных данных и анализа динамики цены за последние 2 часа найти до 3-х потенциальных торговых сетапов с горизонтом 15-120 минут.
Контекст:
- Текущие рыночные индикаторы: { {k: v for k, v in market_data.items() if k not in ['symbol', 'price_dynamics_2h']} }
- Динамика цен за последние 2 часа (price_dynamics_2h): {market_data.get('price_dynamics_2h')}.
Правила:
1. В "market_summary" дай краткую оценку рыночной фазы простым языком.
2. Для каждого сетапа в "playbook" предоставь полный набор полей: "dir", "setup", "trigger", "entry_price", "stop_loss", "take_profit_1", "probability", "rationale".
Ответ: Верни только JSON с полями "market_summary" и "playbook".
"""

# --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ (без изменений) ---
def get_gemini_analysis(prompt):
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    payload_dict = {"contents": [{"parts": [{"text": prompt}]}],"generationConfig": {"response_mime_type": "application/json",}}
    payload = json.dumps(payload_dict)
    try:
        response = requests.post(GEMINI_API_URL, headers=headers, params=params, data=payload, timeout=90)
        response.raise_for_status()
        result = response.json()
        content = result['candidates'][0]['content']['parts'][0]['text']
        logging.info("Получен V2.1 ответ от Gemini.")
        return json.loads(content)
    except Exception as e:
        logging.error(f"Ошибка V2.1 API: {e}")
        return None

def save_market_data_v2(data):
    last_id = None
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        query = """
        INSERT INTO market_data (symbol, event_timestamp, open_price, high_price, low_price, close_price, volume, ema21, ema50, ema200, macd_line, macd_signal, macd_hist, rsi_m5, vwap, squeeze_momentum, atr, volume_ratio, price_dynamics_2h, sfp_pattern_15m, channel_pattern_15m, rsi_m15, stoch_k_m15, ema200_h1, rsi_h1, h4_trend, pivot_p, pivot_r1, pivot_s1) 
        VALUES (%(symbol)s, %(event_timestamp)s, %(open_price)s, %(high_price)s, %(low_price)s, %(close_price)s, %(volume)s, %(ema21)s, %(ema50)s, %(ema200)s, %(macd_line)s, %(macd_signal)s, %(macd_hist)s, %(rsi_m5)s, %(vwap)s, %(squeeze_momentum)s, %(atr)s, %(volume_ratio)s, %(price_dynamics_2h)s, %(sfp_pattern_15m)s, %(channel_pattern_15m)s, %(rsi_m15)s, %(stoch_k_m15)s, %(ema200_h1)s, %(rsi_h1)s, %(h4_trend)s, %(pivot_p)s, %(pivot_r1)s, %(pivot_s1)s)
        """
        params = {key: data.get(key) for key in ['symbol', 'event_timestamp', 'open_price', 'high_price', 'low_price', 'close_price', 'volume', 'ema21', 'ema50', 'ema200', 'macd_line', 'macd_signal', 'macd_hist', 'rsi_m5', 'vwap', 'squeeze_momentum', 'atr', 'volume_ratio', 'price_dynamics_2h', 'sfp_pattern_15m', 'channel_pattern_15m', 'rsi_m15', 'stoch_k_m15', 'ema200_h1', 'rsi_h1', 'h4_trend', 'pivot_p', 'pivot_r1', 'pivot_s1']}
        cursor.execute(query, params)
        conn.commit()
        last_id = cursor.lastrowid
        logging.info(f"V2.1 Рыночные данные сохранены с ID: {last_id}")
    except Error as e:
        logging.error(f"V2.1 Ошибка при сохранении рыночных данных: {e}")
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
        logging.error(f"V2.1 Ошибка при получении данных из MySQL: {e}")
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
        notes_text = analysis_data.get('bias') or analysis_data.get('market_summary') or "N/A"
        params = (market_data_id, "AI Signal V2.1", notes_text, json.dumps(analysis_data.get('playbook'), ensure_ascii=False))
        cursor.execute(query, params)
        conn.commit()
        logging.info(f"V2.1 Анализ Gemini для market_data_id {market_data_id} сохранен.")
    except Error as e:
        logging.error(f"V2.1 Ошибка при сохранении анализа Gemini: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

# --- МАРШРУТЫ API ---
# ИЗМЕНЕНИЕ: Упрощаем все маршруты для работы с поддоменом
@app.route('/get_latest_analysis', methods=['GET'])
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
            latest_analysis['bias'] = latest_analysis.pop('notes', 'Нейтральный')
            return jsonify(latest_analysis)
        else:
            return jsonify({"error": "No analysis found"}), 404
    except Error as e:
        return jsonify({"error": "Database error"}), 500
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

@app.route('/get_manual_analysis', methods=['GET'])
def get_manual_analysis():
    try:
        latest_market_data = get_latest_market_data()
        if latest_market_data:
            prompt = format_manual_analysis_prompt_v2_1(latest_market_data)
            analysis = get_gemini_analysis(prompt)
            if analysis:
                return jsonify(analysis)
            else:
                return jsonify({"error": "Failed to get analysis from Gemini"}), 500
        else:
            return jsonify({"error": "No market data found"}), 404
    except Exception as e:
        return jsonify({"error": "Internal server error"}), 500

@app.route('/webhook', methods=['POST'])
def tradingview_webhook():
    raw_data = request.get_data(as_text=True)
    if not raw_data: abort(400)
    try:
        data = json.loads(raw_data)
        market_data_id = save_market_data_v2(data)
        if market_data_id:
            latest_market_data = get_latest_market_data()
            if latest_market_data:
                prompt = format_corridor_prompt_v2_1(latest_market_data)
                analysis = get_gemini_analysis(prompt)
                if analysis and analysis.get('playbook'):
                    save_gemini_analysis(market_data_id, analysis)
        return jsonify({"status": "success"}), 200
    except Exception as e:
        logging.error(f"Критическая ошибка в webhook v2.1: {e}", exc_info=True)
        abort(500)

@app.route('/run_backtest')
def run_backtest():
    start_date = request.args.get('start_date')
    end_date = request.args.get('end_date')
    if not start_date or not end_date:
        def error_stream():
            yield f"data: {json.dumps({'log': 'Ошибка: Даты не указаны.', 'type': 'loss'})}\n\n"
        return Response(error_stream(), mimetype='text/event-stream')

    def generate():
        # ИЗМЕНЕНИЕ: Путь к venv теперь правильный для тестового проекта
        venv_python_path = os.path.join(PROJECT_PATH, '800456055688cf58a9b2fb3c1ceae059_venv/bin/python3')
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
    # Этот порт используется только при локальном запуске, Gunicorn использует свой
    app.run(host='0.0.0.0', port=5001)

