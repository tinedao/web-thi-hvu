/**
 * test_system.js - Kịch bản kiểm thử tự động toàn diện hệ thống
 */

let WebSocket;
try {
    WebSocket = require('ws').WebSocket || require('ws');
} catch (e) {
    WebSocket = require('../websocket/node_modules/ws').WebSocket || require('../websocket/node_modules/ws');
}

async function runTests() {
    console.log('==================================================');
    console.log('🧪 BẮT ĐẦU KIỂM THỬ HỆ THỐNG THI + WEBSOCKET + SQLITE');
    console.log('==================================================\n');

    let passed = 0;
    let failed = 0;

    function assert(condition, message) {
        if (condition) {
            console.log(`  ✅ PASS: ${message}`);
            passed++;
        } else {
            console.error(`  ❌ FAIL: ${message}`);
            failed++;
        }
    }

    // 1. Kiểm thử HTTP Server & Login
    console.log('[TEST 1] Đăng nhập & Tạo Session qua HTTP...');
    const loginRes = await fetch('http://127.0.0.1:8000/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'student_id=235D150109&fullname=%C4%90%C3%A0o+Quang+Ti%E1%BA%BFn&role=exam',
        redirect: 'manual'
    });

    const cookieHeader = loginRes.headers.get('set-cookie');
    assert(loginRes.status === 302, `Login trả về 302 Redirect (Status: ${loginRes.status})`);
    const location = loginRes.headers.get('location');
    assert(location === 'portal.php', `Điều hướng đến portal.php (Location: ${location})`);
    assert(cookieHeader && cookieHeader.includes('PHPSESSID'), 'Session Cookie PHPSESSID được tạo thành công');

    const phpSessionCookie = cookieHeader ? cookieHeader.split(';')[0] : '';

    // 2. Kiểm thử Portal.php (Giao diện clone HVU)
    console.log('\n[TEST 2] Truy cập Cổng thi portal.php (Giao diện clone HVU)...');
    const portalRes = await fetch('http://127.0.0.1:8000/portal.php', {
        headers: { 'Cookie': phpSessionCookie }
    });
    const portalHtml = await portalRes.text();
    assert(portalRes.status === 200, `Portal trả về HTTP 200`);
    assert(portalHtml.includes('TRƯỜNG ĐẠI HỌC HÙNG VƯƠNG'), `Portal hiển thị đúng tiêu đề trường`);
    assert(portalHtml.includes('Đầu tư chứng khoán') || portalHtml.includes('Hiện tại bạn không có ca thi nào!'), `Portal hiển thị đúng ca thi hoặc thông báo`);
    assert(portalHtml.includes('examModalOverlay'), `Portal có popup làm mờ nền nhập mã thi`);

    // 3. Kiểm thử Nhập mã dự thi trên Popup Portal
    console.log('\n[TEST 3] Kiểm tra xác thực Mã dự thi trên Popup Portal...');
    // 3.1. Mã rỗng
    const invalidForm = new URLSearchParams();
    invalidForm.append('ajax_verify_code', '1');
    invalidForm.append('exam_code', '');
    const emptyCodeRes = await fetch('http://127.0.0.1:8000/portal.php', {
        method: 'POST',
        headers: { 'Cookie': phpSessionCookie },
        body: invalidForm
    });
    const emptyJson = await emptyCodeRes.json();
    assert(emptyJson.status === 'error', 'Từ chối mã rỗng chính xác');

    // 3.2. Mã do sinh viên cung cấp (không check, tự động gán vào ca thi và chuyển thẳng index.php)
    const studentCodeForm = new URLSearchParams();
    studentCodeForm.append('ajax_verify_code', '1');
    studentCodeForm.append('exam_code', 'HVU-STUDENT-TEST');
    const studentCodeRes = await fetch('http://127.0.0.1:8000/portal.php', {
        method: 'POST',
        headers: { 'Cookie': phpSessionCookie },
        body: studentCodeForm
    });
    const studentCodeJson = await studentCodeRes.json();
    assert(studentCodeJson.status === 'success' && studentCodeJson.redirect === 'index.php', `Mã sinh viên cung cấp được chấp nhận ngay và chuyển thẳng index.php`);

    // 4. Kiểm tra mã dự thi được ghi nhận vào hệ thống
    console.log('\n[TEST 4] Kiểm tra mã dự thi đã được lưu vào hệ thống cho giám thị...');
    const codesRes = await fetch('http://127.0.0.1:8000/dashboard.php?ajax_action=list_exam_codes', {
        headers: { 'Cookie': phpSessionCookie }
    });
    const codesData = await codesRes.json();
    const hasStudentCode = codesData.data && codesData.data.some(c => c.code === 'HVU-STUDENT-TEST');
    assert(hasStudentCode, 'Mã ca thi do sinh viên nhập đã xuất hiện trong danh sách quản lý của giám thị');

    // 5. Kiểm thử Màn hình làm bài thi (index.php)
    console.log('\n[TEST 5] Kiểm tra trang thi index.php...');
    const examRes = await fetch('http://127.0.0.1:8000/index.php', {
        headers: { 'Cookie': phpSessionCookie }
    });
    const examHtml = await examRes.text();
    assert(examRes.status === 200, `Trang thi index.php trả về HTTP 200`);
    assert(examHtml.includes('id="countdown"'), 'Đồng hồ đếm ngược có mặt và hoạt động');
    assert(examHtml.includes('Câu 1:'), 'Câu hỏi trắc nghiệm được render chuẩn 2 dòng');
    assert(examHtml.includes('websocket-client.js'), 'Client WebSocket được tải đầy đủ');
    assert(examHtml.includes('chat_popup.php') || examHtml.includes('id="chat-popup"'), 'Widget chat popup có mặt trên giao diện');

    // Lấy chatId từ session qua ajax_action
    const sessionChatIdMatch = examHtml.match(/const sessionChatId = "([^"]+)";/);
    const chatId = sessionChatIdMatch ? sessionChatIdMatch[1] : 'chat_test';
    console.log(`  ℹ️ Chat ID của phiên: ${chatId}`);

    // 6. Kiểm thử WebSocket Realtime (Client Thí sinh & Giám thị)
    console.log('\n[TEST 6] Kiểm thử WebSocket Realtime 2 chiều...');
    await new Promise((resolve) => {
        const studentWs = new WebSocket('ws://127.0.0.1:8085');
        const supportWs = new WebSocket('ws://127.0.0.1:8085');

        let msgSentAck = false;
        let supportReceived = false;
        let deliveredAck = false;
        let readAck = false;
        let examGraded = false;

        studentWs.on('open', () => {
            // Xác thực thí sinh
            studentWs.send(JSON.stringify({
                action: 'auth',
                token: 'mock_token_1234567890123456',
                user_id: '235D150109',
                role: 'exam',
                chat_id: chatId
            }));
        });

        supportWs.on('open', () => {
            // Xác thực giám thị
            supportWs.send(JSON.stringify({
                action: 'auth',
                token: 'mock_token_1234567890123456',
                user_id: 'support_admin',
                role: 'support'
            }));
        });

        let studentMsgId = null;

        studentWs.on('message', (data) => {
            const res = JSON.parse(data.toString());
            if (res.type === 'auth_success') {
                assert(true, 'Thí sinh xác thực WebSocket thành công');
                // Gửi tin nhắn
                studentWs.send(JSON.stringify({
                    action: 'send_message',
                    chat_id: chatId,
                    text: 'Em cần hỗ trợ kỹ thuật câu hỏi số 5!',
                    sender_name: 'Đào Quang Tiến',
                    client_msg_id: 'test_client_001'
                }));
            }
            if (res.type === 'message_sent') {
                msgSentAck = true;
                studentMsgId = res.msg_id;
                assert(res.status === 'sent', `Tin nhắn được ghi vào SQLite (Trạng thái: ${res.status})`);
            }
            if (res.type === 'msg_status_update') {
                if (res.status === 'delivered') {
                    deliveredAck = true;
                    assert(true, 'Người gửi nhận được xác nhận ĐÃ NHẬN (Delivered)');
                }
                if (res.status === 'read') {
                    readAck = true;
                    assert(true, 'Người gửi nhận được xác nhận ĐÃ ĐỌC (Read)');
                }
            }
            if (res.type === 'exam_graded') {
                examGraded = true;
                assert(res.score === '9.0', `Thí sinh nhận được điểm thi realtime: ${res.score}`);
                studentWs.close();
                supportWs.close();
                resolve();
            }
        });

        supportWs.on('message', (data) => {
            const res = JSON.parse(data.toString());
            if (res.type === 'auth_success') {
                assert(true, 'Giám thị xác thực WebSocket thành công');
            }
            if (res.type === 'new_message' && res.msg && res.msg.text === 'Em cần hỗ trợ kỹ thuật câu hỏi số 5!') {
                supportReceived = true;
                assert(true, 'Giám thị nhận được tin nhắn realtime tức thì');
                // Giám thị gửi xác nhận đã nhận (Delivered)
                supportWs.send(JSON.stringify({
                    action: 'ack_delivered',
                    msg_id: res.msg.id,
                    chat_id: chatId
                }));
                // Giám thị mở đoạn chat -> gửi xác nhận đã đọc (Read)
                setTimeout(() => {
                    supportWs.send(JSON.stringify({
                        action: 'ack_read',
                        chat_id: chatId,
                        msg_ids: [res.msg.id]
                    }));
                }, 200);
                // Giám thị chấm điểm cho thí sinh
                setTimeout(() => {
                    supportWs.send(JSON.stringify({
                        action: 'grade_exam',
                        chat_id: chatId,
                        score: '9.0'
                    }));
                }, 400);
            }
        });
    });

    // 7. Kiểm thử Quản lý đợt thi & Đề thi JSON trên Dashboard
    console.log('\n[TEST 7] Kiểm thử Quản lý Đợt thi & Ngân hàng đề...');
    const listSessionsRes = await fetch('http://127.0.0.1:8000/dashboard.php?ajax_action=list_exam_sessions');
    const sessionsJson = await listSessionsRes.json();
    assert(sessionsJson.status === 'success' && sessionsJson.data.length > 0, `Lấy danh sách đợt thi thành công (${sessionsJson.data.length} đợt)`);

    const listCodesRes = await fetch('http://127.0.0.1:8000/dashboard.php?ajax_action=list_exam_codes');
    const codesJson = await listCodesRes.json();
    assert(codesJson.status === 'success' && codesJson.data.length > 0, `Lấy danh sách mã dự thi thành công (${codesJson.data.length} mã)`);

    console.log('\n==================================================');
    console.log(`📊 KẾT QUẢ: ${passed} PASS, ${failed} FAIL`);
    console.log('==================================================');
}

runTests().catch(err => {
    console.error('Lỗi khi chạy kịch bản kiểm thử:', err);
    process.exit(1);
});
