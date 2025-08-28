import json
import os
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
    """Преобразует в float или None."""
    try:
        return float(value)
    except (ValueError, TypeError):
        return None

def to_sql_string(value):
    """Возвращает строку или None, если она пустая."""
    if value and str(value).strip():
        return str(value).strip()
    return None

# Функция для подключения к БД
def get_db_connection():
    return pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASSWORD,
                           database=DB_NAME, cursorclass=pymysql.cursors.DictCursor,
                           autocommit=True)

# --- Функция анализа (без изменений) ---
def run_analysis(connection, latest_data_point):
    print("--- Запуск анализа ---")
    try:
        with connection.cursor() as cursor:
            sql = "SELECT * FROM `market_data` WHERE `created_at` >= NOW() - INTERVAL 12 HOUR ORDER BY `created_at` ASC"
            cursor.execute(sql)
            historical_data = cursor.fetchall()
            
            if not historical_data or len(historical_data) < 2:
                print("Недостаточно исторических данных для анализа.")
                return

            df = pd.DataFrame(historical_data)
            desired_cols = ['created_at', 'close_price', 'trading_session', 'structure_m15', 'sfp_m5', 'adx_14', 'atr_14']
            available_cols = [col for col in desired_cols if col in df.columns]
            
            if not available_cols:
                print("Нет данных для анализа.")
                return

            df_for_prompt = df[available_cols]
            context_str = df_for_prompt.to_string()
            print(f"Подготовлен контекст из {len(df)} записей.")
            # TODO: Вызов Gemini API

    except Exception as e:
        print(f"!!! ОШИБКА АНАЛИЗА: {e}")

# --- Основной маршрут Webhook ---
@app.route('/webhook', methods=['POST'])
def webhook():
    connection = None
    try:
        data_str = request.get_data(as_text=True)
        data = json.loads(data_str)
        print("Получены данные:", data)

        # --- Извлекаем данные с новой, более надежной проверкой ---
        symbol = to_sql_string(data.get('symbol'))
        event_timestamp = data.get('event_timestamp') # Обычно всегда есть
        trading_session = to_sql_string(data.get('trading_session'))
        structure_m15 = to_sql_string(data.get('structure_m15'))
        sfp_m5 = to_sql_string(data.get('sfp_m5'))
        
        close_price = to_sql_float(data.get('close_price'))
        bullish_ob_m5 = to_sql_float(data.get('bullish_ob_m5'))
        bearish_ob_m5 = to_sql_float(data.get('bearish_ob_m5'))
        adx_14 = to_sql_float(data.get('adx_14'))
        atr_14 = to_sql_float(data.get('atr_14'))

        connection = get_db_connection()
        with connection.cursor() as cursor:
            sql = """
            INSERT INTO `market_data` (
                `symbol`, `event_timestamp`, `close_price`, `trading_session`, 
                `structure_m15`, `sfp_m5`, `bullish_ob_m5`, `bearish_ob_m5`,
                `adx_14`, `atr_14`
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            cursor.execute(sql, (
                symbol, event_timestamp, close_price, trading_session,
                structure_m15, sfp_m5, bullish_ob_m5, bearish_ob_m5,
                adx_14, atr_14
            ))
        print("Данные успешно сохранены в БД.")
        
        run_analysis(connection, data)
        return 'Webhook received!', 200

    except Exception as e:
        print(f"!!! КРИТИЧЕСКАЯ ОШИБКА WEBHOOK: {e}")
        abort(500, description=str(e))
    finally:
        if connection:
            connection.close()
            print("Соединение с БД закрыто.")

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=True)
