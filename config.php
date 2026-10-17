<?php
// config.php - Cấu hình cơ sở dữ liệu (MySQL chính + SQLite dự phòng & Chat SQLite)

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '1234';
$db_name = 'ktra';

// Kết nối cơ sở dữ liệu chính (MySQL, fallback sang SQLite nếu MySQL không khả dụng)
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    // Fallback an toàn sang SQLite cục bộ nếu MySQL chưa bật
    $sqlite_file = __DIR__ . '/data/exam_system.sqlite';
    try {
        $pdo = new PDO("sqlite:" . $sqlite_file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $se) {
        if (isset($_GET['ajax_action'])) {
            if (!headers_sent()) header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Lỗi kết nối DB: ' . $se->getMessage()]);
            exit;
        } else {
            die("Lỗi kết nối cơ sở dữ liệu: " . $se->getMessage());
        }
    }
}

// Hàm lấy kết nối SQLite riêng cho Chat (data/chat.sqlite)
function get_chat_db() {
    static $chat_pdo = null;
    if ($chat_pdo === null) {
        $chat_file = __DIR__ . '/data/chat.sqlite';
        $chat_pdo = new PDO("sqlite:" . $chat_file);
        $chat_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $chat_pdo;
}

// --- CÁC HÀM HỖ TRỢ CRUD ---

function db_query($sql, $params = []) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch($sql, $params = []) {
    return db_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}

function db_fetch_all($sql, $params = []) {
    return db_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function db_execute($sql, $params = []) {
    return db_query($sql, $params)->rowCount();
}

// --- CÁC HÀM CRUD NÂNG CAO (Tự động tạo SQL) ---

function db_insert($table, $data) {
    $keys = array_keys($data);
    $cols = implode("`, `", $keys);
    $placeholders = implode(", ", array_fill(0, count($keys), "?"));
    $sql = "INSERT INTO `$table` (`$cols`) VALUES ($placeholders)";
    return db_execute($sql, array_values($data));
}

function db_update($table, $data, $where) {
    $set = [];
    $values = [];
    foreach ($data as $k => $v) {
        $set[] = "`$k` = ?";
        $values[] = $v;
    }
    $conditions = [];
    foreach ($where as $k => $v) {
        $conditions[] = "`$k` = ?";
        $values[] = $v;
    }
    $sql = "UPDATE `$table` SET " . implode(", ", $set) . " WHERE " . implode(" AND ", $conditions);
    return db_execute($sql, $values);
}

function db_delete($table, $where) {
    $conditions = [];
    $values = [];
    foreach ($where as $k => $v) {
        $conditions[] = "`$k` = ?";
        $values[] = $v;
    }
    $sql = "DELETE FROM `$table` WHERE " . implode(" AND ", $conditions);
    return db_execute($sql, $values);
}