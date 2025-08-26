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
    'user': 'gemini',
    'password': '3e6YxiKwRAE7ZpCn',
    'database': 'gemini_tr'
}
GEMINI_API_KEY = "AIzaSyAP6S4G7Jch-rob2YcJmO9eEqx80LvZhoM" # Не забудьте вставить ваш ключ
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent"
PROJECT_PATH = '/www/wwwroot/cryptobavaro.online/gemini_analytics_api'

# --- ИНИЦИАЛИЗАЦИЯ ---
logging.basicConfig(filename='webhook.log', level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
app = Flask(__name__)
CORS(app)

# --- (Все старые функции и маршруты остаются без изменений) ---
# ...

# --- ОБНОВЛЕННЫЙ МАРШРУТ ДЛЯ ЗАПУСКА БЭКТЕСТА ---
@app.route('/gemini_analytics_api/run_backtest')
def run_backtest():
    # ИЗМЕНЕНИЕ: Получаем даты из параметров запроса
    start_date = request.args.get('start_date')
    end_date = request.args.get('end_date')

    if not start_date or not end_date:
        def error_stream():
            yield f"data: {json.dumps({'log': 'Ошибка: Даты не указаны.', 'type': 'loss'})}\n\n"
        return Response(error_stream(), mimetype='text/event-stream')

    def generate():
        venv_python_path = os.path.join(PROJECT_PATH, '58c0af3bed89ba2482d01178345656cb_venv/bin/python3')
        script_path = os.path.join(PROJECT_PATH, 'backtester.py')
        
        # ИЗМЕНЕНИЕ: Передаем даты как аргументы в команду
        command = [venv_python_path, script_path, start_date, end_date]
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1, encoding='utf-8')
        
        # ... (остальная часть функции без изменений) ...
        for line in process.stdout:
            try:
                line_type_str = line.split(']')[0][1:].strip().upper()
                message_str = line.split(']', 1)[1].strip()
                # ...
            except Exception:
                continue

    return Response(generate(), mimetype='text/event-stream')

# --- (Остальные маршруты и функции) ---
# ...

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
