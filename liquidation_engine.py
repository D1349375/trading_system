import time
import pymysql
from datetime import datetime
import os
from dotenv import load_dotenv
load_dotenv(os.path.join(os.path.dirname(__file__), ".env"))


DB_CONFIG = {
    'host': '127.0.0.1',
    'user': os.getenv("DB_USER"),
    'password': os.getenv("DB_PASS"),
    'database': 'crypto_trading_db',
    'charset': 'utf8mb4',
    'cursorclass': pymysql.cursors.DictCursor
}

def execute_auto_close(cursor, pos, trigger_price, trigger_type):
    """通用的自動平倉處理器 (負責結算損益並更新資料庫)"""
    user_id = pos['user_id']
    asset_id = pos['asset_id']
    
    # 1. 計算損益與應退回的本金 (做多邏輯)
    pnl = (trigger_price - pos['avg_cost']) * pos['total_amount']
    
    if trigger_type == 'LIQUIDATION':
        total_return = 0 # 爆倉保證金歸零沒收
    else:
        total_return = max(0, pos['margin'] + pnl) # 止盈止損退回剩餘價值

    # 2. 刪除持倉
    cursor.execute("DELETE FROM Portfolios WHERE user_id = %s AND asset_id = %s AND trade_mode = 'futures'", (user_id, asset_id))
    
    # 3. 寫入交易歷史紀錄
    tx_sql = """
        INSERT INTO Transactions (user_id, asset_id, trade_mode, tx_type, amount, price_at_tx, total_value) 
        VALUES (%s, %s, 'futures', 'sell', %s, %s, %s)
    """
    cursor.execute(tx_sql, (user_id, asset_id, pos['total_amount'], trigger_price, total_return))
    
    # 4. 退回資金給使用者 (除了爆倉以外)
    if total_return > 0:
        cursor.execute("UPDATE Users SET balance = balance + %s WHERE user_id = %s", (total_return, user_id))

def check_liquidations_and_tpsl():
    try:
        conn = pymysql.connect(**DB_CONFIG)
        with conn.cursor() as cursor:
            # 撈取所有合約部位，包含 TP/SL 欄位
            sql = """
                SELECT P.user_id, P.asset_id, P.total_amount, P.margin, P.avg_cost, P.liquidation_price, P.tp_price, P.sl_price, A.symbol, A.current_price
                FROM Portfolios P
                JOIN Assets A ON P.asset_id = A.asset_id
                WHERE P.total_amount > 0 AND P.trade_mode = 'futures'
            """
            cursor.execute(sql)
            positions = cursor.fetchall()
            
            for pos in positions:
                user_id = pos['user_id']
                current_price = pos['current_price']
                symbol = pos['symbol']
                
                # 🛡️ 狀況 1：跌破強平價 (爆倉)
                if current_price <= pos['liquidation_price']:
                    print(f"💀 [爆倉觸發] 帳號 {user_id} 的 {symbol} 跌破強平價 {pos['liquidation_price']:.4f} (市價: {current_price:.4f})")
                    execute_auto_close(cursor, pos, current_price, 'LIQUIDATION')
                    continue
                
                # 🎯 狀況 2：觸及止盈價 (Take Profit)
                if pos['tp_price'] is not None and current_price >= pos['tp_price']:
                    print(f"🎯 [止盈觸發] 帳號 {user_id} 的 {symbol} 達到止盈價 {pos['tp_price']:.4f} (市價: {current_price:.4f})")
                    execute_auto_close(cursor, pos, current_price, 'TP')
                    continue
                    
                # 🛡️ 狀況 3：觸及止損價 (Stop Loss)
                if pos['sl_price'] is not None and current_price <= pos['sl_price']:
                    print(f"🛑 [止損觸發] 帳號 {user_id} 的 {symbol} 跌破止損價 {pos['sl_price']:.4f} (市價: {current_price:.4f})")
                    execute_auto_close(cursor, pos, current_price, 'SL')
                    continue
                
            conn.commit()
    except Exception as e:
        print(f"風控引擎錯誤: {e}")
    finally:
        if 'conn' in locals() and conn.open:
            conn.close()

if __name__ == "__main__":
    print("🤖 全域風控引擎 (Liquidation & TP/SL Engine) 已啟動，24小時自動監控...")
    while True:
        check_liquidations_and_tpsl()
        time.sleep(5) # 每 5 秒高頻掃描一次
