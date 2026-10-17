// Biến questionsData và currentQuestionIndex đã được khởi tạo từ PHP trong index.php

// --- CÁC HÀM XỬ LÝ ---

function renderQuestion(index) {

    const question = questionsData[index];
    
    // 1. Render Tiêu đề
    const titleEl = document.getElementById('question-title');
    if(titleEl) titleEl.innerHTML = `<strong>Câu ${question.id}:</strong><br><span style="font-weight: normal;">${question.question}</span>`;

    // 2. Render Đáp án
    const optionsContainer = document.getElementById('options-container');
    if(optionsContainer) {
        optionsContainer.innerHTML = ''; // Xóa nội dung cũ

        question.options.forEach(opt => {
            const isSelected = question.userAnswer === opt.id;
            
            const optionHTML = `
                <div class="option-item ${isSelected ? 'selected' : ''}" onclick="handleSelectOption('${opt.id}')">
                    <div class="option-label">${opt.id}</div>
                    <div class="option-content">
                        <input type="radio" name="q${question.id}" value="${opt.id}" ${isSelected ? 'checked' : ''}>
                        <span>${opt.text}</span>
                    </div>
                </div>
            `;
            optionsContainer.innerHTML += optionHTML;
        });
    }

    // 3. Cập nhật Pagination
    renderPagination();
    
    // 4. Cập nhật trạng thái nút Next
    updateNextButton();

    // 5. Cập nhật trạng thái nút Nộp bài
    updateSubmitButton();

    // 6. Cập nhật thanh tiến độ trên header
    updateProgressBar();
}

function renderPagination() {
    const container = document.getElementById('pagination-container');
    if(!container) return;
    container.innerHTML = '';

    // Sliding window: Current +/- 2 (Display 3-5 items)
    const start = Math.max(0, currentQuestionIndex - 2);
    const end = Math.min(questionsData.length - 1, currentQuestionIndex + 2);

    for (let i = start; i <= end; i++) {
        const q = questionsData[i];
        const dot = document.createElement('div');
        dot.className = 'page-dot';
        dot.textContent = q.id;
        
        // Tính khoảng cách từ câu hiện tại đến các câu khác
        const dist = Math.abs(i - currentQuestionIndex);

        if (dist === 0) {
            dot.classList.add('active');
        } else if (dist === 1) {
            dot.classList.add('op-1');
        } else if (dist === 2) {
            dot.classList.add('op-2');
        }
        
        // No onclick event (Read-Only)

        container.appendChild(dot);
    }
}

function handleSelectOption(selectedId) {
    // Cập nhật dữ liệu
    // Nếu click vào đáp án đang chọn thì sẽ bỏ chọn (toggle)
    if (questionsData[currentQuestionIndex].userAnswer === selectedId) {
        questionsData[currentQuestionIndex].userAnswer = null;
    } else {
        questionsData[currentQuestionIndex].userAnswer = selectedId;
    }
    
    // Render lại để thấy hiệu ứng selected
    renderQuestion(currentQuestionIndex);
}

// Hàm hỗ trợ chuyển câu hỏi (dùng cho onclick trong HTML PHP render)
function jumpToQuestion(index) {
    currentQuestionIndex = index;
    renderQuestion(index);
    const listModal = document.getElementById('question-list-modal');
    if(listModal) listModal.classList.remove('active');
}

function updateNextButton() {
    const btn = document.getElementById('next-btn');
    if(!btn) return;

    if (currentQuestionIndex >= questionsData.length - 1) {
        // Disable on last question
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    } else {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.textContent = "Câu hỏi tiếp theo";
    }
}

function updateSubmitButton() {
    const btn = document.getElementById('submit-btn');
    if(!btn) return;

    // Nếu đã có điểm (window.finalScore) thì luôn enable nút
    if ((questionsData.length > 0 && currentQuestionIndex === questionsData.length - 1) || window.finalScore !== undefined) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
}

