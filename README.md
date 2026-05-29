# QUANT TERMINAL Pro 🚀
### 專業級虛擬資產模擬交易與量化投資追蹤系統

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![Python Version](https://img.shields.io/badge/Python-3.8%2B-3776AB?style=flat-square&logo=python)](https://www.python.org/)
[![Database](https://img.shields.io/badge/Database-MariaDB%20%2F%20MySQL-003545?style=flat-square&logo=mariadb)](https://mariadb.org/)
[![Frontend](https://img.shields.io/badge/Frontend-Bootstrap%205-7952B3?style=flat-square&logo=bootstrap)](https://getbootstrap.com/)
[![Charts](https://img.shields.io/badge/Charts-TradingView%20Lightweight-2962FF?style=flat-square)](https://tw.tradingview.com/lightweight-charts/)

---

## 📝 專案簡介

**QUANT TERMINAL Pro** 是一個專為虛擬金融資產（加密貨幣、傳統台美股）設計的**高仿真模擬交易與量化分析終端**。系統採用「**雙核心數據抓取引擎**」與「**背景自動判定清算引擎**」，完美還原交易所層級的現貨撮合（Spot）與 U 本位永續合約（Futures）槓桿交易機制，並深度整合 TradingView 圖表，極適合量化策略練習、前端排版實作與資料庫架構設計。

---

## ✨ 核心特色與技術亮點

### 1. 🚀 雙引擎數據中心 (`data_fetcher.py`)
* **加密貨幣引擎**：對接 **Binance API**，暖機模式（Warm-up）下自動批次同步達 1000 筆多級別（1m, 15m, 1h, 4h, 1d）歷史 K 線資料，並在增量模式下自動每分鐘滾動提交（Commit），避免鎖表。
* **傳統股市引擎**：對接 **Yahoo Finance API**，動態相容台美股（如 TSM、NVDA）的開高低收與成交量數據。

### 2. 💀 獵殺爆倉清算引擎 (`liquidation_engine.py`)
* 獨立的背景守護行程（Daemon Process），每 5 秒高頻掃描全網持倉。
* 只要最新市價跌破或觸及**預估強平價（Liquidation Price）**，立刻執行強制平倉，沒收保證金，並在交易紀錄中留存清算明細。

### 3. 📊 專業級圖表與資料補間 (`index.php` / `api_klines.php`)
* 整合 **TradingView Lightweight Charts**，支援滑鼠手動拉伸、價格軸自訂即時自動縮放（Auto Scale 救援機制）。
* 內建**加密貨幣斷線補間演算法**，當背景休眠或網路斷線重連後，自動以最後收盤價補齊空缺 K 線，確保前端圖表不崩潰。

### 4. 🔒 高規權限防禦與金流控制 (`admin.php` / `login.php`)
* 使用 PHP 官方高規格 **BCrypt** 進行密碼雜湊加密。
* 完整管理員（Admin Panel）控制台，支援同級保護機制（管理員之間無法互刪帳號）、自訂使用者模擬金（$1,000 ~ $10,000,000 USDT）與標的軟刪除（Soft Delete / Delist）下架機制。

---

## 📁 專案檔案結構說明

| 檔案名稱 | 核心職責 | 關鍵技術 / 機制 |
| :--- | :--- | :--- |
| `database_setup.sql` | 資料庫 DDL 腳本 | 建立 `Users`, `Assets`, `Transactions`, `Portfolios`, `Klines_1m` 關聯表，複合索引優化。 |
| `index.php` | 交易終端主介面 | WebSocket 即時看盤、現貨/合約下單分流、未實現損益（Unrealized PnL）即時計算。 |
| `admin.php` | 系統管理後台 | 使用者角色/模擬金調整、資產上架與軟下架（Soft Delist）機制。 |
| `api_klines.php` | K 線歷史報價 API | 提供前端 JSON 數據、斷線補間演算法（限制最大補間 100 根防爆）。 |
| `login.php` / `register.php` | 身分驗證系統 | Bcrypt 安全編碼、Session 狀態維持、自訂初始資金註冊。 |
| `data_fetcher.py` | 雙引擎數據抓取中心 | `requests` 串接 Binance、`yfinance` 串接台美股、多級別歷史暖機。 |
| `liquidation_engine.py` | 獵殺清算引擎 | 5秒背景輪詢、強制平倉撮合、`ON DUPLICEY KEY UPDATE` 持倉狀態重置。 |

---

## ⚙️ 環境安裝與部署指南

### 📌 前置需求
* **網頁伺服器**：Apache 2.4+ / PHP 8.0+ (需啟用 PDO 擴充)
* **資料庫**：MariaDB 10.4+ / MySQL 8.0+
* **腳本環境**：Python 3.8+
* **推薦懶人整合包**：[XAMPP](https://www.apachefriends.org/)

---

### 🛠️ Step-by-Step 部署步驟

#### 步驟一：架設網頁環境與資料庫配置
1. 啟動 XAMPP 的 **Apache** 與 **MySQL** 服務。
2. 將本專案的所有原始碼檔案複製到網頁根目錄：
   * Windows: `C:\xampp\htdocs\quant-terminal\`
   * Mac/Linux: `/Applications/XAMPP/htdocs/quant-terminal/`
3. 開啟瀏覽器進入 [phpMyAdmin](http://localhost/phpmyadmin/)。
4. 點選「匯入 (Import)」，選擇專案中的 `database_setup.sql` 檔案並執行，系統會自動建立 `crypto_trading_db` 資料庫及測試資料。

#### 步驟二：配置 Python 第三方依賴套件
打開終端機 (Terminal) 或命令提示字元 (CMD)，執行以下指令安裝背景引擎必備的 Python 套件：
```bash
pip install requests pymysql yfinance
```
#### 步驟三：設定資料庫連線防呆
確認專案中所有 PHP 檔案（如 `index.php`, `api_klines.php`, `login.php`）與 Python 檔案（`data_fetcher.py`, `liquidation_engine.py`）中的資料庫連線參數。預設（XAMPP）設定如下：
* **Host**: `127.0.0.1`
* **User**: `root`
* **Password**: `""` (留空)
* **Database**: `crypto_trading_db`

---

## 🏃 系統啟動與運行順序

為了確保資料流與看盤畫面完全同步，請**務必**按照以下順序啟動：

### 1️⃣ 啟動「數據抓取引擎」 (排程餵料)
打開第一個終端機視窗，導覽至專案目錄並執行：
```bash
python data_fetcher.py
```
*💡 **重要提示**：首次執行會進入歷史資料暖機模式（抓取 1000 筆資料），資料庫寫入需要大約 10~30 秒，看到控制台輸出 `💡 歷史暖機完成！雙引擎切換為「增量更新」模式。` 即可。*

### 2️⃣ 啟動「獵殺清算引擎」 (安全風控)
打開第二個終端機視窗，執行：
```bash
python liquidation_engine.py
```
*控制台會顯示 `💀 獵殺引擎 (Liquidation Engine) 已啟動，24小時監控爆倉風險...`，代表合約風控已就緒。*

### 3️⃣ 登入終端體驗模擬交易
開啟瀏覽器，輸入網址：`http://localhost/quant-terminal/login.php`

**🔑 內建測試憑證（密碼皆為 `123456`）：**
* **一般會員交易帳戶**：
  * 帳號：`student01`
  * 初始資金：`100,000.00 USDT`
* **系統管理後台帳戶**：
  * 帳號：`admin_user`
  * 權限：系統管理員（Admin）

---

## ⚖️ 免責聲明 (Disclaimer)
本專案僅供學術研究、網頁前端/後端程式實作練習與資料庫架構教學使用。系統內所有市場報價、槓桿倍數、模擬盈虧及保證金清算皆為虛擬模擬環境，**不涉及任何真實金流與實際金融市場操作**。請勿將此程式直接用於生產環境或真實交易。
