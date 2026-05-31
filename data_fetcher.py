import time
import requests
import pymysql
import yfinance as yf
from datetime import datetime
import os
from dotenv import load_dotenv
load_dotenv(os.path.join(os.path.dirname(__file__), ".env"))

# ==========================================
# 1. 資料庫連線設定
# ==========================================
DB_CONFIG = {
    'host': '127.0.0.1',
    'user': os.getenv("DB_USER"),
    'password': os.getenv("DB_PASS"),
    'database': 'crypto_trading_db',
    'charset': 'utf8mb4',
    'cursorclass': pymysql.cursors.DictCursor
}

BINANCE_KLINE_URL = "https://api.binance.com/api/v3/klines"
IS_WARM_UP = True

# 全域變數定義區 (🚀 新增 4h 與 1d)
CRYPTO_INTERVALS = ['1m', '3m', '5m', '15m', '1h', '4h', '1d']
# 注意：Yahoo Finance 不支援 4h，所以股票只加 1d
STOCK_INTERVALS = ['1m', '5m', '15m', '60m', '1d']

def fetch_and_update():
    global IS_WARM_UP
    try:
        connection = pymysql.connect(**DB_CONFIG)
        with connection.cursor() as cursor:
            
            # 從資料庫動態撈取所有「上架中」的資產清單
            cursor.execute("SELECT asset_id, symbol FROM Assets WHERE status = 'trading'")
            assets = cursor.fetchall()
            
            # 分流：將資產分為加密貨幣與股票 (這就是剛剛遺失的定義)
            crypto_assets = [a for a in assets if 'USDT' in a['symbol']]
            stock_assets = [a for a in assets if 'USDT' not in a['symbol']]
            
            # ==========================================
            # 引擎 A：Binance API (處理加密貨幣)
            # ==========================================
            limit_count = 1000 if IS_WARM_UP else 1
            for asset in crypto_assets:
                symbol = asset['symbol']
                asset_id = asset['asset_id']
                print(f"\n[{datetime.now().strftime('%H:%M:%S')}] 🟠 Binance 引擎 -> 更新 {symbol} ...")
                
                for interval in CRYPTO_INTERVALS:
                    params = {'symbol': symbol, 'interval': interval, 'limit': limit_count}
                    try:
                        res = requests.get(BINANCE_KLINE_URL, params=params, timeout=10)
                        data = res.json()
                        if data and isinstance(data, list):
                            for kline in data:
                                open_time = datetime.fromtimestamp(kline[0] / 1000.0).strftime('%Y-%m-%d %H:%M:%S')
                                close_price = float(kline[4])
                                insert_kline(cursor, asset_id, f"Klines_{interval}", open_time, kline[1], kline[2], kline[3], close_price, kline[5])
                                
                                if interval == '1m':
                                    update_current_price(cursor, asset_id, close_price)
                            
                            # 🚀 關鍵優化 1：每完成一個級別的 1000 筆資料，立刻提交並釋放鎖
                            connection.commit()
                            
                    except Exception as e:
                        print(f"  ❌ {symbol} ({interval}) 抓取失敗: {e}")

            # ==========================================
            # 引擎 B：Yahoo Finance (處理台美股)
            # ==========================================
            period_str = "7d" if IS_WARM_UP else "1d" 
            
            for asset in stock_assets:
                symbol = asset['symbol']
                asset_id = asset['asset_id']
                print(f"\n[{datetime.now().strftime('%H:%M:%S')}] 🔵 YFinance 引擎 -> 更新 {symbol} ...")
                
                for interval in STOCK_INTERVALS:
                    try:
                        df = yf.Ticker(symbol).history(period=period_str, interval=interval)
                        if not df.empty:
                            db_interval = '1h' if interval == '60m' else interval
                            table_name = f"Klines_{db_interval}"
                            
                            for index, row in df.iterrows():
                                open_time = index.strftime('%Y-%m-%d %H:%M:%S')
                                close_price = float(row['Close'])
                                insert_kline(cursor, asset_id, table_name, open_time, row['Open'], row['High'], row['Low'], close_price, row['Volume'])
                                
                                if interval == '1m':
                                    update_current_price(cursor, asset_id, close_price)
                            
                            # 🚀 關鍵優化 2：台美股也是每寫完一個級別就立刻提交解鎖
                            connection.commit()
                            
                    except Exception as e:
                        print(f"  ❌ {symbol} ({interval}) 抓取失敗: {e}")

            print("\n" + "=" * 50)
            if IS_WARM_UP:
                IS_WARM_UP = False
                print("💡 歷史暖機完成！雙引擎切換為「增量更新」模式。")
            
    except Exception as e:
        print(f"系統錯誤: {e}")
    finally:
        if 'connection' in locals() and connection.open:
            connection.close()

# ==========================================
# 輔助寫入函數
# ==========================================
def insert_kline(cursor, asset_id, table_name, open_time, o, h, l, c, v):
    sql = f"""
        INSERT INTO {table_name} (asset_id, open_time, open_price, high_price, low_price, close_price, volume) 
        VALUES (%s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE 
        high_price = GREATEST(high_price, VALUES(high_price)), low_price = LEAST(low_price, VALUES(low_price)),
        close_price = VALUES(close_price), volume = VALUES(volume)
    """
    cursor.execute(sql, (asset_id, open_time, o, h, l, c, v))

def update_current_price(cursor, asset_id, price):
    cursor.execute("UPDATE Assets SET current_price = %s WHERE asset_id = %s", (price, asset_id))

if __name__ == "__main__":
    print("🚀 虛擬資產交易系統 - [雙引擎 / 批次提交版] 數據中心已啟動！")
    while True:
        fetch_and_update()
        time.sleep(60)
