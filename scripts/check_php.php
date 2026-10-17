<?php
/**
 * File kiểm tra cấu hình PHP cần thiết cho dự án
 * Truy cập: http://localhost/sinhvien/check_php.php
 * XÓA FILE NÀY SAU KHI KIỂM TRA XONG!
 */

$checks = [
    // Extension cần thiết
    [
        'name' => 'pdo',
        'type' => 'extension',
        'required' => true,
        'desc' => 'PDO (PHP Data Objects) - Kết nối database',
        'ini' => 'extension=pdo'
    ],
    [
        'name' => 'pdo_mysql',
        'type' => 'extension',
        'required' => true,
        'desc' => 'PDO MySQL Driver - Kết nối MySQL/MariaDB',
        'ini' => 'extension=pdo_mysql'
    ],
    [
        'name' => 'pdo_sqlite',
        'type' => 'extension',
        'required' => true,
        'desc' => 'PDO SQLite Driver - Kết nối SQLite dự phòng và Chat',
        'ini' => 'extension=pdo_sqlite'
    ],
    [
        'name' => 'sqlite3',
        'type' => 'extension',
        'required' => true,
        'desc' => 'SQLite3 Extension - Xử lý SQLite trực tiếp',
        'ini' => 'extension=sqlite3'
    ],
    [
        'name' => 'mysqli',
        'type' => 'extension',
        'required' => false,
        'desc' => 'MySQLi - Driver MySQL thay thế (không bắt buộc, dự án dùng PDO)',
        'ini' => 'extension=mysqli'
    ],
    [
        'name' => 'json',
        'type' => 'extension',
        'required' => true,
        'desc' => 'JSON - Xử lý dữ liệu JSON (AJAX, đề thi)',
        'ini' => 'extension=json'
    ],
    [
        'name' => 'mbstring',
        'type' => 'extension',
        'required' => true,
        'desc' => 'Multibyte String - Xử lý tiếng Việt (UTF-8)',
        'ini' => 'extension=mbstring'
    ],
    [
        'name' => 'fileinfo',
        'type' => 'extension',
        'required' => true,
        'desc' => 'FileInfo - Kiểm tra loại file khi upload đề thi',
        'ini' => 'extension=fileinfo'
    ],
    [
        'name' => 'openssl',
        'type' => 'extension',
        'required' => false,
        'desc' => 'OpenSSL - Mã hóa (cần nếu kết nối DB qua SSL)',
        'ini' => 'extension=openssl'
    ],
    [
        'name' => 'session',
        'type' => 'function',
        'check' => 'session_start',
        'required' => true,
        'desc' => 'Session - Quản lý đăng nhập sinh viên',
        'ini' => '(Mặc định đã bật)'
    ],
];

$ini_settings = [
    [
        'name' => 'file_uploads',
        'expected' => '1',
        'desc' => 'Cho phép upload file (đề thi JSON)',
    ],
    [
        'name' => 'upload_max_filesize',
        'expected' => '>=2M',
        'desc' => 'Kích thước file upload tối đa',
    ],
    [
        'name' => 'post_max_size',
        'expected' => '>=8M',
        'desc' => 'Kích thước POST tối đa',
    ],
    [
        'name' => 'max_execution_time',
        'expected' => '>=30',
        'desc' => 'Thời gian thực thi tối đa (giây)',
    ],
];

// --- Kiểm tra ---
$results = [];
$allPassed = true;
$needToEnable = [];

foreach ($checks as $check) {
    $loaded = false;
    if ($check['type'] === 'extension') {
        $loaded = extension_loaded($check['name']);
    } elseif ($check['type'] === 'function') {
        $loaded = function_exists($check['check']);
    }

    $passed = $loaded || !$check['required'];
    if (!$loaded && $check['required']) {
        $allPassed = false;
        $needToEnable[] = $check;
    }

    $results[] = array_merge($check, ['loaded' => $loaded, 'passed' => $passed]);
}

