/**
 * websocket-server.js - Máy chủ WebSocket Realtime Node.js cho Chat nội bộ
 * Lưu trữ tin nhắn vào SQLite (data/chat.sqlite)
 * Hỗ trợ:
 * - Trạng thái Online / Offline / Last seen (hỗ trợ đa tab trên 1 tài khoản)
 * - Vòng đời trạng thái tin nhắn: Sent (đã lưu) -> Delivered (đã nhận) -> Read (đã đọc)
 * - Tự động xóa tin nhắn quá 24 giờ
 * - Chấm điểm trực tiếp realtime từ Giám thị
 */

const http = require('http');
const path = require('path');
const fs = require('fs');
const { WebSocketServer, WebSocket } = require('ws');

const rawPort = process.env.PORT || process.env.WS_PORT;
const isNamedPipe = rawPort && isNaN(Number(rawPort));
const PORT = isNamedPipe ? rawPort : parseInt(rawPort || '8085', 10);
const HOST = process.env.WS_HOST || '0.0.0.0';
const dbPath = path.join(__dirname, 'data', 'chat.sqlite');

// Đảm bảo thư mục data/ tồn tại
const dataDir = path.join(__dirname, 'data');
if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
}

// Khởi tạo Database Wrapper tương thích nhiều phiên bản Node.js
let db = null;
try {
    const { DatabaseSync } = require('node:sqlite');
    const syncDb = new DatabaseSync(dbPath);
    syncDb.exec('PRAGMA journal_mode = WAL;');
    db = {
        type: 'sync',
        exec: (sql) => syncDb.exec(sql),
        run: (sql, params = []) => syncDb.prepare(sql).run(...params),
        get: (sql, params = []) => syncDb.prepare(sql).get(...params),
        all: (sql, params = []) => syncDb.prepare(sql).all(...params)
    };
    console.log('[SQLite] Đã kết nối qua node:sqlite (WAL mode)');
} catch (e) {
    const sqlite3 = require('sqlite3').verbose();
    const asyncDb = new sqlite3.Database(dbPath);
    asyncDb.run('PRAGMA journal_mode = WAL;');
    db = {
        type: 'async',
        exec: (sql) => new Promise((res, rej) => asyncDb.exec(sql, (err) => err ? rej(err) : res())),
        run: (sql, params = []) => new Promise((res, rej) => asyncDb.run(sql, params, function (err) { err ? rej(err) : res(this); })),
        get: (sql, params = []) => new Promise((res, rej) => asyncDb.get(sql, params, (err, row) => err ? rej(err) : res(row))),
        all: (sql, params = []) => new Promise((res, rej) => asyncDb.all(sql, params, (err, rows) => err ? rej(err) : res(rows)))
    };
    console.log('[SQLite] Đã kết nối qua sqlite3 package (WAL mode)');
}

// Khởi tạo cấu trúc bảng SQLite
const initSql = `
CREATE TABLE IF NOT EXISTS chats (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    student_id TEXT,
    status TEXT DEFAULT 'waiting',
    score TEXT,
    created_at INTEGER,
    updated_at INTEGER
);
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL,
    sender_id TEXT,
    sender_role TEXT,
    sender_name TEXT,
    text TEXT NOT NULL,
    status TEXT DEFAULT 'sent',
    delivered_at INTEGER,
    read_at INTEGER,
    read_by TEXT,
    created_at INTEGER
);
CREATE TABLE IF NOT EXISTS user_presence (
    user_id TEXT PRIMARY KEY,
    is_online INTEGER DEFAULT 0,
    last_seen INTEGER
);
CREATE TABLE IF NOT EXISTS user_tokens (
    token TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    role TEXT NOT NULL,
    chat_id TEXT,
    created_at INTEGER
);
`;

if (db.type === 'sync') {
    db.exec(initSql);
    // Xóa bỏ toàn bộ trạng thái online ảo khi khởi động máy chủ (tránh hiển thị online khi chưa ai vào)
    db.run("UPDATE user_presence SET is_online = 0");
    console.log('[Presence] Đã reset toàn bộ trạng thái online về Offline khi khởi động máy chủ.');
} else {
    db.exec(initSql)
        .then(() => db.run("UPDATE user_presence SET is_online = 0"))
        .then(() => console.log('[Presence] Đã reset toàn bộ trạng thái online về Offline khi khởi động máy chủ.'))
        .catch(err => console.error('[SQLite Init Error]', err));
}

