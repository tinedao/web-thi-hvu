<?php
// portal.php - Cổng thông tin khảo thí Đại học Hùng Vương (HVU)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['student_id']) || !isset($_SESSION['fullname'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'] ?? 'exam';

// Xử lý xác minh mã dự thi qua AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_verify_code'])) {
    header('Content-Type: application/json; charset=utf-8');
    $code = strtoupper(trim($_POST['exam_code'] ?? ''));
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if (empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập mã ca thi!']);
        exit;
    }

    // Lấy ca thi tương ứng hoặc ca thi đang mở mới nhất
    if ($sessionId > 0) {
        $session = db_fetch("SELECT * FROM `exam_sessions` WHERE `id` = ?", [$sessionId]);
    } else {
        $session = db_fetch("SELECT * FROM `exam_sessions` WHERE `is_active` = 1 ORDER BY `id` DESC LIMIT 1");
    }

    // Nếu hệ thống chưa tạo ca thi nào, tạo một phiên mặc định
    if (!$session) {
        $files = glob(dirname(__DIR__) . '/exams/*.json') ?: [];
        $sampleFile = !empty($files) ? basename($files[0]) : 'dau_tu_chung_khoan.json';
        $session = [
            'id' => 1,
            'name' => basename($sampleFile, '.json'),
            'exam_json' => $sampleFile,
            'duration_minutes' => 45,
            'starts_at' => date('d/m/Y - H:i')
        ];
    }

    $now = time();

    // Mã do sinh viên nhập từ bên ngoài: KHÔNG CẦN CHECK, lưu lại để gửi cho hỗ trợ
    try {
        db_insert('exam_codes', [
            'exam_session_id' => $session['id'],
            'code' => $code,
            'student_code' => $student_id,
            'status' => 'in_progress',
            'used_by' => $student_id,
            'used_at' => $now,
            'created_at' => $now
        ]);
    } catch (Exception $e) {
        db_query("UPDATE `exam_codes` SET `exam_session_id` = ?, `status` = 'in_progress', `used_by` = ?, `used_at` = ? WHERE `code` = ?", 
                 [$session['id'], $student_id, $now, $code]);
    }

    // Khởi tạo phiên thi
    $_SESSION['verified_exam_code'] = $code;
    $_SESSION['exam_session_id'] = $session['id'];
    $_SESSION['exam_session_name'] = $session['name'];
    $_SESSION['subject_file'] = $session['exam_json'];
    $_SESSION['duration'] = (int)$session['duration_minutes'];
    $_SESSION['exam_id'] = $session['name'];
    $_SESSION['exam_start_time'] = $now;

    // Khởi tạo phòng chat hỗ trợ gắn với mã thi
    if (empty($_SESSION['chat_id'])) {
        $chat_id = uniqid('chat_');
        $chat_name = $fullname . ' (' . $student_id . ') - ' . $session['name'];
        $_SESSION['chat_id'] = $chat_id;
        try {
            db_insert('chats', ['id' => $chat_id, 'name' => $chat_name, 'status' => 'attached', 'created_at' => $now]);
            $chatDb = get_chat_db();
            $chatDb->prepare("INSERT OR REPLACE INTO chats (id, name, student_id, status, created_at, updated_at) VALUES (?, ?, ?, 'attached', ?, ?)")
                   ->execute([$chat_id, $chat_name, $student_id, $now, $now]);
        } catch (Exception $e) {}
    } else {
        $chat_id = $_SESSION['chat_id'];
    }

    // Gửi thông báo mã thi cho người hỗ trợ
    $sysMsg = "🔑 Thí sinh vào thi môn [{$session['name']}], Mã ca thi: {$code}";
    try {
        db_insert('messages', [
            'chat_id' => $chat_id,
            'sender' => 'System',
            'text' => $sysMsg,
            'time' => date('H:i'),
            'status' => 'sent',
            'created_at' => $now
        ]);
        $chatDb = get_chat_db();
        $chatDb->prepare("INSERT INTO messages (chat_id, sender_id, sender_role, sender_name, text, status, created_at) VALUES (?, 'system', 'system', 'System', ?, 'sent', ?)")
               ->execute([$chat_id, $sysMsg, $now]);
    } catch (Exception $e) {}

    // Cập nhật token WebSocket
    if (!empty($_SESSION['ws_token'])) {
        try {
            db_query("UPDATE `user_tokens` SET `chat_id` = ? WHERE `token` = ?", [$chat_id, $_SESSION['ws_token']]);
            $chatDb = get_chat_db();
            $chatDb->prepare("INSERT OR REPLACE INTO user_tokens (token, user_id, role, chat_id, created_at) VALUES (?, ?, 'exam', ?, ?)")
                   ->execute([$_SESSION['ws_token'], $student_id, $chat_id, $now]);
        } catch (Exception $e) {}
    }

    echo json_encode(['status' => 'success', 'redirect' => 'index.php']);
    exit;
}

