<?php
// index.php
// 🚀 啟動會話機制，必須放在檔案最頂端，前方不能有任何 HTML 輸出
session_start();

// 🔐 安全防護：檢查使用者是否持有登入 Session。若未授權，強制踢回登入頁面！
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// 動態從小腦（Session）中提領當前登入者的資訊
$current_user_id = $_SESSION['user_id'];
$trade_message = '';

// 初始化資料庫連線
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}
// ==========================================
// 2. 處理買賣交易請求 (CUD 核心邏輯)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 🚀 分流一：處理「清空紀錄」 (不需要檢查資產與數量)
    if ($action === 'clear_history') {
        try {
            $stmt = $pdo->prepare("DELETE FROM Transactions WHERE user_id = ?");
            $stmt->execute([$current_user_id]);
            $trade_message = "<div class='alert alert-warning bg-warning text-dark border-0 mb-3 alert-dismissible fade show'>🧹 你的歷史交易紀錄已全數清空！</div>";
        } catch (Exception $e) {
            $trade_message = "<div class='alert alert-danger bg-danger text-light border-0 mb-3 alert-dismissible fade show'>❌ 清空失敗: " . $e->getMessage() . "</div>";
        }
    } 

    // 🚀 新增分流二：處理「自訂/調整模擬資金」 (類別一的使用者資料 U 更新)
    unset($stmt); // 防呆清除
    if ($action === 'adjust_balance') {
        $new_balance = isset($_POST['new_balance']) ? (float)$_POST['new_balance'] : 0;
        
        // 輸入端嚴謹驗證：防止空值、格式錯誤或極端數值
        if ($new_balance >= 1000 && $new_balance <= 10000000) {
            try {
                $stmt = $pdo->prepare("UPDATE Users SET balance = ? WHERE user_id = ?");
                $stmt->execute([$new_balance, $current_user_id]);
                $trade_message = "<div class='alert alert-success bg-success text-light border-0 mb-3 alert-dismissible fade show'>💰 帳戶餘額已成功調整為 $ " . number_format($new_balance, 2) . " USDT！</div>";
            } catch (Exception $e) {
                $trade_message = "<div class='alert alert-danger bg-danger text-light border-0 mb-3 alert-dismissible fade show'>❌ 資金調整失敗: " . $e->getMessage() . "</div>";
            }
        } else {
            $trade_message = "<div class='alert alert-danger bg-danger text-light border-0 mb-3 alert-dismissible fade show'>❌ 調整失敗：金額必須介於 $1,000 至 $10,000,000 之間。</div>";
        }
    }

    // 🚀 分流二：處理「買入(開多倉)」或「賣出(平多倉)」- 支援現貨/合約分離
    elseif ($action === 'buy' || $action === 'sell') {
        $asset_id = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $leverage = isset($_POST['leverage']) ? (int)$_POST['leverage'] : 1;
        
        // 🌟 新增：接收前端傳來的交易模式 (spot 或是 futures)
        $trade_mode = isset($_POST['trade_mode']) ? $_POST['trade_mode'] : 'spot';

        if ($amount > 0) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT current_price, symbol FROM Assets WHERE asset_id = ? AND status = 'trading'");
                $stmt->execute([$asset_id]);
                $asset = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$asset) { throw new Exception("找不到該資產或已下架"); }
                
                $notional_value = $asset['current_price'] * $amount;
                $required_margin = $notional_value / $leverage;

                if ($action === 'buy') {
                    // 【開倉/買入邏輯】
                    $stmt = $pdo->prepare("SELECT balance FROM Users WHERE user_id = ? FOR UPDATE");
                    $stmt->execute([$current_user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user['balance'] >= $required_margin) {
                        // 🌟 修改點 1：尋找舊持倉時，加上 AND trade_mode = ?
                        $stmt = $pdo->prepare("SELECT * FROM Portfolios WHERE user_id = ? AND asset_id = ? AND trade_mode = ?");
                        $stmt->execute([$current_user_id, $asset_id, $trade_mode]);
                        $port = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $old_amt = $port ? (float)$port['total_amount'] : 0;
                        $old_cost = $port ? (float)$port['avg_cost'] : 0;
                        $old_margin = $port ? (float)$port['margin'] : 0;
                        
                        $new_amt = $old_amt + $amount;
                        $new_cost = (($old_amt * $old_cost) + $notional_value) / $new_amt;
                        $new_margin = $old_margin + $required_margin;
                        $new_leverage = ($new_amt * $new_cost) / $new_margin;
                        
                        $mmr = 0.005; 
                        $liq_price = $new_cost * (1 - (1 / $new_leverage) + $mmr);

                        $pdo->prepare("UPDATE Users SET balance = balance - ? WHERE user_id = ?")->execute([$required_margin, $current_user_id]);
                        
                        // 🌟 修改點 2：寫入 Transactions 時，多塞入 trade_mode 欄位與變數
                        $pdo->prepare("INSERT INTO Transactions (user_id, asset_id, trade_mode, tx_type, amount, price_at_tx, total_value) VALUES (?, ?, ?, 'buy', ?, ?, ?)")
                            ->execute([$current_user_id, $asset_id, $trade_mode, $amount, $asset['current_price'], $required_margin]);
                        
                        // 🌟 修改點 3：寫入 Portfolios 時，多塞入 trade_mode 欄位與變數
                        $pdo->prepare("INSERT INTO Portfolios (user_id, asset_id, trade_mode, total_amount, avg_cost, leverage, margin, liquidation_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_amount = ?, avg_cost = ?, leverage = ?, margin = ?, liquidation_price = ?")
                            ->execute([$current_user_id, $asset_id, $trade_mode, $amount, $asset['current_price'], $leverage, $required_margin, $liq_price, $new_amt, $new_cost, $new_leverage, $new_margin, $liq_price]);
                        
                        $mode_text = $trade_mode === 'spot' ? '現貨' : '合約';
                        $trade_message = "<div class='alert alert-success bg-success text-light border-0 mb-3'>🎉 成功買入 {$mode_text}！扣除金額/保證金 $ " . number_format($required_margin, 2) . "</div>";
                    } else {
                        throw new Exception("可用餘額不足！需要 $ " . number_format($required_margin, 2));
                    }

                } elseif ($action === 'sell') {
                    // 【平倉/賣出邏輯】
                    // 🌟 修改點 4：鎖定持倉時，加上 AND trade_mode = ?
                    $stmt = $pdo->prepare("SELECT * FROM Portfolios WHERE user_id = ? AND asset_id = ? AND trade_mode = ? FOR UPDATE");
                    $stmt->execute([$current_user_id, $asset_id, $trade_mode]);
                    $port = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($port && $port['total_amount'] >= $amount) {
                        $close_ratio = $amount / $port['total_amount'];
                        
                        $margin_returned = $port['margin'] * $close_ratio; 
                        $pnl = ($asset['current_price'] - $port['avg_cost']) * $amount; 
                        
                        $total_return = $margin_returned + $pnl; 
                        $total_return = max(0, $total_return);

                        // 🌟 修改點 5：更新持倉時，加上 AND trade_mode = ?
                        $pdo->prepare("UPDATE Portfolios SET total_amount = total_amount - ?, margin = margin - ? WHERE user_id = ? AND asset_id = ? AND trade_mode = ?")
                            ->execute([$amount, $margin_returned, $current_user_id, $asset_id, $trade_mode]);
                            
                        // 🌟 修改點 6：寫入交易紀錄時，多塞入 trade_mode 欄位與變數
                        $pdo->prepare("INSERT INTO Transactions (user_id, asset_id, trade_mode, tx_type, amount, price_at_tx, total_value) VALUES (?, ?, ?, 'sell', ?, ?, ?)")
                            ->execute([$current_user_id, $asset_id, $trade_mode, $amount, $asset['current_price'], $total_return]);
                            
                        $pdo->prepare("UPDATE Users SET balance = balance + ? WHERE user_id = ?")->execute([$total_return, $current_user_id]);
                        
                        // 🌟 修改點 7：全數平倉刪除殘留紀錄時，加上 AND trade_mode = ?
                        if (($port['total_amount'] - $amount) < 0.000001) {
                            $pdo->prepare("DELETE FROM Portfolios WHERE user_id = ? AND asset_id = ? AND trade_mode = ?")
                                ->execute([$current_user_id, $asset_id, $trade_mode]);
                        }
                        
                        $pnl_msg = $pnl >= 0 ? "+$ " . number_format($pnl, 2) : "-$ " . number_format(abs($pnl), 2);
                        $trade_message = "<div class='alert alert-info bg-info text-dark border-0 mb-3'>💰 成功平倉！退回金額 $ " . number_format($margin_returned, 2) . "，損益：{$pnl_msg}</div>";
                    } else {
                        throw new Exception("該模式(現貨/合約)的庫存部位不足！");
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $trade_message = "<div class='alert alert-danger bg-danger text-light border-0 mb-3'>❌ 交易失敗: " . $e->getMessage() . "</div>";
            }
        }
    }
    // 🚀 新增分流三：處理「設定止盈止損 (TP/SL)」
    elseif ($action === 'update_tpsl') {
        $asset_id = (int)$_POST['asset_id'];
        // 若前端傳來空字串，則存為 null
        $tp_price = !empty($_POST['tp_price']) ? (float)$_POST['tp_price'] : null;
        $sl_price = !empty($_POST['sl_price']) ? (float)$_POST['sl_price'] : null;

        try {
            $stmt = $pdo->prepare("UPDATE Portfolios SET tp_price = ?, sl_price = ? WHERE user_id = ? AND asset_id = ? AND trade_mode = 'futures'");
            $stmt->execute([$tp_price, $sl_price, $current_user_id, $asset_id]);
            $trade_message = "<div class='alert alert-success bg-success text-light border-0 mb-3 alert-dismissible fade show'>✅ 成功更新合約部位的止盈止損設定！</div>";
        } catch (Exception $e) {
            $trade_message = "<div class='alert alert-danger bg-danger text-light border-0 mb-3 alert-dismissible fade show'>❌ 設定失敗: " . $e->getMessage() . "</div>";
        }
    }
}
// ==========================================
// 3. 查詢最新數據 (Read) - 🚀 升級：支援關鍵字搜尋與分頁
// ==========================================
$stmt = $pdo->prepare("SELECT * FROM Users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

// --- 🔍 搜尋與分頁邏輯開始 ---
$search_keyword = isset($_GET['search']) ? trim($_GET['search']) : '';
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$items_per_page = 6; // 每頁顯示 6 筆標的，讓畫面保持清爽

// 建立基礎的 WHERE 條件 (只顯示交易中的標的)
$where_sql = "WHERE status = 'trading'";
$params = [];

// 如果使用者有輸入關鍵字，加入 LIKE 條件過濾 symbol (代碼) 或 name (名稱)
if ($search_keyword !== '') {
    $where_sql .= " AND (symbol LIKE ? OR name LIKE ?)";
    $params[] = "%{$search_keyword}%";
    $params[] = "%{$search_keyword}%";
}

// 計算符合條件的「總筆數」與「總頁數」
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM Assets $where_sql");
$count_stmt->execute($params);
$total_assets = $count_stmt->fetchColumn();
$total_pages = ceil($total_assets / $items_per_page);

// 防呆：如果當前頁數大於總頁數，強制回到最後一頁
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

// 計算分頁的 OFFSET (位移量)
$offset = ($current_page - 1) * $items_per_page;

// 撈取當前頁面的資產數據
$assets_sql = "SELECT asset_id, symbol, name, current_price FROM Assets $where_sql ORDER BY asset_id ASC LIMIT $items_per_page OFFSET $offset";
$stmt = $pdo->prepare($assets_sql);
$stmt->execute($params);
$available_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
// --- 🔍 搜尋與分頁邏輯結束 ---

// 讀取近期交易歷史
$stmt = $pdo->prepare("SELECT t.*, a.symbol FROM Transactions t JOIN Assets a ON t.asset_id = a.asset_id WHERE t.user_id = ? ORDER BY t.created_at DESC LIMIT 5");
$stmt->execute([$current_user_id]);
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🚀 新增：撈取歷史交易紀錄 (JOIN 關聯表)
$stmt = $pdo->prepare("
    SELECT T.*, A.symbol 
    FROM Transactions T 
    JOIN Assets A ON T.asset_id = A.asset_id 
    WHERE T.user_id = ? 
    ORDER BY T.created_at DESC
    LIMIT 20
");
$stmt->execute([$current_user_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
// 🚀 新增這裡：撈取詳細的「投資組合」資料，計算未實現損益
$stmt = $pdo->prepare("
    SELECT P.asset_id, P.total_amount, P.avg_cost, P.trade_mode, P.liquidation_price, P.tp_price, P.sl_price, A.symbol, A.name, A.current_price 
    FROM Portfolios P 
    JOIN Assets A ON P.asset_id = A.asset_id 
    WHERE P.user_id = ? AND P.total_amount > 0
");
$stmt->execute([$current_user_id]);
$active_portfolios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 為了給左側列表顯示與 JS 使用的簡化陣列 (🚀 修正：只提取現貨部位)
$my_holdings = [];
foreach ($active_portfolios as $p) {
    // 嚴格過濾：只有 trade_mode 為 'spot' 的部位，才算入實體持幣數量
    if ($p['trade_mode'] === 'spot') {
        $my_holdings[$p['asset_id']] = $p['total_amount'];
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>專業級量化資產交易終端</title>

    <link rel="manifest" href="/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Quant Pro">
    <link rel="apple-touch-icon" href="icon-192.png">
    <meta name="theme-color" content="#161a1e">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        /* 隱藏數字輸入框的上下箭頭 (Spinners) */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type="number"] {
            -moz-appearance: textfield;
        }
        body { background-color: #0c0d10; color: #eaecef; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .navbar { background-color: #161a1e !important; border-bottom: 1px solid #2f3336; }
        /* 左側邊欄：獨立滾動與黏滯定位 */
        .sidebar { 
            background-color: #161a1e; 
            border-right: 1px solid #2f3336; 
            height: calc(100vh - 56px); /* 固定高度：螢幕總高扣掉上方 Navbar */
            position: sticky; /* 讓它黏在畫面上 */
            top: 56px; /* 貼齊 Navbar 下方 */
            overflow-y: auto; /* 當標的太多時，只在側邊欄內部產生垂直捲軸 */
        }

        /* 順手為側邊欄加上專業黑客風的自訂捲軸，隱藏 Windows 預設的醜醜灰色捲軸 */
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: #161a1e; }
        .sidebar::-webkit-scrollbar-thumb { background: #3f444a; border-radius: 4px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: #00c087; }
        .asset-item { cursor: pointer; transition: all 0.2s; border-bottom: 1px solid #23272a; }
        .asset-item:hover { background-color: #2b3139; }
        .asset-item.active { background-color: #2b3139; border-left: 4px solid #00c087; }
        .card { background-color: #161a1e; border: 1px solid #2f3336; border-radius: 8px; }
        .trading-input { background-color: #24292e !important; border: 1px solid #3f444a !important; color: white !important; }
        #tvchart { height: 450px; width: 100%; background-color: #161a1e; }
        .price-up { color: #00c087 !important; }
        .price-down { color: #f6465d !important; }
        .btn-buy { background-color: #00c087; color: white; font-weight: bold; }
        .btn-buy:hover { background-color: #00a875; color: white; }

       /* ==========================================
           📱 行動裝置專屬優化 (Mobile Responsive) - 完美終極版 V4
           ========================================== */
        @media (max-width: 767.98px) {
            /* 1. 漢堡選單 */
            .sidebar {
                position: fixed !important; top: 0 !important; left: -100% !important;
                width: 250px !important; height: 100vh !important; z-index: 1045 !important;
                transition: left 0.3s ease-in-out !important;
                background-color: #161a1e !important; border-right: 1px solid #2f3336 !important;
            }
            .sidebar.mobile-show { left: 0 !important; }
            .sidebar-backdrop {
                position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
                background: rgba(0,0,0,0.6); z-index: 1040;
                display: none; opacity: 0; transition: opacity 0.3s;
            }
            .sidebar-backdrop.mobile-show { display: block; opacity: 1; }

            /* 2. 卡片與外部留白極致壓縮 */
            .col-md-9.col-lg-10.p-4 { padding: 0.25rem !important; }
            .card.p-2, .card.p-3, .card.p-4 { 
                padding: 0.5rem !important; margin-bottom: 0.5rem !important; border-radius: 6px !important;
            }

            /* 3. 標題與文字精簡化 */
            h2#active-asset { font-size: 1rem !important; }
            h1#active-price { font-size: 1.25rem !important; }
            h4 { font-size: 0.95rem !important; margin-bottom: 0.4rem !important; }
            h5 { font-size: 0.9rem !important; margin-bottom: 0 !important; white-space: nowrap !important; }
            
            /* 4. 圖表 */
            #tvchart { height: 280px !important; width: 100% !important; }

            /* 5. 導覽列與 🏆 頂部價格/時間按鈕換行修復 */
            .navbar .container-fluid { flex-wrap: wrap; padding: 0.25rem 0.5rem !important;}
            .navbar > .container-fluid > .d-flex:last-child { width: 100%; justify-content: space-between; margin-top: 5px; font-size: 0.8rem; }
            
            /* 🔥 修復這裡：將最上方的價格與時間按鈕強制換行並上下排列 */
            .card > .d-flex.flex-wrap {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 8px !important;
            }
            
            #timeframe-buttons { display: flex; width: 100%; overflow-x: auto; }
            #timeframe-buttons .btn { flex: 1 0 auto; padding: 0.15rem 0.3rem; font-size: 0.75rem; }
            
            /* 6. 表格與標籤極致壓縮 */
            .table { font-size: 0.7rem !important; margin-bottom: 0 !important; }
            .table th, .table td { 
                padding: 0.25rem 0.2rem !important; white-space: nowrap !important; vertical-align: middle !important;
            }
            .badge { font-size: 0.65rem !important; padding: 0.2rem 0.3rem !important; }
            .btn-sm { font-size: 0.75rem !important; padding: 0.2rem 0.4rem !important; }

            /* 7. 🛒 現貨委託單 (下單表單) 專屬暴力瘦身 */
            #trade-form .mb-3, #trade-form .mb-4 { margin-bottom: 0.35rem !important; }
            #trade-form .nav-pills { padding: 0.15rem !important; }
            #trade-form .nav-pills .nav-link { padding: 0.15rem 0.4rem !important; font-size: 0.75rem !important; }
            #trade-form .btn-group label.btn { padding: 0.2rem !important; font-size: 0.85rem !important; }
            .form-label { margin-bottom: 0.1rem !important; font-size: 0.7rem !important; }
            #ui-trade-input, #unit-toggle-btn { height: 30px !important; padding: 0.1rem 0.5rem !important; font-size: 0.85rem !important; }
            .card.bg-dark.bg-opacity-50 { padding: 0.4rem !important; margin-bottom: 0.4rem !important; }
            .card.bg-dark.bg-opacity-50 .pt-2.mt-2 { padding-top: 0.25rem !important; margin-top: 0.25rem !important; }
            #est-total { font-size: 1.1rem !important; }
            #submit-btn.py-3.fs-5 { 
                padding-top: 0 !important; padding-bottom: 0 !important; height: 36px !important; 
                font-size: 0.95rem !important; display: flex !important; align-items: center !important; justify-content: center !important;
            }
            .text-center.mt-3.pt-2 { margin-top: 0.2rem !important; padding-top: 0.2rem !important; font-size: 0.65rem !important; }

            /* 8. 🔄 防止「部位切換」與「卡片標題」換行 (排除有 flex-wrap 的頂部價格區塊) */
            .card > .d-flex.justify-content-between.align-items-center:not(.flex-wrap) { 
                flex-wrap: nowrap !important; gap: 5px !important; 
            }
            .card > .d-flex .nav-pills { flex-wrap: nowrap !important; margin-bottom: 0 !important; }
            .card > .d-flex .nav-pills .nav-item { white-space: nowrap !important; }
            .card > .d-flex .nav-pills .nav-link { padding: 0.2rem 0.4rem !important; font-size: 0.7rem !important; }
            /* 9. ⚡ 合約平倉/止盈止損面板專屬瘦身 */
            #closeFuturesModal .modal-body { padding: 0.75rem !important; }
            #closeFuturesModal .mb-3 { margin-bottom: 0.5rem !important; }
            #closeFuturesModal .my-4 { margin-top: 0.75rem !important; margin-bottom: 0.75rem !important; }
            #closeFuturesModal hr { margin: 0.5rem 0 !important; }
            #close-pnl-display { font-size: 1.15rem !important; } /* 縮小一點算錢看板，避免數字太大折行 */
            #closeFuturesModal .form-label { font-size: 0.7rem !important; margin-bottom: 0.1rem !important; }
            #tpsl-form { margin-top: 0.5rem !important; padding-top: 0.5rem !important; }
            #modal-tp-input, #modal-sl-input { padding: 0.2rem 0.4rem !important; font-size: 0.8rem !important; height: 32px !important; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-dark sticky-top shadow-sm py-2">

        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <button class="btn btn-dark btn-sm d-md-none me-2 border-secondary" type="button" onclick="toggleMobileMenu()">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <a class="navbar-brand d-flex align-items-center fw-bold text-success m-0" href="#">
                    <i class="bi bi-lightning-charge-fill me-2"></i> QUANT TERMINAL Pro
                </a>
            </div>
            <div class="d-flex align-items-center">
                <span class="me-4 text-secondary"><i class="bi bi-wallet2 me-2"></i>模擬金餘額: <strong class="text-light">$ <?= number_format($userInfo['balance'], 2) ?></strong></span>
                
                <div class="dropdown">
                    <button class="btn btn-secondary btn-sm dropdown-toggle rounded-pill px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle me-1"></i> <?= htmlspecialchars($userInfo['username']) ?> (會員)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark shadow-sm border-secondary mt-2">
                        <li><h6 class="dropdown-header text-secondary">帳號設定</h6></li>
                        <li><a class="dropdown-item py-2" href="#" data-bs-toggle="modal" data-bs-target="#fundsModal"><i class="bi bi-wallet2 me-2 text-warning"></i>調整 / 重置模擬資金</a></li>
                        <li><hr class="dropdown-divider border-secondary border-opacity-50"></li>
                        <li><a class="dropdown-item text-danger py-2 fw-bold" href="logout"><i class="bi bi-box-arrow-right me-2"></i>安全登出</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="sidebar-backdrop d-md-none" onclick="toggleMobileMenu()"></div>

    <div class="container-fluid">
        <div class="row">

    <div class="col-md-3 col-lg-2 sidebar p-0">
    <div class="p-3 text-secondary small fw-bold border-bottom border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>市場追蹤清單</span>
    </div>

    <div class="p-2 border-bottom border-secondary border-opacity-25 bg-dark">
        <form method="GET" action="index" class="m-0">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-transparent border-secondary text-secondary"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control bg-transparent border-secondary text-light" name="search" placeholder="搜尋代碼或名稱..." value="<?= htmlspecialchars($search_keyword) ?>">
                <?php if ($search_keyword !== ''): ?>
                    <a href="index" class="btn btn-outline-danger border-secondary"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="list-group list-group-flush" id="asset-list">
        <?php if (empty($available_assets)): ?>
            <div class="p-4 text-center text-secondary small">
                <i class="bi bi-search d-block mb-2 fs-4 opacity-50"></i>
                找不到符合「<?= htmlspecialchars($search_keyword) ?>」的標的
            </div>
        <?php else: ?>
            <?php foreach ($available_assets as $asset): ?>
                <?php 
                    $holding = isset($my_holdings[$asset['asset_id']]) ? $my_holdings[$asset['asset_id']] : 0;
                ?>
                <div class="asset-item p-3 d-flex justify-content-between align-items-center" 
                     id="asset-<?= $asset['asset_id'] ?>"
                     data-id="<?= $asset['asset_id'] ?>"
                     data-symbol="<?= strtolower($asset['symbol']) ?>"
                     data-name="<?= $asset['name'] ?>"
                     onclick="selectAsset(<?= $asset['asset_id'] ?>)">
                    <div>
                        <div class="fw-bold text-light"><?= htmlspecialchars($asset['symbol']) ?></div>
                        <div class="text-secondary small"><?= htmlspecialchars($asset['name']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold id-price" id="price-val-<?= $asset['asset_id'] ?>">$ <?= number_format($asset['current_price'], 2) ?></div>
                        <?php if($holding > 0): ?>
                            <span class="badge bg-success-subtle text-success py-1 mt-1">持有: <?= number_format($holding, 4) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="p-2 border-top border-secondary border-opacity-25 bg-dark">
            <nav aria-label="Assets page navigation">
                <ul class="pagination pagination-sm justify-content-center m-0" data-bs-theme="dark">
                    <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link bg-transparent border-secondary text-light" href="?page=<?= $current_page - 1 ?>&search=<?= urlencode($search_keyword) ?>">«</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= ($i === $current_page) ? 'active' : '' ?>">
                            <a class="page-link <?= ($i === $current_page) ? 'bg-info border-info text-dark fw-bold' : 'bg-transparent border-secondary text-light' ?>" 
                               href="?page=<?= $i ?>&search=<?= urlencode($search_keyword) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link bg-transparent border-secondary text-light" href="?page=<?= $current_page + 1 ?>&search=<?= urlencode($search_keyword) ?>">»</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

            <div class="col-md-9 col-lg-10 p-4">
                
                <?= $trade_message ?>

                <div class="card p-3 mb-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <div class="d-flex align-items-center">
                            <h2 class="mb-0 fw-bold me-3" id="active-asset">BTCUSDT</h2>
                            <h1 class="mb-0 fw-bold price-up me-4" id="active-price">$ 0.00</h1>
                        </div>
                        <div class="btn-group btn-group-sm shadow-sm" role="group" id="timeframe-buttons">
                            <button type="button" class="btn btn-outline-secondary active timeframe-btn" onclick="changeTimeframe('1m')">1m</button>
                            <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('3m')">3m</button> <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('5m')">5m</button> <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('15m')">15m</button>
                            <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('1h')">1H</button>
                            <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('4h')">4H</button>
                            <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('1d')">1D</button>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xl-8 mb-4">
                        <div class="card p-2">
                            <div class="position-relative">
                                <div id="tvchart"></div>
                                <button type="button" 
                                        class="btn btn-dark btn-sm position-absolute shadow-sm" 
                                        id="auto-scale-btn"
                                        style="bottom: 35px; right: 65px; z-index: 10; border: 1px solid #3f444a; background-color: #1c2024; color: #00c087; font-weight: bold; padding: 2px 8px; display: none;" 
                                        onclick="resetChartViewport()" 
                                        title="自動重置價格與時間軸">
                                    A
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-4 mb-4">
                        <div class="card p-4 h-100 d-flex flex-column justify-content-between">
    <div>
        <h4 class="fw-bold text-light border-bottom border-secondary pb-2 mb-3"><i class="bi bi-cart-plus me-2 text-success"></i>現貨委託單</h4>
        
        <form method="POST" action="" id="trade-form">
            <input type="hidden" name="action" id="form-action" value="buy">
            <input type="hidden" name="asset_id" id="form-asset-id" value="1">
            <input type="hidden" name="trade_mode" id="form-trade-mode" value="spot"> <input type="hidden" name="amount" id="hidden-actual-amount" value="0"> <ul class="nav nav-pills nav-fill mb-3 bg-dark p-1 rounded border border-secondary border-opacity-25" style="font-size: 0.9rem;">
                <li class="nav-item">
                    <a class="nav-link active fw-bold text-light bg-secondary bg-opacity-25" id="tab-spot" href="#" onclick="switchTradeMode('spot', event)">現貨 (Spot)</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link fw-bold text-secondary" id="tab-futures" href="#" onclick="switchTradeMode('futures', event)">U本位合約</a>
                </li>
            </ul>

            <div class="btn-group w-100 mb-3" role="group">
                <input type="radio" class="btn-check" name="btnradio" id="btn-buy-tab" autocomplete="off" checked onclick="setTradeDirection('buy')">
                <label class="btn btn-outline-success fw-bold" for="btn-buy-tab">買入 (Buy)</label>

                <input type="radio" class="btn-check" name="btnradio" id="btn-sell-tab" autocomplete="off" onclick="setTradeDirection('sell')">
                <label class="btn btn-outline-danger fw-bold" for="btn-sell-tab">賣出 (Sell)</label>
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary small fw-bold d-flex justify-content-between">
                    <span>委託數量 / 價值</span>
                    <span id="holding-hint" class="text-info" style="cursor: pointer;" onclick="fillMaxAmount()">可用持倉: 0.00</span>
                </label>
                <div class="input-group">
                    <input type="number" step="0.000001" min="0.000001" class="form-control trading-input fs-5" id="ui-trade-input" placeholder="0.00" oninput="calculateTotal()" required>
                    <button class="btn btn-secondary fw-bold border-secondary text-light" type="button" id="unit-toggle-btn" onclick="toggleInputUnit()">BTC</button>
                </div>
            </div>

            <div class="mb-3" id="leverage-container" style="display: none;">
                <label class="form-label text-secondary small fw-bold d-flex justify-content-between">
                    <span>槓桿倍數 (Leverage)</span>
                    <span id="leverage-display" class="text-warning">1x</span>
                </label>
                <div class="d-flex align-items-center bg-dark p-2 rounded border border-secondary border-opacity-50">
                    <input type="range" class="form-range flex-grow-1 me-3" id="leverage-slider" min="1" max="100" step="1" value="1" oninput="syncLeverage(this.value, 'slider')">
                    <div class="input-group input-group-sm" style="width: 70px;">
                        <input type="number" class="form-control trading-input text-center text-warning fw-bold px-1" id="leverage-input" name="leverage" min="1" max="100" value="1" oninput="syncLeverage(this.value, 'input')">
                        <span class="input-group-text trading-input px-1">x</span>
                    </div>
                </div>
            </div>

            <div class="card bg-dark bg-opacity-50 border-secondary p-3 mb-4">
                <div class="d-flex justify-content-between small text-secondary mb-1">
                    <span>預估單價:</span>
                    <span id="est-price">$ 0.00</span>
                </div>
                <div class="d-flex justify-content-between fw-bold fs-5 text-light border-top border-secondary border-opacity-25 pt-2 mt-2">
                    <span id="est-label-text">所需保證金:</span>
                    <span class="text-warning" id="est-total">$ 0.00</span>
                </div>
            </div>
            
            <button type="submit" class="btn btn-buy w-100 py-3 fs-5 shadow-sm" id="submit-btn"><i class="bi bi-box-arrow-in-right me-2"></i>執行現貨買入</button>
        </form>
    </div>
    <div class="text-secondary small text-center mt-3 border-top border-secondary border-opacity-25 pt-2">
        <i class="bi bi-shield-check me-1 text-success"></i> 已啟用 SSL 加密防護與安全網關
    </div>
</div> </div> </div> <div class="card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>我的投資組合 (Unrealized PnL)</h5>
            
            <ul class="nav nav-pills bg-dark p-1 rounded border border-secondary border-opacity-25" style="font-size: 0.8rem;">
                <li class="nav-item"><a class="nav-link active py-1 px-2 text-light bg-secondary bg-opacity-25" id="pnl-tab-spot" href="#" onclick="filterPnlTable('spot', event)">現貨部位</a></li>
                <li class="nav-item"><a class="nav-link text-secondary py-1 px-2" id="pnl-tab-futures" href="#" onclick="filterPnlTable('futures', event)">合約部位</a></li>
            </ul>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead class="text-secondary small">
                    <tr>
                        <th>標的</th>
                        <th>模式</th>
                        <th>持倉數量</th>
                        <th>平均成本</th>
                        <th>預估強平價 (爆倉)</th> <th>當前市價</th>
                        <th>未實現損益 (USDT)</th>
                        <th>報酬率 (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($active_portfolios)): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-4">目前尚無任何持倉</td></tr>
                    <?php else: ?>
                        <?php foreach ($active_portfolios as $port): 
                            $cost_val = $port['total_amount'] * $port['avg_cost'];
                            $current_val = $port['total_amount'] * $port['current_price'];
                            $pnl = $current_val - $cost_val;
                            $pnl_percent = ($cost_val > 0) ? ($pnl / $cost_val) * 100 : 0;
                            
                            $pnl_class = ($pnl >= 0) ? 'text-success' : 'text-danger';
                            $pnl_sign = ($pnl >= 0) ? '+' : '';
                            $mode_badge = ($port['trade_mode'] === 'futures') ? '<span class="badge bg-warning text-dark">合約</span>' : '<span class="badge bg-secondary">現貨</span>';
                        ?>
                            <tr class="portfolio-row" 
                                <?php if($port['trade_mode'] === 'futures'): ?>
                                    onclick="openCloseModal(<?= $port['asset_id'] ?>, '<?= $port['symbol'] ?>', <?= $port['total_amount'] ?>, <?= $port['avg_cost'] ?>, '<?= $port['tp_price'] ?>', '<?= $port['sl_price'] ?>')" 
                                    style="cursor:pointer;" 
                                    title="點擊管理合約部位"
                                <?php endif; ?>
                                data-mode="<?= $port['trade_mode'] ?>"
                                data-symbol="<?= strtolower($port['symbol']) ?>" 
                                data-amount="<?= $port['total_amount'] ?>" 
                                data-cost="<?= $port['avg_cost'] ?>">
                                
                                <td>
                                    <div class="fw-bold text-light"><?= htmlspecialchars($port['symbol']) ?></div>
                                </td>
                                <td><?= $mode_badge ?></td>
                                <td class="fw-bold"><?= number_format($port['total_amount'], 4) ?></td>
                                <td class="text-secondary">$ <?= number_format($port['avg_cost'], 4) ?></td>
                                
                                <td class="text-warning fw-bold">
                                    <?= $port['trade_mode'] === 'futures' ? '$ ' . number_format($port['liquidation_price'], 4) : '<span class="text-secondary opacity-50">無 (現貨)</span>' ?>
                                </td>
                                
                                <td class="current-price fw-bold">$ <?= number_format($port['current_price'], 4) ?></td>
                                <td class="pnl-amount fw-bold <?= $pnl_class ?>"><?= $pnl_sign ?>$ <?= number_format($pnl, 2) ?></td>
                                <td class="pnl-percent fw-bold <?= $pnl_class ?>"><?= $pnl_sign ?><?= number_format($pnl_percent, 2) ?> %</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-clock-history me-2 text-warning"></i>近期交易明細</h5>
        
        <form method="POST" action="" onsubmit="return confirm('⚠️ 警告：確定要清空所有交易紀錄嗎？\n\n此動作無法復原，但不會影響你目前的餘額與持倉。');">
            <input type="hidden" name="action" value="clear_history">
            <button type="submit" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash3 me-1"></i>清空紀錄
            </button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
            <thead class="text-secondary small">
                <tr>
                    <th>時間</th>
                    <th>標的</th>
                    <th>方向</th>
                    <th>成交價格</th>
                    <th>數量</th>
                    <th>總金額</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($transactions)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">目前尚無交易紀錄</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                        <tr>
                            <td class="text-secondary"><?= $tx['created_at'] ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($tx['symbol']) ?></td>
                            <td>
                                <?php if($tx['tx_type'] === 'buy'): ?>
                                    <span class="badge bg-success-subtle text-success">買入</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger">賣出</span>
                                <?php endif; ?>
                            </td>
                            <td>$ <?= number_format($tx['price_at_tx'], 2) ?></td>
                            <td><?= number_format($tx['amount'], 4) ?></td>
                            <td>$ <?= number_format($tx['total_value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
    
    <script>
        // ==========================================
        // 全域狀態管理與變數宣告 (🚀 核心修復區)
        // ==========================================
        let currentAssetId = 1;
        let currentSymbol = 'btcusdt'; // 🚀 修復 1：補上遺失的全域變數，防止系統崩潰！
        let currentInterval = '1m';
        let currentPrice = 0;
        let binanceSocket = null;
        let currentTradeMode = 'spot'; // 🚀 修復 2：把這兩個變數移到最上方，確保初始化時就存在
        let inputUnitMode = 'amount';  

        // 1. 初始化 TradingView Lightweight Chart (圖表外框)
        const chart = LightweightCharts.createChart(document.getElementById('tvchart'), {
            layout: { background: { type: 'solid', color: '#161a1e' }, textColor: '#d1d4dc' },
            grid: { vertLines: { color: '#24292e' }, horzLines: { color: '#24292e' } },
            timeScale: { timeVisible: true, secondsVisible: false, timeFormat: '%m/%d %H:%M' }
        });

        // 🎯 遺失的關鍵拼圖：初始化 K 線系列 (蠟燭圖本體)
        const candleSeries = chart.addCandlestickSeries({
            upColor: '#00c087', downColor: '#f6465d', borderVisible: false, wickUpColor: '#00c087', wickDownColor: '#f6465d'
        });

        // 🚀 手機版側邊欄開關邏輯
        function toggleMobileMenu() {
            document.querySelector('.sidebar').classList.toggle('mobile-show');
            document.querySelector('.sidebar-backdrop').classList.toggle('mobile-show');
        }

        // 🚀 安全的圖表縮放：確保圖表隨螢幕大小改變
        window.addEventListener('resize', () => {
            const chartContainer = document.getElementById('tvchart');
            if (chartContainer && chartContainer.clientWidth > 0) {
                chart.resize(chartContainer.clientWidth, chartContainer.clientHeight);
            }
        });
        
        // 初次載入時，給予一點延遲確保抓到正確的手機螢幕寬度，把圖表畫出來
        setTimeout(() => {
            const chartContainer = document.getElementById('tvchart');
            if (chartContainer && chartContainer.clientWidth > 0) {
                chart.resize(chartContainer.clientWidth, chartContainer.clientHeight);
            }
        }, 300);

        // --- 下方保留你原本的 function selectAsset(assetId) 等等... ---
        // 🚀 修正一：動態切換商品標題與下單單位
        function selectAsset(assetId) {
            currentAssetId = assetId;
            document.getElementById('form-asset-id').value = assetId;
            
            // 處理左側列表的 Active 亮燈效果 (已清理重複代碼)
            document.querySelectorAll('.asset-item').forEach(el => el.classList.remove('active'));
            const selectedEl = document.querySelector(`.asset-item[data-id='${assetId}']`);
            if (selectedEl) selectedEl.classList.add('active');

            // 取得商品代碼與名稱
            currentSymbol = selectedEl.getAttribute('data-symbol');
            const assetName = selectedEl.getAttribute('data-name');
            
            const displaySymbol = currentSymbol.includes('USDT') ? currentSymbol.replace('USDT', '/USDT') : currentSymbol.toUpperCase();
            document.getElementById('active-asset').innerHTML = `${displaySymbol} <span class="fs-6 text-secondary ms-2">${assetName}</span>`;
            
            // 動態更新下單單位的按鈕文字
            document.getElementById('unit-toggle-btn').innerText = (inputUnitMode === 'amount') ? currentSymbol.replace('USDT', '').toUpperCase() : 'USDT';

            fetchKlinesAndDraw('1m'); 
            updateHoldingHint();
            // 如果是在手機版，點擊後自動關閉側邊欄
            if (window.innerWidth < 768) toggleMobileMenu();
        }

        // 🚀 修正二：加入「圖表崩潰自動救援」的終極防護
        async function fetchKlinesAndDraw(interval) {
            try {
                const response = await fetch(`api_klines.php?asset_id=${currentAssetId}&interval=${interval}&t=${Date.now()}`);
                if (!response.ok) throw new Error("API 伺服器錯誤");
                
                const klineData = await response.json();

                if (klineData && klineData.length > 0) {
                    candleSeries.setData(klineData);
                    chart.timeScale().fitContent();
                    
                    const lastPrice = klineData[klineData.length - 1].close;
                    updatePriceUI(lastPrice);
                } else {
                    throw new Error("API 傳回空資料");
                }
                
                startRealtimeUpdates(interval);
                resetChartViewport();
                
            } catch (error) {
                console.error("K線載入失敗，啟動救援機制:", error);
                // 🛡️ 核心修復：就算圖表壞了或沒資料，強制從側邊欄抓取「最新價格」塞進系統
                // 這樣一來 currentPrice 就不會是 0，算保證金跟下單就絕對不會失效！
                candleSeries.setData([]); 
                const sidebarPriceStr = document.getElementById(`price-val-${currentAssetId}`).innerText.replace('$', '').replace(/,/g, '');
                updatePriceUI(parseFloat(sidebarPriceStr) || 0);
            }
        }

        // 4. 即時 WebSocket 價格跳動致敬
        function startRealtimeUpdates(interval) {
            if (binanceSocket) { binanceSocket.close(); }

            // 🚀 新增防呆：如果不是加密貨幣 (不包含 usdt)，就直接 return，不啟動 WebSocket
            if (!currentSymbol.includes('usdt')) {
                console.log("傳統股市無 WebSocket 支援，依靠定時重整獲取最新報價。");
                return;
            }


            // 串接幣安即時廣播頻道
            const wsUrl = `wss://stream.binance.com:9443/ws/${currentSymbol}@kline_${interval}`;
            binanceSocket = new WebSocket(wsUrl);

            binanceSocket.onmessage = function(event) {
                const message = JSON.parse(event.data);
                const kline = message.k;

                const tickData = {
                    time: kline.t / 1000,
                    open: parseFloat(kline.o),
                    high: parseFloat(kline.h),
                    low: parseFloat(kline.l),
                    close: parseFloat(kline.c)
                };

                candleSeries.update(tickData);
                updatePriceUI(tickData.close);
            };
        }

        // 5. 更新大字看板報價與顏色閃爍效果 (加上即時計算損益！)
        function updatePriceUI(price) {
            const priceEl = document.getElementById('active-price');
            const sidebarPriceEl = document.getElementById(`price-val-${currentAssetId}`);
            
            const formattedPrice = '$ ' + price.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 4});
            
            // 判斷漲跌顏色
            if (price >= currentPrice) {
                priceEl.className = "mb-0 fw-bold price-up";
            } else {
                priceEl.className = "mb-0 fw-bold price-down";
            }

            currentPrice = price;
            priceEl.innerText = formattedPrice;
            if (sidebarPriceEl) sidebarPriceEl.innerText = formattedPrice;
            
            document.getElementById('est-price').innerText = '$ ' + price.toFixed(2);
            calculateTotal();
            
            // 🚀 核心：即時尋找投資組合列表中對應的列，並重新計算 PnL (支援同時更新現貨與合約行)
            const rows = document.querySelectorAll(`.portfolio-row[data-symbol="${currentSymbol.toLowerCase()}"]`);
            rows.forEach(portfolioRow => {
                const amount = parseFloat(portfolioRow.getAttribute('data-amount'));
                const avgCost = parseFloat(portfolioRow.getAttribute('data-cost'));
                
                const pnl = (price - avgCost) * amount;
                const pnlPercent = (avgCost > 0) ? (pnl / (amount * avgCost)) * 100 : 0;

                const isProfit = pnl >= 0;
                const sign = isProfit ? '+' : '-';
                const colorClass = isProfit ? 'text-success' : 'text-danger';

                portfolioRow.querySelector('.current-price').innerText = '$ ' + price.toFixed(4);
                
                const pnlAmountEl = portfolioRow.querySelector('.pnl-amount');
                pnlAmountEl.className = `pnl-amount fw-bold ${colorClass}`;
                pnlAmountEl.innerText = `${sign}$ ${Math.abs(pnl).toFixed(2)}`;

                const pnlPercentEl = portfolioRow.querySelector('.pnl-percent');
                pnlPercentEl.className = `pnl-percent fw-bold ${colorClass}`;
                pnlPercentEl.innerText = `${sign}${Math.abs(pnlPercent).toFixed(2)} %`;
            });

            // 🚀 如果平倉面板正開著，且剛好是當前跳動的標的，即時更新面板裡的損益！
            const closeModal = document.getElementById('closeFuturesModal');
            if (closeModal && closeModal.classList.contains('show')) {
                if (document.getElementById('close-symbol-display').innerText === currentSymbol.toUpperCase()) {
                    currentModalPrice = price; // 更新面板抓到的最新價
                    const currentCloseAmount = parseFloat(document.getElementById('close-amount-input').value) || 0;
                    updateClosePnL(currentCloseAmount); // 重新算錢
                }
            }
        }
        // 槓桿聯動邏輯
        function syncLeverage(val, source) {
            let leverage = parseInt(val);
            if (isNaN(leverage) || leverage < 1) leverage = 1;
            if (leverage > 100) leverage = 100;

            if (source === 'slider') {
                document.getElementById('leverage-input').value = leverage;
            } else {
                document.getElementById('leverage-slider').value = leverage;
            }
            document.getElementById('leverage-display').innerText = leverage + 'x';
            calculateTotal(); // 每次拉動槓桿都重新計算所需保證金
        }

        
        // 🚀 新增：解鎖並自動重置圖表視窗（價格與時間軸同時收攏）
        function resetChartViewport() {
            // 1. 恢復價格軸的自動縮放（解鎖手動拉伸）
            chart.priceScale('right').applyOptions({
                autoScale: true
            });
            // 2. 讓時間軸自動適應所有 K 線並滾動到最新位置
            chart.timeScale().fitContent();
            
            // 3. 隱藏 A 按鈕
            document.getElementById('auto-scale-btn').style.display = 'none';
        }

        // 7. 時間級別切換
        function changeTimeframe(newInterval) {
            if (currentInterval === newInterval) return;
            currentInterval = newInterval;
            fetchKlinesAndDraw(currentInterval);

            const buttons = document.querySelectorAll('#timeframe-buttons .btn');
            buttons.forEach(btn => {
                if(btn.innerText.toLowerCase() === newInterval.toLowerCase()) btn.classList.add('active');
                else btn.classList.remove('active');
            });
        }

        // 🚀 修正後的網頁初始化與價格軸監聽
        window.onload = function() {
            // 1. 預設選取列表中的第一個資產 (BTC)
            const firstAsset = document.querySelector('.asset-item');
            if(firstAsset) {
                const firstId = firstAsset.getAttribute('data-id');
                selectAsset(parseInt(firstId));
            }

            // 2. 精準偵測使用者是否點擊並拉動右側的「價格座標軸」
            const chartContainer = document.getElementById('tvchart');
            
            chartContainer.addEventListener('mousedown', function(e) {
                const rect = chartContainer.getBoundingClientRect();
                const mouseX = e.clientX - rect.left; // 算出滑鼠在圖表內的相對 X 座標
                const chartWidth = rect.width;
                
                // TradingView 右側價格數字軸的寬度大約是 65 像素
                // 如果滑鼠點擊的位置在「總寬度 - 65px」以右，代表使用者正在手動拉伸價格！
                if (mouseX > chartWidth - 65) {
                    document.getElementById('auto-scale-btn').style.display = 'block';
                }
            });

            // 3. 監聽圖表的價格軸手動變動事件 (如果版本支援)
            if(chart.priceScale('right').subscribeVisibleRadiusChanged) {
                chart.priceScale('right').subscribeVisibleRadiusChanged(() => {
                    // 當使用者手動拖拽價格軸導致非自動縮放時，秀出 A 按鈕
                    document.getElementById('auto-scale-btn').style.display = 'block';
                });
            }
        };
        // 將 PHP 的持倉陣列轉為 JS 物件，方便前端隨時查閱
        const myHoldings = <?= json_encode($my_holdings) ?>;
        // ==========================================
        // 交易表單與 UI 互動邏輯 (現貨合約全面升級版)
        // ==========================================
        

        // 1. 現貨/合約 模式切換
        function switchTradeMode(mode, event) {
            if(event) event.preventDefault();
            currentTradeMode = mode;
            document.getElementById('form-trade-mode').value = mode;
            
            // UI 按鈕樣式切換
            document.getElementById('tab-spot').className = (mode === 'spot') ? 'nav-link active fw-bold text-light bg-secondary bg-opacity-25' : 'nav-link fw-bold text-secondary';
            document.getElementById('tab-futures').className = (mode === 'futures') ? 'nav-link active fw-bold text-light bg-secondary bg-opacity-25' : 'nav-link fw-bold text-secondary';
            
            // 隱藏/顯示槓桿拉桿
            document.getElementById('leverage-container').style.display = (mode === 'futures') ? 'block' : 'none';
            if(mode === 'spot') {
                syncLeverage(1, 'input'); // 現貨強制 1 倍槓桿
            }
            
            updateSubmitBtnText();
            updateHoldingHint();
            calculateTotal();
            filterPnlTable(mode); // 切換下方損益表
        }

        // 2. 買/賣 方向切換
        function setTradeDirection(direction) {
            document.getElementById('form-action').value = direction;
            const btn = document.getElementById('submit-btn');
            
            if (direction === 'buy') {
                btn.className = "btn btn-success w-100 py-3 fs-5 shadow-sm";
            } else {
                btn.className = "btn btn-danger w-100 py-3 fs-5 shadow-sm";
            }
            
            updateSubmitBtnText();
            updateHoldingHint();
            calculateTotal();
        }

        // 3. 更新按鈕文字
        function updateSubmitBtnText() {
            const btn = document.getElementById('submit-btn');
            const direction = document.getElementById('form-action').value;
            const actionText = (direction === 'buy') ? '買入/做多' : '賣出/平倉';
            const modeText = (currentTradeMode === 'spot') ? '現貨' : '合約';
            const icon = (direction === 'buy') ? '<i class="bi bi-box-arrow-in-right me-2"></i>' : '<i class="bi bi-box-arrow-right me-2"></i>';
            btn.innerHTML = `${icon}執行${modeText}${actionText}`;
        }

        // 4. 單位切換 (顆 vs USDT)
        function toggleInputUnit() {
            inputUnitMode = (inputUnitMode === 'amount') ? 'value' : 'amount';
            const baseUnit = currentSymbol.replace('USDT', '').toUpperCase();
            document.getElementById('unit-toggle-btn').innerText = (inputUnitMode === 'amount') ? baseUnit : 'USDT';
            
            // 清空輸入框避免換算混亂
            document.getElementById('ui-trade-input').value = '';
            calculateTotal();
        }

        // 5. 終極算價與保證金引擎
        function calculateTotal() {
            const rawVal = parseFloat(document.getElementById('ui-trade-input').value) || 0;
            let actualAmount = 0;
            let notionalValue = 0;

            // 根據單位換算
            if (inputUnitMode === 'amount') {
                actualAmount = rawVal;
                notionalValue = actualAmount * currentPrice;
            } else {
                notionalValue = rawVal;
                actualAmount = (currentPrice > 0) ? (notionalValue / currentPrice) : 0;
            }

            // 將真實數量塞進隱藏欄位給後端
            document.getElementById('hidden-actual-amount').value = actualAmount.toFixed(6);

            const leverage = (currentTradeMode === 'futures') ? (parseInt(document.getElementById('leverage-input').value) || 1) : 1;
            const requiredMargin = notionalValue / leverage;

            // 更新預估金額顯示
            document.getElementById('est-total').innerText = '$ ' + requiredMargin.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            const direction = document.getElementById('form-action').value;
            document.getElementById('est-label-text').innerText = (direction === 'buy') ? '所需保證金:' : '平倉預估拿回:';
        }

        // 6. 槓桿滑桿聯動
        function syncLeverage(val, source) {
            let leverage = parseInt(val);
            if (isNaN(leverage) || leverage < 1) leverage = 1;
            if (leverage > 100) leverage = 100;

            if (source === 'slider') {
                document.getElementById('leverage-input').value = leverage;
            } else {
                document.getElementById('leverage-slider').value = leverage;
            }
            document.getElementById('leverage-display').innerText = leverage + 'x';
            calculateTotal(); // 每次拉動槓桿都重新計算
        }

        // 7. 更新右上角「可用持倉」文字
        function updateHoldingHint() {
            const amount = myHoldings[currentAssetId] || 0;
            document.getElementById('holding-hint').innerText = `可用持倉: ${parseFloat(amount).toFixed(4)}`;
        }

        // 8. 點擊「可用持倉」時填入最大數量
        function fillMaxAmount() {
            const amount = myHoldings[currentAssetId] || 0;
            if (inputUnitMode === 'value') {
                toggleInputUnit(); // 如果當前是價值模式，強制切回數量模式
            }
            document.getElementById('ui-trade-input').value = parseFloat(amount).toFixed(4);
            calculateTotal();
        }

        // 9. 過濾下方 PnL 表格
        function filterPnlTable(mode = currentTradeMode, event = null) {
            if(event) event.preventDefault();
            
            const tabSpot = document.getElementById('pnl-tab-spot');
            const tabFutures = document.getElementById('pnl-tab-futures');
            
            if (tabSpot && tabFutures) {
                tabSpot.className = (mode === 'spot') ? 'nav-link active py-1 px-2 text-light bg-secondary bg-opacity-25' : 'nav-link text-secondary py-1 px-2';
                tabFutures.className = (mode === 'futures') ? 'nav-link active py-1 px-2 text-light bg-secondary bg-opacity-25' : 'nav-link text-secondary py-1 px-2';
            }
            
            document.querySelectorAll('.portfolio-row').forEach(row => {
                if(row.getAttribute('data-mode') === mode) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        // ==========================================
        // 🚀 合約平倉專屬引擎 (含動態損益計算)
        // ==========================================
        let currentModalAvgCost = 0;
        let currentModalPrice = 0;

        // 10. 合約部位點擊：跳出平倉面板並抓取最新價格
        // 新增接收 tpPrice, slPrice 參數
        function openCloseModal(assetId, symbol, totalAmount, avgCost, tpPrice, slPrice) {
            
            // 🎯【表單 A 的資料綁定】
            document.getElementById('close-asset-id').value = assetId;
            document.getElementById('close-max-amount').value = totalAmount;
            
            // 🎯【表單 B 的資料綁定】
            // 把資產 ID 餵給 TP/SL 表單，後端才知道要改哪一隻幣
            document.getElementById('tpsl-asset-id').value = assetId; 
            // 將資料庫紀錄的 TP/SL 數字，填入面板的輸入框。如果沒有設定 (null)，就填入空字串
            document.getElementById('modal-tp-input').value = tpPrice || '';
            document.getElementById('modal-sl-input').value = slPrice || '';
            
            // ----------------------------------------------------
            // 下方是保留的「動態損益看板」與「市價抓取」邏輯
            // ----------------------------------------------------
            const row = document.querySelector(`.portfolio-row[data-symbol="${symbol.toLowerCase()}"][data-mode="futures"]`);
            let latestPrice = 0;
            if (row) {
                const priceText = row.querySelector('.current-price').innerText;
                latestPrice = parseFloat(priceText.replace('$', '').replace(/,/g, ''));
            }
            
            currentModalAvgCost = parseFloat(avgCost);
            currentModalPrice = latestPrice;

            document.getElementById('close-symbol-display').innerText = symbol.toUpperCase();
            document.getElementById('close-unit-display').innerText = symbol.toUpperCase().replace('USDT', '');
            
            document.getElementById('close-slider').value = 100;
            document.getElementById('close-percentage-display').innerText = '100%';
            document.getElementById('close-amount-input').value = parseFloat(totalAmount).toFixed(6);
            
            updateClosePnL(totalAmount);
            
            new bootstrap.Modal(document.getElementById('closeFuturesModal')).show();
        }

        // 11. 平倉滑桿與輸入框連動
        function syncCloseAmount(percentage) {
            const maxAmount = parseFloat(document.getElementById('close-max-amount').value);
            const calcAmount = maxAmount * (percentage / 100);
            
            document.getElementById('close-percentage-display').innerText = percentage + '%';
            document.getElementById('close-amount-input').value = calcAmount.toFixed(6);
            
            updateClosePnL(calcAmount); // 👈 拉動時即時算錢
        }

        // 12. 平倉輸入框與滑桿連動
        function syncCloseSlider(inputValue) {
            const maxAmount = parseFloat(document.getElementById('close-max-amount').value);
            let currentAmount = parseFloat(inputValue) || 0;
            
            if (currentAmount > maxAmount) { 
                currentAmount = maxAmount; 
                document.getElementById('close-amount-input').value = maxAmount.toFixed(6); 
            }
            
            let percentage = (currentAmount / maxAmount) * 100;
            document.getElementById('close-slider').value = percentage;
            document.getElementById('close-percentage-display').innerText = Math.round(percentage) + '%';
            
            updateClosePnL(currentAmount); // 👈 輸入時即時算錢
        }

        // 13. 💰 核心算錢演算法：更新預估損益 UI
        function updateClosePnL(amount) {
            // 公式：(目前市價 - 平均成本) * 欲平倉數量
            const pnl = (currentModalPrice - currentModalAvgCost) * amount;
            const display = document.getElementById('close-pnl-display');
            
            if (pnl >= 0) {
                display.className = 'fw-bold fs-4 text-success';
                display.innerText = '+$ ' + pnl.toFixed(2);
            } else {
                display.className = 'fw-bold fs-4 text-danger';
                display.innerText = '-$ ' + Math.abs(pnl).toFixed(2);
            }
        }
        
    </script>
            </div> </div> </div> 
    
    <div class="modal fade" id="closeFuturesModal" tabindex="-1" data-bs-theme="dark">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary shadow-lg">
                
                <div class="modal-header border-secondary border-opacity-50">
                    <h5 class="modal-title fw-bold text-light"><i class="bi bi-sliders me-2 text-info"></i>合約部位管理</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <form method="POST" action="" id="close-futures-form">
                    <input type="hidden" name="action" value="sell">
                    <input type="hidden" name="trade_mode" value="futures">
                    <input type="hidden" name="asset_id" id="close-asset-id">
                    <input type="hidden" id="close-max-amount">

                    <div class="modal-body py-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-secondary fw-bold">交易標的:</span>
                            <span class="text-light fw-bold fs-4" id="close-symbol-display">BTCUSDT</span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center bg-black p-3 rounded border border-secondary border-opacity-50 mb-3 shadow-sm">
                            <span class="text-secondary fw-bold">預估平倉損益 (Est. PnL)</span>
                            <span class="fw-bold fs-4 text-secondary" id="close-pnl-display">$ 0.00</span>
                        </div>

                        <h6 class="text-info fw-bold mb-3"><i class="bi bi-pie-chart-fill me-2"></i>市價平倉比例設定</h6>
                        <div class="mb-3">
                            <label class="form-label text-secondary small fw-bold d-flex justify-content-between">
                                <span>欲平倉數量</span>
                                <span class="text-info" id="close-percentage-display">100%</span>
                            </label>
                            <div class="d-flex align-items-center bg-dark p-2 rounded border border-secondary border-opacity-50">
                                <input type="range" class="form-range flex-grow-1" id="close-slider" min="1" max="100" step="1" value="100" oninput="syncCloseAmount(this.value)">
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="input-group">
                                <input type="number" step="0.000001" min="0.000001" class="form-control trading-input" name="amount" id="close-amount-input" required oninput="syncCloseSlider(this.value)">
                                <span class="input-group-text trading-input fw-bold" id="close-unit-display">BTC</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-secondary border-opacity-50 justify-content-between bg-dark py-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-sm btn-primary fw-bold px-4 shadow-sm"><i class="bi bi-check2-circle me-1"></i>執行平倉</button>
                    </div>
                </form> <form method="POST" action="" id="tpsl-form">
                    <input type="hidden" name="action" value="update_tpsl">
                    <input type="hidden" name="asset_id" id="tpsl-asset-id">
                    
                    <div class="modal-body pt-0 pb-4">
                        <hr class="border-secondary border-opacity-50 mt-0 mb-3">
                        <h6 class="text-secondary fw-bold mb-3"><i class="bi bi-shield-lock-fill me-2"></i>自動止盈止損 (TP/SL)</h6>
                        
                        <div class="row g-2 mb-2">
                            <div class="col-5">
                                <label class="form-label text-secondary small fw-bold">止盈觸發價 (TP)</label>
                                <input type="number" step="0.0001" class="form-control trading-input text-success" name="tp_price" id="modal-tp-input" placeholder="留白不設定">
                            </div>
                            <div class="col-5">
                                <label class="form-label text-secondary small fw-bold">止損觸發價 (SL)</label>
                                <input type="number" step="0.0001" class="form-control trading-input text-danger" name="sl_price" id="modal-sl-input" placeholder="留白不設定">
                            </div>
                            <div class="col-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-secondary w-100 p-2" title="儲存條件"><i class="bi bi-save"></i></button>
                            </div>
                        </div>
                        <div class="text-secondary mt-2" style="font-size: 0.75rem;">
                            * 當最新市價觸及設定價格時，背景風控引擎將自動以市價平倉 <strong class="text-warning">100% 倉位</strong>。
                        </div>
                    </div>
                </form> </div>
        </div>
    </div>
    <div class="modal fade" id="fundsModal" tabindex="-1" aria-labelledby="fundsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-light border-secondary shadow-lg">
                <div class="modal-header border-secondary border-opacity-50">
                    <h5 class="modal-title fw-bold text-success" id="fundsModalLabel">
                        <i class="bi bi-wallet2 me-2 text-warning"></i>模擬資金管理中心
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="adjust_balance">
                    <div class="modal-body py-4">
                        <p class="text-secondary small mb-3">你可以在此隨時調整、充值或重置你的模擬倉位資金。此欄位將直接與資料庫進行對接更新。</p>
                        
                        <div class="mb-2">
                            <label class="form-label text-secondary small fw-bold">設定全新可交易資金額度 (USDT)</label>
                            <input type="number" class="form-control trading-input text-warning fw-bold fs-4 py-2" name="new_balance" value="<?= intval($userInfo['balance']) ?>" min="1000" max="10000000" required>
                            <div class="form-text text-secondary small mt-2">
                                <i class="bi bi-info-circle me-1"></i> 最低限額 $1,000，最高上限 $10,000,000 USDT。
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary border-opacity-50">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-sm btn-success fw-bold px-4">確認變更金流</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js')
                .then(registration => {
                    console.log('PWA ServiceWorker 註冊成功:', registration.scope);
                })
                .catch(error => {
                    console.log('PWA ServiceWorker 註冊失敗:', error);
                });
        });
    }
</script>
</body>
</html>