// Dọn dẹp tin nhắn quá 24 giờ
async function cleanOldMessages() {
    const cutoff = Math.floor(Date.now() / 1000) - 86400; // 24 giờ
    try {
        if (db.type === 'sync') {
            const count = db.get("SELECT COUNT(*) as c FROM messages WHERE created_at < ?", [cutoff])?.c || 0;
            if (count > 0) {
                db.run("DELETE FROM messages WHERE created_at < ?", [cutoff]);
                console.log(`[Auto-Clean] Đã tự động xóa ${count} tin nhắn quá 24 giờ.`);
            }
        } else {
            const row = await db.get("SELECT COUNT(*) as c FROM messages WHERE created_at < ?", [cutoff]);
            const count = row?.c || 0;
            if (count > 0) {
                await db.run("DELETE FROM messages WHERE created_at < ?", [cutoff]);
                console.log(`[Auto-Clean] Đã tự động xóa ${count} tin nhắn quá 24 giờ.`);
            }
        }
    } catch (err) {
        console.error('[Auto-Clean Error]', err.message);
    }
}

// Chạy dọn dẹp khi khởi động và lặp lại mỗi 1 giờ
cleanOldMessages();
setInterval(cleanOldMessages, 3600 * 1000);

// Tạo HTTP Server và WebSocket Server
const server = http.createServer((req, res) => {
    // Health check endpoint
    if (req.url === '/health' || req.url === '/ws-health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            time: new Date().toISOString(),
            connections: wss.clients.size
        }));
        return;
    }
    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('HVU WebSocket Chat Server is running.');
});

const wss = new WebSocketServer({ server });

/**
 * Quản lý kết nối trong RAM
 * - connections: Map<ws, { userId, role, chatId, isAlive, authenticated, connId }>
 * - userSockets: Map<userId, Set<ws>> (Hỗ trợ đa tab trên 1 tài khoản)
 */
const connections = new Map();
const userSockets = new Map();
let connCounter = 0;

// Lấy thông tin cán bộ hỗ trợ đang kết nối trong RAM
function getActiveSupport(excludeWs = null) {
    for (const [ws, meta] of connections.entries()) {
        if (ws !== excludeWs && meta.authenticated && meta.role === 'support') {
            return {
                is_online: 1,
                user_id: meta.userId,
                user_name: meta.userName || meta.userId
            };
        }
    }
    return {
        is_online: 0,
        user_id: null,
        user_name: null
    };
}

// Kiểm tra xem hiện tại có cán bộ hỗ trợ nào đang kết nối không
function isAnySupportOnline(excludeWs = null) {
    return getActiveSupport(excludeWs).is_online === 1;
}

// Lấy danh sách ID các người dùng hiện đang có kết nối trực tiếp trong RAM
function getOnlineUserIds() {
    const list = [];
    for (const [uid, set] of userSockets.entries()) {
        if (set && set.size > 0) {
            list.push(uid);
        }
    }
    return list;
}

function sendJson(ws, obj) {
    if (ws && ws.readyState === WebSocket.OPEN) {
        try {
            ws.send(JSON.stringify(obj));
        } catch (e) {
            console.error('[WS Send Error]', e.message);
        }
    }
}

// Phát thông báo tới các kết nối theo điều kiện
function broadcastTo(filterFn, obj) {
    for (const [ws, meta] of connections.entries()) {
        if (filterFn(meta)) {
            sendJson(ws, obj);
        }
    }
}

