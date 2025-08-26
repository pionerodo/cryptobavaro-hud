# -*- coding: utf-8 -*-

from flask import Flask, request, jsonify, abort
import json
import logging
import mysql.connector
from mysql.connector import Error
import requests # Библиотека для отправки запросов к Gemini API

# --- НАСТРОЙКА ---
# ВАЖНО: Замените эти данные на свои
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
# ВАЖНО: Вставьте сюда ваш API ключ от Google AI Studio
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM"
GEMINI_API_URL = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key={GEMINI_API_KEY}"

# --- НАСТРОЙКА ЛОГГИРОВАНИЯ ---
logging.basicConfig(
    filename='webhook.log', 
    level=logging.INFO, 
    format='%(asctime)s - %(levelname)s - %(message)s'
)

app = Flask(__name__)

# --- ФУНКЦИИ ДЛЯ РАБОТЫ С GEMINI ---

def format_prompt(market_data):
    """Формирует текстовый промпт для Gemini из данных."""
    # Используем ваш шаблон промпта для "Сигнала входа"
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
- Сформируй строго 1–2 максимально практичных сценария для работы в коридоре/диапазоне в формате JSON:
  dir: "long"|"short",
  setup: («ложный пробой коридора», «внутри-бар у границы», «ретест mid/EVWAP» и т.п.),
  entry: точка/зона входа,
  invalidation: где идея ломается (стоп/условие),
  tp1: близкая цель,
  tp2: цель-2 (если уместно),
  confidence: 0..100,
  why: 3–5 очень коротких аргументов через «;».

Важное:
- Работаем скальпингом 5m
- Не выдумывай уровни — опирайся на присланные
- Цель — аккуратная игра внутри диапазона (консервативные входы, чёткие условия).
- Всё строго на русском.
- Ответ должен содержать только JSON-объект с полями "notes" (string) и "playbook" (array of objects). Без лишнего текста и markdown.
"""
    return prompt_template

def get_gemini_analysis(prompt):
    """Отправляет промпт в Gemini API и возвращает ответ."""
    headers = {'Content-Type': 'application/json'}
    payload = json.dumps({"contents": [{"parts": [{"text": prompt}]}]})
    
    try:
        response = requests.post(GEMINI_API_URL, headers=headers, data=payload, timeout=60)
        response.raise_for_status() # Проверка на HTTP ошибки
        
        result = response.json()
        # Извлекаем текстовое содержимое ответа
        content = result['candidates'][0]['content']['parts'][0]['text']
        logging.info("Получен ответ от Gemini.")
        # Преобразуем текстовый JSON в реальный JSON-объект
        return json.loads(content)

    except requests.exceptions.RequestException as e:
        logging.error(f"Ошибка при запросе к Gemini API: {e}")
    except (KeyError, IndexError, json.JSONDecodeError) as e:
        logging.error(f"Ошибка парсинга ответа от Gemini: {e}")
        logging.error(f"Сырой ответ: {response.text}")
    return None

# --- ФУНКЦИИ ДЛЯ РАБОТЫ С БД ---

def save_market_data(data):
    """Сохраняет рыночные данные и возвращает ID новой записи."""
    last_id = None
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True) # dictionary=True, чтобы получать данные как словарь
        
        query = "..." # (здесь тот же SQL-запрос, что и раньше)
        params = { ... } # (здесь те же параметры)

        # --- Код для вставки рыночных данных (остается без изменений) ---
        # ... (я его скрыл для краткости, он есть в предыдущей версии)
        
        cursor.execute(query, params)
        conn.commit()
        last_id = cursor.lastrowid # Получаем ID только что вставленной строки
        logging.info(f"Рыночные данные сохранены с ID: {last_id}")

    except Error as e:
        logging.error(f"Ошибка при работе с MySQL: {e}")
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
            market_data_id,
            "Сигнал входа", # Тип анализа
            analysis_data.get('notes'),
            json.dumps(analysis_data.get('playbook'), ensure_ascii=False) # Конвертируем playbook в JSON-строку
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

# --- ОСНОВНОЙ МАРШРУТ ВЕБХУКА ---
@app.route('/gemini_analytics_api/webhook', methods=['POST'])
def tradingview_webhook():
    raw_data = request.get_data(as_text=True)
    if not raw_data: abort(400)

    try:
        data = json.loads(raw_data)
        
        # 1. Сохраняем рыночные данные и получаем ID
        market_data_id = save_market_data(data)

        if market_data_id:
            # 2. Получаем только что сохраненные данные из БД
            latest_market_data = get_market_data_by_id(market_data_id)
            if latest_market_data:
                # 3. Формируем промпт
                prompt = format_prompt(latest_market_data)
                # 4. Получаем анализ от Gemini
                analysis = get_gemini_analysis(prompt)
                if analysis:
                    # 5. Сохраняем анализ в БД
                    save_gemini_analysis(market_data_id, analysis)

        return jsonify({"status": "success"}), 200

    except Exception as e:
        logging.error(f"Критическая ошибка в webhook: {e}")
        abort(500)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
