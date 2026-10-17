<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '1234';
$db_name = 'ktra';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Nếu là request AJAX thì trả về JSON lỗi
    if (isset($_GET['ajax_action'])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Lỗi kết nối DB: ' . $e->getMessage()]);
        exit;
    } else {
        die("Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage());
    }
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