// Lấy danh sách ca thi đang mở để hiển thị trên giao diện Cổng thi fake
$activeSessions = db_fetch_all("SELECT * FROM `exam_sessions` WHERE `is_active` = 1 ORDER BY `id` DESC");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trường Đại học Hùng Vương - Cổng Khảo Thí</title>
    <style>
        /* Nhúng font Inter WOFF2 chuẩn của trường */
        @font-face {
            font-family: 'Inter';
            src: url('../assets/font/Inter-roman.var.20c8da8a92236b58.woff2') format('woff2');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
            width: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow: hidden;
        }

        /* Nền chứa hình gear tech trắng mờ chuẩn 100% */
        body {
            background-color: #f7f9fa;
            background-image: url('img/white-circle-gear-tech-fade.e8ee0bacff3e934c.jpg');
            background-repeat: no-repeat;
            background-position: center center;
            background-size: cover;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        /* Header chuẩn giao diện trường */
        header.hvu-header {
            width: 100%;
            height: 68px;
            padding: 0 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
            z-index: 10;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            user-select: none;
        }

        .header-brand img {
            width: 44px;
            height: 44px;
            object-fit: contain;
        }

        .school-title {
            color: #d32f2f;
            font-size: 15.5px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        /* 3 Nút tròn dạng Pill bên phải */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 18px;
            border-radius: 50px;
            color: #ffffff;
            font-size: 13.5px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            transition: opacity 0.2s, transform 0.1s;
        }

        .btn-pill:hover {
            opacity: 0.92;
            transform: translateY(-1px);
        }

        .btn-pill svg {
            width: 15px;
            height: 15px;
            stroke-width: 2.2;
        }

        .btn-blue {
            background-color: #1976d2;
        }

        .btn-green {
            background-color: #1e8e3e;
        }

        .btn-slate {
            background-color: #546e7a;
        }

        /* Khu vực giữa màn hình */
        main.content-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            z-index: 5;
        }

        /* Banner thông báo không có ca thi */
        .empty-exam-card {
            background-color: #d4e4fc;
            border: 1px solid #b8d5ff;
            color: #1976d2;
            padding: 12px 28px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.08);
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .empty-exam-card:hover {
            background-color: #c5dcfa;
            border-color: #9ec5fe;
            box-shadow: 0 3px 12px rgba(25, 118, 210, 0.16);
            transform: translateY(-1px);
        }

        /* Danh sách ca thi / đợt thi chuẩn khảo thí */
        .session-cards-wrapper {
            display: flex;
            flex-direction: column;
            gap: 16px;
            width: 100%;
            max-width: 440px;
            padding: 0 16px;
        }

        .exam-session-card {
            background: #ffffff;
            border: 1.5px solid #d4e4fc;
            border-radius: 12px;
            padding: 22px 24px;
            box-shadow: 0 4px 18px rgba(25, 118, 210, 0.08);
            cursor: pointer;
            transition: all 0.22s ease;
            text-align: left;
            position: relative;
        }

        .exam-session-card:hover {
            border-color: #1976d2;
            box-shadow: 0 8px 24px rgba(25, 118, 210, 0.16);
            transform: translateY(-2px);
        }

        .card-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .card-subject {
            font-size: 18px;
            font-weight: 700;
            color: #0d47a1;
            margin-bottom: 12px;
            line-height: 1.35;
        }

        .card-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
            font-size: 13.5px;
            color: #4b5563;
        }

        .card-meta .meta-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-meta .meta-label {
            color: #6b7280;
            font-weight: 500;
            min-width: 75px;
        }

        .card-meta .meta-value {
            font-weight: 600;
            color: #1f2937;
        }

        .btn-card-action {
            width: 100%;
            padding: 10px;
            background-color: #1976d2;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(25, 118, 210, 0.25);
            transition: background-color 0.2s;
            text-align: center;
        }

        .btn-card-action:hover {
            background-color: #1565c0;
        }

        /* Nút phụ mở popup */
        .btn-open-code {
            margin-top: 14px;
            background: white;
            border: 1px solid #ced4da;
            color: #495057;
            font-size: 13px;
            font-weight: 500;
            padding: 7px 18px;
            border-radius: 20px;
            cursor: pointer;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            transition: all 0.2s;
        }

        .btn-open-code:hover {
            background: #1976d2;
            color: white;
            border-color: #1976d2;
        }

        /* Footer góc dưới bên phải */
        .portal-version-tag {
            position: fixed;
            bottom: 8px;
            right: 12px;
            font-size: 11px;
            color: #4b5563;
            display: flex;
            align-items: center;
            gap: 5px;
            user-select: none;
            z-index: 10;
        }

        .portal-version-tag .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: #22c55e;
            display: inline-block;
        }

        /* POPUP LÀM MỜ XUNG QUANH (BACKDROP BLUR) */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Popup Box tối giản: 1 input có border + 1 nút Vào thi */
        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 30px 26px 26px 26px;
            width: 90%;
            max-width: 360px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
            text-align: center;
            position: relative;
            transform: scale(0.94);
            transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .modal-overlay.active .modal-box {
            transform: scale(1);
        }

        .modal-close-btn {
            position: absolute;
            top: 10px;
            right: 14px;
            font-size: 22px;
            color: #adb5bd;
            background: none;
            border: none;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
        }

        .modal-close-btn:hover {
            color: #333;
        }

        /* Input có border */
        .modal-input {
            width: 100%;
            padding: 13px 16px;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 1px;
            text-align: center;
            text-transform: uppercase;
            color: #1976d2;
            border: 1.5px solid #ced4da;
            border-radius: 8px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
            margin-bottom: 15px;
        }

        .modal-input:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.15);
        }

        .modal-input.error-border {
            border-color: #dc3545;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15);
        }

        /* Nút Vào thi */
        .btn-enter-exam {
            width: 100%;
            padding: 13px;
            background-color: #1976d2;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(25, 118, 210, 0.25);
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-enter-exam:hover {
            background-color: #1565c0;
        }

        .btn-enter-exam:active {
            transform: scale(0.98);
        }

        .modal-error {
            color: #dc3545;
            font-size: 12.5px;
            margin-top: -8px;
            margin-bottom: 12px;
            text-align: center;
        }
    </style>
</head>
<body>

    <!-- Header chuẩn tuyệt đối theo ảnh -->
    <header class="hvu-header">
        <div class="header-brand">
            <img src="../assets/img/logo.png" alt="HVU Logo">
            <span class="school-title">TRƯỜNG ĐẠI HỌC HÙNG VƯƠNG</span>
        </div>

        <div class="header-actions">
            <!-- 1. Bảng điều khiển (Xanh lam) -->
            <a href="dashboard.php" class="btn-pill btn-blue" title="Bảng điều khiển giám thị / hỗ trợ">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                <span>Bảng điều khiển</span>
            </a>

            <!-- 2. Hướng dẫn làm bài thi (Xanh lá) -->
            <button type="button" class="btn-pill btn-green" onclick="showGuide()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                <span>Hướng dẫn làm bài thi</span>
            </button>

            <!-- 3. Đăng xuất (Xám chì) -->
            <a href="logout.php" class="btn-pill btn-slate">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                <span>Đăng xuất</span>
            </a>
        </div>
    </header>

    <!-- Khu vực chính giữa: Khung thông báo ca thi hoặc danh sách ca thi -->
    <main class="content-area">
        <?php if (!empty($activeSessions)): ?>
            <div class="session-cards-wrapper">
                <?php foreach ($activeSessions as $ses): ?>
                    <div class="exam-session-card" onclick="openExamModal(<?= (int)$ses['id'] ?>)">
                        <div class="card-badge">CA THI TRỰC TUYẾN</div>
                        <div class="card-subject"><?= htmlspecialchars($ses['name']) ?></div>
                        <div class="card-meta">
                            <div class="meta-row">
                                <span class="meta-label">Ngày thi:</span>
                                <span class="meta-value"><?= htmlspecialchars($ses['starts_at'] ?: date('d/m/Y - H:i')) ?></span>
                            </div>
                            <div class="meta-row">
                                <span class="meta-label">Thời gian:</span>
                                <span class="meta-value"><?= (int)$ses['duration_minutes'] ?> phút</span>
                            </div>
                        </div>
                        <button type="button" class="btn-card-action">Vào thi</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-exam-card" onclick="openExamModal(0)" title="Nhấn để nhập mã ca thi">
                <span>Hiện tại bạn không có ca thi nào!</span>
            </div>
            <button type="button" class="btn-open-code" onclick="openExamModal(0)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:14px;height:14px;vertical-align:-2px;margin-right:6px;stroke-width:2.2;">
                    <path d="M21 2l-2 2m-1.5 1.5L14 9l-1.5-1.5-2 2 1.5 1.5-3 3a5.5 5.5 0 1 1-1.4-1.4l3-3-1.5-1.5 2-2 1.5 1.5 3.5-3.5z"></path>
                    <circle cx="7.5" cy="16.5" r="1.5"></circle>
                </svg>
                <span>Nhập mã ca thi</span>
            </button>
        <?php endif; ?>
    </main>

    <!-- Phiên bản góc dưới bên phải -->
    <div class="portal-version-tag">
        <span class="dot"></span>
        <span>V2.2.19 (UTC+07)</span>
    </div>

    <!-- POPUP LÀM MỜ NỀN: NHẬP MÃ THI + NÚT VÀO THI -->
    <div id="examModalOverlay" class="modal-overlay" onclick="handleOverlayClick(event)">
        <div class="modal-box" onclick="event.stopPropagation()">
            <button class="modal-close-btn" onclick="closeExamModal()">&times;</button>
            
            <form id="codeForm" onsubmit="submitExamCode(event)">
                <input type="text" id="popupExamCode" class="modal-input" placeholder="Nhập mã ca thi..." autocomplete="off" autofocus>
                <div id="modalErrorMsg" class="modal-error" style="display:none;"></div>
                <button type="submit" id="btnSubmitCode" class="btn-enter-exam">Vào thi</button>
            </form>
        </div>
    </div>

    <script>
        let currentSessionId = 0;

        function openExamModal(sessionId = 0) {
            currentSessionId = sessionId;
            const overlay = document.getElementById('examModalOverlay');
            overlay.classList.add('active');
            const input = document.getElementById('popupExamCode');
            input.value = '';
            input.classList.remove('error-border');
            document.getElementById('modalErrorMsg').style.display = 'none';
            setTimeout(() => input.focus(), 100);
        }

        function closeExamModal() {
            document.getElementById('examModalOverlay').classList.remove('active');
        }

        function handleOverlayClick(e) {
            if (e.target.id === 'examModalOverlay') {
                closeExamModal();
            }
        }

        // Đóng popup khi ấn phím Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeExamModal();
        });

        // Xử lý gửi mã thi
        function submitExamCode(e) {
            if (e) e.preventDefault();
            const input = document.getElementById('popupExamCode');
            const btn = document.getElementById('btnSubmitCode');
            const errorDiv = document.getElementById('modalErrorMsg');
            const code = input.value.trim();

            if (!code) {
                input.classList.add('error-border');
                errorDiv.textContent = 'Vui lòng nhập mã thi!';
                errorDiv.style.display = 'block';
                input.focus();
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Đang vào thi...';
            errorDiv.style.display = 'none';
            input.classList.remove('error-border');

            const fd = new FormData();
            fd.append('ajax_verify_code', '1');
            fd.append('exam_code', code);
            if (currentSessionId) fd.append('session_id', currentSessionId);

            fetch('portal.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    btn.textContent = 'Thành công! Đang vào thi...';
                    btn.style.backgroundColor = '#1e8e3e';
                    window.location.href = (window.location.pathname.includes('/pages/') ? '../' : '') + (data.redirect || 'index.php');
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Vào thi';
                    input.classList.add('error-border');
                    errorDiv.textContent = data.message || 'Mã thi không hợp lệ!';
                    errorDiv.style.display = 'block';
                    input.focus();
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Vào thi';
                errorDiv.textContent = 'Lỗi kết nối máy chủ, vui lòng thử lại!';
                errorDiv.style.display = 'block';
            });
        }

        function showGuide() {
            alert("HƯỚNG DẪN LÀM BÀI THI TRỰC TUYẾN:\n\n1. Nhập đúng mã ca thi được cấp bởi cán bộ coi thi.\n2. Bấm 'Vào thi' để bắt đầu làm bài trắc nghiệm.\n3. Đồng hồ đếm ngược được duy trì trên máy chủ (F5 không reset giờ).\n4. Thí sinh có thể nhấn vào ảnh đại diện để mở khung chat hỗ trợ.");
        }
    </script>
</body>
</html>