function updateProgressBar() {
    const bar = document.getElementById('header-progress-bar');
    if (!bar || !questionsData || questionsData.length === 0) return;

    // Đếm số câu đã có câu trả lời (userAnswer khác null)
    const answeredCount = questionsData.filter(q => q.userAnswer !== null).length;
    const total = questionsData.length;
    const percent = (answeredCount / total) * 100;

    bar.style.width = `${percent}%`;
}

// Hàm render danh sách đầy đủ (cho Modal)
function renderFullList() {
    const listModal = document.getElementById('question-list-modal');
    if (!listModal) return;
    
    listModal.innerHTML = '';
    
    questionsData.forEach((q, index) => {
        const item = document.createElement('div');
        item.className = 'q-modal-item';
        item.textContent = q.id;
        
        if (index === currentQuestionIndex) {
            item.classList.add('current');
        }

        if (q.userAnswer) {
            item.classList.add('answered');
        }
        
        // Sử dụng hàm jumpToQuestion thay vì addEventListener ẩn danh
        item.setAttribute('onclick', `jumpToQuestion(${index})`);
        
        listModal.appendChild(item);
    });
}

// Event Listener for Next Button
document.addEventListener('DOMContentLoaded', () => {
    const nextBtn = document.getElementById('next-btn');
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (questionsData.length > 0 && currentQuestionIndex < questionsData.length - 1) {
                currentQuestionIndex++;
                renderQuestion(currentQuestionIndex);
            }
        });
    }

    // Event Listener for Menu Button
    const menuBtn = document.getElementById('menu-btn');
    const listModal = document.getElementById('question-list-modal');
    if (menuBtn && listModal) {
        menuBtn.addEventListener('click', () => {
            if (!listModal.classList.contains('active')) {
                renderFullList(); // Render lại để cập nhật trạng thái current
            }
            listModal.classList.toggle('active');
        });
    }

    // --- CHAT LOGIC ---
    initChatSystem();
});

// --- CHAT VARIABLES & FUNCTIONS ---
let activeChatId = null;
let lastMessageId = 0;
let isPolling = false;

function initChatSystem() {
    // 1. Check URL for chat_id (Auto attach logic)
    const urlParams = new URLSearchParams(window.location.search);
    const urlChatId = urlParams.get('chat_id');

    // Ưu tiên URL, sau đó đến Session (được truyền từ PHP index.php)
    if (!urlChatId && typeof sessionChatId !== 'undefined' && sessionChatId) {
        activeChatId = sessionChatId;
    }
    if (urlChatId) {
        activeChatId = urlChatId;
    }

    if (activeChatId) {
        // Auto open popup
        const popup = document.getElementById('chat-popup');
        console.log("Init Chat: ActiveChatId =", activeChatId); // Debug ID
        if(popup) popup.classList.add('active');
        
        // Start polling
        startChatPolling();

        // Send System Message (Attach Exam) - Check if already sent in session storage to avoid spam on refresh
        const sentKey = `chat_joined_${activeChatId}`;
        if (!sessionStorage.getItem(sentKey)) {
            const sysMsg = `Hệ thống: Thí sinh ${examInfo.student} (Mã đề: ${examInfo.examId}, Môn: ${examInfo.subject}) đã tham gia.`;
            sendChatMessageAPI(sysMsg, 'System');
            sessionStorage.setItem(sentKey, 'true');
        }
    }
}

function toggleChatPopup() {
    const popup = document.getElementById('chat-popup');
    if (!popup) return;
    
    popup.classList.toggle('active');
    
    // If opening and we have an ID, ensure polling is running
    if (popup.classList.contains('active') && activeChatId) {
        scrollToBottom();
    } else if (!activeChatId && popup.classList.contains('active')) {
        // If no chat ID, show a message
        document.getElementById('chat-body').innerHTML = '<div class="chat-msg system">Chưa kết nối vào phòng chat nào. Vui lòng dùng link được cung cấp.</div>';
    }
}