// Kiểm tra kết nối DB
$dbOk = false;
$dbError = '';
try {
    $pdo = new PDO("mysql:host=localhost;dbname=ktra;charset=utf8mb4", "root", "1234");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbOk = true;
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Kiểm tra cấu hình PHP</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 800px; margin: 30px auto; background: #f5f5f5; padding: 20px; }
        h1 { color: #333; border-bottom: 2px solid #0056b3; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #0056b3; color: white; }
        .ok { color: #28a745; font-weight: bold; }
        .fail { color: #dc3545; font-weight: bold; }
        .warn { color: #ffc107; font-weight: bold; }
        .optional { color: #6c757d; }
        .box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .box.error { border-left: 4px solid #dc3545; }
        .box.success { border-left: 4px solid #28a745; }
        .box.info { border-left: 4px solid #0056b3; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 3px; font-size: 13px; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
    </style>
</head>
<body>
    <h1>🔧 Kiểm tra cấu hình PHP cho dự án</h1>

    <div class="box info">
        <strong>PHP Version:</strong> <?= phpversion() ?><br>
        <strong>php.ini:</strong> <code><?= php_ini_loaded_file() ?></code><br>
        <strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?>
    </div>

    <!-- KẾT NỐI DATABASE -->
    <h2>🗄️ Kết nối Database</h2>
    <?php if ($dbOk): ?>
        <div class="box success">
            <span class="ok">✅ Kết nối MySQL thành công!</span> (localhost / ktra)
        </div>
    <?php else: ?>
        <div class="box error">
            <span class="fail">❌ Kết nối MySQL thất bại!</span><br>
            <strong>Lỗi:</strong> <?= htmlspecialchars($dbError) ?><br><br>
            <strong>Kiểm tra:</strong>
            <ul>
                <li>MySQL/MariaDB đã khởi động chưa?</li>
                <li>Database <code>ktra</code> đã tạo chưa?</li>
                <li>User <code>root</code> / password <code>1234</code> đúng chưa?</li>
            </ul>
        </div>
    <?php endif; ?>

    <!-- EXTENSIONS -->
    <h2>📦 Extensions PHP</h2>
    <table>
        <tr>
            <th>Extension</th>
            <th>Mô tả</th>
            <th>Trạng thái</th>
            <th>Cần mở trong php.ini</th>
        </tr>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><strong><?= $r['name'] ?></strong></td>
            <td><?= $r['desc'] ?></td>
            <td>
                <?php if ($r['loaded']): ?>
                    <span class="ok">✅ Đã bật</span>
                <?php elseif ($r['required']): ?>
                    <span class="fail">❌ Chưa bật</span>
                <?php else: ?>
                    <span class="optional">⚪ Không bắt buộc</span>
                <?php endif; ?>
            </td>
            <td><code><?= $r['ini'] ?></code></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <!-- CÀI ĐẶT PHP.INI -->
    <h2>⚙️ Cài đặt php.ini</h2>
    <table>
        <tr>
            <th>Cài đặt</th>
            <th>Mô tả</th>
            <th>Giá trị hiện tại</th>
            <th>Yêu cầu</th>
        </tr>
        <?php foreach ($ini_settings as $s): ?>
        <tr>
            <td><strong><?= $s['name'] ?></strong></td>
            <td><?= $s['desc'] ?></td>
            <td><code><?= ini_get($s['name']) ?></code></td>
            <td><code><?= $s['expected'] ?></code></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <!-- HƯỚNG DẪN SỬA -->
    <?php if (!empty($needToEnable)): ?>
    <h2>🛠️ Cần sửa trong php.ini</h2>
    <div class="box error">
        <p>Mở file <code><?= php_ini_loaded_file() ?></code> và <strong>bỏ dấu <code>;</code> (uncomment)</strong> các dòng sau:</p>
        <pre><?php
        foreach ($needToEnable as $ext) {
            echo ";{$ext['ini']}  ←  bỏ dấu ; thành →  {$ext['ini']}\n";
        }
        ?></pre>
        <p><strong>Sau khi sửa xong:</strong> Khởi động lại Apache/PHP (restart XAMPP/Laragon/WAMP).</p>
    </div>
    <?php else: ?>
    <div class="box success">
        <span class="ok">✅ Tất cả extensions bắt buộc đã được bật!</span>
    </div>
    <?php endif; ?>

    <div class="box info" style="margin-top: 30px; border-left-color: #dc3545;">
        <strong>⚠️ Nhớ xóa file <code>check_php.php</code> này sau khi kiểm tra xong!</strong>
    </div>
</body>
</html>
