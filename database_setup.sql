-- ==============================================================================
-- 專案名稱：QUANT TERMINAL Pro - 虛擬資產模擬交易系統
-- 階段：終極完整版 (包含合約欄位、高精度小數點、多時框 K 線、完整預設標的)
-- ==============================================================================

-- 1. 建立並切換至資料庫
CREATE DATABASE IF NOT EXISTS `crypto_trading_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `crypto_trading_db`;

-- 2. 建立使用者資料表 (Users) - 支援 Email 與 Bcrypt 長密碼防截斷
CREATE TABLE IF NOT EXISTS `Users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'member', 'guest') DEFAULT 'member',
    `balance` DECIMAL(18, 4) DEFAULT 100000.0000,
    `status` ENUM('active', 'suspended') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. 建立資產資料表 (Assets)
CREATE TABLE IF NOT EXISTS `Assets` (
    `asset_id` INT AUTO_INCREMENT PRIMARY KEY,
    `symbol` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `current_price` DECIMAL(18, 8) NOT NULL DEFAULT 0.00000000,
    `status` ENUM('trading', 'delisted') DEFAULT 'trading',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. 建立交易明細 (Transactions) - 支援現貨與合約分流 (trade_mode)
CREATE TABLE IF NOT EXISTS `Transactions` (
    `tx_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `asset_id` INT NOT NULL,
    `trade_mode` VARCHAR(20) NOT NULL DEFAULT 'spot', 
    `tx_type` ENUM('buy', 'sell') NOT NULL,
    `amount` DECIMAL(18, 8) NOT NULL,
    `price_at_tx` DECIMAL(18, 8) NOT NULL,
    `total_value` DECIMAL(18, 4) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `Users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`asset_id`) REFERENCES `Assets`(`asset_id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 5. 建立使用者持倉表 (Portfolios) - 支援高精度小幣運算與合約清算
CREATE TABLE IF NOT EXISTS `Portfolios` (
    `user_id` INT NOT NULL,
    `asset_id` INT NOT NULL,
    `trade_mode` VARCHAR(20) NOT NULL DEFAULT 'spot', 
    `total_amount` DECIMAL(18, 8) NOT NULL DEFAULT 0.00000000,
    `avg_cost` DECIMAL(18, 8) NOT NULL DEFAULT 0.00000000, -- 🚀 升級至 8 位數精準度
    `leverage` INT DEFAULT 1,
    `margin` DECIMAL(18, 2) DEFAULT 0.00,
    `liquidation_price` DECIMAL(18, 8) DEFAULT 0.00000000, -- 🚀 升級至 8 位數精準度
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `asset_id`, `trade_mode`),
    FOREIGN KEY (`user_id`) REFERENCES `Users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`asset_id`) REFERENCES `Assets`(`asset_id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6. 建立所有級別的 K 線歷史報價表 (提供 Python 引擎寫入與前端繪圖)
CREATE TABLE IF NOT EXISTS `Klines_1m` (
    `asset_id` INT NOT NULL,
    `open_time` DATETIME NOT NULL,
    `open_price` DECIMAL(18, 8) NOT NULL,
    `high_price` DECIMAL(18, 8) NOT NULL,
    `low_price` DECIMAL(18, 8) NOT NULL,
    `close_price` DECIMAL(18, 8) NOT NULL,
    `volume` DECIMAL(20, 8) NOT NULL,
    PRIMARY KEY (`asset_id`, `open_time`),
    FOREIGN KEY (`asset_id`) REFERENCES `Assets`(`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 🚀 自動複製 Klines_1m 結構，快速建立所有需要的時框表格，防止 Python 報錯
CREATE TABLE IF NOT EXISTS `Klines_3m` LIKE `Klines_1m`;
CREATE TABLE IF NOT EXISTS `Klines_5m` LIKE `Klines_1m`;
CREATE TABLE IF NOT EXISTS `Klines_15m` LIKE `Klines_1m`;
CREATE TABLE IF NOT EXISTS `Klines_1h` LIKE `Klines_1m`;
CREATE TABLE IF NOT EXISTS `Klines_4h` LIKE `Klines_1m`;
CREATE TABLE IF NOT EXISTS `Klines_1d` LIKE `Klines_1m`;

-- ==============================================================================
-- 插入測試資料
-- ==============================================================================

-- 寫入兩組測試帳號 (密碼皆為 123456，已使用正確的 Bcrypt 加密格式)
INSERT IGNORE INTO `Users` (`username`, `email`, `password`, `role`, `balance`) VALUES 
('admin_user', 'admin@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 999999.00),
('student01', 'student@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'member', 100000.00);

-- 🚀 寫入畫面上的 10 個交易標的
INSERT IGNORE INTO `Assets` (`symbol`, `name`, `current_price`) VALUES 
('BTCUSDT', 'Bitcoin', 73566.51),
('ETHUSDT', 'Ethereum', 2016.26),
('TSM', 'Taiwan Semiconductor', 418.61),
('SOLUSDT', 'Solana (索拉納)', 82.30),
('BNBUSDT', 'Binance Coin (幣安幣)', 672.43),
('XRPUSDT', 'Ripple (瑞波幣)', 1.34),
('DOGEUSDT', 'Dogecoin (狗狗幣)', 0.10),
('NVDA', 'NVIDIA Corp (輝達)', 211.15),
('AAPL', 'Apple Inc (蘋果)', 312.07),
('2330.TW', 'TSMC (台灣神山台積電)', 2370.00);