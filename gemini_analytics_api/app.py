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
import time # Добавляем для пауз

# --- КОНФИГУРАЦИЯ ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Не забудь вставить твой ключ
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent"
PROJECT_PATH = '/www/wwwroot/cryptobavaro.online/gemini_analytics_api'

# --- ИНИЦИАЛИЗАЦИЯ ---
logging.basicConfig(filename='webhook.log', level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
app = Flask(__name__)
CORS(app)

# --- ОБНОВЛЕННАЯ ФУНКЦИЯ ---
def get_gemini_analysis(prompt):
    """Отправляет промпт в Gemini API с логикой повторных попыток."""
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    payload = json.dumps({"contents": [{"parts": [{"text": prompt}]}],"generationConfig": {"response_mime_type": "application/json"}})
    
    retries = 5
    delay = 2 # Начальная задержка в секундах
    for i in range(retries):
        try:
            response = requests.post(GEMINI_API_URL, headers=headers, params=params, data=payload, timeout=60)
            
            # Если получаем ошибку "Too Many Requests", ждем и пробуем снова
            if response.status_code == 429:
                logging.warning(f"Получен статус 429 (Too Many Requests). Попытка {i+1}/{retries}. Ждем {delay} сек...")
                time.sleep(delay)
                delay *= 2 # Удваиваем задержку для следующей попытки
                continue

            response.raise_for_status() # Проверяем на другие HTTP ошибки
            
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
            return None # Не повторяем попытку при ошибке парсинга
    
    logging.error("Не удалось получить ответ от Gemini после нескольких попыток.")
    return None

# --- (Остальной код остается без изменений) ---
# ... format_prompt, save_market_data, и т.д. ...
# ... все маршруты API ...

# --- (Полный код для копирования) ---
def format_corridor_prompt(market_data):
    return f"""...""" # (содержимое без изменений)
def format_manual_analysis_prompt(market_data):
    return f"""...""" # (содержимое без изменений)
def save_market_data(data):
    # ... (код без изменений)
    pass
def get_latest_market_data():
    # ... (код без изменений)
    pass
def save_gemini_analysis(market_data_id, analysis_data):
    # ... (код без изменений)
    pass
@app.route('/gemini_analytics_api/get_latest_analysis', methods=['GET'])
def get_latest_analysis():
    # ... (код без изменений)
    pass
@app.route('/gemini_analytics_api/get_manual_analysis', methods=['GET'])
def get_manual_analysis():
    # ... (код без изменений)
    pass
@app.route('/gemini_analytics_api/webhook', methods=['POST'])
def tradingview_webhook():
    # ... (код без изменений)
    pass
@app.route('/gemini_analytics_api/run_backtest')
def run_backtest():
    # ... (код без изменений)
    pass
if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)

