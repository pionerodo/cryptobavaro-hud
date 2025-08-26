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
# ИЗМЕНЕНИЕ: Переключаемся на модель gemini-pro
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent"

# --- (Остальной код остается без изменений) ---
# ...
# ... (весь остальной код такой же, как в предыдущей версии) ...
# ...
def get_gemini_analysis(prompt):
    # ... (код без изменений)
    pass
def format_prompt(market_data):
    # ... (код без изменений)
    pass
def run_simulation(yield_update, start_date, end_date):
    # ... (код без изменений)
    pass
if __name__ == '__main__':
    # ... (код без изменений)
    pass
