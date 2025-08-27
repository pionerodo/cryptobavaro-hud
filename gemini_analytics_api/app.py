import json
import os
import logging
from flask import Flask, request, jsonify
import mysql.connector
from mysql.connector import Error
import requests

# --- Настройка логирования ---
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

app = Flask(__name__)

# --- Конфигурация базы данных ---
# Данные взяты из нашего предыдущего обсуждения.
db_config = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'user': os.getenv('DB_USER', 'gemini_tr'),
    'password': os.getenv('DB_PASSWORD', '20021981'), # Пароль из предыдущих версий
    'database': os.getenv('DB_NAME', 'gemini_tr')
}

# --- Gemini API ---
# Убедитесь, что вы установили переменную окружения GEMINI_API_KEY
GEMINI_API_KEY = os.getenv('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE')
if GEMINI_API_KEY == 'YOUR_GEMINI_API_KEY_HERE':
    logging.warning("Переменная окружения GEMINI_API_KEY не установлена. Используется ключ-заглушка.")

GEMINI_API_URL = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key={GEMINI_API_KEY}"

def create_db_connection():
    """Создает соединение с базой данных."""
    try:
        connection = mysql.connector.connect(**db_config)
        if connection.is_connected():
            logging.info("Соединение с базой данных MySQL установлено.")
            return connection
    except Error as e:
        logging.error(f"Ошибка подключения к MySQL: {e}")
        return None

def format_gemini_prompt(data):
    """Формирует продвинутый промпт для Gemini на основе всех данных."""
    
    prompt = f"""
    Проведи комплексный анализ рыночной ситуации для {data['symbol']} в роли профессионального трейдера.

    --- ОБЩИЙ КОНТЕКСТ (СТАРШИЕ ТАЙМФРЕЙМЫ) ---
    - Глобальный тренд на H4: {data['h4_trend'].upper()}
    - Положение относительно H1 EMA(200): {'Выше (бычий знак)' if data['close_price'] > data['ema200_h1'] else 'Ниже (медвежий знак)'}
    - Ключевые дневные уровни (Пивоты):
      - Поддержка (S1): {data['pivot_s1']}
      - Центральный уровень (P): {data['pivot_p']}
      - Сопротивление (R1): {data['pivot_r1']}

    --- СРЕДНЕСРОЧНЫЙ КОНТЕКСТ (M15) ---
    - RSI(14) на M15: {data['rsi_m15']:.2f}
    - Stochastic %K на M15: {data['stoch_k_m15']:.2f}
    - Торговые паттерны на M15:
      - Swing Failure Pattern: {data['sfp_pattern_15m']}
      - Breakout Channel: {data['channel_pattern_15m']}

    --- ТЕКУЩАЯ СИТУАЦИЯ (M5) ---
    - Цена закрытия: {data['close_price']}
    - Положение относительно VWAP: {'Выше' if data['close_price'] > data['vwap'] else 'Ниже'}
    - Индикаторы импульса:
      - RSI(14): {data['rsi_m5']:.2f}
      - MACD Histogram: {data['macd_hist']:.4f}
      - Squeeze Momentum: {data['squeeze_momentum']:.4f} {'(Импульс растет)' if data['squeeze_momentum'] > 0 else '(Импульс падает)'}
    - Волатильность:
      - ATR(14): {data['atr']:.2f}
      - Коэффициент объема (Volume Ratio): {data['volume_ratio']:.2f} {'(Аномально высокий объем!)' if data['volume_ratio'] > 2.0 else ''}
    - Динамика цены за последние 2 часа (от старых к новым): {json.loads(data['price_dynamics_2h'])}

    --- ЗАДАЧА ---
    1.  **Краткий вывод (2-3 предложения):** Опиши общую картину. Совпадают ли сигналы на разных таймфреймах? Есть ли конфликт между глобальным трендом и текущим импульсом?
    2.  **Оценка паттернов:** Если на M15 есть паттерн (SFP или пробой канала), оцени его значимость в текущем контексте.
    3.  **Playbook (Торговый план):** Предоставь 2-3 наиболее вероятных сценария в формате JSON. Для каждого сценария укажи: "scenario_name", "direction" (Long/Short/Wait), "entry_conditions" (условия для входа), "stop_loss", "take_profit_1", "take_profit_2", и "confidence" (Low/Medium/High). Ответ должен содержать ТОЛЬКО JSON-массив, без лишнего текста или Markdown-форматирования.
    """
    return prompt

