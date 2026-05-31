<?php
// logout.php
session_start();

// 清除所有的 Session 變數
$_SESSION = array();

// 徹底銷毀伺服器端的 Session 檔案
session_destroy();

// 將使用者導向回登入頁面
header("Location: login");
exit;
?>