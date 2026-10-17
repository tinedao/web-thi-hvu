<?php
// role_switch.php - Chuyển đổi vai trò linh hoạt giữa Thi (exam) và Hỗ trợ (support)
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$new_role = $_GET['role'] ?? 'exam';
if (!in_array($new_role, ['exam', 'support'])) {
    $new_role = 'exam';
}

$_SESSION['role'] = $new_role;

// Cập nhật token trong database nếu có
if (!empty($_SESSION['ws_token'])) {
    try {
        db_update('user_tokens', ['role' => $new_role], ['token' => $_SESSION['ws_token']]);
    } catch (Exception $e) {}
}

if ($new_role === 'support') {
    header("Location: dashboard.php");
} else {
    header("Location: portal.php");
}
exit();
