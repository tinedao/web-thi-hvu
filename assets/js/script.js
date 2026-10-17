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

// --- CHAT VARIABLES & WEBSOCKET CLIENT ---
let activeChatId = null;
let wsClient = null;
let pendingUnreadMsgIds = [];
let currentSupportName = '';

const studentDB = {
    '235D150109': 'Đào Quang Tiến',
    '235D150052': 'Nguyễn Hoàng Nam',
    '235D160025': 'Đặng Trần Trung',
    '235D150110': 'Hà Văn Nghị',
    '235D160028': 'Hà Minh Đức',
    '235D150115': 'Hoàng Tuấn Ngọc',
    '245D100088': 'Bùi Nhật Năng'
};

function getPersonLastName(fullName, userId = '') {
    let name = fullName || '';
    if ((!name || name === 'Cán bộ hỗ trợ' || name === 'Hỗ trợ' || name === 'support' || name === 'support_admin') && userId && studentDB[userId]) {
        name = studentDB[userId];
    }
    if (!name || name === 'Cán bộ hỗ trợ' || name === 'Hỗ trợ' || name === 'support' || name === 'support_admin') return 'Hỗ trợ';
    name = name.replace(/\(.*?\)/g, '').split('-')[0].trim();
    if (studentDB[name]) name = studentDB[name];
    const parts = name.split(/\s+/).filter(Boolean);
    return parts.length > 0 ? parts[parts.length - 1] : name;
}

function setSupportOnline(isOnline, supportName = '', supportId = '') {
    if (supportName && supportName !== 'Cán bộ hỗ trợ' && supportName !== 'Hỗ trợ' && supportName !== 'support' && supportName !== 'support_admin') {
        currentSupportName = supportName;
    }
    const dot = document.getElementById('support-status-dot');
    const titleEl = document.getElementById('chat-title');
    const callName = getPersonLastName(currentSupportName, supportId);

    if (dot) {
        if (isOnline) {
            dot.classList.remove('offline');
            dot.title = (callName !== 'Hỗ trợ' ? callName : 'Người hỗ trợ') + ' đang Online';
        } else {
            dot.classList.add('offline');
            dot.title = (callName !== 'Hỗ trợ' ? callName : 'Người hỗ trợ') + ' đang Offline';
        }
    }

    if (titleEl) {
        if (callName && callName !== 'Hỗ trợ') {
            titleEl.textContent = callName;
        } else {
            titleEl.textContent = 'Hỗ trợ';
        }
    }
}

function markCandidateMessagesRead() {
    if (!wsClient || !wsClient.isConnected || !activeChatId) return;
    if (pendingUnreadMsgIds.length === 0) return;
    const ids = [...pendingUnreadMsgIds];
    pendingUnreadMsgIds = [];
    wsClient.markAsRead(activeChatId, ids);
}

