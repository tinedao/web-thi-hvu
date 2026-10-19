<?php
// seed_exam_session.php - XÓA toàn bộ ca thi cũ và tạo ca thi "Phát triển ứng dụng IoT", gán cho các sinh viên chỉ định
//
// Chạy trên terminal hosting (chỉ chạy bằng CLI):
//   php scripts/seed_exam_session.php MA_SV_1 MA_SV_2 ...
//   php scripts/seed_exam_session.php --exam=lap_trinh_iot.json MA_SV_1
//
// Tùy chọn:
//   --exam=<file.json>   Đề thi trong thư mục exams/ (mặc định: lap_trinh_iot.json, nếu không có thì lấy đề đầu tiên)
//   --start="2026-10-20 13:20"   Giờ bắt đầu (mặc định 20-10-2026 13:20)
//   --keep               Không xóa các ca thi cũ, chỉ thêm ca mới

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/exam_schema.php';

$opts = ['exam' => 'lap_trinh_iot.json', 'start' => '2026-10-20 13:20', 'keep' => false];
$students = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(exam|start)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif ($arg === '--keep') {
        $opts['keep'] = true;
    } else {
        $students = array_merge($students, exam_parse_student_codes($arg));
    }
}
$students = array_values(array_unique($students));

if (empty($students)) {
    fwrite(STDERR, "Thiếu mã sinh viên. Ví dụ: php scripts/seed_exam_session.php 2251080001 2251080002\n");
    exit(1);
}

$examDir = dirname(__DIR__) . '/exams';
$examFile = basename($opts['exam']);
if (!is_file("$examDir/$examFile")) {
    $files = glob("$examDir/*.json") ?: [];
    if (empty($files)) {
        fwrite(STDERR, "Không có đề thi nào trong exams/. Hãy upload đề JSON trước.\n");
        exit(1);
    }
    echo "⚠ Không thấy exams/$examFile, tạm dùng đề " . basename($files[0]) . " (đổi đề sau trong Bảng điều khiển)\n";
    $examFile = basename($files[0]);
}

$startTs = exam_parse_datetime($opts['start']);
if (!$startTs) {
    fwrite(STDERR, "Giờ bắt đầu không hợp lệ: {$opts['start']}\n");
    exit(1);
}

global $pdo;
$pdo->beginTransaction();
try {
    if (!$opts['keep']) {
        db_execute("DELETE FROM `exam_session_students`");
        db_execute("DELETE FROM `exam_codes`");
        db_execute("DELETE FROM `exam_sessions`");
        echo "Đã xóa toàn bộ ca thi, mã thi và danh sách sinh viên cũ.\n";
    }

    db_insert('exam_sessions', [
        'name' => 'Phát triển ứng dụng IoT',
        'code' => 'MDS_CPS318_20_10_2026_13_30_A35_Phát triển ứng dụng IoT',
        'batch_name' => 'Đợt thi 1',
        'school_year' => '2026 - 2027',
        'semester' => 'Học kỳ 1',
        'exam_form' => 'Trắc nghiệm',
        'room' => 'A35',
        'exam_json' => $examFile,
        'duration_minutes' => 60,
        'start_ts' => $startTs,
        'starts_at' => date('d/m/Y - H:i', $startTs),
        'is_active' => 1,
        'created_at' => time(),
    ]);
    $sessionId = (int)$pdo->lastInsertId();
    exam_set_session_students($sessionId, $students);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Lỗi: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Đã tạo ca thi #$sessionId: Phát triển ứng dụng IoT\n";
echo "  Bắt đầu: " . date('d-m-Y g:i A', $startTs) . " | 60 phút | Đề: $examFile\n";
echo "  Sinh viên được thi (" . count($students) . "): " . implode(', ', $students) . "\n";