function toggleChatPosition() {
    const popup = document.getElementById('chat-popup');
    if(popup) popup.classList.toggle('left-side');
}

function startChatPolling() {
    if (isPolling) return;
    isPolling = true;
    pollMessages();
}

function requestChatRecovery() {
    if (!activeChatId) {
        alert("Chưa có trong phòng chat để thực hiện hành động này.");
        return;
    }
    if (!confirm("Bạn có chắc muốn tạo một đoạn chat mới? Admin sẽ được tự động kết nối lại vào phòng mới.")) {
        return;
    }
    console.log("Manual recovery initiated by student.");
    // Gọi hàm khôi phục đã có
    recoverChatSession();
}

function pollMessages() {
    if (!activeChatId) {
        isPolling = false;
        return;
    }
    
    fetch(`index.php?ajax_action=fetch&chat_id=${activeChatId}&last_id=${lastMessageId}&t=${Date.now()}`)
        .then(res => res.text()) // Đổi sang text() để xem raw data nếu JSON lỗi
        .then(text => {
            // console.log("Raw Response:", text); // Bật dòng này nếu muốn xem toàn bộ text trả về
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("JSON Parse Error:", e, text);
                return { status: 'error', message: 'Lỗi dữ liệu từ server (Xem console)' };
            }
        })
        .then(data => {
            if (data.status === 'success') {
                // KIỂM TRA LỆNH ĐẶC BIỆT TỪ ADMIN
                const recoverMsg = data.messages.find(m => m.text === '__RECOVER__');
                if (recoverMsg) {
                    console.log("Recovery command received from admin.");
                    recoverChatSession();
                    return; // Dừng xử lý chu kỳ này, vì sẽ chuyển sang phòng mới
                }

                if (data.messages.length > 0) {
                    console.log("New messages received:", data.messages); // Debug tin nhắn mới
                    renderMessages(data.messages);
                    // Update last ID
                    lastMessageId = data.messages[data.messages.length - 1].id;
                    scrollToBottom();
                }

                // Kiểm tra nếu có điểm số từ Admin
                if (data.chat_score !== null && data.chat_score !== undefined) {
                    activateSubmitWithScore(data.chat_score);
                }
                
                if(data.chat_status === 'closed') {
                    document.getElementById('chat-title').textContent = "Chat đã kết thúc";
                    document.getElementById('chat-input').disabled = true;
                    isPolling = false;
                } else {
                    // Hỏi lại sau 500ms (Short Polling - Realtime)
                    setTimeout(pollMessages, 3000);
                }
            } else if (data.status === 'error') {
                // Chat deleted or not found
                console.warn("Chat error detected. Attempting recovery...");
                recoverChatSession();
            }
        })
        .catch(() => setTimeout(pollMessages, 1000)); // Retry nhanh hơn nếu lỗi mạng
}

function recoverChatSession() {
    // Ngừng polling cũ để tránh spam request lỗi
    isPolling = false;

    const fd = new FormData();
    fd.append('fullname', examInfo.student);
    fd.append('subject', examInfo.subject);
    if (activeChatId) fd.append('old_chat_id', activeChatId);

    fetch('index.php?ajax_action=recover', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            // 1. Cập nhật ID mới
            activeChatId = data.new_chat_id;
            lastMessageId = 0; // Reset ID tin nhắn
            
            // 2. Xóa nội dung cũ trên giao diện (hoặc giữ lại tùy ý, ở đây ta xóa để load mới)
            const body = document.getElementById('chat-body');
            if(body) body.innerHTML = '';

            // 3. Hiển thị thông báo hệ thống
            const msgDiv = document.createElement('div');
            msgDiv.className = 'chat-msg system';
            msgDiv.textContent = "Đã tạo đoạn chat mới do lỗi kết nối.";
            body.appendChild(msgDiv);

            // 4. Gửi tin nhắn chào lại để Admin biết
            sendChatMessageAPI(`(Hệ thống: Đã chuyển sang phòng chat mới: ${activeChatId})`, 'System');

            // 5. Tiếp tục polling
            isPolling = true;
            pollMessages();
        } else {
            alert("Không thể khôi phục kết nối chat. Vui lòng tải lại trang.");
        }
    })
    .catch(err => {
        console.error("Recovery failed", err);
        setTimeout(recoverChatSession, 3000); // Thử lại sau 3s
    });
}