function initChatSystem() {
    const urlParams = new URLSearchParams(window.location.search);
    const urlChatId = urlParams.get('chat_id');

    if (!urlChatId && typeof sessionChatId !== 'undefined' && sessionChatId) {
        activeChatId = sessionChatId;
    }
    if (urlChatId) {
        activeChatId = urlChatId;
    }

    if (!activeChatId) return;

    const popup = document.getElementById('chat-popup');
    if (popup) {
        popup.classList.add('active');
        // Gắn sự kiện tương tác: chỉ khi bấm vào ô chat hoặc gõ chữ mới tính là ĐÃ ĐỌC
        popup.addEventListener('click', () => markCandidateMessagesRead());
        const chatInput = document.getElementById('chat-input');
        if (chatInput) {
            chatInput.addEventListener('focus', () => markCandidateMessagesRead());
            chatInput.addEventListener('input', () => markCandidateMessagesRead());
        }
    }

    const myName = (typeof currentUser !== 'undefined' ? currentUser : (window.examInfo ? examInfo.student : 'Thí sinh'));
    const myId = (typeof currentUserId !== 'undefined' ? currentUserId : 'student');
    const token = (typeof wsToken !== 'undefined' ? wsToken : '');
    const wsUrl = (typeof wsConfigUrl !== 'undefined' ? wsConfigUrl : 'ws://' + window.location.hostname + ':8085');

    // Khởi tạo kết nối WebSocket Realtime
    if (typeof HVUWebSocketClient !== 'undefined') {
        wsClient = new HVUWebSocketClient({
            url: wsUrl,
            userId: myId,
            userName: myName,
            role: 'exam',
            chatId: activeChatId,
            chatName: myName + ' - ' + (window.examInfo ? examInfo.subject : 'Bài thi'),
            token: token,
            onAuthSuccess: (data) => {
                // Kiểm tra ngay cán bộ hỗ trợ có online không từ server
                if (data.support_online !== undefined) {
                    setSupportOnline(data.support_online === 1 || data.support_online === true, data.support_name, data.support_id);
                }
            },
            onSupportPresence: (data) => {
                setSupportOnline(data.is_online === 1 || data.is_online === true, data.support_name, data.support_id);
            },
            onNewMessage: (msg) => {
                renderOneMessage(msg);
                scrollToBottom();
                // Nếu tin nhắn từ cán bộ hỗ trợ -> lưu vào danh sách chờ đọc
                if (msg.sender_id !== myId) {
                    pendingUnreadMsgIds.push(msg.id);
                    if (msg.sender_name && (msg.sender_role === 'support' || msg.sender_role === 'admin')) {
                        setSupportOnline(true, msg.sender_name, msg.sender_id);
                    }
                }
            },
            onMessageSent: (data) => {
                updateClientMsgSent(data);
            },
            onMsgStatusUpdate: (data) => {
                updateMsgStatusInDOM(data);
            },
            onPresenceUpdate: (data) => {
                if (data.user_id === 'support_admin' || data.user_id === 'support' || data.role === 'support') {
                    setSupportOnline(data.is_online === 1, data.user_name || data.name, data.user_id);
                }
            },
            onExamGraded: (data) => {
                if (data.score !== undefined && data.score !== null) {
                    activateSubmitWithScore(data.score);
                }
            },
            onHistory: (data) => {
                const body = document.getElementById('chat-body');
                if (body && data.messages) {
                    body.innerHTML = '';
                    pendingUnreadMsgIds = [];
                    data.messages.forEach(m => {
                        renderOneMessage(m);
                        if (m.sender_id !== myId && m.status !== 'read') {
                            pendingUnreadMsgIds.push(m.id);
                        }
                        if ((m.sender_role === 'support' || m.sender_role === 'admin') && m.sender_name) {
                            currentSupportName = m.sender_name;
                        }
                    });
                    if (currentSupportName) {
                        const callName = getPersonLastName(currentSupportName);
                        if (callName !== 'Hỗ trợ') {
                            const titleEl = document.getElementById('chat-title');
                            if (titleEl) titleEl.textContent = callName;
                        }
                    }
                    scrollToBottom();
                }
            }
        });

        if (popup && popup.classList.contains('active')) {
            wsClient.isChatOpen = true;
        }
    }

    // Cơ chế Fallback AJAX: Tự động tải tin nhắn, cập nhật điểm thi và người hỗ trợ định kỳ (mỗi 3.5s)
    let isCandidatePolling = false;
    setInterval(() => {
        if (!activeChatId || isCandidatePolling) return;
        isCandidatePolling = true;
        fetch(`index.php?ajax_action=fetch&chat_id=${encodeURIComponent(activeChatId)}`)
            .then(res => res.json())
            .then(data => {
                if (data && data.status === 'success') {
                    // Tự động kích hoạt nộp bài kèm điểm số nếu cán bộ hỗ trợ đã chấm điểm
                    if (data.chat_score !== undefined && data.chat_score !== null && window.finalScore === undefined) {
                        activateSubmitWithScore(data.chat_score);
                    }
                    if (Array.isArray(data.messages)) {
                        let hasNew = false;
                        data.messages.forEach(m => {
                            if (m.id && !document.querySelector(`.chat-msg[data-msg-id="${m.id}"]`)) {
                                renderOneMessage(m);
                                hasNew = true;
                                if (m.sender_id !== myId) {
                                    pendingUnreadMsgIds.push(m.id);
                                    if (m.sender_name && (m.sender_role === 'support' || m.sender_role === 'admin')) {
                                        setSupportOnline(true, m.sender_name, m.sender_id);
                                    }
                                }
                            }
                        });
                        if (hasNew) scrollToBottom();
                    }
                }
            })
            .catch(() => {})
            .finally(() => {
                isCandidatePolling = false;
            });
    }, 3500);
}

function toggleChatPopup() {
    const popup = document.getElementById('chat-popup');
    if (!popup) return;
    
    popup.classList.toggle('active');
    const isOpen = popup.classList.contains('active');

    if (wsClient) {
        wsClient.isChatOpen = isOpen;
    }

    if (isOpen) {
        scrollToBottom();
        // Khi người dùng bấm mở popup -> tính là tương tác xem tin nhắn -> đánh dấu đã đọc
        markCandidateMessagesRead();
    }
}

function toggleChatPosition() {
    const popup = document.getElementById('chat-popup');
    if(popup) popup.classList.toggle('left-side');
}

