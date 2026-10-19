<?php
require_once __DIR__ . '/../includes/site_guard.php'; // Website tắt -> 404
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- XỬ LÝ AJAX TẬP TRUNG ---
if (isset($_GET['ajax_action'])) {
    require_once __DIR__ . '/../includes/ajax_handler.php';
    exit;
}

// Kiểm tra quyền truy cập (Bắt buộc đã đăng nhập)
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

// Chuyển vai trò sang 'support' khi vào dashboard
$_SESSION['role'] = 'support';
$admin_id = $_SESSION['student_id'];
$admin_name = $_SESSION['fullname'] ?? 'Cán bộ hỗ trợ';

if (empty($_SESSION['ws_token'])) {
    $_SESSION['ws_token'] = bin2hex(random_bytes(16));
}

$ws_config = require __DIR__ . '/../websocket_config.php';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bảng Điều Khiển Quản Trị & Hỗ Trợ - HVU</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #003366;
            --primary-dark: #002244;
            --secondary: #6c757d;
        }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 0; background: #f0f2f5; height: 100vh; display: flex; flex-direction: column; }
        header { background: var(--primary); color: white; padding: 10px 20px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .main-layout { display: flex; flex: 1; overflow: hidden; }
        
        /* Navigation Buttons */
        .nav-btn { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: white; padding: 6px 14px; border-radius: 4px; cursor: pointer; margin-right: 8px; font-size: 13px; font-weight: 500; transition: all 0.2s; }
        .nav-btn:hover { background: rgba(255,255,255,0.15); }
        .nav-btn.active { background: white; color: var(--primary); font-weight: 700; border-color: white; }
        
        /* Sidebar: Danh sách chat */
        .sidebar { width: 360px; background: white; border-right: 1px solid #dee2e6; display: flex; flex-direction: column; }
        .create-box { padding: 15px; border-bottom: 1px solid #eee; background: #f8f9fa; }
        .create-box input, .create-box select { width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; font-size: 13px; }
        .create-box button { width: 100%; padding: 8px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 13px; }
        
        .chat-list { flex: 1; overflow-y: auto; }
        .chat-item { padding: 12px 15px; border-bottom: 1px solid #f1f1f1; cursor: pointer; transition: 0.15s; position: relative; }
        .chat-item:hover { background: #f8faff; }
        .chat-item.active { background: #eaf3ff; border-left: 4px solid var(--primary); }
        .chat-item h3 { margin: 0 0 4px 0; font-size: 14px; color: #333; display: flex; align-items: center; justify-content: space-between; }
        .chat-info { font-size: 12px; color: #666; display: flex; justify-content: space-between; align-items: center; }
        
        .status-badge { padding: 2px 7px; border-radius: 10px; font-size: 10px; text-transform: uppercase; font-weight: bold; }
        .status-waiting { background: #ffc107; color: #333; }
        .status-attached { background: #198754; color: white; }
        .status-graded { background: #0d6efd; color: white; }
        .status-disabled { background: #dc3545; color: white; }
        .status-closed { background: #6c757d; color: white; }

        /* Online status indicator dot */
        .online-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; background-color: #adb5bd; margin-right: 6px; }
        .online-dot.online { background-color: #28a745; box-shadow: 0 0 5px #28a745; }

        /* Chat Area: Khung chat chính */
        .chat-area { flex: 1; display: flex; flex-direction: column; background: #fff; }
        .chat-area-header { padding: 12px 18px; border-bottom: 1px solid #dee2e6; background: #f8f9fa; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .chat-messages { flex: 1; padding: 20px; overflow-y: auto; background: #eef1f5; display: flex; flex-direction: column; gap: 10px; }
        
        .msg { max-width: 70%; padding: 8px 14px; border-radius: 10px; font-size: 13px; line-height: 1.4; position: relative; }
        .msg.admin { align-self: flex-end; background: #004085; color: white; border-bottom-right-radius: 2px; }
        .msg.admin .msg-time { color: rgba(255,255,255,0.7); }
        .msg.user { align-self: flex-start; background: white; color: #212529; border: 1px solid #dee2e6; border-bottom-left-radius: 2px; }
        .msg.system { align-self: center; background: rgba(0,0,0,0.06); font-size: 12px; color: #555; font-style: italic; border-radius: 15px; padding: 4px 15px; }
        .msg-time { font-size: 10px; color: #888; display: flex; justify-content: flex-end; align-items: center; gap: 4px; margin-top: 4px; }
        
        .status-tick { font-size: 11px; user-select: none; }
        .status-tick.read { color: #66b0ff !important; font-weight: bold; }
        .status-tick.delivered { color: #ced4da; font-weight: bold; }
        .status-tick.sent { color: #adb5bd; }

        .chat-input-area { padding: 12px 18px; border-top: 1px solid #dee2e6; background: #f8f9fa; display: flex; gap: 10px; }
        .chat-input-area input { flex: 1; padding: 10px 14px; border: 1px solid #ced4da; border-radius: 20px; outline: none; font-size: 13px; }
        .chat-input-area button { padding: 8px 20px; background: var(--primary); color: white; border: none; border-radius: 20px; cursor: pointer; font-weight: 600; }
        
        .empty-state { flex: 1; display: flex; align-items: center; justify-content: center; color: #888; font-size: 16px; }

        /* Styles cho Quản lý Đề thi JSON */
        .exam-list-item { padding: 10px 15px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
        .exam-list-item:hover { background: #f8faff; }
        .exam-list-item.active { background: #eaf3ff; border-left: 4px solid var(--primary); }
        .btn-sm-danger { background: #dc3545; color: white; border: none; padding: 3px 8px; border-radius: 4px; font-size: 11px; cursor: pointer; }

        /* Styles cho Quản lý Đợt thi */
        .sessions-container { flex: 1; padding: 25px; overflow-y: auto; background: #f8f9fa; }
        .card-custom { background: white; border-radius: 8px; border: 1px solid #dee2e6; box-shadow: 0 2px 6px rgba(0,0,0,0.04); margin-bottom: 25px; padding: 20px; }
        .card-custom h4 { margin-top: 0; font-size: 16px; color: var(--primary); font-weight: 700; border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 15px; }
        .table-custom th { background: #e9ecef; font-size: 12px; text-transform: uppercase; }
        .table-custom td { font-size: 13px; vertical-align: middle; }

        /* Mobile */
        .back-btn { display: none; background: none; border: none; font-size: 20px; cursor: pointer; color: var(--primary); margin-right: 10px; }
        @media (max-width: 768px) {
            .sidebar { width: 100%; border-right: none; }
            .chat-area { display: none; width: 100%; }
            .main-layout.mobile-view-detail .sidebar { display: none; }
            .main-layout.mobile-view-detail .chat-area { display: flex; }
            .back-btn { display: inline-block; }
            header { flex-direction: column; align-items: flex-start; gap: 8px; }
        }
    </style>
</head>
<body>
    <header>
        <div style="display:flex; align-items: center; flex-wrap: wrap; gap: 8px;">
            <div style="font-size: 16px; font-weight: 700; margin-right: 15px;">🏛️ HVU DASHBOARD</div>
            <nav style="display: flex;">
                <button onclick="switchView('chat')" id="btn-view-chat" class="nav-btn active">💬 Chat Realtime</button>
                <button onclick="switchView('exam')" id="btn-view-exam" class="nav-btn">📑 Ngân Hàng Đề JSON</button>
                <button onclick="switchView('sessions')" id="btn-view-sessions" class="nav-btn">🔑 Quản Lý Đợt Thi & Mã</button>
            </nav>
        </div>
        <div style="font-size: 13px; font-weight: normal; display: flex; align-items: center; gap: 12px;">
            <span>Hỗ trợ: <strong><?= htmlspecialchars($admin_name) ?></strong></span>
            <a href="role_switch.php?role=exam" class="btn btn-sm btn-warning" style="font-size: 11px; font-weight: 600;">🔄 Đổi sang Thí sinh</a>
            <a href="logout.php" class="btn btn-sm btn-outline-light" style="font-size: 11px;">Đăng xuất</a>
        </div>
    </header>

    <!-- VIEW 1: CHAT SUPPORT REALTIME -->
    <div id="view-chat" class="main-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="create-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span style="font-size: 13px; font-weight: 700; color: #333;">Danh Sách Phiên Chat</span>
                    <button onclick="loadChats()" style="width: auto; padding: 3px 8px; background: #6c757d; font-size: 11px;">🔄 Làm mới</button>
                </div>
                <input type="text" id="chatSearch" placeholder="🔍 Tìm theo tên hoặc mã SV..." oninput="filterChatList()">
            </div>
            <div id="chatList" class="chat-list">
                <div class="text-center p-4 text-muted">Đang tải danh sách...</div>
            </div>
        </div>

        <!-- Chat Window -->
        <div class="chat-area">
            <div id="chatHeader" class="chat-area-header" style="display:none;">
                <div style="display: flex; align-items: center;">
                    <button class="back-btn" onclick="backToList()"><i class="bi bi-arrow-left"></i></button>
                    <div>
                        <span id="currentChatName">Chọn một đoạn chat</span>
                        <div id="currentChatSub" style="font-size: 11px; font-weight: normal; color: #6c757d;"></div>
                    </div>
                </div>
                <div>
                    <button onclick="gradeCurrentChat()" style="background:#28a745; color:white; font-size:12px; margin-right:8px; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight: 600;">⭐ Chấm điểm</button>
                    <button onclick="deleteCurrentChat()" class="btn-sm-danger">Xóa phòng</button>
                </div>
            </div>
            
            <div id="messagesBox" class="chat-messages">
                <div class="empty-state">Chọn một thí sinh ở danh sách bên trái để bắt đầu hỗ trợ realtime</div>
            </div>

            <div id="inputArea" class="chat-input-area" style="display:none;">
                <input type="text" id="adminInput" placeholder="Nhập tin nhắn hỗ trợ (Enter để gửi)..." onkeypress="handleAdminKey(event)">
                <button onclick="sendAdminMessage()">Gửi tin</button>
            </div>
        </div>
    </div>

    <!-- VIEW 2: QUẢN LÝ ĐỀ THI JSON -->
    <div id="view-exam" class="main-layout" style="display:none;">
        <div class="sidebar">
            <div class="create-box">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">Tạo Đề thi Mới</h4>
                <input type="text" id="newSubjectName" placeholder="Tên file (không dấu, vd: toan_cao_cap)...">
                <button onclick="createSubject()" style="background: #28a745;">Tạo mới file JSON</button>
            </div>
            <div class="create-box" style="margin-top: 5px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">Upload file JSON</h4>
                <input type="file" id="uploadSubjectFile" accept=".json" style="font-size: 12px; background: white;">
                <button onclick="uploadSubject()" style="background: #0056b3;">Upload lên Server</button>
            </div>
            <div id="subjectList" class="chat-list">
                <!-- Danh sách file JSON -->
            </div>
        </div>

        <div class="chat-area">
            <div id="examHeader" class="chat-area-header" style="display:none;">
                <button class="back-btn" onclick="backToList()"><i class="bi bi-arrow-left"></i></button>
                <span id="currentSubjectName">Đang chọn: ...</span>
                <div>
                    <button onclick="formatJSON()" style="background:#6c757d; color:white; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; margin-right: 5px;">Định dạng (Format)</button>
                    <button onclick="saveQuestions()" style="background:#28a745; color:white; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight:600;">Lưu thay đổi</button>
                </div>
            </div>
            
            <div id="questionList" class="question-editor" style="flex:1; display:flex; flex-direction:column;">
                <div class="empty-state">Chọn một đề thi JSON bên trái để chỉnh sửa trực tiếp</div>
            </div>
        </div>
    </div>

    <!-- VIEW 3: QUẢN LÝ ĐỢT THI & CẤP MÃ DỰ THI -->
    <div id="view-sessions" class="main-layout" style="display:none;">
        <div class="sessions-container">
            <div class="row">
                <!-- Cột trái: Form tạo ca thi mới -->
                <div class="col-md-5">
                    <div class="card-custom">
                        <h4 id="sessionFormTitle">1. Thiết Lập Ca Thi Mới</h4>
                        <form id="formCreateSession" onsubmit="handleCreateSession(event)">
                            <input type="hidden" id="sessionEditId" value="">
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Môn thi</label>
                                <input type="text" id="sessionName" class="form-control form-control-sm" required placeholder="VD: Phát triển ứng dụng IoT">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Mã ca thi (hiển thị ở cột "Ca thi")</label>
                                <input type="text" id="sessionCode" class="form-control form-control-sm" placeholder="VD: MDS_CPS318_20_10_2026_13_30_A35_Phát triển ứng dụng IoT">
                            </div>
                            <div class="row mb-3">
                                <div class="col-4">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Đợt thi</label>
                                    <input type="text" id="sessionBatch" class="form-control form-control-sm" value="Đợt thi 1">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Năm học</label>
                                    <input type="text" id="sessionYear" class="form-control form-control-sm" value="2026 - 2027">
                                </div>
                                <div class="col-4">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Học kỳ</label>
                                    <input type="text" id="sessionSemester" class="form-control form-control-sm" value="Học kỳ 1">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Chọn Đề thi JSON</label>
                                <select id="sessionExamJson" class="form-select form-select-sm" required>
                                    <option value="">-- Đang tải danh sách đề... --</option>
                                </select>
                            </div>
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Thời gian thi (phút)</label>
                                    <input type="number" id="sessionDuration" class="form-control form-control-sm" value="45" min="5" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:13px; font-weight:600;">Giờ bắt đầu thi</label>
                                    <input type="datetime-local" id="sessionStartsAt" class="form-control form-control-sm" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Mã sinh viên được thi (mỗi dòng 1 mã, hoặc cách nhau dấu phẩy)</label>
                                <textarea id="sessionStudents" class="form-control form-control-sm" rows="4" placeholder="VD:&#10;235D150109&#10;235D150052"></textarea>
                                <div class="form-text" style="font-size:11.5px;">Chỉ những sinh viên có trong danh sách mới thấy và vào được ca thi này.</div>
                            </div>
                            <div id="studentTimesBox" class="mb-3" style="display:none;">
                                <label class="form-label" style="font-size:13px; font-weight:600;">Giờ thi riêng từng sinh viên (lưu ngay, sinh viên tự cập nhật sau ~15 giây)</label>
                                <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
                                    <table class="table table-sm table-bordered mb-1" style="font-size:12px;">
                                        <thead>
                                            <tr>
                                                <th>Mã SV</th>
                                                <th>Giờ bắt đầu</th>
                                                <th style="width:70px;">Phút</th>
                                                <th>Thao tác</th>
                                            </tr>
                                        </thead>
                                        <tbody id="studentTimesBody"></tbody>
                                    </table>
                                </div>
                                <div class="form-text" style="font-size:11.5px;">Dòng tô vàng là sinh viên đang dùng giờ riêng. "Mặc định" = quay về giờ chung của ca thi.</div>
                            </div>
                            <button type="submit" id="btnSaveSession" class="btn btn-sm btn-primary w-100" style="font-weight:600;">+ Lưu Ca Thi</button>
                            <button type="button" id="btnCancelEditSession" class="btn btn-sm btn-outline-secondary w-100 mt-2" style="display:none;" onclick="resetSessionForm()">Hủy sửa</button>
                        </form>
                    </div>

                    <div class="card-custom" style="background:#eef6ff; border-color:#b8d5ff;">
                        <h6 style="color:#003366; font-weight:700; margin-bottom:8px;">💡 Hướng dẫn luồng thi:</h6>
                        <p style="font-size:12.5px; color:#444; margin-bottom:0; line-height:1.5;">
                            • Người hỗ trợ tạo ca thi gồm: <strong>Môn thi</strong>, <strong>Đề thi</strong>, <strong>Thời gian thi</strong>, <strong>Giờ bắt đầu</strong> và <strong>danh sách mã sinh viên</strong> được thi.<br>
                            • Sinh viên chỉ thấy ca thi của mình, đếm ngược tới giờ thi rồi mới bấm "Vào thi" được.<br>
                            • Sinh viên khi đi thi sẽ tự nhập mã thi từ phòng thi bên ngoài. Hệ thống tự động chấp nhận và hiển thị mã thi của sinh viên vào danh sách bên phải.
                        </p>
                    </div>
                </div>

                <!-- Cột phải: Danh sách ca thi & thí sinh đang thi -->
                <div class="col-md-7">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Danh Sách Ca Thi Đang Quản Lý</h4>
                            <button onclick="loadExamSessions()" class="btn btn-sm btn-outline-secondary">🔄 Tải lại</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered table-custom">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Môn thi</th>
                                        <th>Ngày tháng thi</th>
                                        <th>Thời lượng</th>
                                        <th>Sinh viên</th>
                                        <th>Trạng thái</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="tableSessionsBody">
                                    <tr><td colspan="7" class="text-center text-muted">Đang tải...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Thí Sinh Đang Thi & Mã Ca Thi Đã Nhập</h4>
                            <div class="d-flex gap-2">
                                <select id="filterSessionId" class="form-select form-select-sm" style="width:180px;" onchange="loadExamCodes()">
                                    <option value="">Tất cả ca thi</option>
                                </select>
                                <button onclick="loadExamCodes()" class="btn btn-sm btn-outline-secondary">🔄</button>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-bordered table-custom">
                                <thead>
                                    <tr>
                                        <th>Mã ca thi</th>
                                        <th>Thí sinh (Mã SV)</th>
                                        <th>Môn thi</th>
                                        <th>Thời điểm vào</th>
                                        <th>Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody id="tableCodesBody">
                                    <tr><td colspan="5" class="text-center text-muted">Đang tải danh sách thí sinh...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Script WebSocket Client -->
    <script src="../assets/js/websocket-client.js?v=<?= time() ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Danh bạ sinh viên để nhận diện tên gọi chính xác
        const studentDB = {
            '235D150109': 'Đào Quang Tiến',
            '235D150052': 'Nguyễn Hoàng Nam',
            '235D160025': 'Đặng Trần Trung',
            '235D150110': 'Hà Văn Nghị',
            '235D160028': 'Hà Minh Đức',
            '235D150115': 'Hoàng Tuấn Ngọc',
            '245D100088': 'Bùi Nhật Năng'
        };

        // Hàm trích xuất tên gọi cuối cùng của sinh viên (VD: Đào Quang Tiến -> Tiến)
        function getStudentCallName(chatName, studentId) {
            let fullName = '';
            if (studentId && studentDB[studentId]) {
                fullName = studentDB[studentId];
            } else if (chatName) {
                let clean = chatName.replace(/\(.*?\)/g, '').split('-')[0].trim();
                if (studentDB[clean]) {
                    fullName = studentDB[clean];
                } else {
                    fullName = clean;
                }
            }
            if (!fullName) fullName = studentId || 'Thí sinh';
            const parts = fullName.trim().split(/\s+/).filter(Boolean);
            const lastName = parts.length > 0 ? parts[parts.length - 1] : fullName;
            return {
                fullName: fullName,
                callName: lastName
            };
        }

        // --- THIẾT LẬP WEBSOCKET DÀNH CHO ADMIN ---
        const adminId = "<?= htmlspecialchars($admin_id) ?>";
        const adminName = "<?= htmlspecialchars($admin_name) ?>";
        const wsToken = "<?= htmlspecialchars($_SESSION['ws_token'] ?? '') ?>";
        const wsUrl = "<?= $ws_config['client_url'] ?>";

        let currentChatId = null;
        let currentStudentCallName = '';
        let allChats = [];
        let wsAdmin = null;
        let pendingAdminUnreadIds = [];

        function markAdminCurrentChatAsRead() {
            if (!wsAdmin || !wsAdmin.isConnected || !currentChatId) return;
            if (pendingAdminUnreadIds.length === 0) return;
            const ids = [...pendingAdminUnreadIds];
            pendingAdminUnreadIds = [];
            wsAdmin.markAsRead(currentChatId, ids);
        }

        function initAdminWebSocket() {
            wsAdmin = new HVUWebSocketClient({
                url: wsUrl,
                userId: adminId,
                userName: adminName,
                role: 'support',
                token: wsToken,
                onAuthSuccess: (data) => {
                    // Cập nhật trạng thái online thực tế từ RAM server, tránh online ảo
                    if (data.online_users && Array.isArray(data.online_users)) {
                        document.querySelectorAll('.online-dot').forEach(dot => dot.classList.remove('online'));
                        data.online_users.forEach(uid => {
                            const dot = document.getElementById(`dot-${uid}`);
                            if (dot) dot.classList.add('online');
                        });
                    }
                },
                onNewMessage: (msg) => {
                    // Nếu đang mở đúng phòng chat này -> render ngay
                    if (currentChatId && msg.chat_id === currentChatId) {
                        renderAdminMessage(msg);
                        scrollToBottomMsg();
                        // Theo logic Messenger: chỉ lưu vào hàng đợi chưa đọc, KHÔNG tự động chuyển đã đọc nếu chưa click/gõ
                        if (msg.sender_role !== 'support') {
                            pendingAdminUnreadIds.push(msg.id);
                        }
                    }
                    // Cập nhật danh sách chat sidebar
                    updateChatListItemPreview(msg.chat_id, msg.text, msg.time);
                },
                onMessageSent: (data) => {
                    updateAdminMsgSent(data);
                },
                onMsgStatusUpdate: (data) => {
                    updateAdminMsgStatusInDOM(data);
                },
                onPresenceUpdate: (data) => {
                    updatePresenceInSidebar(data.user_id, data.is_online);
                },
                onChatListUpdate: () => {
                    loadChats();
                },
                onHistory: (data) => {
                    if (currentChatId && data.chat_id === currentChatId) {
                        const box = document.getElementById('messagesBox');
                        box.innerHTML = '';
                        pendingAdminUnreadIds = [];
                        if (data.messages && data.messages.length > 0) {
                            data.messages.forEach(m => {
                                renderAdminMessage(m);
                                if (m.sender_role !== 'support' && m.status !== 'read') {
                                    pendingAdminUnreadIds.push(m.id);
                                }
                            });
                            scrollToBottomMsg();
                        } else {
                            box.innerHTML = '<div class="empty-state">Chưa có tin nhắn nào trong phòng này</div>';
                        }
                    }
                }
            });
        }

        // --- ĐIỀU HƯỚNG VIEW ---
        function switchView(viewName) {
            document.getElementById('view-chat').style.display = (viewName === 'chat') ? 'flex' : 'none';
            document.getElementById('view-exam').style.display = (viewName === 'exam') ? 'flex' : 'none';
            document.getElementById('view-sessions').style.display = (viewName === 'sessions') ? 'flex' : 'none';
            
            document.getElementById('btn-view-chat').classList.toggle('active', viewName === 'chat');
            document.getElementById('btn-view-exam').classList.toggle('active', viewName === 'exam');
            document.getElementById('btn-view-sessions').classList.toggle('active', viewName === 'sessions');

            if (viewName === 'exam') loadSubjects();
            if (viewName === 'sessions') {
                loadExamSessions();
                loadExamCodes();
                loadSubjectsForSessionForm();
            }
        }

        function backToList() {
            document.getElementById('view-chat').classList.remove('mobile-view-detail');
            document.getElementById('view-exam').classList.remove('mobile-view-detail');
        }

        // --- CHAT SUPPORT LOGIC ---
        // --- CHAT SUPPORT LOGIC ---
        let isLoadingChats = false;
        function loadChats(isSilent = false) {
            if (isLoadingChats) return;
            isLoadingChats = true;
            fetch('dashboard.php?ajax_action=list')
                .then(res => res.json())
                .then(data => {
                    allChats = Array.isArray(data) ? data : [];
                    const searchInput = document.getElementById('chatSearch');
                    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
                    if (query) {
                        const filtered = allChats.filter(c => (c.name || '').toLowerCase().includes(query) || (c.id || '').toLowerCase().includes(query));
                        renderChatList(filtered);
                    } else {
                        renderChatList(allChats);
                    }
                })
                .catch(err => {
                    if (!isSilent) console.error("Lỗi khi tải danh sách phiên chat:", err);
                })
                .finally(() => {
                    isLoadingChats = false;
                });
        }

        function renderChatList(chats) {
            const container = document.getElementById('chatList');
            if (!container) return;
            const prevScrollTop = container.scrollTop;

            if (!chats || chats.length === 0) {
                container.innerHTML = '<div class="text-center p-4 text-muted">Chưa có phiên chat nào</div>';
                return;
            }

            let html = '';
            chats.forEach(chat => {
                let statusClass = 'status-waiting';
                let statusText = 'Chờ';
                if (chat.status === 'attached') { statusClass = 'status-attached'; statusText = 'Đang thi'; }
                if (chat.status === 'graded') { statusClass = 'status-graded'; statusText = `Điểm: ${chat.score}`; }
                if (chat.status === 'disabled') { statusClass = 'status-disabled'; statusText = 'Đã hủy'; }
                if (chat.status === 'closed') { statusClass = 'status-closed'; statusText = 'Đóng'; }

                const isOnline = chat.is_online ? 'online' : '';
                const studentId = chat.student_id || (chat.name.match(/[0-9]{2,3}[A-Z][0-9]+/i) ? chat.name.match(/[0-9]{2,3}[A-Z][0-9]+/i)[0] : '') || chat.name.split(' - ')[0] || '';
                const nameInfo = getStudentCallName(chat.name, studentId);
                const callName = nameInfo.callName;
                const fullStudentName = nameInfo.fullName;
                const isActive = (chat.id === currentChatId) ? 'active' : '';

                const safeChatId = escapeHtml(chat.id);
                const safeName = escapeHtml(chat.name);
                const safeStudentId = escapeHtml(studentId);
                const safeCallName = escapeHtml(callName);
                const safeFullName = escapeHtml(fullStudentName);
                const timeStr = new Date(chat.created_at * 1000).toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit'});

                html += `
                    <div class="chat-item ${isActive}" id="chat-item-${safeChatId}"
                         onclick="selectChat('${safeChatId}', '${safeName.replace(/'/g, "\\'")}', '${safeStudentId}', '${safeCallName.replace(/'/g, "\\'")}', '${safeFullName.replace(/'/g, "\\'")}')">
                        <h3>
                            <span>
                                <span class="online-dot ${isOnline}" id="dot-${safeStudentId}"></span>
                                <strong style="color:#003366; font-size:14px;">${safeCallName}</strong>
                                <span style="font-size:12px; font-weight:normal; color:#555; margin-left:4px;">(${safeStudentId || 'SV'})</span>
                            </span>
                            <span class="status-badge ${statusClass}">${statusText}</span>
                        </h3>
                        <div class="chat-info">
                            <span id="preview-${safeChatId}">ID: ${safeChatId.substring(0, 12)}...</span>
                            <span>${timeStr}</span>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
            container.scrollTop = prevScrollTop;
        }

        function filterChatList() {
            const query = document.getElementById('chatSearch').value.toLowerCase();
            const filtered = allChats.filter(c => c.name.toLowerCase().includes(query) || c.id.toLowerCase().includes(query));
            renderChatList(filtered);
        }

        let isPollingActiveChat = false;
        function pollActiveChatMessages() {
            if (!currentChatId || isPollingActiveChat) return;
            isPollingActiveChat = true;
            fetch(`dashboard.php?ajax_action=fetch&chat_id=${encodeURIComponent(currentChatId)}`)
                .then(res => res.json())
                .then(data => {
                    if (data && data.status === 'success' && Array.isArray(data.messages)) {
                        let hasNew = false;
                        data.messages.forEach(m => {
                            if (m.id && !document.querySelector(`.msg[data-msg-id="${m.id}"]`)) {
                                renderAdminMessage(m);
                                hasNew = true;
                                if (m.sender_role !== 'support') {
                                    pendingAdminUnreadIds.push(m.id);
                                }
                            }
                        });
                        if (hasNew) scrollToBottomMsg();
                    }
                })
                .catch(() => {})
                .finally(() => {
                    isPollingActiveChat = false;
                });
        }

        function selectChat(chatId, chatName, studentId = '', callName = '', fullName = '') {
            currentChatId = chatId;
            currentStudentCallName = callName || getStudentCallName(chatName, studentId).callName;

            document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
            const activeItem = document.getElementById(`chat-item-${chatId}`);
            if (activeItem) activeItem.classList.add('active');

            if (!callName) {
                const info = getStudentCallName(chatName, studentId);
                callName = info.callName;
                fullName = info.fullName;
            }

            document.getElementById('currentChatName').innerHTML = `<strong style="font-size:15px; color:#003366;">${escapeHtml(callName)}</strong> <span style="font-size:12px; font-weight:normal; color:#6c757d;">(${escapeHtml(studentId)}) - ${escapeHtml(fullName)}</span>`;
            document.getElementById('currentChatSub').textContent = `Mã phiên: ${chatId}`;
            document.getElementById('chatHeader').style.display = 'flex';
            document.getElementById('inputArea').style.display = 'flex';

            document.getElementById('view-chat').classList.add('mobile-view-detail');

            // Khi người hỗ trợ bấm chọn phòng chat -> tính là tương tác xem -> đánh dấu đã đọc
            markAdminCurrentChatAsRead();

            // Yêu cầu tải lịch sử qua WebSocket
            if (wsAdmin && wsAdmin.isConnected) {
                wsAdmin.send({ action: 'get_history', chat_id: chatId });
            } else {
                // Fallback qua AJAX nếu WebSocket chưa sẵn sàng
                fetch(`dashboard.php?ajax_action=fetch&chat_id=${chatId}`)
                    .then(res => res.json())
                    .then(data => {
                        const box = document.getElementById('messagesBox');
                        box.innerHTML = '';
                        if (data.messages) {
                            data.messages.forEach(m => renderAdminMessage(m));
                            scrollToBottomMsg();
                        }
                    });
            }
        }

        function renderAdminMessage(msg) {
            const box = document.getElementById('messagesBox');
            if (!box) return;

            if (msg.id && document.querySelector(`.msg[data-msg-id="${msg.id}"]`)) return;

            let type = 'user';
            let isMe = (msg.sender_role === 'support') || (msg.sender === adminName);
            let isSystem = (msg.sender_role === 'system') || (msg.sender === 'System');

            if (isSystem) type = 'system';
            else if (isMe) type = 'admin';

            const div = document.createElement('div');
            div.className = `msg ${type}`;
            if (msg.id) div.setAttribute('data-msg-id', msg.id);

            const safeText = escapeHtml(msg.text);
            const timeStr = msg.time || new Date((msg.created_at || Date.now() / 1000) * 1000).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false });
            const tickHtml = isMe ? getStatusTickHtml(msg.status || 'sent') : '';

            if (isSystem) {
                div.innerHTML = `${safeText}`;
            } else if (isMe) {
                div.innerHTML = `<div>${safeText}</div><div class="msg-time"><span>${timeStr}</span> <span class="tick-wrap">${tickHtml}</span></div>`;
            } else {
                const sInfo = getStudentCallName(msg.sender_name || msg.sender, msg.sender_id);
                const sName = escapeHtml(sInfo.callName || currentStudentCallName || 'Thí sinh');
                div.innerHTML = `<div style="font-size:11px; font-weight:bold; color:#0056b3; margin-bottom:2px;">${sName}</div><div>${safeText}</div><div class="msg-time">${timeStr}</div>`;
            }

            box.appendChild(div);
        }

        function sendAdminMessage() {
            const input = document.getElementById('adminInput');
            const text = input.value.trim();
            if (!text || !currentChatId) return;

            if (wsAdmin && wsAdmin.isConnected) {
                const res = wsAdmin.sendMessage(text, currentChatId);
                if (res) {
                    input.value = '';
                    const timeStr = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false });
                    const box = document.getElementById('messagesBox');
                    const div = document.createElement('div');
                    div.className = 'msg admin';
                    div.setAttribute('data-client-id', res.clientMsgId);
                    div.innerHTML = `<div>${escapeHtml(text)}</div><div class="msg-time"><span>${timeStr}</span> <span class="tick-wrap">${getStatusTickHtml('sent')}</span></div>`;
                    box.appendChild(div);
                    scrollToBottomMsg();
                }
            } else {
                alert("Kết nối WebSocket đang gián đoạn, vui lòng chờ...");
            }
        }

        function handleAdminKey(e) {
            if (e.key === 'Enter') sendAdminMessage();
        }

        function gradeCurrentChat() {
            if (!currentChatId) return;
            let score = prompt("Nhập điểm số cho bài thi này (VD: 8.5):");
            if (score === null || score.trim() === '') return;

            if (wsAdmin && wsAdmin.isConnected) {
                wsAdmin.gradeCandidate(currentChatId, score.trim());
                alert(`Đã gửi điểm ${score} tới thí sinh qua WebSocket!`);
            } else {
                const fd = new FormData();
                fd.append('chat_id', currentChatId);
                fd.append('score', score);
                fetch('dashboard.php?ajax_action=grade', { method: 'POST', body: fd })
                    .then(res => res.json())
                    .then(() => alert(`Đã gửi điểm ${score} thành công!`));
            }
            loadChats();
        }

        function deleteCurrentChat() {
            if (!currentChatId || !confirm("Xóa vĩnh viễn đoạn chat này?")) return;
            const fd = new FormData();
            fd.append('chat_id', currentChatId);
            fetch('dashboard.php?ajax_action=delete', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(() => {
                    currentChatId = null;
                    document.getElementById('chatHeader').style.display = 'none';
                    document.getElementById('inputArea').style.display = 'none';
                    document.getElementById('messagesBox').innerHTML = '<div class="empty-state">Đã xóa phiên chat</div>';
                    loadChats();
                });
        }

        function updateChatListItemPreview(chatId, text, time) {
            const preview = document.getElementById(`preview-${chatId}`);
            if (preview) {
                preview.textContent = text.length > 25 ? text.substring(0, 25) + '...' : text;
                preview.style.fontWeight = 'bold';
                preview.style.color = '#0056b3';
            }
        }

        function updatePresenceInSidebar(userId, isOnline) {
            const dot = document.getElementById(`dot-${userId}`);
            if (dot) {
                dot.className = `online-dot ${isOnline ? 'online' : ''}`;
            }
        }

        function updateAdminMsgSent(data) {
            if (data.client_msg_id) {
                const node = document.querySelector(`[data-client-id="${data.client_msg_id}"]`);
                if (node) {
                    node.setAttribute('data-msg-id', data.msg_id);
                    const tick = node.querySelector('.tick-wrap');
                    if (tick) tick.innerHTML = getStatusTickHtml(data.status);
                }
            }
        }

        function updateAdminMsgStatusInDOM(data) {
            const status = data.status;
            if (data.msg_id) {
                const node = document.querySelector(`.msg[data-msg-id="${data.msg_id}"] .tick-wrap`);
                if (node) node.innerHTML = getStatusTickHtml(status);
            }
            if (data.msg_ids && Array.isArray(data.msg_ids)) {
                data.msg_ids.forEach(id => {
                    const node = document.querySelector(`.msg[data-msg-id="${id}"] .tick-wrap`);
                    if (node) node.innerHTML = getStatusTickHtml(status);
                });
            }
        }

        function scrollToBottomMsg() {
            const box = document.getElementById('messagesBox');
            if (box) box.scrollTop = box.scrollHeight;
        }

        // --- EXAM JSON MANAGEMENT (BẢO TOÀN 100%) ---
        let currentSubjectFile = null;

        function loadSubjects() {
            fetch('dashboard.php?ajax_action=list_subjects')
                .then(res => res.json())
                .then(data => {
                    const container = document.getElementById('subjectList');
                    container.innerHTML = '';
                    data.data.forEach(sub => {
                        const div = document.createElement('div');
                        div.className = `exam-list-item ${sub.filename === currentSubjectFile ? 'active' : ''}`;
                        div.innerHTML = `
                            <span onclick="selectSubject('${sub.filename}')" style="flex:1;">${sub.name}</span>
                            <button class="btn-sm-danger" onclick="deleteSubject('${sub.filename}')">Xóa</button>
                        `;
                        container.appendChild(div);
                    });
                });
        }

        function createSubject() {
            const name = document.getElementById('newSubjectName').value.trim();
            if (!name) return alert('Vui lòng nhập tên file');
            const fd = new FormData(); fd.append('name', name);
            fetch('dashboard.php?ajax_action=create_subject', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('newSubjectName').value = '';
                        loadSubjects();
                    } else alert(data.message);
                });
        }

        function uploadSubject() {
            const fileInput = document.getElementById('uploadSubjectFile');
            if (!fileInput.files[0]) return alert('Vui lòng chọn file .json');
            const fd = new FormData();
            fd.append('subject_file', fileInput.files[0]);
            fetch('dashboard.php?ajax_action=upload_subject', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        fileInput.value = '';
                        loadSubjects();
                    } else alert('Lỗi: ' + data.message);
                });
        }

        function deleteSubject(filename) {
            if (!confirm(`Xóa file ${filename}? Hành động này không thể hoàn tác.`)) return;
            const fd = new FormData(); fd.append('filename', filename);
            fetch('dashboard.php?ajax_action=delete_subject', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (currentSubjectFile === filename) {
                            currentSubjectFile = null;
                            document.getElementById('examHeader').style.display = 'none';
                            document.getElementById('questionList').innerHTML = '<div class="empty-state">Đã xóa file</div>';
                        }
                        loadSubjects();
                    } else alert(data.message);
                });
        }

        function selectSubject(filename) {
            currentSubjectFile = filename;
            document.getElementById('currentSubjectName').textContent = filename;
            document.getElementById('examHeader').style.display = 'flex';
            loadSubjects();
            document.getElementById('view-exam').classList.add('mobile-view-detail');
            
            fetch(`dashboard.php?ajax_action=get_questions&filename=${filename}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        const container = document.getElementById('questionList');
                        const jsonStr = JSON.stringify(data.data, null, 4);
                        const count = Array.isArray(data.data) ? data.data.length : 0;
                        container.innerHTML = `
                            <div style="padding: 10px; display: flex; flex-direction: column; height: 100%;">
                                <div style="margin-bottom: 8px; font-size: 13px; color: #666;">
                                    <span id="jsonStatus" style="color: #28a745;">&#10003; JSON hợp lệ</span>
                                    &nbsp;|&nbsp; <strong>${count}</strong> câu hỏi
                                </div>
                                <textarea id="jsonEditor" style="flex:1; width:100%; min-height:420px; font-family:'Consolas','Courier New',monospace; font-size:13px; border:1px solid #ddd; border-radius:6px; padding:12px; resize:vertical; tab-size:4; line-height:1.5;" oninput="validateJSON()">${escapeHtml(jsonStr)}</textarea>
                            </div>
                        `;
                    } else alert(data.message);
                });
        }

        function validateJSON() {
            const textarea = document.getElementById('jsonEditor');
            const status = document.getElementById('jsonStatus');
            try {
                const data = JSON.parse(textarea.value);
                const count = Array.isArray(data) ? data.length : 0;
                status.innerHTML = `&#10003; JSON hợp lệ - <strong>${count}</strong> câu hỏi`;
                status.style.color = '#28a745';
                textarea.style.borderColor = '#28a745';
            } catch(e) {
                status.innerHTML = `&#10007; JSON lỗi: ${e.message}`;
                status.style.color = '#dc3545';
                textarea.style.borderColor = '#dc3545';
            }
        }

        function formatJSON() {
            const textarea = document.getElementById('jsonEditor');
            if (!textarea) return;
            try {
                const data = JSON.parse(textarea.value);
                textarea.value = JSON.stringify(data, null, 4);
                validateJSON();
            } catch(e) {
                alert('Không thể định dạng: JSON đang bị lỗi cú pháp.\n' + e.message);
            }
        }

        function saveQuestions() {
            if (!currentSubjectFile) return;
            const textarea = document.getElementById('jsonEditor');
            if (!textarea) return;

            let questions;
            try {
                questions = JSON.parse(textarea.value);
            } catch(e) {
                alert('JSON bị lỗi cú pháp, không thể lưu!\n' + e.message);
                return;
            }

            const fd = new FormData();
            fd.append('filename', currentSubjectFile);
            fd.append('questions', JSON.stringify(questions));
            
            fetch('dashboard.php?ajax_action=save_questions', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') alert('Đã lưu đề thi thành công!');
                    else alert('Lỗi: ' + data.message);
                });
        }

        // --- QUẢN LÝ ĐỢT THI & CẤP MÃ (SESSIONS & CODES) ---
        function loadSubjectsForSessionForm() {
            fetch('dashboard.php?ajax_action=list_subjects')
                .then(res => res.json())
                .then(data => {
                    const select = document.getElementById('sessionExamJson');
                    select.innerHTML = '<option value="">-- Chọn đề thi JSON --</option>';
                    data.data.forEach(sub => {
                        select.innerHTML += `<option value="${sub.filename}">${sub.name} (${sub.filename})</option>`;
                    });
                });
        }

        function loadExamSessions() {
            fetch('dashboard.php?ajax_action=list_exam_sessions')
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('tableSessionsBody');
                    const codeSelect = document.getElementById('codeSessionSelect');
                    const filterSelect = document.getElementById('filterSessionId');

                    tbody.innerHTML = '';
                    filterSelect.innerHTML = '<option value="">Tất cả ca thi</option>';

                    if (!data.data || data.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Chưa có ca thi nào</td></tr>';
                        return;
                    }

                    examSessionsById = {};
                    data.data.forEach(s => {
                        examSessionsById[s.id] = s;
                        filterSelect.innerHTML += `<option value="${s.id}">${escapeHtml(s.name)}</option>`;

                        const statusBadge = s.is_active 
                            ? '<span class="badge bg-success">Đang mở</span>' 
                            : '<span class="badge bg-secondary">Tạm đóng</span>';

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${s.id}</td>
                            <td><strong>${escapeHtml(s.name)}</strong>${s.code ? `<br><small>${escapeHtml(s.code)}</small>` : ''}<br><small class="text-muted"><code>${escapeHtml(s.exam_json)}</code></small></td>
                            <td>${escapeHtml(s.starts_at || 'Mở tự do')}</td>
                            <td>${s.duration_minutes} phút</td>
                            <td title="${escapeHtml((s.students || []).join(', '))}">${(s.students || []).length ? (s.students.length + ' SV') : '<span class="text-danger">Chưa gán</span>'}${(() => { const n = (s.student_rows || []).filter(r => r.start_ts > 0 || r.duration_minutes > 0).length; return n ? `<br><small class="text-warning-emphasis">${n} giờ riêng</small>` : ''; })()}</td>
                            <td>${statusBadge}</td>
                            <td>
                                <button onclick="editExamSession(${s.id})" class="btn btn-xs btn-primary btn-sm py-0 px-2" style="font-size:11px;">Sửa / Gán SV</button>
                                <button onclick="toggleExamSession(${s.id})" class="btn btn-xs btn-${s.is_active ? 'warning' : 'success'} btn-sm py-0 px-2" style="font-size:11px;">
                                    ${s.is_active ? 'Đóng' : 'Mở'}
                                </button>
                                <button onclick="deleteExamSession(${s.id})" class="btn btn-xs btn-danger btn-sm py-0 px-2" style="font-size:11px;">Xóa</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                });
        }

        let examSessionsById = {};

        function handleCreateSession(e) {
            e.preventDefault();
            const editId = document.getElementById('sessionEditId').value;
            const fd = new FormData();
            if (editId) fd.append('id', editId);
            fd.append('name', document.getElementById('sessionName').value.trim());
            fd.append('code', document.getElementById('sessionCode').value.trim());
            fd.append('batch_name', document.getElementById('sessionBatch').value.trim());
            fd.append('school_year', document.getElementById('sessionYear').value.trim());
            fd.append('semester', document.getElementById('sessionSemester').value.trim());
            fd.append('exam_json', document.getElementById('sessionExamJson').value);
            fd.append('duration_minutes', document.getElementById('sessionDuration').value);
            fd.append('starts_at', document.getElementById('sessionStartsAt').value);
            fd.append('students', document.getElementById('sessionStudents').value);

            const action = editId ? 'update_exam_session' : 'create_exam_session';
            fetch('dashboard.php?ajax_action=' + action, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message);
                        resetSessionForm();
                        loadExamSessions();
                    } else alert(data.message);
                });
        }

        function editExamSession(id) {
            const s = examSessionsById[id];
            if (!s) return;
            document.getElementById('sessionEditId').value = s.id;
            document.getElementById('sessionName').value = s.name || '';
            document.getElementById('sessionCode').value = s.code || '';
            document.getElementById('sessionBatch').value = s.batch_name || 'Đợt thi 1';
            document.getElementById('sessionYear').value = s.school_year || '';
            document.getElementById('sessionSemester').value = s.semester || '';
            const examSelect = document.getElementById('sessionExamJson');
            if (![...examSelect.options].some(o => o.value === s.exam_json)) {
                examSelect.innerHTML += `<option value="${escapeHtml(s.exam_json)}">${escapeHtml(s.exam_json)}</option>`;
            }
            examSelect.value = s.exam_json;
            document.getElementById('sessionDuration').value = s.duration_minutes;
            document.getElementById('sessionStartsAt').value = s.start_input || '';
            document.getElementById('sessionStudents').value = (s.students || []).join('\n');
            renderStudentTimes(s);
            document.getElementById('sessionFormTitle').textContent = '1. Sửa Ca Thi #' + s.id;
            document.getElementById('btnSaveSession').textContent = 'Lưu thay đổi';
            document.getElementById('btnCancelEditSession').style.display = 'block';
            document.getElementById('formCreateSession').scrollIntoView({ behavior: 'smooth' });
        }

        function resetSessionForm() {
            document.getElementById('formCreateSession').reset();
            document.getElementById('sessionEditId').value = '';
            document.getElementById('sessionFormTitle').textContent = '1. Thiết Lập Ca Thi Mới';
            document.getElementById('btnSaveSession').textContent = '+ Lưu Ca Thi';
            document.getElementById('btnCancelEditSession').style.display = 'none';
            document.getElementById('studentTimesBox').style.display = 'none';
        }

        // Bảng giờ thi riêng từng sinh viên trong form sửa ca thi
        function renderStudentTimes(s) {
            const box = document.getElementById('studentTimesBox');
            const tbody = document.getElementById('studentTimesBody');
            const rows = s.student_rows || [];
            box.style.display = rows.length ? 'block' : 'none';
            tbody.innerHTML = '';
            rows.forEach(r => {
                const custom = r.start_ts > 0 || r.duration_minutes > 0;
                const tr = document.createElement('tr');
                if (custom) tr.className = 'table-warning';
                tr.innerHTML = `
                    <td style="vertical-align:middle;"><strong>${escapeHtml(r.student_code)}</strong></td>
                    <td><input type="datetime-local" class="form-control form-control-sm stu-start" value="${r.start_input || s.start_input || ''}"></td>
                    <td><input type="number" min="1" class="form-control form-control-sm stu-duration" value="${r.duration_minutes || ''}" placeholder="${s.duration_minutes}"></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-primary btn-sm py-0 px-2" style="font-size:11px;" data-act="save">Lưu</button>
                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:11px;" data-act="shift" data-min="5">+5'</button>
                        <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:11px;" data-act="shift" data-min="10">+10'</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:11px;" data-act="reset">Mặc định</button>
                    </td>`;
                tr.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => {
                    const fd = new FormData();
                    fd.append('session_id', s.id);
                    fd.append('student_code', r.student_code);
                    if (btn.dataset.act === 'shift') {
                        fd.append('shift_minutes', btn.dataset.min);
                    } else if (btn.dataset.act === 'save') {
                        const start = tr.querySelector('.stu-start').value;
                        fd.append('starts_at', start === s.start_input ? '' : start);
                        fd.append('duration_minutes', tr.querySelector('.stu-duration').value);
                    } else {
                        fd.append('starts_at', '');
                        fd.append('duration_minutes', '');
                    }
                    saveStudentTime(s.id, fd);
                }));
                tbody.appendChild(tr);
            });
        }

        function saveStudentTime(sessionId, fd) {
            fetch('dashboard.php?ajax_action=set_student_exam_time', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status !== 'success') { alert(data.message); return; }
                    return fetch('dashboard.php?ajax_action=list_exam_sessions')
                        .then(res => res.json())
                        .then(list => {
                            (list.data || []).forEach(x => { examSessionsById[x.id] = x; });
                            if (examSessionsById[sessionId]) renderStudentTimes(examSessionsById[sessionId]);
                        });
                });
        }

        function toggleExamSession(id) {
            const fd = new FormData(); fd.append('id', id);
            fetch('dashboard.php?ajax_action=toggle_exam_session', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') loadExamSessions();
                    else alert(data.message);
                });
        }

        function deleteExamSession(id) {
            if (!confirm("Xóa ca thi này?")) return;
            const fd = new FormData(); fd.append('id', id);
            fetch('dashboard.php?ajax_action=delete_exam_session', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        loadExamSessions();
                        loadExamCodes();
                    } else alert(data.message);
                });
        }

        function loadExamCodes(isSilent = false) {
            const filterEl = document.getElementById('filterSessionId');
            const sessionId = filterEl ? filterEl.value : '';
            fetch(`dashboard.php?ajax_action=list_exam_codes&session_id=${sessionId}`)
                .then(res => res.json())
                .then(data => {
                    const tbody = document.getElementById('tableCodesBody');
                    if (!tbody) return;
                    if (!data.data || data.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Chưa có thí sinh nào vào thi</td></tr>';
                        return;
                    }

                    let html = '';
                    data.data.forEach(c => {
                        let stBadge = '<span class="badge bg-primary">Đang thi</span>';
                        if (c.status === 'completed') stBadge = '<span class="badge bg-success">Hoàn thành</span>';
                        if (c.status === 'disabled') stBadge = '<span class="badge bg-danger">Vô hiệu</span>';
                        if (c.status === 'unused') stBadge = '<span class="badge bg-secondary">Chưa thi</span>';

                        const usedTime = c.used_at ? new Date(c.used_at * 1000).toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit', second:'2-digit'}) : '-';

                        html += `
                            <tr>
                                <td><strong style="letter-spacing:1px; color:#003366; font-size:13.5px;">${escapeHtml(c.code)}</strong></td>
                                <td><code>${escapeHtml(c.used_by || c.student_code || 'Chưa rõ')}</code></td>
                                <td style="font-size:12px;"><strong>${c.session_name ? escapeHtml(c.session_name) : `<span class="text-danger">Ca #${c.exam_session_id} (không tồn tại)</span>`}</strong></td>
                                <td>${usedTime}</td>
                                <td>${stBadge}</td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                })
                .catch(err => {
                    if (!isSilent) console.error("Lỗi khi tải mã ca thi:", err);
                });
        }

        function handleGenerateCodes(e) {
            e.preventDefault();
            const sessionId = document.getElementById('codeSessionSelect').value;
            const count = document.getElementById('codeCount').value;
            const studentCode = document.getElementById('codeStudent').value;
            const customCode = document.getElementById('codeCustom').value;

            const fd = new FormData();
            fd.append('exam_session_id', sessionId);
            fd.append('count', count);
            fd.append('student_code', studentCode);
            fd.append('custom_code', customCode);

            fetch('dashboard.php?ajax_action=generate_exam_codes', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(data.message + "\n\nDanh sách mã: " + data.codes.join(', '));
                        document.getElementById('codeCustom').value = '';
                        loadExamCodes();
                    } else alert(data.message);
                });
        }

        function toggleExamCode(id) {
            const fd = new FormData(); fd.append('id', id);
            fetch('dashboard.php?ajax_action=toggle_exam_code', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') loadExamCodes();
                    else alert(data.message);
                });
        }

        function deleteExamCode(id) {
            if (!confirm("Xóa mã dự thi này?")) return;
            const fd = new FormData(); fd.append('id', id);
            fetch('dashboard.php?ajax_action=delete_exam_code', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') loadExamCodes();
                    else alert(data.message);
                });
        }

        // --- KHỞI CHẠY TRANG ---
        window.onload = function() {
            initAdminWebSocket();
            loadChats();

            // Tự động làm mới danh sách phiên chat định kỳ qua AJAX (mỗi 3.5 giây)
            setInterval(() => {
                loadChats(true);
            }, 3500);

            // Tự động kiểm tra tin nhắn mới trong phòng chat đang mở (mỗi 3 giây)
            setInterval(() => {
                pollActiveChatMessages();
            }, 3000);

            // Tự động làm mới danh sách thí sinh / mã dự thi khi đang ở tab Ca thi (mỗi 4 giây)
            setInterval(() => {
                const sessionsView = document.getElementById('view-sessions');
                if (sessionsView && sessionsView.style.display !== 'none') {
                    loadExamCodes(true);
                }
            }, 4000);

            // Gắn sự kiện tương tác Facebook Messenger: chỉ khi click/focus/gõ vào khung chat mới tính là ĐÃ ĐỌC
            const msgBox = document.getElementById('messagesBox');
            if (msgBox) {
                msgBox.addEventListener('click', () => markAdminCurrentChatAsRead());
            }
            const adminInput = document.getElementById('adminInput');
            if (adminInput) {
                adminInput.addEventListener('focus', () => markAdminCurrentChatAsRead());
                adminInput.addEventListener('input', () => markAdminCurrentChatAsRead());
            }
        };
    </script>
</body>
</html>