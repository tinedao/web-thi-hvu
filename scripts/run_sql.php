<?php
// run_sql.php - Chạy file .sql vào CSDL đang dùng (MySQL hoặc SQLite dự phòng theo config.php)
// Dùng: php scripts/run_sql.php scripts/iot_session.sql

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/exam_schema.php'; // tạo đủ bảng/cột trước khi chạy SQL

$file = $argv[1] ?? '';
if (!is_file($file)) {
    fwrite(STDERR, "Không tìm thấy file SQL: $file\n");
    exit(1);
}

// Bỏ dòng chú thích "--" rồi tách từng câu lệnh theo dấu ;
$sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
$statements = array_filter(array_map('trim', explode(';', $sql)), 'strlen');

global $pdo;
echo "CSDL: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
$pdo->beginTransaction();
try {
    foreach ($statements as $st) {
        $n = $pdo->exec($st);
        echo "OK ($n dòng): " . substr(preg_replace('/\s+/', ' ', $st), 0, 70) . "...\n";
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Lỗi, đã hoàn tác toàn bộ: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Xong.\n";
