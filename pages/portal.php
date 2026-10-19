<?php
require_once __DIR__ . '/../includes/site_guard.php'; // Website tắt -> 404
// portal.php - Cổng thông tin khảo thí Đại học Hùng Vương (HVU)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/exam_schema.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['student_id']) || !isset($_SESSION['fullname'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$fullname = $_SESSION['fullname'];
$role = $_SESSION['role'] ?? 'exam';

// AJAX của popup chat (tải tin nhắn dự phòng khi WebSocket gián đoạn)
if (isset($_GET['ajax_action'])) {
    require_once __DIR__ . '/../includes/ajax_handler.php';
    exit;
}

// Tạo phòng chat hỗ trợ cho sinh viên (1 lần mỗi phiên đăng nhập) và gắn vào token WebSocket
function portal_ensure_chat($student_id, $fullname, $label) {
    $now = time();
    if (empty($_SESSION['chat_id'])) {
        $chat_id = uniqid('chat_');
        $chat_name = $fullname . ' (' . $student_id . ') - ' . $label;
        $_SESSION['chat_id'] = $chat_id;
        try {
            db_insert('chats', ['id' => $chat_id, 'name' => $chat_name, 'status' => 'attached', 'created_at' => $now]);
        } catch (Exception $e) {}
        try {
            get_chat_db()->prepare("INSERT OR REPLACE INTO chats (id, name, student_id, status, created_at, updated_at) VALUES (?, ?, ?, 'attached', ?, ?)")
                         ->execute([$chat_id, $chat_name, $student_id, $now, $now]);
        } catch (Exception $e) {}
    }
    $chat_id = $_SESSION['chat_id'];

    if (empty($_SESSION['ws_token'])) {
        $_SESSION['ws_token'] = bin2hex(random_bytes(16));
    }
    if (($_SESSION['ws_token_chat'] ?? '') !== $chat_id) {
        try {
            db_query("UPDATE `user_tokens` SET `chat_id` = ? WHERE `token` = ?", [$chat_id, $_SESSION['ws_token']]);
        } catch (Exception $e) {}
        try {
            get_chat_db()->prepare("INSERT OR REPLACE INTO user_tokens (token, user_id, role, chat_id, created_at) VALUES (?, ?, 'exam', ?, ?)")
                         ->execute([$_SESSION['ws_token'], $student_id, $chat_id, $now]);
        } catch (Exception $e) {}
        $_SESSION['ws_token_chat'] = $chat_id;
    }
    return $chat_id;
}

// Lấy giờ thi hiện tại (để trang tự cập nhật khi người hỗ trợ đổi giờ thi / danh sách)
if (isset($_GET['ajax_sessions'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'signature' => exam_sessions_signature(exam_sessions_for_student($student_id))]);
    exit;
}

// Xử lý xác minh mã dự thi qua AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_verify_code'])) {
    header('Content-Type: application/json; charset=utf-8');
    $code = strtoupper(trim($_POST['exam_code'] ?? ''));
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if (empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập mã ca thi!']);
        exit;
    }

    // Chỉ cho vào ca thi đã được gán cho chính sinh viên này
    $session = exam_session_for_student($sessionId, $student_id);
    if (!$session) {
        echo json_encode(['status' => 'error', 'message' => 'Bạn không có trong danh sách thi của ca thi này!']);
        exit;
    }

    $now = time();
    $startTs = (int)($session['start_ts'] ?? 0);
    $endTs = $startTs + (int)$session['duration_minutes'] * 60;
    if ($startTs > 0 && $now < $startTs) {
        echo json_encode(['status' => 'error', 'message' => 'Chưa đến giờ thi!']);
        exit;
    }
    if ($startTs > 0 && $now >= $endTs) {
        echo json_encode(['status' => 'error', 'message' => 'Ca thi đã kết thúc!']);
        exit;
    }

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

    // Phòng chat hỗ trợ (đã tạo sẵn khi vào Cổng thi)
    $chat_id = portal_ensure_chat($student_id, $fullname, $session['name']);

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

    echo json_encode(['status' => 'success', 'redirect' => 'index.php']);
    exit;
}

// Danh sách ca thi đang mở được gán cho sinh viên đang đăng nhập
$activeSessions = exam_sessions_for_student($student_id);
$serverNow = time();