function renderOneMessage(msg) {
    const body = document.getElementById('chat-body');
    if (!body) return;

    // Tránh render trùng
    if (msg.id && document.querySelector(`.chat-msg[data-msg-id="${msg.id}"]`)) {
        return;
    }

    const myId = (typeof currentUserId !== 'undefined' ? currentUserId : 'student');
    const myName = (typeof currentUser !== 'undefined' ? currentUser : (window.examInfo ? examInfo.student : 'Thí sinh'));
    
    let isMe = (msg.sender_id === myId) || (msg.sender === myName) || (msg.sender_name === myName);
    let isSystem = (msg.sender_role === 'system') || (msg.sender === 'System');

    const div = document.createElement('div');
    div.className = `chat-msg ${isSystem ? 'system' : (isMe ? 'me' : 'other')}`;
    if (msg.id) div.setAttribute('data-msg-id', msg.id);

    const safeText = escapeHtml(msg.text);
    const timeStr = msg.time || new Date((msg.created_at || Date.now() / 1000) * 1000).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false });
    const tickHtml = isMe ? getStatusTickHtml(msg.status || 'sent') : '';

    if (isSystem) {
        div.innerHTML = `${safeText}`;
    } else if (isMe) {
        div.innerHTML = `<div>${safeText}</div><div class="msg-meta"><span>${timeStr}</span> <span class="tick-wrap">${tickHtml}</span></div>`;
    } else {
        const supName = msg.sender_name || msg.sender || currentSupportName || 'Hỗ trợ';
        const callName = getPersonLastName(supName, msg.sender_id);
        if (callName && callName !== 'Hỗ trợ' && (!currentSupportName || currentSupportName === 'Hỗ trợ')) {
            currentSupportName = supName;
            const titleEl = document.getElementById('chat-title');
            if (titleEl) titleEl.textContent = callName;
        }
        div.innerHTML = `<div style="font-size:11px; font-weight:bold; color:#003366; margin-bottom:2px;">${escapeHtml(callName)}</div><div>${safeText}</div><div class="msg-meta">${timeStr}</div>`;
    }

    body.appendChild(div);
}

function updateClientMsgSent(data) {
    const body = document.getElementById('chat-body');
    if (!body) return;
    if (data.client_msg_id) {
        const tempNode = document.querySelector(`[data-client-id="${data.client_msg_id}"]`);
        if (tempNode) {
            tempNode.setAttribute('data-msg-id', data.msg_id);
            const tickWrap = tempNode.querySelector('.tick-wrap');
            if (tickWrap) tickWrap.innerHTML = getStatusTickHtml(data.status);
            return;
        }
    }
}

function updateMsgStatusInDOM(data) {
    const status = data.status;
    if (data.msg_id) {
        const node = document.querySelector(`.chat-msg[data-msg-id="${data.msg_id}"] .tick-wrap`);
        if (node) node.innerHTML = getStatusTickHtml(status);
    }
    if (data.msg_ids && Array.isArray(data.msg_ids)) {
        data.msg_ids.forEach(id => {
            const node = document.querySelector(`.chat-msg[data-msg-id="${id}"] .tick-wrap`);
            if (node) node.innerHTML = getStatusTickHtml(status);
        });
    }
}

function handleChatKey(e) {
    if (e.key === 'Enter') sendChatMessage();
}

function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text || !activeChatId) return;

    if (wsClient && wsClient.isConnected) {
        const res = wsClient.sendMessage(text, activeChatId);
        if (res) {
            input.value = '';
            // Render optimistic message locally
            const timeStr = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false });
            const body = document.getElementById('chat-body');
            if (body) {
                const div = document.createElement('div');
                div.className = 'chat-msg me';
                div.setAttribute('data-client-id', res.clientMsgId);
                div.innerHTML = `<div>${escapeHtml(text)}</div><div class="msg-meta"><span>${timeStr}</span> <span class="tick-wrap">${getStatusTickHtml('sent')}</span></div>`;
                body.appendChild(div);
                scrollToBottom();
            }
        }
    } else {
        alert("Kết nối WebSocket đang gián đoạn, đang tự động kết nối lại...");
    }
}

function activateSubmitWithScore(score) {
    if (window.finalScore !== undefined) return;
    
    window.finalScore = score;
    const btn = document.getElementById('submit-btn');
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.style.backgroundColor = '#0033cc';
        
        btn.onclick = function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            alert(`BẠN ĐÃ HOÀN THÀNH BÀI THI!\n\nĐIỂM SỐ CỦA BẠN: ${score}\n\nHệ thống sẽ tự động chuyển về Cổng thông tin sau 5 giây.`);
            
            setTimeout(() => {
                fetch('logout.php').then(() => {
                    window.location.href = 'portal.php';
                });
            }, 5000);
        };
    }
}

function scrollToBottom() {
    const body = document.getElementById('chat-body');
    if (body) body.scrollTop = body.scrollHeight;
}