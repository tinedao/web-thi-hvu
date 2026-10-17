<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- XỬ LÝ AJAX TẬP TRUNG ---
if (isset($_GET['ajax_action'])) {
    require_once __DIR__ . '/../includes/ajax_handler.php';
    exit;
}

// Xử lý khi form đăng nhập được submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../config.php';

    $student_id = trim($_POST['student_id'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $role = $_POST['role'] ?? 'exam'; // 'exam' hoặc 'support'

    if (!in_array($role, ['exam', 'support'])) {
        $role = 'exam';
    }

    if (!empty($student_id) && !empty($fullname)) {
        // Lưu thông tin vào session
        $_SESSION['student_id'] = $student_id;
        $_SESSION['fullname'] = $fullname;
        $_SESSION['role'] = $role;
        $_SESSION['chat_sender'] = $fullname;

        // Sinh token WebSocket an toàn
        $ws_token = bin2hex(random_bytes(16));
        $_SESSION['ws_token'] = $ws_token;

        // Lưu token vào bảng user_tokens (cả SQLite/MySQL) để WebSocket Server xác thực
        try {
            db_query("DELETE FROM `user_tokens` WHERE `user_id` = ?", [$student_id]);
            db_insert('user_tokens', [
                'token' => $ws_token,
                'user_id' => $student_id,
                'role' => $role,
                'chat_id' => null,
                'created_at' => time()
            ]);
        } catch (Exception $e) {}

        // Điều hướng theo vai trò
        if ($role === 'support') {
            header('Location: dashboard.php');
        } else {
            header('Location: portal.php');
        }
        exit();
    } else {
        $error = "Vui lòng nhập mã sinh viên hợp lệ!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Cổng Thi Trực Tuyến HVU</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .login-card {
            background-color: white;
            padding: 35px 30px;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 25px;
        }
        .login-header img {
            width: 65px;
            height: auto;
            margin-bottom: 10px;
        }
        .login-header h2 {
            margin: 0;
            font-size: 20px;
            color: #003366;
            text-transform: uppercase;
            font-weight: 700;
        }
        .login-header p {
            margin: 6px 0 0 0;
            font-size: 13px;
            color: #6c757d;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #0056b3;
            box-shadow: 0 0 0 3px rgba(0,86,179,0.1);
        }
        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #004085;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            cursor: pointer;
            margin-top: 15px;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .btn-login:hover {
            background-color: #002752;
        }
        .error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            text-align: center;
        }
        .role-selector {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        .role-option {
            flex: 1;
            text-align: center;
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            user-select: none;
            transition: all 0.2s;
        }
        .role-option.active {
            border-color: #004085;
            background-color: #e8f0fe;
            color: #004085;
            font-weight: 700;
        }
        .footer-note {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 12px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <img src="../assets/img/logo.png" alt="Logo HVU" onerror="this.style.display='none'">
            <h2>CỔNG THI TRỰC TUYẾN</h2>
            <p>Hệ thống Khảo thí & Đánh giá Năng lực</p>
        </div>

        <?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Mã sinh viên / Tài khoản</label>
                <input type="text" name="student_id" id="student_id" required placeholder="Nhập mã (VD: 235D150109 hoặc 235D150052)" oninput="lookupStudent()" autocomplete="off">
            </div>

            <div class="form-group">
                <label>Họ và tên thí sinh</label>
                <input type="text" id="fullname_display" readonly placeholder="Tự động điền theo mã sinh viên" style="background: #f8f9fa; color: #495057;">
                <input type="hidden" name="fullname" id="fullname">
            </div>

            <div class="form-group">
                <label>Vai trò truy cập</label>
                <input type="hidden" name="role" id="selected_role" value="exam">
                <div class="role-selector">
                    <div class="role-option active" id="role-exam" onclick="selectRole('exam')">
                        📝 Thí sinh (Thi)
                    </div>
                    <div class="role-option" id="role-support" onclick="selectRole('support')">
                        🛠️ Cán bộ hỗ trợ
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-login" id="btn-submit">Đăng nhập</button>
        </form>

        <div class="footer-note">
            Đại học Hùng Vương &bull; Hệ thống Demo Khảo Thí
        </div>
    </div>

    <script>
        // Danh bạ sinh viên được hỗ trợ
        const studentDB = {
            '235D150109': 'Đào Quang Tiến',
            '235D150052': 'Nguyễn Hoàng Nam',
            '235D160025': 'Đặng Trần Trung',
            '235D150110': 'Hà Văn Nghị',
            '235D160028': 'Hà Minh Đức',
            '235D150115': 'Hoàng Tuấn Ngọc',
            '245D100088': 'Bùi Nhật Năng'
        };

        function lookupStudent() {
            const input = document.getElementById('student_id').value.trim().toUpperCase();
            const displayEl = document.getElementById('fullname_display');
            const hiddenEl = document.getElementById('fullname');

            if (studentDB[input]) {
                displayEl.value = studentDB[input];
                hiddenEl.value = studentDB[input];
                displayEl.style.color = '#198754';
                displayEl.style.fontWeight = '600';
            } else if (input.length >= 3) {
                // Cho phép nhập tự do nếu là tài khoản hỗ trợ/mã khác
                displayEl.value = 'Thí sinh ' + input;
                hiddenEl.value = 'Thí sinh ' + input;
                displayEl.style.color = '#495057';
                displayEl.style.fontWeight = 'normal';
            } else {
                displayEl.value = '';
                hiddenEl.value = '';
                displayEl.style.color = '#495057';
            }
        }

        function selectRole(role) {
            document.getElementById('selected_role').value = role;
            document.getElementById('role-exam').classList.toggle('active', role === 'exam');
            document.getElementById('role-support').classList.toggle('active', role === 'support');
            
            const btn = document.getElementById('btn-submit');
            if (role === 'support') {
                btn.textContent = 'Vào Dashboard Hỗ trợ';
                btn.style.backgroundColor = '#495057';
            } else {
                btn.textContent = 'Đăng nhập vào Cổng thi';
                btn.style.backgroundColor = '#004085';
            }
        }
    </script>
</body>
</html>