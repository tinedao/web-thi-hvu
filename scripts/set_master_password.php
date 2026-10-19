<?php
// set_master_password.php - Đặt / đổi mật khẩu Master Admin (chỉ chạy bằng terminal)
// Dùng: php scripts/set_master_password.php <mật_khẩu>

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('HVU_SKIP_SITE_GUARD', true);
require_once __DIR__ . '/../includes/site_guard.php';

$password = $argv[1] ?? '';
if (strlen($password) < 8) {
    fwrite(STDERR, "Mật khẩu phải có ít nhất 8 ký tự. Dùng: php scripts/set_master_password.php <mật_khẩu>\n");
    exit(1);
}

$dir = dirname(HVU_MASTER_AUTH_FILE);
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Lưu dạng file PHP để truy cập trực tiếp qua web không lộ nội dung
$hash = password_hash($password, PASSWORD_DEFAULT);
file_put_contents(HVU_MASTER_AUTH_FILE, "<?php\nreturn " . var_export($hash, true) . ";\n");
@chmod(HVU_MASTER_AUTH_FILE, 0600);

echo "Đã đặt mật khẩu Master. Đăng nhập tại: /pages/master.php\n";
