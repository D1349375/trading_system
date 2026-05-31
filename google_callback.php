<?php
session_start();
require_once __DIR__ . '/config.php';

// 1. 驗證 state(防 CSRF,Google 官方強調的關鍵步驟)
if (empty($_GET['state']) || empty($_SESSION['oauth_state'])
    || !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    http_response_code(400);
    exit('state 驗證失敗,請重新登入');
}
unset($_SESSION['oauth_state']);

if (empty($_GET['code'])) {
    exit('未取得授權碼');
}

// 2. 用授權碼換 access token
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => $_ENV['GOOGLE_CLIENT_ID'],
        'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'],
        'redirect_uri'  => $_ENV['GOOGLE_REDIRECT_URI'],
        'grant_type'    => 'authorization_code',
    ]),
]);
$token = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($token['access_token'])) {
    exit('換取 token 失敗');
}

// 3. 用 access token 取得使用者資訊
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
]);
$info = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($info['email']) || empty($info['email_verified'])) {
    exit('無法取得已驗證的 Google 信箱');
}

$google_id = $info['sub'];
$email     = $info['email'];
$name      = $info['name'] ?? explode('@', $email)[0];

// 4. 找使用者:先比對 google_id,再比對 email(避免重複帳號),都沒有才新建
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
                   $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

$stmt = $pdo->prepare("SELECT * FROM Users WHERE google_id = ? LIMIT 1");
$stmt->execute([$google_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // 沒有 google_id 對應,看看是不是已有相同 email 的帳號 → 把 Google 綁上去
    $stmt = $pdo->prepare("SELECT * FROM Users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $pdo->prepare("UPDATE Users SET google_id = ? WHERE user_id = ?")
            ->execute([$google_id, $user['user_id']]);
    } else {
        // 全新使用者:用名稱產生不重複的 username 後建立帳號
        $base = preg_replace('/[^A-Za-z0-9_]/', '', explode('@', $email)[0]) ?: 'user';
        $username = $base;
        $i = 1;
        $chk = $pdo->prepare("SELECT 1 FROM Users WHERE username = ?");
        while ($chk->execute([$username]) && $chk->fetch()) {
            $username = $base . $i++;
        }
        $pdo->prepare("INSERT INTO Users (username, email, google_id, password, role, status)
                       VALUES (?, ?, ?, NULL, 'member', 'active')")
            ->execute([$username, $email, $google_id]);
        $user = [
            'user_id'  => $pdo->lastInsertId(),
            'username' => $username,
            'role'     => 'member',
            'status'   => 'active',
        ];
    }
}

if (($user['status'] ?? 'active') !== 'active') {
    exit('此帳號已被停權');
}

// 5. 寫入 Session(三個變數對齊你的 login.php)
$_SESSION['user_id']  = $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];

// 6. 導回主頁(跟 login.php 一致用乾淨網址)
header('Location: index');
exit;