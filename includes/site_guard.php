<?php
// site_guard.php - Công tắc bật/tắt toàn bộ website (do Master Admin điều khiển tại pages/master.php)
// Khi TẮT: mọi trang trả về 404 giống link chết, chỉ trình duyệt đã đăng nhập Master Admin mới vào được.

define('HVU_SITE_OFF_FLAG', dirname(__DIR__) . '/data/site_disabled.lock');
define('HVU_MASTER_AUTH_FILE', dirname(__DIR__) . '/data/master_auth.php');

function hvu_site_enabled() {
    return !is_file(HVU_SITE_OFF_FLAG);
}

function hvu_set_site_enabled($enabled) {
    if ($enabled) {
        if (is_file(HVU_SITE_OFF_FLAG)) @unlink(HVU_SITE_OFF_FLAG);
    } else {
        if (!is_dir(dirname(HVU_SITE_OFF_FLAG))) @mkdir(dirname(HVU_SITE_OFF_FLAG), 0755, true);
        file_put_contents(HVU_SITE_OFF_FLAG, (string)time());
    }
}

function hvu_is_master() {
    return !empty($_SESSION['hvu_master']);
}

function hvu_not_found() {
    http_response_code(404);
    header('Content-Type: text/html; charset=iso-8859-1');
    echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>404 Not Found</title>\n</head><body>\n<h1>Not Found</h1>\n<p>The requested URL was not found on this server.</p>\n</body></html>\n";
    exit;
}

function hvu_site_guard() {
    if (hvu_site_enabled()) return;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!hvu_is_master()) {
        hvu_not_found();
    }
}

if (!defined('HVU_SKIP_SITE_GUARD')) {
    hvu_site_guard();
}
