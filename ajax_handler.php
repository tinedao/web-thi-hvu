<?php
// ajax_handler.php - Xử lý AJAX tập trung
// File này được include bởi login.php, index.php, dashboard.php khi có ajax_action
// Yêu cầu: session_start() đã được gọi trước khi include file này

header('Content-Type: application/json');
require_once 'config.php';

$action = $_GET['ajax_action'];

try {

    // === QUẢN LÝ CHAT ===

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? 'Chat Support';
        $chatId = uniqid('chat_');
        db_insert('chats', [
            'id' => $chatId,
            'name' => $name,
            'status' => 'waiting',
            'created_at' => time()
        ]);
        echo json_encode(['status' => 'success', 'chat_id' => $chatId]);
        exit;
    }

    if ($action === 'fetch') {
        $chatId = $_GET['chat_id'] ?? '';
        $lastId = (int)($_GET['last_id'] ?? 0);

        $chat = db_fetch("SELECT status, score FROM chats WHERE id = ?", [$chatId]);

        if (!$chat) {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy phòng chat']);
            exit;
        }

        $messages = db_fetch_all("SELECT id, sender, text, time FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC", [$chatId, $lastId]);

        echo json_encode([
            'status' => 'success',
            'chat_status' => $chat['status'],
            'chat_score' => $chat['score'] ?? null,
            'messages' => $messages
        ]);
        exit;
    }

    if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        $text = $_POST['text'] ?? '';
        $sender = $_POST['sender'] ?? 'User';
        db_insert('messages', [
            'chat_id' => $chatId,
            'sender' => $sender,
            'text' => htmlspecialchars($text),
            'time' => date('H:i'),
            'created_at' => time()
        ]);
        // Tự động cập nhật trạng thái khi sinh viên tham gia (System message)
        if ($sender === 'System') {
            db_update('chats', ['status' => 'attached'], ['id' => $chatId]);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        db_delete('chats', ['id' => $chatId]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        db_update('chats', ['status' => 'closed'], ['id' => $chatId]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // === CHẤM ĐIỂM ===

    if (($action === 'update_score' || $action === 'grade') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        $score = $_POST['score'] ?? 0;
        db_update('chats', ['score' => $score, 'status' => 'graded'], ['id' => $chatId]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // === KHÔI PHỤC CHAT ===

    if ($action === 'find_replacement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $oldChatId = $_POST['old_chat_id'] ?? '';
        $chatName = $_POST['chat_name'] ?? '';
        $chats = db_fetch_all("SELECT id FROM chats WHERE name = ? AND id != ? ORDER BY created_at DESC LIMIT 1", [$chatName, $oldChatId]);
        if (count($chats) > 0) {
            echo json_encode(['status' => 'success', 'new_chat_id' => $chats[0]['id']]);
        } else {
            echo json_encode(['status' => 'waiting']);
        }
        exit;
    }

    if ($action === 'recover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $fullname = $_POST['fullname'] ?? 'Student';
        $subject = $_POST['subject'] ?? 'Exam';
        $oldChatId = $_POST['old_chat_id'] ?? '';

        $newChatId = uniqid('chat_');
        $chatName = "$fullname - $subject";

        db_insert('chats', [
            'id' => $newChatId,
            'name' => $chatName,
            'status' => 'waiting',
            'created_at' => time()
        ]);

        if ($oldChatId) {
            db_update('chats', ['status' => 'disabled'], ['id' => $oldChatId]);
        }

        $_SESSION['chat_id'] = $newChatId;
        echo json_encode(['status' => 'success', 'new_chat_id' => $newChatId]);
        exit;
    }

    // === DANH SÁCH CHAT (Dashboard) ===

    if ($action === 'list') {
        echo json_encode(db_fetch_all("SELECT * FROM chats WHERE status != 'closed' ORDER BY created_at DESC"));
        exit;
    }

    // === QUẢN LÝ ĐỀ THI ===

    if ($action === 'upload_subject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['subject_file']) && $_FILES['subject_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['subject_file']['tmp_name'];
            $name = basename($_FILES['subject_file']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($ext !== 'json') {
                echo json_encode(['status' => 'error', 'message' => 'Chỉ cho phép file .json']);
                exit;
            }

            if (move_uploaded_file($tmpName, __DIR__ . '/' . $name)) {
                echo json_encode(['status' => 'success', 'message' => 'Đã upload file: ' . $name]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Không thể lưu file lên server']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Lỗi upload file']);
        }
        exit;
    }

    if ($action === 'list_subjects') {
        $files = glob('*.json');
        $result = array_map(function($f) {
            return ['filename' => $f, 'name' => basename($f, '.json')];
        }, $files);
        echo json_encode(['status' => 'success', 'data' => $result]);
        exit;
    }

    if ($action === 'create_subject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        if (!$name) { echo json_encode(['status' => 'error', 'message' => 'Tên không hợp lệ']); exit; }

        $filename = $name . '.json';
        if (file_exists($filename)) { echo json_encode(['status' => 'error', 'message' => 'File đã tồn tại']); exit; }

        file_put_contents($filename, json_encode([], JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete_subject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $filename = $_POST['filename'] ?? '';
        if (file_exists($filename) && pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
            unlink($filename);
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File không tồn tại']);
        }
        exit;
    }

    if ($action === 'get_questions') {
        $filename = $_GET['filename'] ?? '';
        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            echo json_encode(['status' => 'success', 'data' => json_decode($content, true) ?? []]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File không tìm thấy']);
        }
        exit;
    }

    if ($action === 'save_questions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $filename = $_POST['filename'] ?? '';
        $questionsJSON = $_POST['questions'] ?? '[]';
        $questions = json_decode($questionsJSON, true);
        if ($questions === null) { echo json_encode(['status' => 'error', 'message' => 'JSON lỗi']); exit; }
        file_put_contents($filename, json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // Action không tìm thấy
    echo json_encode(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
    exit;

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Lỗi Server: ' . $e->getMessage()]);
    exit;
}
