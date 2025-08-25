# -*- coding: utf-8 -*-

# Импортируем необходимые библиотеки
from flask import Flask, request, jsonify, abort
import json
import logging

# --- НАСТРОЙКА ЛОГГИРОВАНИЯ ---
# Настраиваем логирование, чтобы видеть все входящие запросы и ошибки в файле.
# Это очень поможет при отладке.
logging.basicConfig(
    filename='webhook.log', 
    level=logging.INFO, 
    format='%(asctime)s - %(levelname)s - %(message)s'
)

# --- ИНИЦИАЛИЗАЦИЯ ПРИЛОЖЕНИЯ FLASK ---
# Создаем экземпляр нашего веб-приложения
app = Flask(__name__)

# --- ОСНОВНОЙ МАРШРУТ ДЛЯ ВЕБХУКА ---
# Определяем URL, который будет "слушать" наше приложение.
# methods=['POST'] означает, что он будет реагировать только на POST-запросы.
@app.route('/gemini_analytics_api/webhook', methods=['POST'])
def tradingview_webhook():
    """
    Эта функция обрабатывает входящие вебхуки от TradingView.
    """
    # Получаем сырые текстовые данные из тела запроса
    raw_data = request.get_data(as_text=True)
    
    # Проверяем, не пустые ли данные
    if not raw_data:
        logging.warning("Получен пустой запрос.")
        # Возвращаем ошибку 400 Bad Request
        abort(400, description="Пустое тело запроса")

    logging.info(f"Получены сырые данные: {raw_data}")

    # Пытаемся преобразовать текстовую строку в JSON-объект
    try:
        data = json.loads(raw_data)
        # Если успешно, выводим в лог для проверки
        logging.info("Данные успешно преобразованы в JSON:")
        logging.info(json.dumps(data, indent=4))

        # --- ЗДЕСЬ БУДЕТ БУДУЩАЯ ЛОГИКА ---
        # 1. Сохранение данных в базу данных (MySQL/MariaDB).
        # 2. Вызов функции для анализа данных через Gemini API.
        # 3. Сохранение ответа от Gemini в БД.
        # 4. Отправка сигнала на дашборд (через WebSocket или Redis).
        # ------------------------------------

        # Возвращаем успешный ответ
        return jsonify({"status": "success", "message": "Данные получены"}), 200

    except json.JSONDecodeError:
        logging.error(f"Ошибка декодирования JSON. Данные: {raw_data}")
        # Если пришла невалидная JSON-строка, возвращаем ошибку
        abort(400, description="Неверный формат JSON")
    except Exception as e:
        logging.error(f"Произошла непредвиденная ошибка: {e}")
        # На случай других непредвиденных ошибок
        abort(500, description="Внутренняя ошибка сервера")

# --- ТОЧКА ВХОДА ДЛЯ ЗАПУСКА СЕРВЕРА ---
# Этот блок выполняется, только если скрипт запущен напрямую
if __name__ == '__main__':
    # Запускаем приложение.
    # host='0.0.0.0' делает его доступным извне (не только с localhost).
    # port=5000 - стандартный порт для Flask-приложений. Его можно изменить.
    app.run(host='0.0.0.0', port=5000, debug=True)

