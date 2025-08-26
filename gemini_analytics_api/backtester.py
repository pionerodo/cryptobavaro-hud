# -*- coding: utf-8 -*-
import json
import time
import requests
import mysql.connector
from mysql.connector import Error
import sys # Импортируем для работы с аргументами

# --- КОНФИГУРАЦИЯ ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Не забудьте вставить ваш ключ
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent"

# --- ЛОГИКА БЭКТЕСТЕРА ---

def get_gemini_analysis(prompt):
    # ... (код без изменений)
    pass

def format_prompt(market_data):
    # ... (код без изменений)
    pass

# ИЗМЕНЕНИЕ: Функция теперь принимает даты
def run_simulation(yield_update, start_date, end_date):
    """Основная функция симуляции."""
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        
        yield_update("log", f"Получение данных с {start_date} по {end_date}...")
        # ИЗМЕНЕНИЕ: SQL-запрос теперь фильтрует по дате
        query = "SELECT * FROM market_data WHERE DATE(created_at) BETWEEN %s AND %s ORDER BY id ASC"
        cursor.execute(query, (start_date, end_date))
        all_data = cursor.fetchall()
        
        if not all_data:
            yield_update("log", "В выбранном диапазоне нет данных для симуляции.", "loss")
            return

        yield_update("log", f"Найдено {len(all_data)} записей. Начинаем симуляцию...")
        
        # ... (остальная логика симуляции без изменений) ...

    except Error as e:
        yield_update("log", f"Ошибка БД: {e}", "loss")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
        yield_update("log", "Симуляция завершена.")

# ИЗМЕНЕНИЕ: Основной блок теперь считывает аргументы
if __name__ == '__main__':
    # Проверяем, что даты были переданы
    if len(sys.argv) != 3:
        print("[LOSS] Ошибка: Не переданы даты начала и окончания.")
        sys.exit(1)
    
    start_date_arg = sys.argv[1]
    end_date_arg = sys.argv[2]

    # Функция для вывода в консоль
    def print_update(type, data):
        if type == 'stats':
             print(f"[{type.upper()}] {json.dumps(data)}")
        else:
             print(f"[{type.upper()}] {data}")

    run_simulation(print_update, start_date_arg, end_date_arg)
