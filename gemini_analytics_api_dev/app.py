# -*- coding: utf-8 -*-

from flask import Flask, request, jsonify, abort
from flask_cors import CORS
import json
import logging
import mysql.connector
from mysql.connector import Error
import requests
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
PROJECT_PATH = '/www/wwwroot/cryptobavaro.online/gemini_analytics_api_dev'

# --- ИНИЦИАЛИЗАЦИЯ ---
logging.basicConfig(filename=os.path.join(PROJECT_PATH, 'webhook.log'), level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
app = Flask(__name__)
CORS(app)

# --- НОВЫЙ ПРОМПТ v4.0 ДЛЯ SMC-АНАЛИЗА ---
def format_smc_prompt(market_data):
    return f"""
Ты — профессиональный трейдер, использующий методологию Smart Money Concepts (SMC). Твоя задача — провести многослойный анализ и найти один высоковероятностный сетап.

**Слой 1: Контекст и Структура**
- Текущая торговая сессия: {market_data.get('trading_session')}
- Структурное событие на M15: {market_data.get('structure_m15')} (Ищи BOS для подтверждения тренда или CHoCH для разворота).

**Слой 2: Зоны Интереса (POI) и Триггер**
- Цена закрытия: {market_data.get('close_price')}
- Последний бычий Ордер Блок на M5: {market_data.get('bullish_ob_m5')}
- Последний медвежий Ордер Блок на M5: {market_data.get('bearish_ob_m5')}
- Триггерное событие на M5: {market_data.get('sfp_m5')} (SFP - ключевой сигнал для входа).

**Правила принятия решений:**
1.  **ИЩИ ЛОНГ**, если выполнены ВСЕ условия:
    a) Структура на М15 бычья (`BOS_Up`) или произошла смена характера на бычью (`CHoCH_Up`).
    b) Произошел бычий триггер `SFP_Down` (сбор ликвидности снизу).
    c) Этот SFP произошел вблизи или на бычьем Ордер Блоке.
    d) Сессия - Лондон или Нью-Йорк (повышенный приоритет).
2.  **ИЩИ ШОРТ**, если выполнены ВСЕ условия:
    a) Структура на М15 медвежья (`BOS_Down`) или произошла смена характера на медвежью (`CHoCH_Down`).
    b) Произошел медвежий триггер `SFP_Up` (сбор ликвидности сверху).
    c) Этот SFP произошел вблизи или на медвежьем Ордер Блоке.
    d) Сессия - Лондон или Нью-Йорк (повышенный приоритет).
3.  **НЕ ГЕНЕРИРУЙ СИГНАЛ**, если нет четкого совпадения всех факторов. Верни пустой "playbook".
4.  **Для ЛОНГ-сетапа:**
    - `setup`: "SMC Long"
    - `stop_loss`: Рассчитай как минимум свечи, на которой был `SFP_Down`.
    - `take_profit_1`: Ближайший медвежий Ордер Блок или предыдущий значимый максимум.
5.  **Для ШОРТ-сетапа:**
    - `setup`: "SMC Short"
    - `stop_loss`: Рассчитай как максимум свечи, на которой был `SFP_Up`.
    - `take_profit_1`: Ближайший бычий Ордер Блок или предыдущий значимый минимум.
6.  `probability` должна быть не ниже 75%, так как мы ищем только лучшие сетапы.
7.  `rationale` должно кратко описывать всю цепочку логики (например, "CHoCH на M15, затем SFP на тесте бычьего ОБ во время Лондонской сессии").

Ответ: Верни только JSON с полями "bias" и "playbook".
"""

# --- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ---
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
        logging.info("Получен v4.0 SMC ответ от Gemini.")
        return json.loads(content)
    except Exception as e:
        logging.error(f"Ошибка v4.0 API: {e}")
        return None

def save_market_data_v4(data):
    last_id = None
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        query = """
        INSERT INTO market_data (
            symbol, event_timestamp, close_price, volume, trading_session, 
            structure_m15, sfp_m5, bullish_ob_m5, bearish_ob_m5, h4_trend, 
            pivot_p, pivot_r1, pivot_s1, ema200_m5
        ) VALUES (
            %(symbol)s, %(event_timestamp)s, %(close_price)s, %(volume)s, %(trading_session)s,
            %(structure_m15)s, %(sfp_m5)s, %(bullish_ob_m5)s, %(bearish_ob_m5)s, %(h4_trend)s,
            %(pivot_p)s, %(pivot_r1)s, %(pivot_s1)s, %(ema200_m5)s
        )
        """
        # Собираем только те параметры, которые есть в нашей новой таблице
        params = {
            'symbol': data.get('symbol'),
            'event_timestamp': data.get('event_timestamp'),
            'close_price': data.get('close_price'),
            'volume': data.get('volume'),
            'trading_session': data.get('trading_session'),
            'structure_m15': data.get('structure_m15'),
            'sfp_m5': data.get('sfp_m5'),
            'bullish_ob_m5': data.get('bullish_ob_m5'),
            'bearish_ob_m5': data.get('bearish_ob_m5'),
            'h4_trend': data.get('h4_trend'),
            'pivot_p': data.get('pivot_p'),
            'pivot_r1': data.get('pivot_r1'),
            'pivot_s1': data.get('pivot_s1'),
            'ema200_m5': data.get('ema200_m5')
        }
        cursor.execute(query, params)
        conn.commit()
        last_id = cursor.lastrowid
        logging.info(f"v4.0 SMC данные сохранены с ID: {last_id}")
    except Error as e:
        logging.error(f"v4.0 Ошибка при сохранении рыночных данных: {e}")
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
        logging.error(f"v4.0 Ошибка при получении данных из MySQL: {e}")
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
        notes_text = analysis_data.get('bias') or "N/A"
        params = (market_data_id, "AI Signal v4.0 SMC", notes_text, json.dumps(analysis_data.get('playbook'), ensure_ascii=False))
        cursor.execute(query, params)
        conn.commit()
        logging.info(f"v4.0 Анализ Gemini для market_data_id {market_data_id} сохранен.")
    except Error as e:
        logging.error(f"v4.0 Ошибка при сохранении анализа Gemini: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()

# --- МАРШРУТЫ API ---
@app.route('/webhook', methods=['POST'])
def tradingview_webhook():
    raw_data = request.get_data(as_text=True)
    if not raw_data: abort(400)
    try:
        data = json.loads(raw_data)
        market_data_id = save_market_data_v4(data)
        if market_data_id:
            latest_market_data = get_latest_market_data()
            if latest_market_data:
                prompt = format_smc_prompt(latest_market_data)
                analysis = get_gemini_analysis(prompt)
                if analysis and analysis.get('playbook') and len(analysis['playbook']) > 0:
                    save_gemini_analysis(market_data_id, analysis)
        return jsonify({"status": "success"}), 200
    except Exception as e:
        logging.error(f"Критическая ошибка в webhook v4.0: {e}", exc_info=True)
        abort(500)

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

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001)
