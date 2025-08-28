import json
import os
import traceback # Импортируем модуль для детального отслеживания ошибок
from flask import Flask, request, abort
import pymysql.cursors
import pandas as pd

app = Flask(__name__)

# --- Настройки подключения к базе данных ---
DB_HOST = os.getenv("localhost")
DB_USER = os.getenv("gemini_dev")
DB_PASSWORD = os.getenv("27C7fYRbhfcJhWB6")
DB_NAME = os.getenv("gemini_tr_dev") # Используем тестовую БД

# --- Вспомогательные функции ---
def to_sql_float(value):
    try:
        if isinstance(value, str) and value.strip().lower() == 'none': return None
        return float(value)
    except (ValueError, TypeError): return None

def to_sql_string(value):
    if value is None: return None
    val_str = str(value).strip()
    if val_str and val_str.lower() != 'none': return val_str
    return None

# --- Функция анализа ---
def run_analysis(connection):
    print("--- [DEBUG] Вход в функцию run_analysis ---")
    try:
        with connection.cursor() as cursor:
            sql = "SELECT * FROM `market_data` WHERE `created_at` >= NOW() - INTERVAL 12 HOUR ORDER BY `created_at` ASC"
            cursor.execute(sql)
            historical_data = cursor.fetchall()
            if not historical_data:
                print("[DEBUG] Анализ пропущен: нет исторических данных.")
                return
            df = pd.DataFrame(historical_data)
            print(f"[DEBUG] Подготовлен контекст из {len(df)} записей.")
    except Exception as e:
        print(f"!!! [DEBUG] ОШИБКА ВНУТРИ run_analysis: {e}")
        traceback.print_exc() # Печатаем полный путь ошибки

# --- Основной маршрут Webhook ---
@app.route('/webhook', methods=['POST'])
def webhook():
    connection = None
    try:
        # --- Блок 1: Получение и разбор данных ---
        print("--- [DEBUG] Шаг 1: Получение запроса ---")
        data_str = request.get_data(as_text=True)
        print(f"[DEBUG] Сырые данные: {data_str}")
        data = json.loads(data_str)
        print("[DEBUG] JSON успешно разобран.")

        # --- Блок 2: Извлечение и очистка ---
        print("--- [DEBUG] Шаг 2: Извлечение и очистка данных ---")
        payload = (
            to_sql_string(data.get('symbol')),
            data.get('event_timestamp'),
            to_sql_float(data.get('close_price')),
            to_sql_string(data.get('trading_session')),
            to_sql_string(data.get('structure_m15')),
            to_sql_string(data.get('sfp_m5')),
            to_sql_float(data.get('bullish_ob_m5')),
            to_sql_float(data.get('bearish_ob_m5')),
            to_sql_float(data.get('adx_14')),
            to_sql_float(data.get('atr_14'))
        )
        print(f"[DEBUG] Данные для БД подготовлены: {payload}")

        # --- Блок 3: Подключение и запись в БД ---
        print("--- [DEBUG] Шаг 3: Подключение к БД и запись ---")
        connection = pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASSWORD, database=DB_NAME, cursorclass=pymysql.cursors.DictCursor, autocommit=True)
        with connection.cursor() as cursor:
            sql = "INSERT INTO `market_data` (`symbol`, `event_timestamp`, `close_price`, `trading_session`, `structure_m15`, `sfp_m5`, `bullish_ob_m5`, `bearish_ob_m5`, `adx_14`, `atr_14`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)"
            cursor.execute(sql, payload)
        print(">>> [DEBUG] Данные успешно сохранены в БД! <<<")
        
        # --- Блок 4: Запуск анализа ---
        run_analysis(connection)
        
        return 'Webhook received and processed!', 200

    except Exception as e:
        print(f"!!! [DEBUG] КРИТИЧЕСКАЯ ОШИБКА В WEBHOOK !!!")
        print(f"!!! [DEBUG] Тип ошибки: {type(e).__name__}, Сообщение: {e}")
        traceback.print_exc() # Печатаем ПОЛНУЮ информацию об ошибке
        abort(500)
    finally:
        if connection:
            connection.close()
            print("[DEBUG] Соединение с БД закрыто.")

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=True)