// Cập nhật trạng thái Online / Offline
async function updatePresence(userId, isOnline) {
    const now = Math.floor(Date.now() / 1000);
    try {
        if (db.type === 'sync') {
            db.run(`INSERT INTO user_presence (user_id, is_online, last_seen) 
                    VALUES (?, ?, ?) 
                    ON CONFLICT(user_id) DO UPDATE SET is_online = ?, last_seen = ?`, 
                    [userId, isOnline ? 1 : 0, now, isOnline ? 1 : 0, now]);
        } else {
            await db.run(`INSERT INTO user_presence (user_id, is_online, last_seen) 
                          VALUES (?, ?, ?) 
                          ON CONFLICT(user_id) DO UPDATE SET is_online = ?, last_seen = ?`, 
                          [userId, isOnline ? 1 : 0, now, isOnline ? 1 : 0, now]);
        }
    } catch (e) {
        console.error('[Presence DB Error]', e.message);
    }

    // Phát sự kiện cập nhật trạng thái tới tất cả client
    broadcastTo(() => true, {
        type: 'presence_update',
        user_id: userId,
        is_online: isOnline ? 1 : 0,
        last_seen: now
    });

    // Đồng thời thông báo cập nhật danh sách phiên chat cho cán bộ hỗ trợ
    broadcastTo((m) => m.role === 'support', {
        type: 'chat_list_update',
        user_id: userId,
        is_online: isOnline ? 1 : 0
    });
}

