import time
import pymysql
from datetime import datetime

DB_CONFIG = {
    'host': '127.0.0.1', 'user': 'root', 'password': '',
    'database': 'crypto_trading_db', 'charset': 'utf8mb4',
    'cursorclass': pymysql.cursors.DictCursor
}

def check_liquidations():
    try:
        conn = pymysql.connect(**DB_CONFIG)
        with conn.cursor() as cursor:
            # 撈取所有有危險的持倉 (最新價格 <= 爆倉價)
            sql = """
                SELECT P.user_id, P.asset_id, P.total_amount, P.margin, A.symbol, A.current_price, P.liquidation_price
                FROM Portfolios P
                JOIN Assets A ON P.asset_id = A.asset_id
                WHERE A.current_price <= P.liquidation_price AND P.total_amount > 0 AND P.trade_mode = 'futures'
            """
            cursor.execute(sql)
            liquidated_positions = cursor.fetchall()
            
            for pos in liquidated_positions:
                user_id = pos['user_id']
                asset_id = pos['asset_id']
                
                print(f"💀 [爆倉觸發] 帳號 {user_id} 的 {pos['symbol']} 跌破強平價 {pos['liquidation_price']:.8f} (市價: {pos['current_price']:.8f})")
                
                # 1. 將該筆合約部位強制刪除 (保證金被系統沒收，不退回餘額)
                cursor.execute("DELETE FROM Portfolios WHERE user_id = %s AND asset_id = %s AND trade_mode = 'futures'", (user_id, asset_id))
                
                # 2. 🚀 寫入一筆特殊的「強制平倉」紀錄 (明確標記 trade_mode 為 futures)
                cursor.execute("""
                    INSERT INTO Transactions (user_id, asset_id, trade_mode, tx_type, amount, price_at_tx, total_value) 
                    VALUES (%s, %s, 'futures', 'sell', %s, %s, 0)
                """, (user_id, asset_id, pos['total_amount'], pos['current_price']))
                
            conn.commit()
    except Exception as e:
        print(f"引擎錯誤: {e}")
    finally:
        if 'conn' in locals() and conn.open:
            conn.close()

if __name__ == "__main__":
    print("💀 獵殺引擎 (Liquidation Engine) 已啟動，24小時精準監控合約爆倉風險...")
    while True:
        check_liquidations()
        time.sleep(5) # 每 5 秒掃描一次