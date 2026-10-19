<?php
// websocket_config.php - Cấu hình kết nối WebSocket cho Client và Server

$http_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$host_only = explode(':', $http_host)[0];

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

$ws_proto = $is_https ? 'wss://' : 'ws://';
$ws_port = getenv('WS_PORT') ?: 8085;

// Tự động nhận diện môi trường (Local vs Hosting sinhvienhvu.top):
if ($host_only === 'localhost' || $host_only === '127.0.0.1') {
    // Môi trường Local / Dev: dùng cổng trực tiếp
    $ws_url = "ws://{$host_only}:{$ws_port}";
} else {
    // Môi trường Hosting / Production (sinhvienhvu.top):
    if (getenv('WS_PUBLIC_URL')) {
        $ws_url = getenv('WS_PUBLIC_URL');
    } elseif (getenv('WS_USE_PORT')) {
        $ws_url = "{$ws_proto}{$host_only}:{$ws_port}";
    } else {
        // Tự động kết nối qua /ws (Port 443 SSL an toàn, không bị firewall chặn)
        $ws_url = "{$ws_proto}{$host_only}/ws";
    }

    // Đảm bảo Node.js WebSocket server đang chạy (hosting có thể tự tắt tiến trình khi rảnh)
    if (stripos(PHP_OS, 'WIN') !== 0) {
        require_once __DIR__ . '/includes/ws_watchdog.php';
        hvu_ws_watchdog((int)$ws_port);
    }
}

return [
    'port' => (int)$ws_port,
    'host' => '0.0.0.0',
    'client_url' => $ws_url
];
