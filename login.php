<?php
session_start();

// --- XỬ LÝ AJAX (Chuyển sang ajax_handler.php tập trung) ---
if (isset($_GET['ajax_action'])) {
    require_once 'ajax_handler.php';
    exit;
}
// --- KẾT THÚC XỬ LÝ AJAX ---

// Lấy danh sách các file .json trong thư mục hiện tại
$json_files = glob('*.json');

// Xử lý khi form được submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config.php'; // Kết nối DB để tạo chat tự động

    $fullname = $_POST['fullname'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    $exam_id = $_POST['exam_id'] ?? '';
    $subject_file = $_POST['subject_file'] ?? '';
    $chat_id = $_POST['chat_id'] ?? ''; // Lấy ID chat từ form ẩn
    $duration = $_POST['duration'] ?? 45; // Mặc định 45 phút
    $question_limit = $_POST['question_limit'] ?? 30; // Mặc định 30 câu

    if (!empty($fullname) && !empty($student_id) && !empty($exam_id) && !empty($subject_file)) {
        // Lưu thông tin vào session
        $_SESSION['fullname'] = $fullname;
        $_SESSION['student_id'] = $student_id;
        $_SESSION['exam_id'] = $exam_id;
        $_SESSION['subject_file'] = $subject_file;
        $_SESSION['duration'] = $duration;
        $_SESSION['question_limit'] = $question_limit;
        $_SESSION['chat_sender'] = $fullname; // Lưu tên người chat vào session
        if(!empty($chat_id)) $_SESSION['chat_id'] = $chat_id; // Lưu chat ID để index.php mở lại
        
        // Nếu chưa có chat ID (chưa tạo nhanh), tự động tạo mới
        if(empty($chat_id)) {
            $chat_id = uniqid('chat_');
            $subject_name = basename($subject_file, '.json');
            $chat_name = "$fullname - $subject_name"; // Tên chat: Nguyễn Văn A - mang_may_tinh
            
            db_insert('chats', [
                'id' => $chat_id,
                'name' => $chat_name,
                'status' => 'waiting',
                'created_at' => time()
            ]);
        }

        $_SESSION['chat_id'] = $chat_id; // Lưu chat ID để index.php mở lại

        // Chuyển hướng vào trang làm bài
        header('Location: index.php');
        exit();
    } else {
        $error = "Vui lòng nhập đầy đủ thông tin!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập hệ thống thi</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f1f1f1;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-card {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            width: 350px;
        }
        .login-card h2 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #333;
            text-align: center;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn-login {
            width: 100%;
            padding: 10px;
            background-color: #0056b3;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
            font-weight: bold;
        }
        .btn-login:hover {
            background-color: #004494;
        }
        .error {
            color: #dc3545;
            margin-bottom: 15px;
            font-size: 14px;
            text-align: center;
        }
        .admin-tools {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 13px;
        }
        .admin-tools a, .admin-tools button {
            color: #666;
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
        }
        .admin-tools a:hover, .admin-tools button:hover {
            color: #0056b3;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Thông tin thí sinh</h2>
        <?php if(isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>Mã sinh viên</label>
                <input type="text" name="student_id" id="student_id" required placeholder="Nhập mã sinh viên" oninput="lookupStudent()" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Họ và tên</label>
                <input type="text" id="fullname_display" readonly placeholder="Tự động điền khi nhập đúng mã SV" style="background: #f5f5f5; color: #555;">
                <input type="hidden" name="fullname" id="fullname">
            </div>
            <div class="form-group">
                <label>Mã bài thi</label>
                <input type="text" name="exam_id" required placeholder="Nhập mã bài thi">
            </div>
            <div class="form-group">
                <label>Môn thi</label>
                <select name="subject_file" required>
                    <?php foreach ($json_files as $file): ?>
                        <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars(basename($file, '.json')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Thời gian làm bài (phút)</label>
                <input type="number" name="duration" value="60" min="1" required placeholder="Nhập số phút">
            </div>
            <div class="form-group">
                <label>Số lượng câu hỏi</label>
                <input type="number" name="question_limit" value="40" min="1" max="60" required placeholder="Nhập số lượng câu (VD: 60)">
            </div>
            <!-- Input ẩn để lưu ID chat nếu đã tạo -->
            <input type="hidden" name="chat_id" id="hidden_chat_id">
            <button type="submit" class="btn-login">Vào thi</button>
        </form>

        <!-- Khu vực dành cho Admin -->
        <div class="admin-tools">
            <a href="dashboard.php" target="_blank">Admin Dashboard</a>
            <span style="margin: 0 5px; color: #ccc;">|</span>
            <button type="button" onclick="quickCreateChat()">Tạo Chat Nhanh</button>
        </div>
    </div>

    <!-- Include Chat Popup -->
    <?php include 'chat_popup.php'; ?>

    <script>
        // Danh sách sinh viên (mã SV -> tên) - so sánh không phân biệt hoa thường
        const studentDB = {
            '235D150109': 'Đào Quang Tiến',
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
                displayEl.style.color = '#28a745';
            } else {
                displayEl.value = '';
                hiddenEl.value = '';
                displayEl.style.color = '#555';
            }
        }

        let activeChatId = null;
        let lastMessageId = 0;
        let isPolling = false;
        let currentChatName = ""; // Lưu tên chat để tìm lại nếu mất kết nối
        
        // TẠO SESSION NGẪU NHIÊN CHO ADMIN (Lưu vào sessionStorage của trình duyệt)
        // Giúp tách biệt hoàn toàn với session của Sinh viên khi test trên cùng máy
        let currentUser = sessionStorage.getItem('dashboard_user');
        if (!currentUser) {
            // Tạo tên ngẫu nhiên: Support_ + 4 ký tự cuối của thời gian
            currentUser = 'Support_' + Date.now().toString().slice(-4);
            sessionStorage.setItem('dashboard_user', currentUser);
        }

        function quickCreateChat() {
            const name = prompt("Nhập tên gợi nhớ cho phòng chat (VD: Phòng thi 01):");
            if (!name) return;

            const formData = new FormData();
            formData.append('name', name);

            fetch('login.php?ajax_action=create', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text()) // Đổi sang text() để kiểm tra lỗi PHP nếu có
            .then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error("Server Error Response:", text);
                    throw new Error("Lỗi dữ liệu từ server (Xem console để biết chi tiết)");
                }
            })
            .then(data => {
                if(data.status === 'success') {
                    activeChatId = data.chat_id;
                    currentChatName = name; // Lưu tên
                    document.getElementById('hidden_chat_id').value = activeChatId; // Gán vào form
                    document.getElementById('chat-title').textContent = name;
                    toggleChatPopup(true);
                    startChatPolling();
                } else {
                    alert("Lỗi: " + (data.message || 'Không thể tạo chat'));
                }
            })
            .catch(err => alert("Lỗi: " + err.message));
        }

        function requestChatRecovery() {
            if (!activeChatId) {
                alert("Vui lòng chọn một cuộc trò chuyện trước khi tạo mới.");
                return;
            }
            if (!confirm("Bạn có chắc muốn tạo một đoạn chat mới và kéo sinh viên sang? Đoạn chat cũ sẽ bị ngắt kết nối.")) {
                return;
            }

            // 1. Gửi một tin nhắn lệnh đặc biệt để kích hoạt client của sinh viên
            const fd = new FormData();
            fd.append('chat_id', activeChatId);
            fd.append('text', '__RECOVER__'); // Lệnh đặc biệt
            fd.append('sender', 'System');
            
            fetch('login.php?ajax_action=send', {
                method: 'POST',
                body: fd
            }).then(() => {
                // 2. Ngay lập tức bắt đầu tìm kiếm phòng chat thay thế
                console.log("Recovery command sent. Admin is now attempting to reconnect...");
                const body = document.getElementById('chat-body');
                if(body) body.innerHTML += '<div class="chat-msg system">Đã gửi yêu cầu tạo chat mới. Đang chờ kết nối lại...</div>';
                // Hàm này đã có sẵn, sẽ lặp lại cho đến khi tìm thấy phòng mới
                attemptReconnect();
            }).catch(err => alert("Không thể gửi yêu cầu tạo chat mới. Lỗi: " + err));
        }

        function toggleChatPopup(forceOpen = false) {
            const popup = document.getElementById('chat-popup');
            if (forceOpen) popup.classList.add('active');
            else popup.classList.toggle('active');
        }

        function copyLink() {
            if(!activeChatId) return;
            const baseUrl = window.location.href.substring(0, window.location.href.lastIndexOf('/'));
            const link = `${baseUrl}/index.php?chat_id=${activeChatId}`;
            navigator.clipboard.writeText(link).then(() => alert('Đã copy link! Gửi cho thí sinh để bắt đầu.'));
        }

        function startChatPolling() {
            if (isPolling) return;
            isPolling = true;
            pollMessages();
        }

        function pollMessages() {
            if (!activeChatId) { isPolling = false; return; }
            
            fetch(`login.php?ajax_action=fetch&chat_id=${activeChatId}&last_id=${lastMessageId}&t=${Date.now()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'error') {
                        console.warn("Chat deleted or error. Attempting reconnect...");
                        attemptReconnect();
                        return;
                    }

                    // Nếu chat bị vô hiệu hóa (do user tạo mới), tự động tìm chat thay thế
                    if (data.chat_status === 'disabled') {
                        console.warn("Chat disabled. Switching to new session...");
                        attemptReconnect();
                        return;
                    }

                    if (data.status === 'success' && data.messages.length > 0) {
                        renderMessages(data.messages);
                        lastMessageId = data.messages[data.messages.length - 1].id;
                    }
                    if (data.chat_status !== 'closed') setTimeout(pollMessages, 3000); // 3s polling
                })
                .catch(err => {
                    console.error("Polling error", err);
                    // Nếu lỗi mạng hoặc lỗi server, thử tìm phòng thay thế
                    if (activeChatId) attemptReconnect();
                });
        }

        function attemptReconnect() {
            isPolling = false;
            const fd = new FormData();
            fd.append('old_chat_id', activeChatId);
            fd.append('chat_name', currentChatName); // Dùng tên để tìm

            fetch('login.php?ajax_action=find_replacement', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    console.log("Found replacement chat:", data.new_chat_id);
                    activeChatId = data.new_chat_id;
                    lastMessageId = 0; // Reset để load lại từ đầu
                    
                    const body = document.getElementById('chat-body');
                    body.innerHTML += '<div class="chat-msg system">Đã chuyển sang đoạn chat mới do lỗi kết nối.</div>';
                    
                    isPolling = true;
                    pollMessages();
                } else {
                    // Chưa tìm thấy, thử lại sau 2s
                    setTimeout(attemptReconnect, 2000);
                }
            })
            .catch(() => setTimeout(attemptReconnect, 2000));
        }

        function renderMessages(messages) {
            const body = document.getElementById('chat-body');
            messages.forEach(msg => {
                // Bỏ qua, không hiển thị các tin nhắn lệnh
                if (msg.text === '__RECOVER__') return;

                const div = document.createElement('div');
                // Ở đây người tạo là Support/Admin
                const sender = msg.sender;
                let type = 'other';

                // So sánh với currentUser (được lấy từ sessionStorage)
                if (sender === currentUser) {
                    type = 'me';
                }
                
                div.className = `chat-msg ${type}`;
                
                if (type === 'me') {
                    div.innerHTML = `${msg.text}`;
                } else {
                    div.innerHTML = `<strong>${sender}</strong>: ${msg.text}`;
                }
                
                body.appendChild(div);
            });
            body.scrollTop = body.scrollHeight;
        }

        // --- HÀM CHẤM ĐIỂM (Mới) ---
        function finishExam() {
            if (!activeChatId) return;
            const score = prompt("Nhập điểm số cho bài thi này (0-10):");
            if (score === null || score.trim() === "") return;

            const fd = new FormData();
            fd.append('chat_id', activeChatId);
            fd.append('score', score);

            fetch('login.php?ajax_action=update_score', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    alert("Đã chấm điểm thành công! Sinh viên có thể xem kết quả.");
                    // Gửi tin nhắn hệ thống báo đã chấm
                    const input = document.getElementById('chat-input');
                    input.value = "Giám thị đã chấm điểm bài thi.";
                    sendChatMessage();
                } else {
                    alert("Lỗi khi cập nhật điểm.");
                }
            });
        }

        function sendChatMessage() {
            const input = document.getElementById('chat-input');
            const text = input.value.trim();
            if (!text || !activeChatId) return;

            // Clear input immediately
            input.value = '';

            const fd = new FormData();
            fd.append('chat_id', activeChatId);
            fd.append('text', text);
            fd.append('sender', currentUser); // Gửi kèm session ID ngẫu nhiên

            fetch('login.php?ajax_action=send', {
                method: 'POST',
                body: fd
            }).catch(err => console.error("Send error", err));
        }

        function handleChatKey(e) {
            if (e.key === 'Enter') sendChatMessage();
        }

        function deleteChat() {
            if (!activeChatId) return;
            if (!confirm("Xóa đoạn chat này?")) return;

            const fd = new FormData();
            fd.append('chat_id', activeChatId);
            fetch('login.php?ajax_action=delete', { method: 'POST', body: fd })
                .then(() => {
                    activeChatId = null;
                    toggleChatPopup(false);
                });
        }
    </script>
</body>
</html>