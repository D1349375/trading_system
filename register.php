<?php
// register.php
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $initial_balance = isset($_POST['initial_balance']) ? (float)$_POST['initial_balance'] : 100000.00; // 🎯 實作新想法：允許自訂初始資金！

    if (empty($username) || empty($email) || empty($password)) {
        $message = "<div class='alert alert-danger border-0 bg-danger text-light'>❌ 所有欄位皆為必填！</div>";
    } elseif ($password !== $confirm_password) {
        $message = "<div class='alert alert-danger border-0 bg-danger text-light'>❌ 兩次輸入的密碼不一致！</div>";
    } else {
        try {
            // 檢查帳號或 Email 是否已被註冊
            $stmt = $pdo->prepare("SELECT user_id FROM Users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $message = "<div class='alert alert-danger border-0 bg-danger text-light'>❌ 帳號或 Email 已被註冊！</div>";
            } else {
                // 🔐 安全高規格：使用 PHP 官方推薦的 BCRYPT 進行密碼雜湊加密
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                
                // 寫入新使用者，並設定初始資金
                $stmt = $pdo->prepare("INSERT INTO Users (username, email, password, balance, role) VALUES (?, ?, ?, ?, 'member')");
                $stmt->execute([$username, $email, $hashed_password, $initial_balance]);
                
                $message = "<div class='alert alert-success border-0 bg-success text-light'>🎉 註冊成功！3秒後自動導向登入頁面...</div>";
                header("Refresh: 3; url=login.php");
            }
        } catch (Exception $e) {
            $message = "<div class='alert alert-danger border-0 bg-danger text-light'>❌ 系統錯誤: " . $e->getMessage() . "</div>";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUANT TERMINAL - 建立帳戶</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #0b0e11; color: #eaecef; font-family: sans-serif; }
        .auth-card { background-color: #161a1e; border: 1px solid #2f3336; border-radius: 12px; width: 100%; max-width: 450px; }
        .form-control { background-color: #2b3139 !important; border: 1px solid #474f59 !important; color: white !important; }
        .form-control:focus { border-color: #00c087 !important; box-shadow: none !important; }
        .btn-success { background-color: #00c087; border: none; font-weight: bold; }
        .btn-success:hover { background-color: #00a875; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">

<div class="auth-card p-4 shadow-lg m-3">
    <div class="text-center mb-4">
        <h3 class="fw-bold text-success"><i class="bi bi-lightning-charge-fill me-2"></i>QUANT TERMINAL</h3>
        <p class="text-secondary small">歡迎加入，開啟你的高頻模擬量化交易之旅</p>
    </div>

    <?= $message ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label text-secondary small fw-bold">使用者帳號 (Username)</label>
            <input type="text" class="form-control" name="username" required placeholder="請輸入登入帳號">
        </div>
        <div class="mb-3">
            <label class="form-label text-secondary small fw-bold">電子郵件 (Email)</label>
            <input type="email" class="form-control" name="email" required placeholder="crypto@example.com">
        </div>
        <div class="mb-3">
            <label class="form-label text-secondary small fw-bold">初始模擬金設定 (USDT)</label>
            <input type="number" class="form-control text-warning fw-bold" name="initial_balance" value="100000" min="1000" max="10000000">
            <div class="form-text text-secondary small">最低 $1,000，最高 $10,000,000 USDT</div>
        </div>
        <div class="mb-3">
            <label class="form-label text-secondary small fw-bold">登入密碼 (Password)</label>
            <input type="password" class="form-control" name="password" required placeholder="請輸入密碼">
        </div>
        <div class="mb-4">
            <label class="form-label text-secondary small fw-bold">確認密碼 (Confirm Password)</label>
            <input type="password" class="form-control" name="confirm_password" required placeholder="請再次輸入密碼">
        </div>

        <button type="submit" class="btn btn-success w-100 py-2.5 mb-3"><i class="bi bi-person-plus-fill me-2"></i>註冊全新帳戶</button>
        
        <div class="text-center small">
            <span class="text-secondary">已經有帳戶了？</span> <a href="login.php" class="text-success text-decoration-none fw-bold">立即登入</a>
        </div>
    </form>
</div>

</body>
</html>
