<?php
// ws_watchdog.php - Kiểm tra máy chủ WebSocket (127.0.0.1:8085) mỗi khi tải trang, tự khởi động lại nếu đã chết.
// Bổ trợ cho cron websocket/keepalive.sh: khi có người vào trang mà Node.js vừa bị hosting tắt,
// server được bật lại ngay thay vì chờ tới lần chạy cron tiếp theo.

function hvu_ws_watchdog(int $port): void
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
    if ($fp) {
        fclose($fp);
        return;
    }

    $script = realpath(__DIR__ . '/../websocket/keepalive.sh');
    if (!$script || !function_exists('exec')) {
        return;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return;
    }

    // Chống khởi động trùng khi nhiều request cùng lúc: tối đa 1 lần / 20 giây
    $lock = __DIR__ . '/../data/ws-watchdog.lock';
    if (is_file($lock) && time() - (int)@filemtime($lock) < 20) {
        return;
    }
    @touch($lock);

    @exec('/bin/bash ' . escapeshellarg($script) . ' once > /dev/null 2>&1 &');
}
