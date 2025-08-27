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
GEMINI_API_URL = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent"

# --- ЛОГИКА БЭКТЕСТЕРА ---
def get_gemini_analysis(prompt):
    headers = {'Content-Type': 'application/json'}
    params = {'key': GEMINI_API_KEY}
    payload = json.dumps({"contents": [{"parts": [{"text": prompt}]}],"generationConfig": {"response_mime_type": "application/json"}})
    try:
        response = requests.post(GEMINI_API_URL, headers=headers, params=params, data=payload, timeout=90)
        response.raise_for_status()
        result = response.json()
        content = result['candidates'][0]['content']['parts'][0]['text']
        return json.loads(content)
    except Exception:
        return None

def format_prompt(market_data):
    return f"""
Ты — системный сканер торговых сетапов. Твоя задача — находить качественные внутридневные сетапы с горизонтом реализации от 15 до 120 минут.
Контекст:
- Текущие рыночные индикаторы: { {k: v for k, v in market_data.items() if k not in ['symbol', 'price_dynamics_2h']} }
- Динамика цен за последние 2 часа (price_dynamics_2h): {market_data.get('price_dynamics_2h')}.
Правила:
1. Генерируй сетап только если вероятность его успеха выше 65%. Если уверенность ниже, верни пустой "playbook".
2. Ответ должен быть в строгом JSON-формате.
Ответ: Верни только JSON.
"""

def run_simulation(yield_update, start_date, end_date):
    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        yield_update("log", f"Получение данных с {start_date} по {end_date}...")
        query = "SELECT * FROM market_data WHERE DATE(created_at) BETWEEN %s AND %s ORDER BY id ASC"
        cursor.execute(query, (start_date, end_date))
        all_data = cursor.fetchall()
        if not all_data:
            yield_update("log", "В выбранном диапазоне нет данных для симуляции.", "loss")
            return
        yield_update("log", f"Найдено {len(all_data)} записей. Начинаем симуляцию...")
        
        total_trades, winning_trades, total_profit, total_loss = 0, 0, 0.0, 0.0
        
        for i in range(len(all_data) - 24): # Запас свечей для проверки исхода
            current_data = all_data[i]
            prompt = format_prompt(current_data)
            analysis = get_gemini_analysis(prompt)
            time.sleep(1.1) # Лимит 60 RPM, делаем чуть медленнее
            if not (analysis and analysis.get('playbook') and isinstance(analysis['playbook'], list) and len(analysis['playbook']) > 0):
                continue
            
            trade = analysis['playbook'][0]
            if not all(k in trade for k in ['dir', 'entry_price', 'take_profit_1', 'stop_loss']):
                continue
            
            total_trades += 1
            entry_price = float(trade['entry_price'])
            stop_loss = float(trade['stop_loss'])
            take_profit = float(trade['take_profit_1'])
            
            yield_update("log", f"Свеча #{current_data['id']}: Новый сигнал {trade['dir']} с входом по {entry_price}")
            
            outcome = "timeout"
            for future_candle in all_data[i+1 : i+24]: # Проверяем исход в течение 2 часов
                if trade['dir'] == 'long':
                    if future_candle['high_price'] >= take_profit:
                        outcome = "win"
                        break
                    if future_candle['low_price'] <= stop_loss:
                        outcome = "loss"
                        break
                elif trade['dir'] == 'short':
                    if future_candle['low_price'] <= take_profit:
                        outcome = "win"
                        break
                    if future_candle['high_price'] >= stop_loss:
                        outcome = "loss"
                        break
            
            if outcome == "win":
                winning_trades += 1
                profit = abs(take_profit - entry_price)
                total_profit += profit
                yield_update("win", f"  -> Итог: ПОБЕДА (+{profit:.2f})")
            elif outcome == "loss":
                loss = abs(entry_price - stop_loss)
                total_loss += loss
                yield_update("loss", f"  -> Итог: УБЫТОК (-{loss:.2f})")
            
            win_rate = (winning_trades / total_trades * 100) if total_trades > 0 else 0
            profit_factor = (total_profit / total_loss) if total_loss > 0 else 0
            stats = {"total_trades": total_trades, "win_rate": win_rate, "profit_factor": profit_factor}
            yield_update("stats", stats)
    except Error as e:
        yield_update("loss", f"Ошибка БД: {e}")
    finally:
        if conn and conn.is_connected():
            cursor.close()
            conn.close()
        yield_update("log", "Симуляция завершена.")

if __name__ == '__main__':
    if len(sys.argv) != 3:
        print("[LOSS] Ошибка: Не переданы даты начала и окончания.")
        sys.exit(1)
    
    start_date_arg, end_date_arg = sys.argv[1], sys.argv[2]
    
    def print_update(type, data):
        print(f"[{type.upper()}] {json.dumps(data) if isinstance(data, dict) else data}")
    
    run_simulation(print_update, start_date_arg, end_date_arg)