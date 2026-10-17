/**
 * websocket-client.js - Quản lý kết nối WebSocket Client Realtime
 * Hỗ trợ tự động kết nối lại, heartbeat, gửi nhận tin nhắn, xác nhận đã nhận/đã đọc
 */

class HVUWebSocketClient {
    constructor(options = {}) {
        this.url = options.url || 'ws://' + window.location.hostname + ':8085';
        this.userId = options.userId || 'guest';
        this.userName = options.userName || 'Người dùng';
        this.role = options.role || 'exam';
        this.chatId = options.chatId || null;
        this.chatName = options.chatName || '';
        this.token = options.token || '';

        this.ws = null;
        this.isConnected = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 30;
        this.reconnectDelay = 1500;
        this.pingTimer = null;
        this.isChatOpen = false;

        // Callbacks
        this.onNewMessage = options.onNewMessage || (() => {});
        this.onMessageSent = options.onMessageSent || (() => {});
        this.onMsgStatusUpdate = options.onMsgStatusUpdate || (() => {});
        this.onPresenceUpdate = options.onPresenceUpdate || (() => {});
        this.onSupportPresence = options.onSupportPresence || (() => {});
        this.onAuthSuccess = options.onAuthSuccess || (() => {});
        this.onExamGraded = options.onExamGraded || (() => {});
        this.onChatListUpdate = options.onChatListUpdate || (() => {});
        this.onConnectionChange = options.onConnectionChange || (() => {});
        this.onHistory = options.onHistory || (() => {});

        this.connect();
    }