function activateSubmitWithScore(score) {
    if (window.finalScore !== undefined) return; // Đã kích hoạt rồi thì thôi
    
    window.finalScore = score;
    const btn = document.getElementById('submit-btn');
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.style.backgroundColor = '#0033cc'; // Đổi màu xanh lá
        
        // Hiệu ứng "sáng lên"
        
        // Gán sự kiện click mới (ghi đè sự kiện cũ bằng cách clone hoặc gán onclick trực tiếp)
        btn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Hiện điểm 5s
            alert(`BẠN ĐÃ HOÀN THÀNH BÀI THI!\n\nĐIỂM SỐ CỦA BẠN: ${score}\n\nHệ thống sẽ tự động thoát sau 5 giây.`);
            
            setTimeout(() => {
                // Gọi logout ngầm
                fetch('logout.php').then(() => {
                    // Thử đóng tab (thường trình duyệt sẽ chặn nếu không phải script mở)
                    window.close();
                    // Chuyển hướng đến trang trường
                    window.location.href = 'https://sinhvien.hvu.edu.vn';
                });
            }, 5000);
        };
    }
}

function renderMessages(messages) {
    const body = document.getElementById('chat-body');
    if (!body) { console.error("Lỗi: Không tìm thấy phần tử #chat-body"); return; }
    
    messages.forEach(msg => {
        // Bỏ qua, không hiển thị các tin nhắn lệnh
        if (msg.text === '__RECOVER__') return;

        const div = document.createElement('div');
        let type = 'other';
        
        // Lấy tên người dùng hiện tại từ biến toàn cục currentUser (được PHP truyền sang)
        // Nếu không có currentUser thì fallback về examInfo.student
        const myName = (typeof currentUser !== 'undefined' ? currentUser : examInfo.student);
        const sender = msg.sender;
        // console.log(`Render msg: ${msg.text} | Sender: ${sender} | MyName: ${myName}`); // Debug so sánh tên

        if (sender === 'System') type = 'system';
        else if (sender === myName) type = 'me'; // Trùng tên Session -> me
        
        div.className = `chat-msg ${type}`;
        
        // Nếu là tin nhắn của mình (bên phải), không cần hiện tên
        if (type === 'me') {
            div.innerHTML = `${msg.text}`;
        } else {
            // Tin nhắn người khác hoặc System
            div.innerHTML = `<strong>${sender === 'System' ? '' : sender + ':'}</strong> ${msg.text}`;
        }
        
        body.appendChild(div);
    });
}

function handleChatKey(e) {
    if (e.key === 'Enter') sendChatMessage();
}

function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text || !activeChatId) return;
    
    sendChatMessageAPI(text, examInfo.student);
    input.value = '';
}

function sendChatMessageAPI(text, sender) {
    const fd = new FormData();
    fd.append('chat_id', activeChatId);
    fd.append('text', text);
    fd.append('sender', sender);
    
    fetch('index.php?ajax_action=send', {
        method: 'POST',
        body: fd
    }).catch(err => console.error("Send error", err)); // Catch error if network fails
}

function deleteChat() {
    if (!activeChatId) return;
    if (!confirm("Bạn có chắc muốn xóa vĩnh viễn đoạn chat này?")) return;

    const fd = new FormData();
    fd.append('chat_id', activeChatId);
    fetch('index.php?ajax_action=delete', { method: 'POST', body: fd })
        .then(() => {
            alert("Đã xóa đoạn chat.");
            location.reload();
        });
}

function scrollToBottom() {
    const body = document.getElementById('chat-body');
    body.scrollTop = body.scrollHeight;
}