@app.route('/tradingview_webhook', methods=['POST'])
def tradingview_webhook():
    try:
        data = request.json
        logging.info(f"Получены данные от TradingView для {data.get('symbol')}")
    except Exception as e:
        logging.error(f"Ошибка получения JSON: {e}")
        return jsonify({"status": "error", "message": "Invalid JSON received"}), 400
    
    # 1. Сохранение данных в market_data
    conn = create_db_connection()
    if not conn:
        return jsonify({"status": "error", "message": "Database connection failed"}), 500
    
    cursor = conn.cursor()
    
    # Убедимся, что все ключи из data существуют в таблице
    columns = [key for key in data.keys()]
    values = [data[key] for key in columns]
    
    query = f"""
        INSERT INTO market_data ({', '.join(columns)}) 
        VALUES ({', '.join(['%s'] * len(values))})
    """
    
    try:
        cursor.execute(query, values)
        market_data_id = cursor.lastrowid
        conn.commit()
        logging.info(f"Данные сохранены в market_data с ID: {market_data_id}")
    except Error as e:
        logging.error(f"Ошибка вставки данных: {e}")
        conn.rollback()
        return jsonify({"status": "error", "message": str(e)}), 500
    finally:
        cursor.close()
        conn.close()

    # 2. Формирование промпта и вызов Gemini
    prompt = format_gemini_prompt(data)
    
    payload = {"contents": [{"parts": [{"text": prompt}]}]}
    
    try:
        response = requests.post(GEMINI_API_URL, json=payload, timeout=90)
        response.raise_for_status()
        
        result = response.json()
        analysis_text = result['candidates'][0]['content']['parts'][0]['text']
        logging.info("Анализ от Gemini получен успешно.")
        
        # Извлечение JSON (playbook) из текста
        playbook_json = None
        try:
            json_start = analysis_text.find('[')
            json_end = analysis_text.rfind(']') + 1
            if json_start != -1 and json_end > json_start:
                playbook_str = analysis_text[json_start:json_end]
                # Проверка и загрузка JSON для валидации
                parsed_json = json.loads(playbook_str)
                playbook_json = json.dumps(parsed_json) # Сохраняем как строку
                logging.info("Playbook JSON успешно извлечен и валидирован.")
            else:
                 logging.warning("JSON-массив в ответе Gemini не найден.")
                 playbook_json = '[]'
        except (json.JSONDecodeError, IndexError) as e:
            logging.error(f"Не удалось распарсить Playbook JSON из ответа: {e}")
            playbook_json = '[]'

    except requests.exceptions.RequestException as e:
        logging.error(f"Ошибка вызова Gemini API: {e}")
        # Не прерываем выполнение, просто не будет анализа
        analysis_text = f"Ошибка API: {e}"
        playbook_json = '[]'

    # 3. Сохранение анализа в gemini_analysis
    conn = create_db_connection()
    if not conn:
        return jsonify({"status": "error", "message": "Database connection failed on analysis save"}), 500
        
    cursor = conn.cursor()
    query = """
        INSERT INTO gemini_analysis (market_data_id, analysis_type, notes, playbook) 
        VALUES (%s, %s, %s, %s)
    """
    try:
        cursor.execute(query, (market_data_id, 'AI Signal', analysis_text, playbook_json))
        conn.commit()
        logging.info(f"Анализ для market_data_id {market_data_id} сохранен.")
    except Error as e:
        logging.error(f"Ошибка сохранения анализа: {e}")
        conn.rollback()
    finally:
        cursor.close()
        conn.close()

    return jsonify({"status": "success", "market_data_id": market_data_id}), 200

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)

