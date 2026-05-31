# QUANT TERMINAL Pro 🚀
### 專業級虛擬資產模擬交易與量化投資追蹤系統

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php)](https://www.php.net/)
[![Python Version](https://img.shields.io/badge/Python-3.8%2B-3776AB?style=flat-square&logo=python)](https://www.python.org/)
[![Database](https://img.shields.io/badge/Database-MariaDB%20%2F%20MySQL-003545?style=flat-square&logo=mariadb)](https://mariadb.org/)
[![Frontend](https://img.shields.io/badge/Frontend-Bootstrap%205-7952B3?style=flat-square&logo=bootstrap)](https://getbootstrap.com/)
[![Charts](https://img.shields.io/badge/Charts-TradingView%20Lightweight-2962FF?style=flat-square)](https://tw.tradingview.com/lightweight-charts/)
[![PWA Ready](https://img.shields.io/badge/PWA-Ready-5A0FC8?style=flat-square&logo=pwa)](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps)

---

## 📝 專案簡介

**QUANT TERMINAL Pro** 是一個專為虛擬金融資產（加密貨幣、傳統台美股）設計的**高仿真模擬交易與量化分析終端**。系統採用「**雙核心數據抓取引擎**」與「**全域自動風控清算引擎**」，完美還原交易所層級的現貨撮合（Spot）與 U 本位永續合約（Futures）槓桿交易機制。

本終極版本更導入了 **自動止盈止損 (TP/SL)** 觸價機制，以及 **PWA (Progressive Web App)** 架構，讓使用者能在手機端一鍵「加入主畫面」，享受無網址列、全螢幕沉浸式的原生 App 級別交易體驗！

---

## ✨ 核心特色與技術亮點

### 1. 📱 PWA 行動端原生應用架構
* 完整實作 Service Worker 與 Manifest 註冊，支援 iOS / Android 雙平台「新增至主畫面」。
* 針對行動裝置進行了極致的 RWD 空間壓縮、隱藏原生捲軸、優化側邊欄與動態選單，打造媲美頂級交易所 App 的操作體驗。

### 2. 💀 全域風控與獵殺清算引擎 (`liquidation_engine.py`)
* 獨立的背景守護行程（Daemon Process），每 5 秒高頻掃描全網持倉。
* **強平爆倉 (Liquidation)**：最新市價觸及預估強平價，立刻強制平倉並沒收保證金。
* **止盈止損 (TP/SL)**：精準監控使用者設定的條件單，達標瞬間自動 100% 市價平倉，並將剩餘保證金與損益退回帳戶餘額。

### 3. 🚀 雙引擎數據中心 (`data_fetcher.py`)
* **加密貨幣引擎**：對接 **Binance API**，暖機模式下自動批次同步達 1000 筆多級別歷史 K 線資料，並在增量模式下自動每分鐘滾動提交。
* **傳統股市引擎**：對接 **Yahoo Finance API**，動態相容台美股（如 TSM、NVDA）的開高低收與成交量數據。

### 4. 📊 專業級圖表與動態算錢 UI (`index.php`)
* 整合 **TradingView Lightweight Charts**，支援 WebSocket 即時報價連動與斷線補間演算法。
* 具備「**合約部位專屬管理面板**」，拖拉平倉滑桿即可「動態即時試算」預估 PnL (未實現損益) 並閃爍數字，提供極佳的互動反饋。

### 5. 🔒 高規權限防禦與金流控制 (`admin.php`)
* 使用 PHP 官方高規格 **BCrypt** 進行密碼雜湊加密。
* 完整管理員（Admin Panel）控制台，支援同級保護機制、自訂使用者模擬金（$1,000 ~ $10,000,000 USDT）與標的軟刪除（Soft Delete）下架機制。

---

## 📁 專案檔案結構說明

| 檔案名稱 | 核心職責 | 關鍵技術 / 機制 |
| :--- | :--- | :--- |
| `database_setup.sql` | 資料庫 DDL 腳本 | 建立關聯表，包含最新合約 `tp_price`, `sl_price` 欄位與高精度小數點優化。 |
| `index.php` | 交易終端主介面 | PWA 喚醒、現貨/合約下單分流、動態 PnL 滑桿算錢面板與 WebSocket 看盤。 |
| `admin.php` | 系統管理後台 | 使用者角色/模擬金調整、資產上架與軟下架機制。 |
| `api_klines.php` | K 線歷史報價 API | 提供前端 JSON 數據、斷線補間演算法。 |
| `login.php` / `register.php` | 身分驗證系統 | Bcrypt 安全編碼、Session 狀態維持、自訂初始資金註冊。 |
| `data_fetcher.py` | 雙引擎數據抓取中心 | `requests` 串接 Binance、`yfinance` 串接台美股。 |
| `liquidation_engine.py` | 全域風控觸發引擎 | 5秒背景輪詢，處理爆倉清算、止盈(TP)與止損(SL)自動平倉退款。 |
| `manifest.json` & `sw.js` | PWA 核心設定檔 | 控制 App 獨立顯示模式、背景顏色與 Service Worker 快取攔截。 |
| `icon-*.png` | App 桌面圖示 | 符合 Android/iOS 規範的 192x192 與 512x512 Maskable 安全邊距圖標。 |

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
2. 將本專案的所有原始碼檔案（包含 `manifest.json`、`sw.js` 與 `icon-*.png` 圖示）複製到網頁根目錄。
3. 開啟瀏覽器進入 [phpMyAdmin](http://localhost/phpmyadmin/)。
4. 點選「匯入 (Import)」，選擇專案中的 `database_setup.sql` 檔案並執行，系統會自動建立 `crypto_trading_db` 資料庫及測試資料。

#### 步驟二：配置 Python 第三方依賴套件
打開終端機 (Terminal) 或命令提示字元 (CMD)，執行以下指令安裝背景引擎必備的 Python 套件：
```bash
pip install requests pymysql yfinance
```

#### 步驟三：設定資料庫連線防呆
確認專案中所有 PHP 檔案（如 `index.php`, `api_klines.php`, `login.php`, `register.php`, `admin.php`）與 Python 檔案（`data_fetcher.py`, `liquidation_engine.py`）中的資料庫連線參數。預設設定如下：
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
*💡 **重要提示**：首次執行會進入歷史資料暖機模式（抓取 1000 筆資料），看到控制台輸出 `💡 歷史暖機完成！` 即可。*

### 2️⃣ 啟動「全域風控引擎」 (TP/SL 與爆倉監控)
打開第二個終端機視窗，執行：
```bash
python liquidation_engine.py
```
*控制台會顯示 `🤖 全域風控引擎 (Liquidation & TP/SL Engine) 已啟動`，代表背景平倉守護程式已就緒。*

### 3️⃣ 登入終端體驗模擬交易 (或安裝為 App)
* **電腦端**：開啟瀏覽器，輸入網址：`http://localhost/quant-terminal/login.php`
* **手機端 (PWA 體驗)**：確保手機與電腦在同一個 Wi-Fi 下，用手機瀏覽器輸入電腦的區域 IP（例如 `http://192.168.x.x/quant-terminal/login.php`），並點擊瀏覽器選單中的 **「加入主畫面 / Add to Home Screen」**。

**🔑 內建測試憑證（密碼皆為 `123456`）：**
* **一般會員交易帳戶**：`student01`
* **系統管理後台帳戶**：`admin_user`

---

## ⚖️ 免責聲明 (Disclaimer)
本專案僅供學術研究、網頁前端/後端程式實作練習與資料庫架構教學使用。系統內所有市場報價、槓桿倍數、模擬盈虧及保證金清算皆為虛擬模擬環境，**不涉及任何真實金流與實際金融市場操作**。請勿將此程式直接用於生產環境或真實交易。