    connect() {
        if (this.ws && (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING)) {
            return;
        }

        try {
            this.ws = new WebSocket(this.url);
        } catch (e) {
            console.warn('[WS Client] Không thể kết nối tới', this.url, e);
            this.scheduleReconnect();
            return;
        }

        this.ws.onopen = () => {
            console.log('[WS Client] Đã kết nối tới', this.url);
            this.isConnected = true;
            this.reconnectAttempts = 0;
            this.reconnectDelay = 1500;
            this.onConnectionChange(true);

            // Gửi gói xác thực
            this.send({
                action: 'auth',
                token: this.token,
                user_id: this.userId,
                user_name: this.userName,
                role: this.role,
                chat_id: this.chatId,
                chat_name: this.chatName
            });

            // Tải lịch sử tin nhắn ban đầu nếu có chatId
            if (this.chatId) {
                this.send({ action: 'get_history', chat_id: this.chatId });
            }

            // Bắt đầu heartbeat ping
            this.startHeartbeat();
        };

        this.ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                this.handleMessage(data);
            } catch (err) {
                console.error('[WS Message Parse Error]', err);
            }
        };

        this.ws.onclose = (event) => {
            console.warn(`[WS Client] Mất kết nối tới: ${this.url} (Code: ${event.code || 'None'}, Reason: ${event.reason || 'Không rõ'})`);
            this.isConnected = false;
            this.stopHeartbeat();
            this.onConnectionChange(false);
            this.scheduleReconnect();
        };

        this.ws.onerror = (err) => {
            console.error('[WS Client Error] Không thể thiết lập kết nối WebSocket tới:', this.url);
            console.info('[WS Diagnostic] Bạn có thể kiểm tra trạng thái WebSocket Server bằng cách mở link sau trên trình duyệt:');
            const testHttpUrl = this.url.replace(/^wss:/i, 'https:').replace(/^ws:/i, 'http:') + '/health';
            console.info('👉 ' + testHttpUrl);
            try { this.ws.close(); } catch (e) {}
        };
    }

    scheduleReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.error('[WS Client] Đã vượt quá số lần thử kết nối lại tối đa');
            return;
        }
        this.reconnectAttempts++;
        const delay = Math.min(this.reconnectDelay * Math.pow(1.3, this.reconnectAttempts), 10000);
        console.log(`[WS Client] Sẽ thử kết nối lại sau ${Math.round(delay / 1000)}s (lần ${this.reconnectAttempts})...`);
        setTimeout(() => this.connect(), delay);
    }

    startHeartbeat() {
        this.stopHeartbeat();
        this.pingTimer = setInterval(() => {
            if (this.isConnected) {
                this.send({ action: 'ping' });
            }
        }, 20000);
    }

    stopHeartbeat() {
        if (this.pingTimer) {
            clearInterval(this.pingTimer);
            this.pingTimer = null;
        }
    }

    send(obj) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(obj));
            return true;
        }
        return false;
    }

    sendMessage(text, chatId = null) {
        const targetChatId = chatId || this.chatId;
        if (!targetChatId || !text.trim()) return null;

        const clientMsgId = 'c_' + Date.now() + '_' + Math.random().toString(36).substring(2, 7);
        const payload = {
            action: 'send_message',
            chat_id: targetChatId,
            text: text.trim(),
            sender_name: this.userName,
            client_msg_id: clientMsgId
        };

        const success = this.send(payload);
        return { clientMsgId, success };
    }

    markAsRead(chatId, msgIds) {
        if (!chatId || !Array.isArray(msgIds) || msgIds.length === 0) return;
        this.send({
            action: 'ack_read',
            chat_id: chatId,
            msg_ids: msgIds
        });
    }

    gradeCandidate(chatId, score) {
        if (!chatId || score === undefined) return;
        this.send({
            action: 'grade_exam',
            chat_id: chatId,
            score: score
        });
    }

    handleMessage(data) {
        // Heartbeat response
        if (data.type === 'pong' || data.type === 'ping') {
            return;
        }

        // Xác thực thành công
        if (data.type === 'auth_success') {
            console.log('[WS Client] Xác thực thành công:', data);
            this.onAuthSuccess(data);
            return;
        }

        // Cập nhật trạng thái hỗ trợ Online/Offline
        if (data.type === 'support_presence') {
            this.onSupportPresence(data);
            return;
        }

        // Tin nhắn của mình đã lưu vào server (sent)
        if (data.type === 'message_sent') {
            this.onMessageSent(data);
            return;
        }

        // Có tin nhắn mới từ người khác hoặc hệ thống
        if (data.type === 'new_message') {
            const msg = data.msg;
            this.onNewMessage(msg);

            // Nếu người nhận nhận được -> chỉ gửi xác nhận Delivered (đã nhận tới máy)
            // LƯU Ý: Không tự ý gửi Read. Read chỉ được gửi khi có tương tác (click, focus, type)
            if (msg.sender_id !== this.userId) {
                this.send({
                    action: 'ack_delivered',
                    msg_id: msg.id,
                    chat_id: msg.chat_id
                });
            }
            return;
        }

        // Cập nhật trạng thái tin nhắn (delivered, read)
        if (data.type === 'msg_status_update') {
            this.onMsgStatusUpdate(data);
            return;
        }

        // Cập nhật trạng thái Online / Offline
        if (data.type === 'presence_update') {
            this.onPresenceUpdate(data);
            return;
        }

        // Thông báo chấm điểm bài thi từ Giám thị
        if (data.type === 'exam_graded') {
            this.onExamGraded(data);
            return;
        }

        // Cập nhật danh sách phòng chat cho Dashboard
        if (data.type === 'chat_list_update') {
            this.onChatListUpdate(data);
            return;
        }

        // Nhận toàn bộ lịch sử tin nhắn
        if (data.type === 'chat_history') {
            this.onHistory(data);
            return;
        }
    }
}

// Hàm format thời gian và icon tick
function getStatusTickHtml(status) {
    if (status === 'read') {
        return '<span class="status-tick read" title="Đã đọc" style="color: #0d6efd; font-weight: bold; margin-left: 4px;">✓✓</span>';
    } else if (status === 'delivered') {
        return '<span class="status-tick delivered" title="Đã nhận" style="color: #6c757d; font-weight: bold; margin-left: 4px;">✓✓</span>';
    } else {
        return '<span class="status-tick sent" title="Đã gửi" style="color: #adb5bd; margin-left: 4px;">✓</span>';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