// Vào Cổng thi là đã có sẵn phòng chat hỗ trợ
$chat_id = portal_ensure_chat($student_id, $fullname, !empty($activeSessions) ? $activeSessions[0]['name'] : 'Cổng thi');
$_SESSION['chat_sender'] = $_SESSION['chat_sender'] ?? $fullname;
$ws_config = require __DIR__ . '/../websocket_config.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trường Đại học Hùng Vương - Cổng Khảo Thí</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
            width: 100%;
            font-family: Roboto, 'Helvetica Neue', Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow: hidden;
        }

        /* Nền chứa hình gear tech trắng mờ */
        body {
            background-color: #f7f9fa;
            background-image: url('../assets/img/white-circle-gear-tech-fade.e8ee0bacff3e934c.jpg');
            background-repeat: no-repeat;
            background-position: center center;
            background-size: cover;
            position: relative;
            color: #131920;
        }

        /* Header */
        .header-brand {
            position: absolute;
            top: 32px;
            left: 31px;
            display: flex;
            align-items: center;
            text-decoration: none;
            user-select: none;
            z-index: 10;
        }

        .header-brand img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            display: block;
        }

        .school-title {
            margin-left: 17px;
            color: #ed1d24;
            font-size: 17px;
            font-weight: 500;
            line-height: 1;
            text-transform: uppercase;
            white-space: nowrap;
            position: relative;
            top: -1px;
            left: -1px;
        }

        .header-actions {
            position: absolute;
            top: 31px;
            right: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            z-index: 10;
        }

        .btn-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 36px;
            padding: 0 15px;
            border-radius: 18px;
            color: #ffffff;
            font-family: inherit;
            font-size: 14px;
            font-weight: 400;
            line-height: 1;
            text-decoration: none;
            border: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-pill:hover {
            filter: brightness(0.95);
        }

        .btn-pill svg {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            stroke-width: 2;
        }

        .btn-blue { background-color: #3b82f6; }
        .btn-green { background-color: #22c55e; gap: 5px; }
        .btn-slate { background-color: #64748b; }

        /* Khu vực giữa màn hình */
        main.content-area {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 5;
        }

        .exam-list-title {
            font-size: 20px;
            font-weight: 700;
            color: #1d2630;
            line-height: 34px;
            margin-bottom: 13px;
            text-transform: uppercase;
        }

        table.exam-table {
            border-collapse: collapse;
            table-layout: fixed;
            width: 1063px;
            background: #ffffff;
            font-size: 14px;
            line-height: 20px;
            color: #131920;
        }

        .exam-table th,
        .exam-table td {
            border: 1px solid #e3e3e3;
            vertical-align: middle;
        }

        .exam-table th {
            height: 39px;
            background: #f6f7f8;
            font-weight: 500;
            text-align: center;
            padding: 0 4px;
        }

        .exam-table td {
            height: 58px;
            padding: 8px 9px;
            text-align: center;
        }

        .exam-table td.col-status {
            padding: 0;
        }

        .exam-table td.col-session {
            text-align: left;
            padding-right: 4px;
        }

        .exam-table .cell-block {
            display: inline-block;
            text-align: left;
            position: relative;
            top: -1px;
        }

        .exam-table td.col-session .cell-block {
            display: block;
        }

        .exam-table .sub {
            color: #707070;
        }

        .exam-table .empty-row td {
            color: #707070;
        }

        /* Nút trạng thái */
        .status-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            width: 120px;
            height: 35px;
            border: none;
            border-radius: 4px;
            background: #bec8d0;
            color: #ffffff;
            font-family: inherit;
            font-size: 13px;
            line-height: 1;
            cursor: not-allowed;
            user-select: none;
        }

        .status-btn .count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 23px;
            padding: 0 5px;
            min-width: 50px;
            border-radius: 3px;
            background: #ffffff;
            color: #d82222;
        }

        .status-btn.ready {
            background: #3b82f6;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }

        .status-btn.ready:hover {
            background: #2f74e6;
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
            user-select: none;
        }

        /* Footer góc dưới bên phải */
        .portal-version-tag {
            position: fixed;
            bottom: 0;
            right: 0;
            font-size: 11px;
            line-height: 15px;
            color: #000000;
            display: flex;
            align-items: center;
            user-select: none;
            z-index: 10;
        }

        .portal-version-tag .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #05c30c;
            box-shadow: 0 0 4px #05c30c;
            display: inline-block;
        }

        .portal-version-tag .ver-text {
            background: #e0e2e5;
            padding: 1px 3px 0 2px;
            line-height: 14px;
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

        .modal-input {
            width: 100%;
            padding: 13px 16px;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 1px;
            text-align: center;
            text-transform: uppercase;
            color: #1976d2;
            border: 1.5px solid #ced4da;
            border-radius: 8px;
            outline: none;
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

        .btn-enter-exam {
            width: 100%;
            padding: 13px;
            background-color: #1976d2;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-family: inherit;
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

        /* Vùng bấm ẩn trên logo + chat nằm trên lớp làm mờ của popup nhập mã */
        .logo-chat-hotspot {
            position: fixed;
            top: 32px;
            left: 31px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            z-index: 1050;
        }

        .chat-popup {
            z-index: 1100 !important;
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

    <!-- Header -->
    <div class="header-brand">
        <img src="../assets/img/logo.png" alt="HVU Logo">
        <span class="school-title">TRƯỜNG ĐẠI HỌC HÙNG VƯƠNG</span>
    </div>
    <!-- Vùng bấm ẩn trùng vị trí logo: mở/đóng chat hỗ trợ, bấm được cả khi popup nhập mã đang làm mờ nền -->
    <div class="logo-chat-hotspot" onclick="toggleChatPopup()"></div>

    <div class="header-actions">
        <a href="dashboard.php" class="btn-pill btn-blue" title="Bảng điều khiển">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 9l-10 -4l-10 4l10 4l10 -4v6"></path>
                <path d="M6 10.6v5.4a6 3 0 0 0 12 0v-5.4"></path>
            </svg>
            <span>Bảng điều khiển</span>
        </a>

        <button type="button" class="btn-pill btn-green" onclick="showGuide()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"></path>
                <path d="M7 11l5 5l5 -5"></path>
                <path d="M12 4l0 12"></path>
            </svg>
            <span>Hướng dẫn làm bài thi</span>
        </button>

        <a href="logout.php" class="btn-pill btn-slate">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span>Đăng xuất</span>
        </a>
    </div>

    <!-- Khu vực chính giữa: Danh sách ca thi của sinh viên -->
    <main class="content-area">
        <?php if (!empty($activeSessions)): ?>
            <div class="exam-list-title">DANH SÁCH CA THI KTHP - TRẮC NGHIỆM</div>
            <table class="exam-table">
                <colgroup>
                    <col style="width:50px">
                    <col style="width:413px">
                    <col style="width:130px">
                    <col style="width:150px">
                    <col style="width:100px">
                    <col style="width:90px">
                    <col style="width:130px">
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Ca thi</th>
                        <th>Năm học</th>
                        <th>Thời gian</th>
                        <th>Hình thức</th>
                        <th>Thời lượng</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeSessions as $i => $ses):
                        $startTs = (int)($ses['start_ts'] ?? 0);
                        $title = trim($ses['code'] ?? '') !== '' ? $ses['code'] : $ses['name'];
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="col-session">
                            <div class="cell-block">
                                <div><?= htmlspecialchars($title) ?></div>
                                <div class="sub"><?= htmlspecialchars($ses['batch_name'] ?: 'Đợt thi 1') ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="cell-block">
                                <div><?= htmlspecialchars($ses['school_year'] ?? '') ?></div>
                                <div class="sub"><?= htmlspecialchars($ses['semester'] ?? '') ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="cell-block">
                                <?php if ($startTs > 0): ?>
                                    <div><?= date('d-m-Y', $startTs) ?></div>
                                    <div class="sub"><?= date('g:i A', $startTs) ?></div>
                                <?php else: ?>
                                    <div><?= htmlspecialchars($ses['starts_at'] ?? '') ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($ses['exam_form'] ?: 'Trắc nghiệm') ?></td>
                        <td><?= (int)$ses['duration_minutes'] ?> phút</td>
                        <td class="col-status">
                            <button type="button" class="status-btn"
                                    data-session-id="<?= (int)$ses['id'] ?>"
                                    data-start="<?= $startTs ?>"
                                    data-end="<?= $startTs > 0 ? $startTs + (int)$ses['duration_minutes'] * 60 : 0 ?>">
                                <span>Còn lại</span><span class="count">--:--</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-exam-card">
                <span>Hiện tại bạn không có ca thi nào!</span>
            </div>
        <?php endif; ?>
    </main>

    <!-- Phiên bản góc dưới bên phải -->
    <div class="portal-version-tag">
        <span class="dot"></span>
        <span class="ver-text">V3.2.89 (UTC+07)</span>
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

        // Đồng hồ đếm ngược tới giờ thi (lệch giờ máy khách được bù theo giờ máy chủ)
        const serverOffset = <?= $serverNow ?> * 1000 - Date.now();

        function pad2(n) { return String(n).padStart(2, '0'); }

        function updateStatusButtons() {
            const now = Math.floor((Date.now() + serverOffset) / 1000);
            document.querySelectorAll('.status-btn').forEach(btn => {
                const start = parseInt(btn.dataset.start, 10) || 0;
                const end = parseInt(btn.dataset.end, 10) || 0;
                let state;
                if (start > 0 && now < start) state = 'wait';
                else if (end > 0 && now >= end) state = 'ended';
                else state = 'ready';

                if (state === 'wait') {
                    const left = start - now;
                    const text = Math.floor(left / 60) + ':' + pad2(left % 60);
                    if (btn.dataset.state !== 'wait') {
                        btn.className = 'status-btn';
                        btn.innerHTML = '<span>Còn lại</span><span class="count"></span>';
                    }
                    btn.querySelector('.count').textContent = text;
                } else if (btn.dataset.state !== state) {
                    btn.className = 'status-btn' + (state === 'ready' ? ' ready' : '');
                    btn.textContent = state === 'ready' ? 'Vào thi' : 'Đã kết thúc';
                }
                btn.dataset.state = state;
            });
        }

        document.querySelectorAll('.status-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.dataset.state === 'ready') openExamModal(parseInt(btn.dataset.sessionId, 10));
            });
        });
        updateStatusButtons();
        setInterval(updateStatusButtons, 1000);

        // Người hỗ trợ đổi giờ thi / danh sách -> tải lại trang để cập nhật (trừ khi đang nhập mã)
        const sessionsSignature = <?= json_encode(exam_sessions_signature($activeSessions)) ?>;
        setInterval(() => {
            if (document.getElementById('examModalOverlay').classList.contains('active')) return;
            fetch('portal.php?ajax_sessions=1', { cache: 'no-store' })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.signature !== sessionsSignature) location.reload();
                })
                .catch(() => {});
        }, 15000);

        function openExamModal(sessionId) {
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
            fd.append('session_id', currentSessionId);

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
            alert("HƯỚNG DẪN LÀM BÀI THI TRỰC TUYẾN:\n\n1. Khi đến giờ thi, bấm 'Vào thi' ở ca thi của bạn.\n2. Nhập đúng mã ca thi được cấp bởi cán bộ coi thi.\n3. Đồng hồ đếm ngược được duy trì trên máy chủ (F5 không reset giờ).\n4. Thí sinh có thể nhấn vào ảnh đại diện để mở khung chat hỗ trợ.");
        }
    </script>

    <!-- Popup chat hỗ trợ (ẩn, mở bằng cách bấm logo) -->
    <?php include __DIR__ . '/../includes/chat_popup.php'; ?>
    <script>
        const sessionChatId = <?= json_encode($chat_id) ?>;
        const currentUser = <?= json_encode($_SESSION['chat_sender']) ?>;
        const currentUserId = <?= json_encode($student_id) ?>;
        const wsToken = <?= json_encode($_SESSION['ws_token'] ?? '') ?>;
        const wsConfigUrl = <?= json_encode($ws_config['client_url']) ?>;
        window.examInfo = {
            student: <?= json_encode($fullname) ?>,
            examId: '',
            subject: <?= json_encode(!empty($activeSessions) ? $activeSessions[0]['name'] : 'Cổng thi') ?>
        };
        window.chatStartHidden = true;
        window.chatAjaxUrl = 'portal.php';
    </script>
    <script src="../assets/js/websocket-client.js?v=<?= time() ?>"></script>
    <script src="../assets/js/chat.js?v=<?= time() ?>"></script>
    <script>initChatSystem();</script>
</body>
</html>
