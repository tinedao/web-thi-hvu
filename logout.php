<?php
session_start();
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['ws_token'])) {
    try {
        db_query("DELETE FROM `user_tokens` WHERE `token` = ?", [$_SESSION['ws_token']]);
        $chatDb = get_chat_db();
        $chatDb->prepare("DELETE FROM `user_tokens` WHERE `token` = ?")->execute([$_SESSION['ws_token']]);
    } catch (Exception $e) {}
}

// Xóa tất cả các biến session
session_unset();
// Hủy session
session_destroy();
// Chuyển hướng về trang đăng nhập
header("Location: login.php");
exit();
?>