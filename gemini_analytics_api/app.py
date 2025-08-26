# -*- coding: utf-8 -*-

from flask import Flask, request, jsonify, abort
from flask_cors import CORS
import json
import logging
import mysql.connector
from mysql.connector import Error
import requests
import datetime

# --- НАСТРОЙКА ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Не забудьте вставить ваш ключ
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent"

# --- ИНИЦИАЛИЗАЦИЯ ---
logging.basicConfig(
    filename='webhook.log', 
    level=logging.INFO, 
    format='%(asctime)s - %(levelname)s - %(message)s'
)
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

# --- (Остальные функции get_gemini_analysis, save_market_data и т.д. остаются без изменений) ---
def get_gemini_analysis(prompt):
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    payload_dict = {"contents": [{"parts": [{"text": prompt}]}],"generationConfig": {"response_mime_type": "application/json",}}
    payload = json.dumps(payload_dict)
    try:
        response = requests.post(GEMINI_API_URL, headers=headers, params=params, data=payload, timeout=60)
        response.raise_for_status()
        result = response.json()
        content = result['candidates'][0]['content']['parts'][0]['text']
        logging.info("Получен ответ от Gemini.")
        return json.loads(content)
    except requests.exceptions.RequestException as e:
        logging.error(f"Ошибка при запросе к Gemini API: {e}")
    except (KeyError, IndexError, json.JSONDecodeError) as e:
        logging.error(f"Ошибка парсинга ответа от Gemini: {e}")
        logging.error(f"Сырой ответ: {response.text if 'response' in locals() else 'No response'}")
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

# НОВЫЙ МАРШРУТ
@app.route('/gemini_analytics_api/get_manual_analysis', methods=['GET'])
def get_manual_analysis():
    """Получает последние рыночные данные и запрашивает для них общий анализ."""
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

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
