-- ==============================================================================
-- 專案名稱：虛擬資產模擬交易與投資追蹤系統
-- 階段：Phase 2 - 資料庫 DDL 建置腳本 (適用於 MariaDB)
-- ==============================================================================

-- 1. 建立並切換至資料庫
CREATE DATABASE IF NOT EXISTS `crypto_trading_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `crypto_trading_db`;

-- ==============================================================================
-- [類別 1] 使用者資料表 (Users)
-- 負責帳號管理、角色權限、現金餘額控制
-- ==============================================================================
CREATE TABLE `Users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '使用者帳號',
    `password_hash` VARCHAR(255) NOT NULL COMMENT '密碼雜湊值 (建議 bcrypt)',
    `role` ENUM('admin', 'member', 'guest') DEFAULT 'member' COMMENT '多身分角色區分',
    `balance` DECIMAL(18, 4) DEFAULT 100000.0000 COMMENT '帳戶現金餘額 (預設給予十萬模擬金)',
    `status` ENUM('active', 'suspended') DEFAULT 'active' COMMENT '帳號狀態 (停權機制)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==============================================================================
-- [類別 2] 核心業務資料表 (Assets)
-- 負責管理可交易的金融標的與「最新」報價
-- ==============================================================================
CREATE TABLE `Assets` (
    `asset_id` INT AUTO_INCREMENT PRIMARY KEY,
    `symbol` VARCHAR(20) NOT NULL UNIQUE COMMENT '資產代號 (如 BTCUSDT, TSM)',
    `name` VARCHAR(100) NOT NULL COMMENT '資產名稱',
    `current_price` DECIMAL(18, 8) NOT NULL DEFAULT 0.00000000 COMMENT '當前最新報價',
    `status` ENUM('trading', 'delisted') DEFAULT 'trading' COMMENT '上下架狀態 (軟刪除應用)',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==============================================================================
-- [類別 3] 關聯橋接資料表 - 交易明細 (Transactions)
-- 紀錄誰 (User) 買賣了什麼 (Asset)，以及成交價格
-- ==============================================================================
CREATE TABLE `Transactions` (
    `tx_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `asset_id` INT NOT NULL,
    `tx_type` ENUM('buy', 'sell') NOT NULL COMMENT '交易類別',
    `amount` DECIMAL(18, 8) NOT NULL COMMENT '交易數量',
    `price_at_tx` DECIMAL(18, 8) NOT NULL COMMENT '成交當下單價',
    `total_value` DECIMAL(18, 4) NOT NULL COMMENT '總花費/獲得金額 (amount * price_at_tx)',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- 設定外鍵約束，確保資料一致性
    FOREIGN KEY (`user_id`) REFERENCES `Users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`asset_id`) REFERENCES `Assets`(`asset_id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ==============================================================================
-- [類別 3] 關聯橋接資料表 - 使用者持倉表 (Portfolios)
-- 紀錄使用者當下擁有的資產總量，方便 UPDATE 與快速查詢餘額
-- ==============================================================================
CREATE TABLE `Portfolios` (
    `portfolio_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `asset_id` INT NOT NULL,
    `total_amount` DECIMAL(18, 8) NOT NULL DEFAULT 0.00000000 COMMENT '持有總數量',
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- 確保同一個使用者對同一個資產只有一筆持倉加總紀錄
    UNIQUE KEY `unique_user_asset` (`user_id`, `asset_id`),
    FOREIGN KEY (`user_id`) REFERENCES `Users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`asset_id`) REFERENCES `Assets`(`asset_id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ==============================================================================
-- [進階擴充] K 線歷史報價表 (Klines_1m)
-- 用於 Python 模擬器寫入，前端 TradingView 畫圖讀取用
-- ==============================================================================
CREATE TABLE `Klines_1m` (
    `kline_id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `asset_id` INT NOT NULL,
    `open_time` DATETIME NOT NULL COMMENT 'K線起始時間',
    `open_price` DECIMAL(18, 8) NOT NULL,
    `high_price` DECIMAL(18, 8) NOT NULL,
    `low_price` DECIMAL(18, 8) NOT NULL,
    `close_price` DECIMAL(18, 8) NOT NULL,
    `volume` DECIMAL(18, 8) NOT NULL,
    
    -- 建立複合索引：極大化提升歷史圖表的查詢速度
    UNIQUE KEY `unique_asset_time` (`asset_id`, `open_time`),
    FOREIGN KEY (`asset_id`) REFERENCES `Assets`(`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==============================================================================
-- 插入測試資料 (符合簡報 Phase 4 規範)
-- ==============================================================================
-- 1. 新增測試帳號 (密碼均為 123456，此處先使用簡單 MD5 示範，後端實作時需改用 bcrypt)
INSERT INTO `Users` (`username`, `password_hash`, `role`, `balance`) VALUES 
('admin_user', MD5('123456'), 'admin', 999999.00),
('student01', MD5('123456'), 'member', 100000.00);

-- 2. 新增測試資產標的
INSERT INTO `Assets` (`symbol`, `name`, `current_price`) VALUES 
('BTCUSDT', 'Bitcoin (比特幣)', 65000.50),
('ETHUSDT', 'Ethereum (以太幣)', 3200.75),
('TSM', 'Taiwan Semiconductor', 150.00);

-- 3. 新增一筆測試交易 (student01 買入 0.5 顆 BTC)
INSERT INTO `Transactions` (`user_id`, `asset_id`, `tx_type`, `amount`, `price_at_tx`, `total_value`) VALUES 
(2, 1, 'buy', 0.50000000, 65000.50, 32500.25);

-- 4. 更新持倉表
INSERT INTO `Portfolios` (`user_id`, `asset_id`, `total_amount`) VALUES 
(2, 1, 0.50000000);