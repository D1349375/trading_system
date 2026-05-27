<?php
// login.php
session_start();

// 如果使用者已經登入，直接導向交易主頁，不需要重複登入
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

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

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account = trim($_POST['account']); // 可以是用戶名或 Email
    $password = $_POST['password'];

    if (empty($account) || empty($password)) {
        $message = "<div class='alert alert-danger border-0 bg-danger text-light'>❌ 請輸入帳號與密碼！</div>";
    } else {
        try {
            // 支援使用 帳號 或 Email 登入 (提高 UX 體驗)，使用 PDO 預處理防止 SQL Injection
            $stmt = $pdo->prepare("SELECT * FROM Users WHERE username = ? OR email = ?");
            $stmt->execute([$account, $account]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 使用 password_verify 比對 Bcrypt 雜湊值
            if ($user && password_verify($password, $user['password'])) {
                // 驗證成功，將使用者關鍵資訊寫入 Session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                $message = "<div class='alert alert-success border-0 bg-success text-light'>🔓 登入成功！正在導向終端機...</div>";
                header("Refresh: 1; url=index.php");
            } else {
                $message = "<div class='alert alert-danger border-0 bg-danger text-light'>❌ 帳號或密碼錯誤！</div>";
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
    <title>QUANT TERMINAL - 會員登入</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #0b0e11; color: #eaecef; font-family: sans-serif; }
        .auth-card { background-color: #161a1e; border: 1px solid #2f3336; border-radius: 12px; width: 100%; max-width: 420px; }
        .form-control { background-color: #2b3139 !important; border: 1px solid #474f59 !important; color: white !important; }
        .form-control:focus { border-color: #00c087 !important; box-shadow: none !important; }
        .btn-success { background-color: #00c087; border: none; font-weight: bold; }
        .btn-success:hover { background-color: #00a875; }
        .btn-google { background-color: #ffffff; color: #212529; border: none; font-weight: 500; transition: all 0.2s; }
        .btn-google:hover { background-color: #f1f3f4; }
        .divider { display: flex; align-items: center; text-align: center; color: #474f59; font-size: 12px; }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid #2f3336; }
        .divider:not(:empty)::before { margin-right: .5em; }
        .divider:not(:empty)::after { margin-left: .5em; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">

<div class="auth-card p-4 shadow-lg m-3">
    <div class="text-center mb-4">
        <h3 class="fw-bold text-success"><i class="bi bi-lightning-charge-fill me-2"></i>QUANT TERMINAL</h3>
        <p class="text-secondary small">請輸入憑證以連線至量化交易終端</p>
    </div>

    <?= $message ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label text-secondary small fw-bold">帳號 / 電子郵件 (Account / Email)</label>
            <input type="text" class="form-control" name="account" required placeholder="使用者名稱或 Email">
        </div>
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label text-secondary small fw-bold mb-0">安全密碼 (Password)</label>
                <a href="forgot_password.php" class="text-success text-decoration-none small">忘記密碼？</a>
            </div>
            <input type="password" class="form-control" name="password" required placeholder="請輸入密碼">
        </div>

        <button type="submit" class="btn btn-success w-100 py-2.5 mb-3"><i class="bi bi-shield-lock-fill me-2"></i>安全授權登入</button>
        
        <div class="divider my-3">或使用第三方帳戶</div>
        
        <button type="button" class="btn btn-google w-100 py-2 mb-4 d-flex align-items-center justify-content-center" onclick="alert('Google 快捷登入介面已就緒，待後端 API 憑證設定完成後即可通電！')">
            <svg class="me-2" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.5 24c0-1.61-.15-3.16-.42-4.69H24v8.87h12.66c-.54 2.85-2.15 5.27-4.57 6.89l7.1 5.51C43.34 36.16 46.5 30.67 46.5 24z"/>
                <path fill="#FBBC05" d="M10.54 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.98-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.1-5.51c-1.97 1.32-4.5 2.11-7.79 2.11-6.26 0-11.57-4.22-13.46-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            使用 Google 帳戶登入
        </button>

        <div class="text-center small">
            <span class="text-secondary">還沒有交易帳戶？</span> <a href="register.php" class="text-success text-decoration-none fw-bold">立即註冊</a>
        </div>
    </form>
</div>

</body>
</html>