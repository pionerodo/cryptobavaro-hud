# -*- coding: utf-8 -*-

from flask import Flask, request, jsonify, abort
from flask_cors import CORS # Импортируем CORS
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
    'password': 'your_db_password',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM"
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent"

# --- НАСТРОЙКА ЛОГГИРОВАНИЯ ---
logging.basicConfig(
    filename='webhook.log', 
    level=logging.INFO, 
    format='%(asctime)s - %(levelname)s - %(message)s'
)

# --- ИНИЦИАЛИЗАЦИЯ ПРИЛОЖЕНИЯ И CORS ---
app = Flask(__name__)
CORS(app) # Включаем CORS для всего приложения

# --- ФУНКЦИИ ДЛЯ РАБОТЫ С GEMINI ---
def format_prompt(market_data):
    """Формирует текстовый промпт для Gemini из данных."""
    prompt_template = f"""
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
- Сформируй строго 1–2 максимально практичных сценария для работы в коридоре/диапазоне в формате JSON-массива объектов.

Важное:
- Работаем скальпингом 5m
- Не выдумывай уровни — опирайся на присланные
- Цель — аккуратная игра внутри диапазона (консервативные входы, чёткие условия).
- Всё строго на русском.
- Ответ должен содержать только JSON-объект с полями "notes" (string) и "playbook" (array of objects). Без лишнего текста, объяснений и markdown.
"""
    return prompt_template

def get_gemini_analysis(prompt):
    """Отправляет промпт в Gemini API и возвращает ответ."""
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    
    payload_dict = {
        "contents": [{"parts": [{"text": prompt}]}],
        "generationConfig": {
            "response_mime_type": "application/json",
        }
    }
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

# --- ФУНКЦИИ ДЛЯ РАБОТЫ С БД ---
def save_market_data(data):
    """Сохраняет рыночные данные и возвращает ID новой записи."""
    last_id = None
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        query = """
        INSERT INTO market_data (
            symbol, event_timestamp, open_price, high_price, low_price, close_price, volume,
            ema21, ema50, macd_line, macd_signal, macd_hist, rsi, stoch_k, stoch_d,
            bb_upper, bb_middle, bb_lower, atr, vwap, pivot_p, pivot_r1, pivot_s1,
            h1_ema200, h1_rsi, h4_trend
        ) VALUES (
            %(symbol)s, %(timestamp)s, %(open)s, %(high)s, %(low)s, %(close)s, %(volume)s,
            %(ema21)s, %(ema50)s, %(macd_line)s, %(macd_signal)s, %(macd_hist)s, %(rsi)s,
            %(stoch_k)s, %(stoch_d)s, %(bb_upper)s, %(bb_middle)s, %(bb_lower)s, %(atr)s,
            %(vwap)s, %(pivot_p)s, %(pivot_r1)s, %(pivot_s1)s, %(h1_ema200)s,
            %(h1_rsi)s, %(h4_trend)s
        )
        """
        params = {
            "symbol": data.get("symbol"), "timestamp": data.get("timestamp"),
            **data.get("price_data_m5", {}), **data.get("indicators_m5", {}),
            **data.get("levels_m5", {}), **data.get("context_htf", {})
        }
        
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

def get_market_data_by_id(data_id):
    """Извлекает одну запись рыночных данных по ее ID."""
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM market_data WHERE id = %s", (data_id,))
        return cursor.fetchone()
    except Error as e:
        logging.error(f"Ошибка при получении данных из MySQL: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
    return None

def save_gemini_analysis(market_data_id, analysis_data):
    """Сохраняет аналитику от Gemini в БД."""
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        
        query = """
        INSERT INTO gemini_analysis (market_data_id, analysis_type, notes, playbook)
        VALUES (%s, %s, %s, %s)
        """
        params = (
            market_data_id, "Сигнал входа",
            analysis_data.get('notes'),
            json.dumps(analysis_data.get('playbook'), ensure_ascii=False)
        )
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
    """Находит последнюю запись анализа в БД и отдает ее в формате JSON."""
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        query = "SELECT * FROM gemini_analysis ORDER BY id DESC LIMIT 1"
        cursor.execute(query)
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

@app.route('/gemini_analytics_api/webhook', methods=['POST'])
def tradingview_webhook():
    """Обрабатывает входящие вебхуки от TradingView."""
    raw_data = request.get_data(as_text=True)
    if not raw_data: abort(400)
    try:
        data = json.loads(raw_data)
        market_data_id = save_market_data(data)
        if market_data_id:
            latest_market_data = get_market_data_by_id(market_data_id)
            if latest_market_data:
                prompt = format_prompt(latest_market_data)
                analysis = get_gemini_analysis(prompt)
                if analysis:
                    save_gemini_analysis(market_data_id, analysis)
        return jsonify({"status": "success"}), 200
    except Exception as e:
        logging.error(f"Критическая ошибка в webhook: {e}", exc_info=True)
        abort(500)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
