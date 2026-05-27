<?php
// index.php
// 🚀 啟動會話機制，必須放在檔案最頂端，前方不能有任何 HTML 輸出
session_start();

// 🔐 安全防護：檢查使用者是否持有登入 Session。若未授權，強制踢回登入頁面！
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 動態從小腦（Session）中提領當前登入者的資訊
$current_user_id = $_SESSION['user_id'];
$trade_message = '';

// 初始化資料庫連線
$db_host = '127.0.0.1';
$db_name = 'crypto_trading_db';
$db_user = 'root';
$db_pass = '';

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

    // 🚀 分流二：處理「買入」或「賣出」
    elseif ($action === 'buy' || $action === 'sell') {
        $asset_id = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;

        if ($amount > 0) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT current_price, symbol FROM Assets WHERE asset_id = ? AND status = 'trading'");
                $stmt->execute([$asset_id]);
                $asset = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$asset) { throw new Exception("找不到該資產或已下架"); }
                $total_value = $asset['current_price'] * $amount;

                if ($action === 'buy') {
                    // 【買入邏輯】
                    $stmt = $pdo->prepare("SELECT balance FROM Users WHERE user_id = ? FOR UPDATE");
                    $stmt->execute([$current_user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user['balance'] >= $total_value) {
                        // 🚀 核心升級：計算新的「平均持倉成本」
                        $stmt = $pdo->prepare("SELECT total_amount, avg_cost FROM Portfolios WHERE user_id = ? AND asset_id = ?");
                        $stmt->execute([$current_user_id, $asset_id]);
                        $port = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        $old_amount = $port ? (float)$port['total_amount'] : 0;
                        $old_cost = $port ? (float)$port['avg_cost'] : 0;
                        $new_amount = $old_amount + $amount;
                        // 公式：(原本總價值 + 這次買入總價值) / 買入後總數量
                        $new_avg_cost = (($old_amount * $old_cost) + $total_value) / $new_amount;

                        $pdo->prepare("UPDATE Users SET balance = balance - ? WHERE user_id = ?")->execute([$total_value, $current_user_id]);
                        $pdo->prepare("INSERT INTO Transactions (user_id, asset_id, tx_type, amount, price_at_tx, total_value) VALUES (?, ?, 'buy', ?, ?, ?)")->execute([$current_user_id, $asset_id, $amount, $asset['current_price'], $total_value]);
                        
                        // 寫入/更新持倉與平均成本
                        $pdo->prepare("INSERT INTO Portfolios (user_id, asset_id, total_amount, avg_cost) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_amount = ?, avg_cost = ?")->execute([$current_user_id, $asset_id, $amount, $asset['current_price'], $new_amount, $new_avg_cost]);
                        
                        $trade_message = "<div class='alert alert-success bg-success text-light border-0 mb-3'>🎉 成功買入 {$amount} 單位 {$asset['symbol']}！</div>";
                    } else {
                        throw new Exception("餘額不足！需要 $ " . number_format($total_value, 2));
                    }

                } elseif ($action === 'sell') {
                    // 【賣出邏輯】
                    $stmt = $pdo->prepare("SELECT total_amount FROM Portfolios WHERE user_id = ? AND asset_id = ? FOR UPDATE");
                    $stmt->execute([$current_user_id, $asset_id]);
                    $portfolio = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($portfolio && $portfolio['total_amount'] >= $amount) {
                        // 賣出不會改變平均成本，只會減少數量
                        $pdo->prepare("UPDATE Portfolios SET total_amount = total_amount - ? WHERE user_id = ? AND asset_id = ?")->execute([$amount, $current_user_id, $asset_id]);
                        $pdo->prepare("INSERT INTO Transactions (user_id, asset_id, tx_type, amount, price_at_tx, total_value) VALUES (?, ?, 'sell', ?, ?, ?)")->execute([$current_user_id, $asset_id, $amount, $asset['current_price'], $total_value]);
                        $pdo->prepare("UPDATE Users SET balance = balance + ? WHERE user_id = ?")->execute([$total_value, $current_user_id]);
                        
                        $trade_message = "<div class='alert alert-info bg-info text-dark border-0 mb-3'>💰 成功賣出 {$amount} 單位 {$asset['symbol']}！</div>";
                    } else {
                        throw new Exception("庫存不足！你沒有足夠的 {$asset['symbol']} 可以賣出。");
                    }
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $trade_message = "<div class='alert alert-danger bg-danger text-light border-0 mb-3'>❌ 交易失敗: " . $e->getMessage() . "</div>";
            }
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
$stmt = $pdo->prepare("SELECT t.*, a.symbol FROM Transactions t JOIN Assets a ON t.asset_id = a.asset_id WHERE t.user_id = ? ORDER BY T.created_at DESC LIMIT 5");
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
    SELECT P.asset_id, P.total_amount, P.avg_cost, A.symbol, A.name, A.current_price 
    FROM Portfolios P 
    JOIN Assets A ON P.asset_id = A.asset_id 
    WHERE P.user_id = ? AND P.total_amount > 0
");
$stmt->execute([$current_user_id]);
$active_portfolios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 為了給左側列表顯示與 JS 使用的簡化陣列
$my_holdings = [];
foreach ($active_portfolios as $p) {
    $my_holdings[$p['asset_id']] = $p['total_amount'];
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>專業級量化資產交易終端</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
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
    </style>
</head>
<body>

    <nav class="navbar navbar-dark sticky-top shadow-sm py-2">

        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center fw-bold text-success" href="#">
                <i class="bi bi-lightning-charge-fill me-2"></i> QUANT TERMINAL Pro
            </a>
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
                        <li><a class="dropdown-item text-danger py-2 fw-bold" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>安全登出</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav><div class="container-fluid">
        <div class="row">

    <div class="col-md-3 col-lg-2 sidebar p-0">
    <div class="p-3 text-secondary small fw-bold border-bottom border-secondary border-opacity-25 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>市場追蹤清單</span>
    </div>

    <div class="p-2 border-bottom border-secondary border-opacity-25 bg-dark">
        <form method="GET" action="index.php" class="m-0">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-transparent border-secondary text-secondary"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control bg-transparent border-secondary text-light" name="search" placeholder="搜尋代碼或名稱..." value="<?= htmlspecialchars($search_keyword) ?>">
                <?php if ($search_keyword !== ''): ?>
                    <a href="index.php" class="btn btn-outline-danger border-secondary"><i class="bi bi-x-lg"></i></a>
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
                            <button type="button" class="btn btn-outline-secondary timeframe-btn" onclick="changeTimeframe('15m')">15m</button>
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

            <div class="btn-group w-100 mb-3" role="group">
                <input type="radio" class="btn-check" name="btnradio" id="btn-buy-tab" autocomplete="off" checked onclick="setTradeMode('buy')">
                <label class="btn btn-outline-success fw-bold" for="btn-buy-tab">買入 (Buy)</label>

                <input type="radio" class="btn-check" name="btnradio" id="btn-sell-tab" autocomplete="off" onclick="setTradeMode('sell')">
                <label class="btn btn-outline-danger fw-bold" for="btn-sell-tab">賣出 (Sell)</label>
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary small fw-bold d-flex justify-content-between">
                    <span>委託數量</span>
                    <span id="holding-hint" class="text-info" style="cursor: pointer;" onclick="fillMaxAmount()">可用持倉: 0.00</span>
                </label>
                <div class="input-group">
                    <input type="number" step="0.0001" min="0.0001" class="form-control trading-input fs-5" name="amount" id="trade-amount" placeholder="0.00" oninput="calculateTotal()" required>
                    <span class="input-group-text trading-input fw-bold" id="amount-unit">BTC</span>
                </div>
            </div>

            <div class="card bg-dark bg-opacity-50 border-secondary p-3 mb-4">
                <div class="d-flex justify-content-between small text-secondary mb-1">
                    <span>預估單價:</span>
                    <span id="est-price">$ 0.00</span>
                </div>
                <div class="d-flex justify-content-between fw-bold fs-5 text-light border-top border-secondary border-opacity-25 pt-2 mt-2">
                    <span>預估總額:</span>
                    <span class="text-warning" id="est-total">$ 0.00</span>
                </div>
            </div>
            
            <button type="submit" class="btn btn-buy w-100 py-3 fs-5 shadow-sm" id="submit-btn"><i class="bi bi-box-arrow-in-right me-2"></i>執行買入委託</button>
        </form>
    </div>
    <div class="text-secondary small text-center mt-3 border-top border-secondary border-opacity-25 pt-2">
        <i class="bi bi-shield-check me-1 text-success"></i> 已啟用 SSL 加密防護與安全網關
    </div>
</div>
                            </div>
                            <div class="text-secondary small text-center mt-3 border-top border-secondary border-opacity-25 pt-2">
                                <i class="bi bi-shield-check me-1 text-success"></i> 已啟用 SSL 加密防護與安全網關
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <div class="card p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>我的投資組合 (Unrealized PnL)</h5>
        <div class="table-responsive">
            <table class="table table-dark table-hover align-middle mb-0">
                <thead class="text-secondary small">
                    <tr>
                        <th>標的</th>
                        <th>持倉數量</th>
                        <th>平均成本</th>
                        <th>當前市價</th>
                        <th>未實現損益 (USDT)</th>
                        <th>報酬率 (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($active_portfolios)): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">目前尚無任何持倉</td></tr>
                    <?php else: ?>
                        <?php foreach ($active_portfolios as $port): 
                            $cost_val = $port['total_amount'] * $port['avg_cost'];
                            $current_val = $port['total_amount'] * $port['current_price'];
                            $pnl = $current_val - $cost_val;
                            $pnl_percent = ($cost_val > 0) ? ($pnl / $cost_val) * 100 : 0;
                            
                            $pnl_class = ($pnl >= 0) ? 'text-success' : 'text-danger';
                            $pnl_sign = ($pnl >= 0) ? '+' : '';
                        ?>
                            <tr id="portfolio-row-<?= strtolower($port['symbol']) ?>" 
                                data-amount="<?= $port['total_amount'] ?>" 
                                data-cost="<?= $port['avg_cost'] ?>">
                                <td>
                                    <div class="fw-bold text-light"><?= htmlspecialchars($port['symbol']) ?></div>
                                </td>
                                <td class="fw-bold"><?= number_format($port['total_amount'], 4) ?></td>
                                <td class="text-secondary">$ <?= number_format($port['avg_cost'], 4) ?></td>
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
        // 全域狀態管理
        let currentAssetId = 1;
        let currentInterval = '1m';
        let currentPrice = 0;
        let binanceSocket = null;

        

        // 1. 初始化 TradingView Lightweight Chart
        const chart = LightweightCharts.createChart(document.getElementById('tvchart'), {
            layout: { background: { type: 'solid', color: '#161a1e' }, textColor: '#d1d4dc' },
            grid: { vertLines: { color: '#24292e' }, horzLines: { color: '#24292e' } },
            timeScale: { timeVisible: true, secondsVisible: false, timeFormat: '%m/%d %H:%M' }
        });

        const candleSeries = chart.addCandlestickSeries({
            upColor: '#00c087', downColor: '#f6465d', borderVisible: false, wickUpColor: '#00c087', wickDownColor: '#f6465d'
        });

        // 🚀 修正一：動態切換商品標題與下單單位
        function selectAsset(assetId) {
            currentAssetId = assetId;
            
            // 🚀 加上這行：讓隱藏的下單表單知道現在切換到哪一個商品了！
            document.getElementById('form-asset-id').value = assetId;
            
            // 1. 處理左側列表的 Active 亮燈效果
            document.querySelectorAll('.asset-item').forEach(el => el.classList.remove('active'));
            
            // 1. 處理左側列表的 Active 亮燈效果
            document.querySelectorAll('.asset-item').forEach(el => el.classList.remove('active'));
            const selectedEl = document.querySelector(`.asset-item[data-id='${assetId}']`);
            if (selectedEl) selectedEl.classList.add('active');

            // 2. 取得商品代碼與名稱
            currentSymbol = selectedEl.getAttribute('data-symbol');
            const assetName = selectedEl.getAttribute('data-name');
            
            // 3. 動態更新上方大看板 (是加密貨幣才加 /USDT)
            const displaySymbol = currentSymbol.includes('USDT') ? currentSymbol.replace('USDT', '/USDT') : currentSymbol;
            document.getElementById('active-asset').innerHTML = `${displaySymbol} <span class="fs-6 text-secondary ms-2">${assetName}</span>`;
            
            // 4. 動態更新右側委託單的「單位」
            const unitName = currentSymbol.replace('USDT', '');
            document.getElementById('amount-unit').innerText = unitName;

            // 5. 重新載入圖表與持倉
            fetchKlinesAndDraw('1m'); 
            updateHoldingHint();
        }

        // 🚀 修正二：加入「快取破壞者」與「休市防呆機制」
        async function fetchKlinesAndDraw(interval) {
            try {
                // 💡 在網址後面加上 &t=${Date.now()}，強制瀏覽器每次都抓最新資料，無視幽靈快取！
                const response = await fetch(`api_klines.php?asset_id=${currentAssetId}&interval=${interval}&t=${Date.now()}`);
                const klineData = await response.json();

                if (klineData && klineData.length > 0) {
                    // 有資料：正常畫圖並更新最新價格
                    candleSeries.setData(klineData);
                    chart.timeScale().fitContent();
                    
                    const lastPrice = klineData[klineData.length - 1].close;
                    updatePriceUI(lastPrice);
                } else {
                    // 沒資料 (例如美股未開盤)：清空圖表，但從左側列表抓取歷史收盤價，防止價格變 $ 0.00
                    candleSeries.setData([]); 
                    const sidebarPriceStr = document.getElementById(`price-val-${currentAssetId}`).innerText.replace('$', '').replace(/,/g, '');
                    updatePriceUI(parseFloat(sidebarPriceStr) || 0);
                }
                
                startRealtimeUpdates(interval);
                resetChartViewport();
                
            } catch (error) {
                console.error("K線載入失敗:", error);
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

            // 🚀 核心：即時尋找投資組合列表中對應的列，並重新計算 PnL
            const portfolioRow = document.getElementById(`portfolio-row-${currentSymbol.toLowerCase()}`);
            if (portfolioRow) {
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
            }
        }

        // 6. 計算預估總額表單連動
        function calculateTotal() {
            const amount = parseFloat(document.getElementById('trade-amount').value) || 0;
            const total = amount * currentPrice;
            document.getElementById('est-total').innerText = '$ ' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
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

        // 切換買/賣模式的 UI 邏輯
        function setTradeMode(mode) {
            document.getElementById('form-action').value = mode;
            const btn = document.getElementById('submit-btn');
            if (mode === 'buy') {
                btn.className = "btn btn-success w-100 py-3 fs-5 shadow-sm";
                btn.innerHTML = '<i class="bi bi-box-arrow-in-right me-2"></i>執行買入委託';
            } else {
                btn.className = "btn btn-danger w-100 py-3 fs-5 shadow-sm";
                btn.innerHTML = '<i class="bi bi-box-arrow-right me-2"></i>執行賣出委託';
            }
            updateHoldingHint();
        }

        // 根據目前選定的資產，更新右上角的「可用持倉」文字
        function updateHoldingHint() {
            const amount = myHoldings[currentAssetId] || 0;
            document.getElementById('holding-hint').innerText = `可用持倉: ${parseFloat(amount).toFixed(4)}`;
        }

        // 點擊「可用持倉」時，一鍵填入最大數量 (方便 All-in 或清倉)
        function fillMaxAmount() {
            const amount = myHoldings[currentAssetId] || 0;
            document.getElementById('trade-amount').value = parseFloat(amount).toFixed(4);
            calculateTotal();
        }
    

        
    </script>
    </div> </div><div class="modal fade" id="fundsModal" tabindex="-1" aria-labelledby="fundsModalLabel" aria-hidden="true">
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
</body>
</html>