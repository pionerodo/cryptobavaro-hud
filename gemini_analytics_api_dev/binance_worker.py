import time
import traceback
import requests
import pymysql.cursors

# --- КОНФИГУРАЦИЯ ---
# (Скопируй эти данные из своего app.py)
DB_HOST = "localhost"
DB_USER = "gemini_dev"
DB_PASSWORD = "27C7fYRbhfcJhWB6"
DB_NAME = "gemini_tr_dev"

def get_db_connection():
    return pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASSWORD, database=DB_NAME, cursorclass=pymysql.cursors.DictCursor, autocommit=True)

def fetch_and_save_data():
    """Основная функция, которая выполняет один цикл работы."""
    symbol = 'BTCUSDT'
    try:
        print(f"--- [Binance Worker] Запрос данных для {symbol} ---")
        
        # 1. Получаем Long/Short Ratio
        ls_ratio_url = f"https://fapi.binance.com/futures/data/globalLongShortAccountRatio?symbol={symbol}&period=5m"
        ls_ratio_response = requests.get(ls_ratio_url, timeout=10)
        ls_ratio_response.raise_for_status()
        latest_ls_ratio = float(ls_ratio_response.json()[-1]['longShortRatio'])

        # 2. Получаем Open Interest
        oi_url = f"https://fapi.binance.com/fapi/v1/openInterest?symbol={symbol}"
        oi_response = requests.get(oi_url, timeout=10)
        oi_response.raise_for_status()
        open_interest_value = float(oi_response.json()['openInterest'])
        
        print(f"[Binance Worker] L/S Ratio: {latest_ls_ratio}, Open Interest: {open_interest_value}")
        
        # 3. Сохраняем в БД
        connection = get_db_connection()
        with connection.cursor() as cursor:
            sql = "INSERT INTO `market_sentiment` (symbol, long_short_ratio, open_interest) VALUES (%s, %s, %s)"
            cursor.execute(sql, (symbol, latest_ls_ratio, open_interest_value))
        connection.close()
        print("[Binance Worker] Данные успешно сохранены.")

    except Exception as e:
        print(f"!!! [Binance Worker] ОШИБКА: {e}")
        traceback.print_exc()

if __name__ == "__main__":
    print("--- Binance Data Worker запущен ---")
    while True:
        fetch_and_save_data()
        print(f"--- Следующий сбор данных через 5 минут ---")
        time.sleep(300) # Пауза 5 минут (300 секунд)
