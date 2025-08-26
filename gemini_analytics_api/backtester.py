# -*- coding: utf-8 -*-
import json
import time
import requests
import mysql.connector
from mysql.connector import Error
import sys

# --- КОНФИГУРАЦИЯ ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Не забудь вставить твой ключ
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent"

# --- ОБНОВЛЕННАЯ ФУНКЦИЯ ---
def get_gemini_analysis(prompt):
    """Отправляет промпт в Gemini API с логикой повторных попыток."""
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    payload = json.dumps({"contents": [{"parts": [{"text": prompt}]}],"generationConfig": {"response_mime_type": "application/json"}})
    
    retries = 5
    delay = 2
    for i in range(retries):
        try:
            response = requests.post(GEMINI_API_URL, headers=headers, params=params, data=payload, timeout=60)
            if response.status_code == 429:
                print(f"[LOG] Получен статус 429. Ждем {delay} сек...")
                time.sleep(delay)
                delay *= 2
                continue
            response.raise_for_status()
            result = response.json()
            content = result['candidates'][0]['content']['parts'][0]['text']
            return json.loads(content)
        except Exception as e:
            print(f"[LOSS] Ошибка API: {e}. Попытка {i+1}/{retries}")
            time.sleep(delay)
            delay *= 2
    return None

# --- (Остальной код остается без изменений) ---
def format_prompt(market_data):
    # ... (код без изменений)
    pass
def run_simulation(yield_update, start_date, end_date):
    # ... (код без изменений)
    pass
if __name__ == '__main__':
    # ... (код без изменений)
    pass
