<?php
session_start();

// --- XỬ LÝ AJAX (Chuyển sang ajax_handler.php tập trung) ---
if (isset($_GET['ajax_action'])) {
    require_once 'ajax_handler.php';
    exit;
}
// --- KẾT THÚC XỬ LÝ AJAX ---
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Chat Support</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; background: #f0f2f5; height: 100vh; display: flex; flex-direction: column; }
        header { background: #0056b3; color: white; padding: 10px 20px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .main-layout { display: flex; flex: 1; overflow: hidden; }
        
        /* Sidebar: Danh sách chat */
        .sidebar { width: 350px; background: white; border-right: 1px solid #ddd; display: flex; flex-direction: column; }
        .create-box { padding: 15px; border-bottom: 1px solid #eee; background: #f9f9f9; }
        .create-box input { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .create-box button { width: 100%; padding: 8px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; }
        
        .chat-list { flex: 1; overflow-y: auto; }
        .chat-item { padding: 15px; border-bottom: 1px solid #f1f1f1; cursor: pointer; transition: 0.2s; }
        .chat-item:hover { background: #f0f8ff; }
        .chat-item.active { background: #e6f2ff; border-left: 4px solid #0056b3; }
        .chat-item h3 { margin: 0 0 5px 0; font-size: 15px; color: #333; }
        .chat-info { font-size: 12px; color: #666; display: flex; justify-content: space-between; }
        .status-badge { padding: 2px 6px; border-radius: 10px; font-size: 10px; text-transform: uppercase; }
        .status-waiting { background: #ffc107; color: #333; }
        .status-attached { background: #28a745; color: white; }
        .status-disabled { background: #dc3545; color: white; } /* Màu đỏ cho disabled */
        .status-closed { background: #6c757d; color: white; }

        /* Chat Area: Khung chat chính */
        .chat-area { flex: 1; display: flex; flex-direction: column; background: #fff; }
        .chat-area-header { padding: 15px; border-bottom: 1px solid #ddd; background: #f8f9fa; font-weight: bold; display: flex; justify-content: space-between; }
        .chat-messages { flex: 1; padding: 20px; overflow-y: auto; background: #e5ddd5; display: flex; flex-direction: column; gap: 10px; }
        
        .msg { max-width: 70%; padding: 8px 12px; border-radius: 8px; font-size: 14px; line-height: 1.4; position: relative; }
        .msg.admin { align-self: flex-end; background: #dcf8c6; }
        .msg.user { align-self: flex-start; background: white; }
        .msg.system { align-self: center; background: rgba(255,255,255,0.8); font-size: 12px; color: #555; font-style: italic; border-radius: 20px; padding: 5px 15px; }
        .msg-time { font-size: 10px; color: #999; display: block; text-align: right; margin-top: 4px; }

        .chat-input-area { padding: 15px; border-top: 1px solid #ddd; background: #f0f0f0; display: flex; gap: 10px; }
        .chat-input-area input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; outline: none; }
        .chat-input-area button { padding: 10px 20px; background: #0056b3; color: white; border: none; border-radius: 20px; cursor: pointer; }
        
        .empty-state { flex: 1; display: flex; align-items: center; justify-content: center; color: #888; font-size: 18px; }
        .btn-copy { font-size: 11px; color: #0056b3; text-decoration: underline; cursor: pointer; border: none; background: none; padding: 0; }

        /* Styles cho Quản lý Đề thi */
        .nav-btn { background: transparent; border: 1px solid rgba(255,255,255,0.5); color: white; padding: 5px 15px; border-radius: 4px; cursor: pointer; margin-left: 10px; }
        .nav-btn.active { background: white; color: #0056b3; font-weight: bold; }
        
        .exam-list-item { padding: 10px 15px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .exam-list-item:hover { background: #f0f8ff; }
        .exam-list-item.active { background: #e6f2ff; border-left: 4px solid #0056b3; }
        .btn-sm-danger { background: #dc3545; color: white; border: none; padding: 2px 6px; border-radius: 3px; font-size: 11px; cursor: pointer; }

        .question-editor { padding: 20px; overflow-y: auto; flex: 1; }
        .q-item { background: white; padding: 15px; border-radius: 8px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #eee; }
        .q-header { display: flex; justify-content: space-between; margin-bottom: 10px; font-weight: bold; color: #0056b3; }
        .q-options { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .q-opt { padding: 5px; background: #f9f9f9; border: 1px solid #eee; border-radius: 4px; font-size: 13px; }
        
        .editor-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 2000; }
        .modal-content { background: white; width: 600px; padding: 20px; border-radius: 8px; max-height: 90vh; overflow-y: auto; }
        .form-row { margin-bottom: 10px; }
        .form-row label { display: block; font-weight: 500; margin-bottom: 5px; }
        .form-row input, .form-row textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .modal-actions { text-align: right; margin-top: 20px; }
        .btn-primary { background: #0056b3; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
        .btn-secondary { background: #6c757d; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-right: 5px; }

        /* --- Mobile Responsive Styles --- */
        .back-btn { display: none; background: none; border: none; font-size: 20px; cursor: pointer; color: #0056b3; margin-right: 10px; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; border-right: none; }
            .chat-area { display: none; width: 100%; }
            
            /* Khi đang xem chi tiết (Chat/Exam) trên mobile */
            .main-layout.mobile-view-detail .sidebar { display: none; }
            .main-layout.mobile-view-detail .chat-area { display: flex; }
            
            .back-btn { display: inline-block; }
            header { flex-direction: column; align-items: flex-start; gap: 10px; }
            header nav { width: 100%; display: flex; gap: 5px; }
            .nav-btn { flex: 1; margin-left: 0; text-align: center; }
        }
    </style>
</head>
<body>
    <header>
        <div style="display:flex; align-items: center;">
            <div style="margin-right: 20px;">Hệ thống Admin</div>
            <nav>
                <button onclick="switchView('chat')" id="btn-view-chat" class="nav-btn active">Chat Support</button>
                <button onclick="switchView('exam')" id="btn-view-exam" class="nav-btn">Quản lý Đề thi</button>
            </nav>
        </div>
        <div style="font-size: 14px; font-weight: normal;">Admin Dashboard</div>
    </header>

    <!-- VIEW 1: CHAT SUPPORT -->
    <div id="view-chat" class="main-layout">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="create-box">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">Upload Đề thi (JSON)</h4>
                <input type="file" id="jsonFile" accept=".json" style="background: white;">
                <button onclick="uploadSubject()" style="background: #17a2b8;">Upload lên Server</button>
            </div>
            <div id="chatList" class="chat-list">
                <!-- List items -->
            </div>
        </div>

        <!-- Chat Window -->
        <div class="chat-area">
            <div id="chatHeader" class="chat-area-header" style="display:none;">
                <button class="back-btn" onclick="backToList()"><i class="bi bi-arrow-left"></i></button>
                <span id="currentChatName">Chọn một đoạn chat</span>
                <div>
                    <button onclick="gradeCurrentChat()" style="background:#28a745; color:white; font-size:12px; margin-right:10px; border:none; padding:5px 10px; border-radius:4px; cursor:pointer;">Hoàn thành</button>
                    <button onclick="deleteCurrentChat()" style="color:red; font-size:12px;">Xóa</button>
                </div>
            </div>
            
            <div id="messagesBox" class="chat-messages">
                <div class="empty-state">Chọn một phiên chat bên trái để bắt đầu hỗ trợ</div>
            </div>

            <div id="inputArea" class="chat-input-area" style="display:none;">
                <input type="text" id="adminInput" placeholder="Nhập tin nhắn hỗ trợ..." onkeypress="handleAdminKey(event)">
                <button onclick="sendAdminMessage()">Gửi</button>
            </div>
        </div>
    </div>

    <!-- VIEW 2: QUẢN LÝ ĐỀ THI -->
    <div id="view-exam" class="main-layout" style="display:none;">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="create-box">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">Tạo Đề thi Mới</h4>
                <input type="text" id="newSubjectName" placeholder="Nhập tên file (không dấu)...">
                <button onclick="createSubject()" style="background: #28a745;">Tạo mới</button>
            </div>
            <div class="create-box" style="margin-top: 5px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #333;">Upload file JSON</h4>
                <input type="file" id="uploadSubjectFile" accept=".json" style="font-size: 12px;">
                <button onclick="uploadSubject()" style="background: #0056b3;">Upload</button>
            </div>
            <div id="subjectList" class="chat-list">
                <!-- List subjects -->
            </div>
        </div>

        <!-- Main Editor - JSON Textarea -->
        <div class="chat-area">
            <div id="examHeader" class="chat-area-header" style="display:none;">
                <button class="back-btn" onclick="backToList()"><i class="bi bi-arrow-left"></i></button>
                <span id="currentSubjectName">Đang chọn: ...</span>
                <div>
                    <button onclick="formatJSON()" style="background:#6c757d; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer; margin-right: 5px;" title="Format lại JSON">Định dạng</button>
                    <button onclick="saveQuestions()" style="background:#28a745; color:white; border:none; padding:5px 10px; border-radius:4px; cursor:pointer;">Lưu thay đổi</button>
                </div>
            </div>
            
            <div id="questionList" class="question-editor">
                <div class="empty-state">Chọn một đề thi bên trái để chỉnh sửa</div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        let currentChatId = null;
        let lastMessageId = 0;
        let isPolling = false;
        let currentView = 'chat';
        let activeChatName = ''; // Biến lưu tên chat đang xem
        
        // --- ĐỊNH DANH ADMIN (Để phân biệt khi có nhiều Admin) ---
        let currentAdminUser = sessionStorage.getItem('admin_user_id');
        if (!currentAdminUser) {
            // Tạo tên ngẫu nhiên: Support_ + 3 số cuối
            currentAdminUser = 'Support_' + Math.floor(Math.random() * 1000);
            sessionStorage.setItem('admin_user_id', currentAdminUser);
        }

        // --- VIEW SWITCHING ---
        function switchView(view) {
            currentView = view;
            document.getElementById('view-chat').style.display = view === 'chat' ? 'flex' : 'none';
            document.getElementById('view-exam').style.display = view === 'exam' ? 'flex' : 'none';
            
            document.getElementById('btn-view-chat').className = view === 'chat' ? 'nav-btn active' : 'nav-btn';
            document.getElementById('btn-view-exam').className = view === 'exam' ? 'nav-btn active' : 'nav-btn';

            // Reset mobile view state
            backToList();

            if (view === 'chat') {
                loadChats();
                if(currentChatId) startPolling();
            } else {
                stopPolling();
                loadSubjects();
            }
        }

        function backToList() {
            // Remove mobile detail view class
            document.getElementById('view-chat').classList.remove('mobile-view-detail');
            document.getElementById('view-exam').classList.remove('mobile-view-detail');
        }

        function startPolling() {
            if (!isPolling) { isPolling = true; pollMessages(); }
        }
        function stopPolling() { isPolling = false; }

        function loadChats() {
            if (currentView !== 'chat') return;
            fetch('dashboard.php?ajax_action=list&t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    const container = document.getElementById('chatList');
                    // Giữ lại scroll position nếu cần
                    const currentScroll = container.scrollTop;

                    // --- LOGIC TỰ ĐỘNG CHUYỂN CHAT (AUTO SWITCH) ---
                    if (currentChatId && activeChatName) {
                        const currentExists = Object.values(data).some(c => c.id === currentChatId);
                        const currentChatObj = Object.values(data).find(c => c.id === currentChatId);

                        // Nếu chat bị xóa HOẶC trạng thái chuyển sang disabled
                        if (!currentExists || (currentChatObj && currentChatObj.status === 'disabled')) {
                            // Tìm chat mới nhất có cùng tên và KHÔNG bị disabled
                            const replacement = Object.values(data).find(c => c.name === activeChatName && c.status !== 'disabled');
                            // Chỉ chuyển nếu tìm thấy chat thay thế khác với chat hiện tại
                            if (replacement && replacement.id !== currentChatId) selectChat(replacement.id, replacement.name);
                        }
                    }

                    container.innerHTML = ''; 
                    
                    Object.values(data).forEach(chat => {
                        const div = document.createElement('div');
                        div.className = `chat-item ${chat.id === currentChatId ? 'active' : ''}`;
                        div.onclick = () => selectChat(chat.id, chat.name);

                        div.innerHTML = `
                            <h3>${chat.name}</h3>
                            <div class="chat-info">
                                <span class="status-badge status-${chat.status}">${chat.status}</span>
                                <span>${new Date(chat.created_at * 1000).toLocaleTimeString()}</span>
                            </div>
                        `;
                        container.appendChild(div);
                    });
                    container.scrollTop = currentScroll;
                });
        }

        function selectChat(id, name) {
            currentChatId = id;
            activeChatName = name; // Lưu lại tên để dùng cho auto switch
            document.getElementById('currentChatName').textContent = name;
            document.getElementById('chatHeader').style.display = 'flex';
            document.getElementById('inputArea').style.display = 'flex';
            
            // Mobile: Show detail
            document.getElementById('view-chat').classList.add('mobile-view-detail');

            // Reset for new chat
            document.getElementById('messagesBox').innerHTML = '';
            lastMessageId = 0;
            
            // Highlight UI
            loadChats(); 
            
            startPolling();
        }

        function pollMessages() {
            if (!currentChatId) {
                // If no chat selected, check again in 1s
                setTimeout(pollMessages, 1000);
                return;
            }

            fetch(`dashboard.php?ajax_action=fetch&chat_id=${currentChatId}&last_id=${lastMessageId}&t=${Date.now()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success' && data.messages.length > 0) {
                        renderMessages(data.messages);
                        lastMessageId = data.messages[data.messages.length - 1].id;
                    }
                    // Loop sau 500ms
                    setTimeout(pollMessages, 3000);
                })
                .catch(err => {
                    console.error("Polling error", err);
                    setTimeout(pollMessages, 5000);
                });
        }

        function renderMessages(messages) {
            const box = document.getElementById('messagesBox');
            
            messages.forEach(msg => {
                const div = document.createElement('div');
                let type = 'user';
                // Admin hoặc Support (bất kỳ ai bắt đầu bằng Support) là phía quản trị
                if (msg.sender === 'Admin' || msg.sender.indexOf('Support') !== -1) type = 'admin';
                if (msg.sender === 'System') type = 'system';

                div.className = `msg ${type}`;
                div.innerHTML = `
                    ${msg.sender !== 'Admin' && msg.sender !== 'Support' && type !== 'system' ? `<strong>${msg.sender}</strong><br>` : ''}
                    ${msg.text}
                    <span class="msg-time">${msg.time}</span>
                `;
                box.appendChild(div);
            });
            
            // Auto scroll to bottom
            box.scrollTop = box.scrollHeight;
        }

        function sendAdminMessage() {
            const input = document.getElementById('adminInput');
            const text = input.value.trim();
            if (!text || !currentChatId) return;

            // Clear input immediately for better UX
            input.value = '';

            const fd = new FormData();
            fd.append('chat_id', currentChatId);
            fd.append('text', text);
            fd.append('sender', currentAdminUser); // Sử dụng tên định danh riêng (VD: Support_123)

            fetch('dashboard.php?ajax_action=send', { method: 'POST', body: fd })
                .catch(err => console.error("Send error", err));
        }

        function handleAdminKey(e) {
            if (e.key === 'Enter') sendAdminMessage();
        }

        function copyLink() {
            if(!currentChatId) return;
            const link = `${window.location.origin}/webkiemtra/index.php?chat_id=${currentChatId}`;
            navigator.clipboard.writeText(link).then(() => alert('Đã copy link vào bài thi!'));
        }

        function closeCurrentChat() {
            if(!currentChatId || !confirm('Đóng phiên hỗ trợ này?')) return;
            const fd = new FormData(); fd.append('chat_id', currentChatId);
            fetch('dashboard.php?ajax_action=close', { method: 'POST', body: fd })
                .then(() => {
                    currentChatId = null;
                    document.getElementById('chatHeader').style.display = 'none';
                    document.getElementById('inputArea').style.display = 'none';
                    document.getElementById('messagesBox').innerHTML = '<div class="empty-state">Đã đóng chat</div>';
                    currentChatId = null; // Stop polling logic for this chat
                    if(window.innerWidth <= 768) backToList(); // Mobile: back to list
                    loadChats();
                });
        }

        function deleteCurrentChat() {
            if(!currentChatId || !confirm('Xóa vĩnh viễn đoạn chat này? Dữ liệu sẽ mất hoàn toàn.')) return;
            const fd = new FormData(); fd.append('chat_id', currentChatId);
            fetch('dashboard.php?ajax_action=delete', { method: 'POST', body: fd })
                .then(() => {
                    currentChatId = null;
                    document.getElementById('chatHeader').style.display = 'none';
                    document.getElementById('inputArea').style.display = 'none';
                    document.getElementById('messagesBox').innerHTML = '<div class="empty-state">Đã xóa chat</div>';
                    if(window.innerWidth <= 768) backToList(); // Mobile: back to list
                    loadChats();
                });
        }

        function createChat() {
            const name = document.getElementById('chatName').value;
            if(!name) return alert('Vui lòng nhập tên gợi nhớ');

            const formData = new FormData();
            formData.append('name', name);

            fetch('dashboard.php?ajax_action=create', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    document.getElementById('chatName').value = '';
                    loadChats();
                }
            });
        }

        function uploadSubject() {
            const fileInput = document.getElementById('jsonFile');
            const file = fileInput.files[0];
            
            if (!file) {
                alert("Vui lòng chọn file JSON!");
                return;
            }

            const formData = new FormData();
            formData.append('subject_file', file);

            fetch('dashboard.php?ajax_action=upload_subject', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    alert(data.message);
                    fileInput.value = ''; // Reset input
                } else {
                    alert("Lỗi: " + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert("Lỗi kết nối server");
            });
        }

        function gradeCurrentChat() {
            if(!currentChatId) return;
            let score = prompt("Nhập điểm số cho bài thi này (VD: 8.5):");
            if(score === null || score.trim() === "") return;

            const fd = new FormData();
            fd.append('chat_id', currentChatId);
            fd.append('score', score);

            fetch('dashboard.php?ajax_action=grade', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    // Gửi tin nhắn thông báo tự động
                    const msgFd = new FormData();
                    msgFd.append('chat_id', currentChatId);
                    msgFd.append('text', `Hệ thống: Bài thi đã được chấm. Điểm số: ${score}`);
                    msgFd.append('sender', 'System');
                    fetch('dashboard.php?ajax_action=send', { method: 'POST', body: msgFd });
                    alert("Đã gửi điểm thành công!");
                }
            });
        }

        setInterval(() => {
            loadChats();
        }, 5000); // Cập nhật danh sách mỗi 5s (tránh quá tải hosting)
        
        loadChats();

        // --- EXAM MANAGEMENT LOGIC ---
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
                    if(data.status === 'success') {
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
                    if(data.status === 'success') {
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
                    if(data.status === 'success') {
                        if(currentSubjectFile === filename) {
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
            loadSubjects(); // Update active class
            
            // Mobile: Show detail
            document.getElementById('view-exam').classList.add('mobile-view-detail');
            
            fetch(`dashboard.php?ajax_action=get_questions&filename=${filename}`)
                .then(res => res.json())
                .then(data => {
                    if(data.status === 'success') {
                        const container = document.getElementById('questionList');
                        const jsonStr = JSON.stringify(data.data, null, 4);
                        const count = Array.isArray(data.data) ? data.data.length : 0;
                        container.innerHTML = `
                            <div style="padding: 10px; display: flex; flex-direction: column; height: 100%;">
                                <div style="margin-bottom: 8px; font-size: 13px; color: #666;">
                                    <span id="jsonStatus" style="color: #28a745;">&#10003; JSON hợp lệ</span>
                                    &nbsp;|&nbsp; <strong>${count}</strong> câu hỏi
                                </div>
                                <textarea id="jsonEditor" style="flex:1; width:100%; min-height:400px; font-family:'Consolas','Courier New',monospace; font-size:13px; border:1px solid #ddd; border-radius:6px; padding:12px; resize:vertical; tab-size:4; line-height:1.5;" oninput="validateJSON()">${escapeHtml(jsonStr)}</textarea>
                            </div>
                        `;
                    } else alert(data.message);
                });
        }

        function escapeHtml(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
                    if(data.status === 'success') alert('Đã lưu thành công!');
                    else alert('Lỗi: ' + data.message);
                });
        }
    </script>
</body>
</html>