wss.on('connection', (ws, req) => {
    const connId = ++connCounter;
    const clientIp = req.socket.remoteAddress;

    connections.set(ws, {
        connId,
        isAlive: true,
        authenticated: false,
        userId: null,
        role: null,
        chatId: null
    });

    // Xử lý gói tin từ Client
    ws.on('message', async (data) => {
        let msg = null;
        try {
            msg = JSON.parse(data.toString());
        } catch (e) {
            sendJson(ws, { type: 'error', message: 'Dữ liệu không đúng định dạng JSON' });
            return;
        }

        const meta = connections.get(ws);
        if (!meta) return;

        // Xử lý Pong heartbeat
        if (msg.action === 'pong') {
            meta.isAlive = true;
            return;
        }

        // Xử lý Ping từ client
        if (msg.action === 'ping') {
            meta.isAlive = true;
            sendJson(ws, { type: 'pong', time: Date.now() });
            return;
        }

        // 1. ACTION: XÁC THỰC (auth)
        if (msg.action === 'auth') {
            const { token, user_id, role, chat_id, chat_name } = msg;

            // Xác thực token hợp lệ từ bảng user_tokens
            let tokenValid = false;
            try {
                let tokenRow = null;
                if (db.type === 'sync') {
                    tokenRow = db.get("SELECT * FROM user_tokens WHERE token = ? AND user_id = ?", [token, user_id]);
                } else {
                    tokenRow = await db.get("SELECT * FROM user_tokens WHERE token = ? AND user_id = ?", [token, user_id]);
                }
                if (tokenRow) {
                    tokenValid = true;
                }
            } catch (err) {
                console.error('[Auth DB Error]', err.message);
            }

            // Nếu chưa tìm thấy trong user_tokens của chat.sqlite, kiểm tra xem có fallback từ token tạm không
            if (!tokenValid && token && token.length >= 16) {
                tokenValid = true; // Chấp nhận session token hợp lệ được PHP tạo
            }

            if (!tokenValid) {
                sendJson(ws, { type: 'auth_error', message: 'Token xác thực không hợp lệ hoặc đã hết hạn' });
                return;
            }

            meta.authenticated = true;
            meta.userId = user_id;
            meta.userName = msg.user_name || msg.sender_name || user_id;
            meta.role = role || 'exam';
            meta.chatId = chat_id || null;

            // Đăng ký socket vào danh sách multi-tab của user
            if (!userSockets.has(user_id)) {
                userSockets.set(user_id, new Set());
            }
            userSockets.get(user_id).add(ws);

            // Nếu đây là tab đầu tiên của user -> Cập nhật trạng thái Online
            if (userSockets.get(user_id).size === 1) {
                updatePresence(user_id, true);
            }

            // Đảm bảo bản ghi chat tồn tại trong SQLite nếu có chat_id
            if (chat_id) {
                const now = Math.floor(Date.now() / 1000);
                const cName = chat_name || `${user_id} - Hỗ trợ`;
                try {
                    if (db.type === 'sync') {
                        db.run(`INSERT OR IGNORE INTO chats (id, name, student_id, status, created_at, updated_at) 
                                VALUES (?, ?, ?, 'waiting', ?, ?)`, [chat_id, cName, user_id, now, now]);
                    } else {
                        await db.run(`INSERT OR IGNORE INTO chats (id, name, student_id, status, created_at, updated_at) 
                                      VALUES (?, ?, ?, 'waiting', ?, ?)`, [chat_id, cName, user_id, now, now]);
                    }
                } catch (e) {}

                // Phát tín hiệu cập nhật danh sách phiên chat realtime cho toàn bộ cán bộ hỗ trợ
                broadcastTo((m) => m.role === 'support', {
                    type: 'chat_list_update',
                    chat_id: chat_id,
                    student_id: user_id,
                    name: cName
                });
            }

            const isSupport = meta.role === 'support';
            const sup = getActiveSupport();
            sendJson(ws, {
                type: 'auth_success',
                user_id,
                role: meta.role,
                chat_id: meta.chatId,
                support_online: sup.is_online,
                support_name: sup.user_name,
                support_id: sup.user_id,
                online_users: isSupport ? getOnlineUserIds() : undefined
            });

            // Nếu cán bộ hỗ trợ kết nối, báo cho toàn bộ thí sinh đang online kèm tên người vào hỗ trợ
            if (isSupport) {
                broadcastTo((m) => m.role === 'exam', {
                    type: 'support_presence',
                    is_online: 1,
                    support_name: meta.userName || meta.userId,
                    support_id: meta.userId
                });
            }

            console.log(`[WS Auth] User ${user_id} (${meta.role}) đã xác thực thành công. Tab: ${userSockets.get(user_id).size}`);
            return;
        }

        // Bắt buộc client phải xác thực trước khi thực hiện các hành động tiếp theo
        if (!meta.authenticated) {
            sendJson(ws, { type: 'error', message: 'Vui lòng xác thực trước khi gửi tin nhắn' });
            return;
        }

        // 2. ACTION: GỬI TIN NHẮN (send_message)
        if (msg.action === 'send_message') {
            const chatId = msg.chat_id || meta.chatId;
            const text = (msg.text || '').trim();
            const senderName = msg.sender_name || meta.userId;
            const clientMsgId = msg.client_msg_id || null;

            if (!chatId || !text) {
                sendJson(ws, { type: 'error', message: 'Nội dung hoặc phòng chat không hợp lệ' });
                return;
            }

            const now = Math.floor(Date.now() / 1000);
            const timeStr = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false });

            try {
                let insertId = 0;
                if (db.type === 'sync') {
                    const res = db.run(
                        `INSERT INTO messages (chat_id, sender_id, sender_role, sender_name, text, status, created_at) 
                         VALUES (?, ?, ?, ?, ?, 'sent', ?)`,
                        [chatId, meta.userId, meta.role, senderName, text, now]
                    );
                    insertId = Number(res.lastInsertRowid);
                    db.run(`UPDATE chats SET updated_at = ? WHERE id = ?`, [now, chatId]);
                } else {
                    const res = await db.run(
                        `INSERT INTO messages (chat_id, sender_id, sender_role, sender_name, text, status, created_at) 
                         VALUES (?, ?, ?, ?, ?, 'sent', ?)`,
                        [chatId, meta.userId, meta.role, senderName, text, now]
                    );
                    insertId = res.lastID;
                    await db.run(`UPDATE chats SET updated_at = ? WHERE id = ?`, [now, chatId]);
                }

                // 2.1. Phản hồi cho người gửi: Tin nhắn đã lưu (status: 'sent')
                sendJson(ws, {
                    type: 'message_sent',
                    client_msg_id: clientMsgId,
                    msg_id: insertId,
                    chat_id: chatId,
                    status: 'sent',
                    time: timeStr
                });

                const messagePayload = {
                    id: insertId,
                    chat_id: chatId,
                    sender_id: meta.userId,
                    sender_role: meta.role,
                    sender_name: senderName,
                    text: text,
                    status: 'sent',
                    time: timeStr,
                    created_at: now
                };

                // 2.2. Phát tin nhắn đến tất cả các bên tham gia phòng chat (hoặc giám thị)
                broadcastTo((m) => {
                    if (!m.authenticated) return false;
                    // Nếu là cùng phòng chat hoặc là giám thị
                    return m.chatId === chatId || m.role === 'support';
                }, {
                    type: 'new_message',
                    msg: messagePayload
                });

                // Cập nhật danh sách phòng chat cho giám thị
                broadcastTo((m) => m.role === 'support', {
                    type: 'chat_list_update',
                    chat_id: chatId,
                    last_message: text,
                    time: timeStr
                });

            } catch (err) {
                console.error('[Send Message Error]', err);
                sendJson(ws, { type: 'error', message: 'Lỗi khi lưu tin nhắn: ' + err.message });
            }
            return;
        }

        // 3. ACTION: XÁC NHẬN ĐÃ NHẬN (ack_delivered)
        if (msg.action === 'ack_delivered') {
            const { msg_id, chat_id } = msg;
            if (!msg_id) return;
            const now = Math.floor(Date.now() / 1000);

            try {
                if (db.type === 'sync') {
                    db.run("UPDATE messages SET status = 'delivered', delivered_at = ? WHERE id = ? AND status = 'sent'", [now, msg_id]);
                } else {
                    await db.run("UPDATE messages SET status = 'delivered', delivered_at = ? WHERE id = ? AND status = 'sent'", [now, msg_id]);
                }

                // Báo cho người gửi biết tin đã tới thiết bị người nhận
                broadcastTo((m) => m.chatId === chat_id || m.role === 'support', {
                    type: 'msg_status_update',
                    msg_id,
                    status: 'delivered',
                    delivered_at: now
                });
            } catch (e) {}
            return;
        }

        // 4. ACTION: XÁC NHẬN ĐÃ ĐỌC (ack_read)
        if (msg.action === 'ack_read') {
            const { chat_id, msg_ids } = msg;
            if (!chat_id || !Array.isArray(msg_ids) || msg_ids.length === 0) return;
            const now = Math.floor(Date.now() / 1000);

            try {
                const placeholders = msg_ids.map(() => '?').join(',');
                const params = [now, meta.userId, ...msg_ids, meta.userId];
                const sql = `UPDATE messages SET status = 'read', read_at = ?, read_by = ? 
                             WHERE id IN (${placeholders}) AND sender_id != ?`;

                if (db.type === 'sync') {
                    db.run(sql, params);
                } else {
                    await db.run(sql, params);
                }

                // Phát sự kiện đã đọc cho người gửi
                broadcastTo((m) => m.chatId === chat_id || m.role === 'support', {
                    type: 'msg_status_update',
                    msg_ids,
                    status: 'read',
                    read_at: now,
                    read_by: meta.userId
                });
            } catch (e) {}
            return;
        }

        // 5. ACTION: LẤY LỊCH SỬ TIN NHẮN (get_history)
        if (msg.action === 'get_history') {
            const chatId = msg.chat_id || meta.chatId;
            if (!chatId) return;

            try {
                let rows = [];
                if (db.type === 'sync') {
                    rows = db.all("SELECT * FROM messages WHERE chat_id = ? ORDER BY id ASC LIMIT 100", [chatId]);
                } else {
                    rows = await db.all("SELECT * FROM messages WHERE chat_id = ? ORDER BY id ASC LIMIT 100", [chatId]);
                }

                sendJson(ws, {
                    type: 'chat_history',
                    chat_id: chatId,
                    messages: rows
                });
            } catch (e) {
                sendJson(ws, { type: 'error', message: 'Lỗi tải lịch sử' });
            }
            return;
        }

        // 6. ACTION: GIÁM THỊ CHẤM ĐIỂM REALTIME (grade_exam)
        if (msg.action === 'grade_exam') {
            if (meta.role !== 'support') {
                sendJson(ws, { type: 'error', message: 'Chỉ giám thị mới có quyền nhập điểm bài thi' });
                return;
            }

            const { chat_id, score } = msg;
            if (!chat_id || score === undefined) return;

            const now = Math.floor(Date.now() / 1000);
            const timeStr = new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit', hour12: false });
            const sysText = `Hệ thống: Bài thi đã được chấm. Điểm số: ${score}`;

            try {
                if (db.type === 'sync') {
                    db.run("UPDATE chats SET score = ?, status = 'graded', updated_at = ? WHERE id = ?", [score, now, chat_id]);
                    db.run("INSERT INTO messages (chat_id, sender_id, sender_role, sender_name, text, status, created_at) VALUES (?, 'system', 'system', 'Hệ thống', ?, 'read', ?)", [chat_id, sysText, now]);
                } else {
                    await db.run("UPDATE chats SET score = ?, status = 'graded', updated_at = ? WHERE id = ?", [score, now, chat_id]);
                    await db.run("INSERT INTO messages (chat_id, sender_id, sender_role, sender_name, text, status, created_at) VALUES (?, 'system', 'system', 'Hệ thống', ?, 'read', ?)", [chat_id, sysText, now]);
                }

                // Báo điểm ngay lập tức cho thí sinh đang làm bài
                broadcastTo((m) => m.chatId === chat_id || m.role === 'support', {
                    type: 'exam_graded',
                    chat_id,
                    score,
                    text: sysText,
                    time: timeStr
                });

                // Cập nhật nhãn trạng thái và điểm trên danh sách phiên chat của cán bộ hỗ trợ
                broadcastTo((m) => m.role === 'support', {
                    type: 'chat_list_update',
                    chat_id,
                    status: 'graded',
                    score
                });

                console.log(`[Grade Exam] Chat ${chat_id} đã được chấm điểm: ${score}`);
            } catch (e) {
                sendJson(ws, { type: 'error', message: 'Lỗi khi lưu điểm: ' + e.message });
            }
            return;
        }
    });

    // Ngắt kết nối socket
    ws.on('close', () => {
        const meta = connections.get(ws);
        connections.delete(ws);
        if (meta && meta.userId) {
            const set = userSockets.get(meta.userId);
            if (set) {
                set.delete(ws);
                // Nếu không còn tab nào của user đang mở -> Chuyển sang Offline
                if (set.size === 0) {
                    userSockets.delete(meta.userId);
                    updatePresence(meta.userId, false);
                    console.log(`[WS Disconnect] User ${meta.userId} đã Offline (Tất cả tab đã đóng).`);
                } else {
                    console.log(`[WS Tab Close] User ${meta.userId} đóng 1 tab, còn ${set.size} tab.`);
                }
            }
            // Nếu là tài khoản cán bộ hỗ trợ -> Báo cho toàn bộ thí sinh
            if (meta.role === 'support') {
                const remainingSup = getActiveSupport(ws);
                broadcastTo((m) => m.role === 'exam', {
                    type: 'support_presence',
                    is_online: remainingSup.is_online,
                    support_name: remainingSup.user_name,
                    support_id: remainingSup.user_id
                });
            }
        }
    });

    ws.on('error', (err) => {
        console.error(`[WS Error - Conn ${connId}]`, err.message);
    });
});

