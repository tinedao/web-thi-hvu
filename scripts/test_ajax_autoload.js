// scripts/test_ajax_autoload.js - Kiểm tra cơ chế tự động tải (Auto-load AJAX & Realtime WS)
const http = require('http');
const WebSocket = require('ws');

const BASE_URL = 'http://127.0.0.1:8000';
const WS_URL = 'ws://127.0.0.1:8085';

function request(options, postData = null) {
    return new Promise((resolve, reject) => {
        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve({ statusCode: res.statusCode, headers: res.headers, body: data }));
        });
        req.on('error', reject);
        if (postData) req.write(postData);
        req.end();
    });
}

async function runTest() {
    console.log("=== KIỂM TRA CƠ CHẾ AUTO-LOAD AJAX & REALTIME WEBSOCKET ===");

    // 1. Kiểm tra AJAX dashboard.php?ajax_action=list
    console.log("\n[TEST 1] Gọi AJAX lấy danh sách phiên chat (ajax_action=list)...");
    const listRes = await request({
        hostname: '127.0.0.1',
        port: 8000,
        path: '/dashboard.php?ajax_action=list',
        method: 'GET'
    });
    
    if (listRes.statusCode === 200) {
        const chats = JSON.parse(listRes.body);
        console.log(`  ✅ PASS: Trả về HTTP 200, tìm thấy ${chats.length} phiên chat.`);
        if (chats.length > 0) {
            console.log(`  ℹ️ Phiên mới nhất: ID=${chats[0].id}, Tên=${chats[0].name}, Trạng thái=${chats[0].status}, Online=${chats[0].is_online}`);
        }
    } else {
        console.error("  ❌ FAIL: Không thể gọi ajax_action=list");
        process.exit(1);
    }

    // 2. Kiểm tra WebSocket chat_list_update khi thí sinh mới vào thi
    console.log("\n[TEST 2] Giám thị kết nối WS và lắng nghe thông báo phiên chat mới...");
    const supportWs = new WebSocket(WS_URL);

    let chatListUpdateReceived = false;
    let receivedUpdatePayload = null;

    await new Promise((resolve, reject) => {
        supportWs.on('open', () => {
            supportWs.send(JSON.stringify({
                action: 'auth',
                token: 'token_admin_test_12345678',
                user_id: 'support_admin',
                role: 'support',
                sender_name: 'Cán bộ hỗ trợ'
            }));
        });

        supportWs.on('message', (raw) => {
            const data = JSON.parse(raw.toString());
            if (data.type === 'auth_success') {
                console.log("  ✅ PASS: Giám thị xác thực thành công");
                resolve();
            } else if (data.type === 'chat_list_update') {
                chatListUpdateReceived = true;
                receivedUpdatePayload = data;
                console.log("  ✅ PASS: Giám thị nhận được thông báo realtime chat_list_update!", data);
            }
        });

        supportWs.on('error', reject);
    });

    // 3. Thí sinh mới kết nối vào phòng chat mới
    const testChatId = 'chat_autoload_test_' + Date.now();
    const studentWs = new WebSocket(WS_URL);

    await new Promise((resolve) => {
        studentWs.on('open', () => {
            console.log(`\n[TEST 3] Thí sinh kết nối và xác thực phòng thi mới (${testChatId})...`);
            studentWs.send(JSON.stringify({
                action: 'auth',
                token: 'token_cand_test_12345678',
                user_id: '235D150110',
                user_name: 'Hà Văn Nghị',
                role: 'exam',
                chat_id: testChatId,
                chat_name: 'Hà Văn Nghị (235D150110) - Kiểm thử Auto-load'
            }));
        });

        studentWs.on('message', (raw) => {
            const data = JSON.parse(raw.toString());
            if (data.type === 'auth_success') {
                console.log("  ✅ PASS: Thí sinh xác thực thành công");
                resolve();
            }
        });
    });

    // Đợi 500ms để đảm bảo gói tin realtime chat_list_update đã được nhận
    await new Promise(r => setTimeout(r, 600));

    if (chatListUpdateReceived) {
        console.log("  ✅ PASS: Xác nhận realtime: Khi thí sinh mới vào thi, WebSocket lập tức báo chat_list_update cho giám thị!");
    } else {
        console.error("  ❌ FAIL: Giám thị chưa nhận được chat_list_update!");
        process.exit(1);
    }

    // 4. Kiểm tra AJAX tự động trả về phiên mới vừa tạo
    console.log("\n[TEST 4] Gọi lại AJAX ajax_action=list để xác minh phiên mới xuất hiện...");
    const verifyRes = await request({
        hostname: '127.0.0.1',
        port: 8000,
        path: '/dashboard.php?ajax_action=list',
        method: 'GET'
    });
    const updatedChats = JSON.parse(verifyRes.body);
    const foundNewChat = updatedChats.find(c => c.id === testChatId);

    if (foundNewChat) {
        console.log(`  ✅ PASS: Phiên chat ${testChatId} đã tự động xuất hiện trong danh sách AJAX!`);
        console.log(`  ℹ️ Thông tin: Tên="${foundNewChat.name}", Trạng thái="${foundNewChat.status}", Online=${foundNewChat.is_online}`);
    } else {
        console.error(`  ❌ FAIL: Không tìm thấy ${testChatId} trong danh sách chat AJAX!`);
        process.exit(1);
    }

    // 5. Thí sinh gửi tin nhắn, kiểm tra AJAX fetch
    console.log("\n[TEST 5] Thí sinh gửi tin nhắn qua WebSocket, kiểm tra AJAX ajax_action=fetch...");
    studentWs.send(JSON.stringify({
        action: 'send_message',
        chat_id: testChatId,
        text: 'Em cần hỗ trợ câu 10 ạ',
        sender_name: 'Hà Văn Nghị'
    }));

    await new Promise(r => setTimeout(r, 500));

    const fetchRes = await request({
        hostname: '127.0.0.1',
        port: 8000,
        path: `/dashboard.php?ajax_action=fetch&chat_id=${testChatId}`,
        method: 'GET'
    });
    const fetchJson = JSON.parse(fetchRes.body);
    if (fetchJson.status === 'success' && fetchJson.messages && fetchJson.messages.length > 0) {
        console.log(`  ✅ PASS: AJAX ajax_action=fetch trả về đúng ${fetchJson.messages.length} tin nhắn: "${fetchJson.messages[0].text}"`);
    } else {
        console.error("  ❌ FAIL: Không lấy được tin nhắn qua AJAX fetch!", fetchJson);
        process.exit(1);
    }

    // Đóng socket kết thúc
    studentWs.close();
    supportWs.close();

    console.log("\n==================================================");
    console.log("🎉 TOÀN BỘ CƠ CHẾ AUTO-LOAD AJAX & REALTIME WEBSOCKET HOẠT ĐỘNG HOÀN HẢO!");
    console.log("==================================================");
}

runTest().catch(err => {
    console.error("Lỗi test:", err);
    process.exit(1);
});
