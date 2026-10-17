<?php
// ajax_handler.php - Xử lý AJAX tập trung (Chat, Đề thi JSON, Đợt thi & Cấp mã)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
$rootDir = dirname(__DIR__);
require_once $rootDir . '/config.php';

$action = $_GET['ajax_action'] ?? '';

try {

    // === QUẢN LÝ CHAT ===

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? 'Chat Support';
        $chatId = uniqid('chat_');
        $now = time();
        db_insert('chats', [
            'id' => $chatId,
            'name' => $name,
            'status' => 'waiting',
            'created_at' => $now
        ]);
        try {
            $chatDb = get_chat_db();
            $chatDb->prepare("INSERT OR IGNORE INTO chats (id, name, status, created_at, updated_at) VALUES (?, ?, 'waiting', ?, ?)")
                   ->execute([$chatId, $name, $now, $now]);
        } catch (Exception $e) {}

        echo json_encode(['status' => 'success', 'chat_id' => $chatId]);
        exit;
    }

    if ($action === 'fetch') {
        $chatId = $_GET['chat_id'] ?? '';
        $lastId = (int)($_GET['last_id'] ?? 0);

        $chat = db_fetch("SELECT status, score FROM chats WHERE id = ?", [$chatId]);

        // Luôn kiểm tra và đồng bộ trạng thái mới nhất từ SQLite chat.sqlite (nơi WebSocket server cập nhật)
        try {
            $chatDb = get_chat_db();
            $sChat = $chatDb->prepare("SELECT status, score FROM chats WHERE id = ?");
            $sChat->execute([$chatId]);
            $sqliteChatRow = $sChat->fetch(PDO::FETCH_ASSOC);
            if ($sqliteChatRow) {
                if (!$chat) {
                    $chat = $sqliteChatRow;
                } else {
                    if (!empty($sqliteChatRow['status'])) $chat['status'] = $sqliteChatRow['status'];
                    if (!empty($sqliteChatRow['score'])) $chat['score'] = $sqliteChatRow['score'];
                }
            }
        } catch (Exception $e) {}

        if (!$chat) {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy phòng chat']);
            exit;
        }

        $messages = db_fetch_all("SELECT id, sender, text, time, status, created_at FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC", [$chatId, $lastId]);

        // Lấy thêm tin nhắn từ chat.sqlite (tin nhắn gửi trực tiếp qua WebSocket)
        try {
            $chatDb = get_chat_db();
            $sMsgs = $chatDb->prepare("SELECT id, sender_name as sender, sender_id, sender_role, text, status, created_at FROM messages WHERE chat_id = ? AND id > ? ORDER BY id ASC");
            $sMsgs->execute([$chatId, $lastId]);
            $sqliteMsgs = $sMsgs->fetchAll(PDO::FETCH_ASSOC);

            // Gộp tin nhắn, tránh trùng lặp
            $existingTexts = [];
            foreach ($messages as $m) {
                $existingTexts[$m['text'] . '_' . ($m['created_at'] ?? '')] = true;
            }

            foreach ($sqliteMsgs as $sm) {
                $sm['time'] = date('H:i', $sm['created_at'] ?? time());
                $key = $sm['text'] . '_' . ($sm['created_at'] ?? '');
                if (!isset($existingTexts[$key])) {
                    $messages[] = $sm;
                    $existingTexts[$key] = true;
                }
            }

            // Sắp xếp lại danh sách tin nhắn theo id/created_at
            usort($messages, function($a, $b) {
                return ($a['id'] ?? 0) - ($b['id'] ?? 0);
            });
        } catch (Exception $e) {}

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
        $now = time();
        db_insert('messages', [
            'chat_id' => $chatId,
            'sender' => $sender,
            'text' => htmlspecialchars($text),
            'time' => date('H:i'),
            'status' => 'sent',
            'created_at' => $now
        ]);
        try {
            $chatDb = get_chat_db();
            $chatDb->prepare("INSERT INTO messages (chat_id, sender_id, sender_role, sender_name, text, status, created_at) VALUES (?, ?, 'user', ?, ?, 'sent', ?)")
                   ->execute([$chatId, $sender, $sender, htmlspecialchars($text), $now]);
        } catch (Exception $e) {}

        if ($sender === 'System') {
            db_update('chats', ['status' => 'attached'], ['id' => $chatId]);
            try {
                $chatDb = get_chat_db();
                $chatDb->prepare("UPDATE chats SET status = 'attached', updated_at = ? WHERE id = ?")->execute([$now, $chatId]);
            } catch (Exception $e) {}
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        db_delete('chats', ['id' => $chatId]);
        db_delete('messages', ['chat_id' => $chatId]);
        try {
            $chatDb = get_chat_db();
            $chatDb->prepare("DELETE FROM chats WHERE id = ?")->execute([$chatId]);
            $chatDb->prepare("DELETE FROM messages WHERE chat_id = ?")->execute([$chatId]);
        } catch (Exception $e) {}
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        db_update('chats', ['status' => 'closed'], ['id' => $chatId]);
        try {
            $chatDb = get_chat_db();
            $chatDb->prepare("UPDATE chats SET status = 'closed', updated_at = ? WHERE id = ?")->execute([time(), $chatId]);
        } catch (Exception $e) {}
        echo json_encode(['status' => 'success']);
        exit;
    }

    if (($action === 'update_score' || $action === 'grade') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chatId = $_POST['chat_id'] ?? '';
        $score = $_POST['score'] ?? 0;
        db_update('chats', ['score' => $score, 'status' => 'graded'], ['id' => $chatId]);
        try {
            $chatDb = get_chat_db();
            $chatDb->prepare("UPDATE chats SET score = ?, status = 'graded', updated_at = ? WHERE id = ?")
                   ->execute([$score, time(), $chatId]);
        } catch (Exception $e) {}
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'list') {
        // Lấy danh sách chat từ database chính
        $chats = db_fetch_all("SELECT * FROM chats WHERE status != 'closed' ORDER BY created_at DESC");
        $chatIds = array_column($chats, 'id');

        try {
            $chatDb = get_chat_db();
            // Lấy thêm các phòng chat từ chat.sqlite nếu chưa có trong DB chính
            $sqliteChats = $chatDb->query("SELECT * FROM chats WHERE status != 'closed' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sqliteChats as $sc) {
                if (!in_array($sc['id'], $chatIds)) {
                    $chats[] = $sc;
                    $chatIds[] = $sc['id'];
                }
            }

            // Sắp xếp lại theo created_at giảm dần
            usort($chats, function($a, $b) {
                return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
            });

            $presenceRows = $chatDb->query("SELECT user_id, is_online, last_seen FROM user_presence")->fetchAll(PDO::FETCH_ASSOC);
            $presenceMap = [];
            foreach ($presenceRows as $pr) {
                $presenceMap[$pr['user_id']] = $pr;
            }

            foreach ($chats as &$c) {
                $sid = $c['student_id'] ?? '';
                if (empty($sid)) {
                    if (preg_match('/[0-9]{2,3}[A-Z][0-9]+/i', $c['name'], $m)) {
                        $sid = $m[0];
                    } else {
                        $sid = explode(' - ', $c['name'])[0] ?? '';
                    }
                }
                $c['student_id'] = $sid;
                $c['is_online'] = isset($presenceMap[$sid]) ? (int)$presenceMap[$sid]['is_online'] : 0;
                $c['last_seen'] = isset($presenceMap[$sid]) ? $presenceMap[$sid]['last_seen'] : null;

                // Đồng bộ điểm và status mới nhất từ chat.sqlite nếu có
                foreach ($sqliteChats as $sc) {
                    if ($sc['id'] === $c['id']) {
                        if (!empty($sc['score'])) $c['score'] = $sc['score'];
                        if (!empty($sc['status'])) $c['status'] = $sc['status'];
                        break;
                    }
                }
            }
        } catch (Exception $e) {}

        echo json_encode($chats);
        exit;
    }

    // === QUẢN LÝ ĐỀ THI JSON ===

    if ($action === 'upload_subject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['subject_file']) && $_FILES['subject_file']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['subject_file']['tmp_name'];
            $name = basename($_FILES['subject_file']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($ext !== 'json') {
                echo json_encode(['status' => 'error', 'message' => 'Chỉ cho phép file .json']);
                exit;
            }

            $targetDir = $rootDir . '/exams';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $targetPath = $targetDir . '/' . $name;

            if (move_uploaded_file($tmpName, $targetPath)) {
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
        $examDirFiles = glob($rootDir . '/exams/*.json') ?: [];
        $rootFiles = glob($rootDir . '/*.json') ?: [];
        $seen = [];
        $result = [];
        foreach (array_merge($examDirFiles, $rootFiles) as $f) {
            $base = basename($f);
            if (!isset($seen[$base])) {
                $seen[$base] = true;
                $result[] = ['filename' => $base, 'name' => basename($base, '.json')];
            }
        }
        echo json_encode(['status' => 'success', 'data' => $result]);
        exit;
    }

    if ($action === 'create_subject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = $_POST['name'] ?? '';
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        if (!$name) { echo json_encode(['status' => 'error', 'message' => 'Tên không hợp lệ']); exit; }

        $filename = $name . '.json';
        $targetPath = $rootDir . '/exams/' . $filename;
        if (file_exists($targetPath) || file_exists($rootDir . '/' . $filename)) { 
            echo json_encode(['status' => 'error', 'message' => 'File đã tồn tại']); exit; 
        }

        if (!is_dir($rootDir . '/exams')) mkdir($rootDir . '/exams', 0777, true);
        file_put_contents($targetPath, json_encode([], JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete_subject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $filename = basename($_POST['filename'] ?? '');
        $path1 = $rootDir . '/exams/' . $filename;
        $path2 = $rootDir . '/' . $filename;
        $deleted = false;
        if (file_exists($path1)) { unlink($path1); $deleted = true; }
        if (file_exists($path2)) { unlink($path2); $deleted = true; }
        if ($deleted) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File không tồn tại']);
        }
        exit;
    }

    if ($action === 'get_questions') {
        $filename = basename($_GET['filename'] ?? '');
        $path = file_exists($rootDir . '/exams/' . $filename) ? ($rootDir . '/exams/' . $filename) : ($rootDir . '/' . $filename);
        if (file_exists($path)) {
            $content = file_get_contents($path);
            echo json_encode(['status' => 'success', 'data' => json_decode($content, true) ?? []]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'File không tìm thấy']);
        }
        exit;
    }

    if ($action === 'save_questions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $filename = basename($_POST['filename'] ?? '');
        $questionsJSON = $_POST['questions'] ?? '[]';
        $questions = json_decode($questionsJSON, true);
        if ($questions === null) { echo json_encode(['status' => 'error', 'message' => 'JSON lỗi cú pháp']); exit; }
        $targetDir = $rootDir . '/exams';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $targetPath = file_exists($rootDir . '/' . $filename) ? ($rootDir . '/' . $filename) : ($targetDir . '/' . $filename);
        file_put_contents($targetPath, json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['status' => 'success']);
        exit;
    }

    // === QUẢN LÝ ĐỢT THI (EXAM SESSIONS) ===

    if ($action === 'list_exam_sessions') {
        $sessions = db_fetch_all("SELECT * FROM `exam_sessions` ORDER BY `id` DESC");
        echo json_encode(['status' => 'success', 'data' => $sessions]);
        exit;
    }

    if ($action === 'create_exam_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $room = trim($_POST['room'] ?? '');
        $exam_json = trim($_POST['exam_json'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 45);
        $starts_at = trim($_POST['starts_at'] ?? '');
        $ends_at = trim($_POST['ends_at'] ?? '');

        if (empty($name) || empty($exam_json)) {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng nhập tên đợt thi và chọn đề thi JSON']);
            exit;
        }

        db_insert('exam_sessions', [
            'name' => $name,
            'room' => $room,
            'exam_json' => $exam_json,
            'duration_minutes' => $duration > 0 ? $duration : 45,
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
            'is_active' => 1,
            'created_at' => time()
        ]);

        echo json_encode(['status' => 'success', 'message' => 'Tạo đợt thi mới thành công']);
        exit;
    }

    if ($action === 'toggle_exam_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $session = db_fetch("SELECT is_active FROM `exam_sessions` WHERE `id` = ?", [$id]);
        if ($session) {
            $newStatus = $session['is_active'] ? 0 : 1;
            db_update('exam_sessions', ['is_active' => $newStatus], ['id' => $id]);
            echo json_encode(['status' => 'success', 'is_active' => $newStatus]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Không tìm thấy đợt thi']);
        }
        exit;
    }

    if ($action === 'delete_exam_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        db_delete('exam_sessions', ['id' => $id]);
        db_delete('exam_codes', ['exam_session_id' => $id]);
        echo json_encode(['status' => 'success']);
        exit;
    }

    // === QUẢN LÝ MÃ DỰ THI (EXAM CODES) ===

    if ($action === 'list_exam_codes') {
        $sessionId = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int)$_GET['session_id'] : null;
        if ($sessionId) {
            $codes = db_fetch_all("SELECT c.*, s.name as session_name, s.exam_json 
                                  FROM `exam_codes` c 
                                  LEFT JOIN `exam_sessions` s ON c.exam_session_id = s.id 
                                  WHERE c.exam_session_id = ? 
                                  ORDER BY c.id DESC LIMIT 200", [$sessionId]);
        } else {
            $codes = db_fetch_all("SELECT c.*, s.name as session_name, s.exam_json 
                                  FROM `exam_codes` c 
                                  LEFT JOIN `exam_sessions` s ON c.exam_session_id = s.id 
                                  ORDER BY c.id DESC LIMIT 200");
        }
        echo json_encode(['status' => 'success', 'data' => $codes]);
        exit;
    }

    if ($action === 'generate_exam_codes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sessionId = (int)($_POST['exam_session_id'] ?? 0);
        $count = min(100, max(1, (int)($_POST['count'] ?? 1)));
        $studentCode = trim($_POST['student_code'] ?? '');
        $customCode = strtoupper(trim($_POST['custom_code'] ?? ''));

        if (!$sessionId) {
            echo json_encode(['status' => 'error', 'message' => 'Vui lòng chọn đợt thi']);
            exit;
        }

        $session = db_fetch("SELECT id FROM `exam_sessions` WHERE `id` = ?", [$sessionId]);
        if (!$session) {
            echo json_encode(['status' => 'error', 'message' => 'Đợt thi không tồn tại']);
            exit;
        }

        $now = time();
        $generated = [];

        if (!empty($customCode)) {
            // Nhập mã thủ công
            $exists = db_fetch("SELECT id FROM `exam_codes` WHERE `code` = ?", [$customCode]);
            if ($exists) {
                echo json_encode(['status' => 'error', 'message' => "Mã $customCode đã tồn tại trên hệ thống!"]);
                exit;
            }
            db_insert('exam_codes', [
                'exam_session_id' => $sessionId,
                'code' => $customCode,
                'student_code' => !empty($studentCode) ? $studentCode : null,
                'status' => 'unused',
                'created_at' => $now
            ]);
            $generated[] = $customCode;
        } else {
            // Sinh mã hàng loạt ngẫu nhiên khó đoán (định dạng HVU-XXXX-XXXX)
            $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Bỏ 0, 1, I, O để tránh nhầm lẫn
            for ($i = 0; $i < $count; $i++) {
                $code = null;
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    $p1 = substr(str_shuffle($chars), 0, 4);
                    $p2 = substr(str_shuffle($chars), 0, 4);
                    $candidate = "HVU-{$p1}-{$p2}";
                    if (!db_fetch("SELECT id FROM `exam_codes` WHERE `code` = ?", [$candidate])) {
                        $code = $candidate;
                        break;
                    }
                }
                if ($code) {
                    db_insert('exam_codes', [
                        'exam_session_id' => $sessionId,
                        'code' => $code,
                        'student_code' => !empty($studentCode) ? $studentCode : null,
                        'status' => 'unused',
                        'created_at' => $now
                    ]);
                    $generated[] = $code;
                }
            }
        }

        echo json_encode([
            'status' => 'success', 
            'message' => "Đã tạo thành công " . count($generated) . " mã dự thi",
            'codes' => $generated
        ]);
        exit;
    }

    if ($action === 'toggle_exam_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $codeRow = db_fetch("SELECT status FROM `exam_codes` WHERE `id` = ?", [$id]);
        if ($codeRow) {
            $newStatus = ($codeRow['status'] === 'disabled') ? 'unused' : 'disabled';
            db_update('exam_codes', ['status' => $newStatus], ['id' => $id]);
            echo json_encode(['status' => 'success', 'status_text' => $newStatus]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Mã không tồn tại']);
        }
        exit;
    }

    if ($action === 'delete_exam_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        db_delete('exam_codes', ['id' => $id]);
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