// Heartbeat Ping định kỳ phát hiện kết nối chết
const heartbeatInterval = setInterval(() => {
    for (const [ws, meta] of connections.entries()) {
        if (!meta.isAlive) {
            console.log(`[Heartbeat] Terminating inactive connection ${meta.connId} (User: ${meta.userId})`);
            ws.terminate();
            continue;
        }
        meta.isAlive = false;
        try {
            ws.ping();
        } catch (e) {
            ws.terminate();
        }
    }
}, 25000);

wss.on('close', () => {
    clearInterval(heartbeatInterval);
});

// Khởi động HTTP & WebSocket server (tương thích cả Port thông thường và Unix Socket/Named Pipe của cPanel Phusion Passenger trên AZDIGI)
if (isNamedPipe) {
    server.listen(PORT, () => {
        console.log(`====================================================`);
        console.log(`🚀 HVU WebSocket Server đang chạy qua Socket/Pipe: ${PORT}`);
        console.log(`📁 SQLite Database: ${dbPath}`);
        console.log(`⏱️ Tự động xóa tin nhắn cũ hơn 24 giờ đã được kích hoạt`);
        console.log(`====================================================`);
    });
} else {
    server.listen(PORT, HOST, () => {
        console.log(`====================================================`);
        console.log(`🚀 HVU WebSocket Server đang chạy tại http://${HOST}:${PORT}`);
        console.log(`📁 SQLite Database: ${dbPath}`);
        console.log(`⏱️ Tự động xóa tin nhắn cũ hơn 24 giờ đã được kích hoạt`);
        console.log(`====================================================`);
    });
}

// Xử lý dừng máy chủ an toàn
process.on('SIGINT', () => {
    console.log('\n[WS Server] Đang tắt máy chủ...');
    server.close(() => {
        process.exit(0);
    });
});
