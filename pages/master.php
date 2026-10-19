<?php
// master.php - Master Admin: bật / tắt toàn bộ website
// Đặt mật khẩu lần đầu bằng terminal: php scripts/set_master_password.php <mật_khẩu>
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Không gọi hvu_site_guard() ở đây: trang này phải vào được cả khi website đang TẮT
define('HVU_SKIP_SITE_GUARD', true);
require_once __DIR__ . '/../includes/site_guard.php';

$hash = is_file(HVU_MASTER_AUTH_FILE) ? (require HVU_MASTER_AUTH_FILE) : '';
$configured = is_string($hash) && $hash !== '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfOk = hash_equals($_SESSION['hvu_master_csrf'] ?? '', $_POST['csrf'] ?? '');

    if ($action === 'login' && $configured) {
        if (password_verify($_POST['password'] ?? '', $hash)) {
            session_regenerate_id(true);
            $_SESSION['hvu_master'] = true;
            header('Location: master.php');
            exit;
        }
        sleep(2); // làm chậm dò mật khẩu
        $error = 'Sai mật khẩu.';
    } elseif ($action === 'logout' && $csrfOk) {
        unset($_SESSION['hvu_master']);
        header('Location: master.php');
        exit;
    } elseif (($action === 'enable' || $action === 'disable') && $csrfOk && hvu_is_master()) {
        hvu_set_site_enabled($action === 'enable');
        header('Location: master.php');
        exit;
    }
}

// Khi website đang tắt, người lạ vào trang này cũng chỉ thấy 404 (trừ form đăng nhập Master qua ?login)
if (!hvu_site_enabled() && !hvu_is_master() && !isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    hvu_not_found();
}

if (empty($_SESSION['hvu_master_csrf'])) {
    $_SESSION['hvu_master_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['hvu_master_csrf'];
$enabled = hvu_site_enabled();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Master Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            color: #0f172a;
            padding: 16px;
        }
        .card {
            width: 100%;
            max-width: 380px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            padding: 28px 24px;
            text-align: center;
        }
        h1 { font-size: 20px; margin-bottom: 18px; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 999px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .status.on { background: #dcfce7; color: #166534; }
        .status.off { background: #fee2e2; color: #991b1b; }
        .status .dot { width: 10px; height: 10px; border-radius: 50%; background: currentColor; }
        input[type=password] {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 12px;
            outline: none;
        }
        input[type=password]:focus { border-color: #2563eb; }
        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
        }
        .btn-blue { background: #2563eb; }
        .btn-green { background: #16a34a; }
        .btn-red { background: #dc2626; }
        .btn-link { background: none; color: #64748b; font-weight: 500; margin-top: 10px; }
        .error { color: #dc2626; font-size: 13px; margin-bottom: 12px; }
        .note { color: #64748b; font-size: 13px; line-height: 1.5; margin-top: 14px; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 12px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Master Admin</h1>

        <?php if (!$configured): ?>
            <p class="note">Chưa đặt mật khẩu Master. Chạy lệnh sau trên terminal hosting rồi tải lại trang:</p>
            <p class="note"><code>php scripts/set_master_password.php MatKhauCuaBan</code></p>

        <?php elseif (!hvu_is_master()): ?>
            <form method="post" action="master.php">
                <input type="hidden" name="action" value="login">
                <input type="password" name="password" placeholder="Mật khẩu Master" autofocus required>
                <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <button type="submit" class="btn-blue">Đăng nhập</button>
            </form>

        <?php else: ?>
            <div class="status <?= $enabled ? 'on' : 'off' ?>">
                <span class="dot"></span>
                <span><?= $enabled ? 'Website đang BẬT' : 'Website đang TẮT' ?></span>
            </div>
            <form method="post" action="master.php">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <?php if ($enabled): ?>
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="btn-red" onclick="return confirm('Tắt website? Mọi người truy cập sẽ thấy trang 404.')">Tắt website</button>
                <?php else: ?>
                    <input type="hidden" name="action" value="enable">
                    <button type="submit" class="btn-green">Bật website</button>
                <?php endif; ?>
            </form>
            <form method="post" action="master.php">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn-link">Đăng xuất Master</button>
            </form>
            <p class="note">Khi tắt: mọi trang (đăng nhập, cổng thi, bài thi, bảng điều khiển, chat) trả về 404. Trình duyệt đang đăng nhập Master vẫn dùng được để kiểm tra.<br>Khi website tắt, vào lại trang này bằng <code>master.php?login</code>.</p>
        <?php endif; ?>
    </div>
</body>
</html>
