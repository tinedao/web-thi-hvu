<?php
// exam_schema.php - Tự nâng cấp cấu trúc bảng ca thi (MySQL / SQLite) và các hàm dùng chung
// - exam_sessions: thêm mã ca thi, đợt thi, năm học, học kỳ, hình thức, thời điểm bắt đầu (unix)
// - exam_session_students: danh sách mã sinh viên được phép thi từng ca

require_once __DIR__ . '/../config.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

function exam_db_driver() {
    global $pdo;
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function ensure_exam_schema() {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;

    $isSqlite = exam_db_driver() === 'sqlite';

    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `exam_sessions` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `name` TEXT NOT NULL,
            `room` TEXT DEFAULT '',
            `exam_json` TEXT NOT NULL,
            `duration_minutes` INTEGER DEFAULT 45,
            `starts_at` TEXT,
            `ends_at` TEXT,
            `is_active` INTEGER DEFAULT 1,
            `created_at` INTEGER
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `exam_session_students` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `exam_session_id` INTEGER NOT NULL,
            `student_code` TEXT NOT NULL,
            `created_at` INTEGER,
            UNIQUE (`exam_session_id`, `student_code`)
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `exam_sessions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `room` VARCHAR(255) DEFAULT '',
            `exam_json` VARCHAR(255) NOT NULL,
            `duration_minutes` INT DEFAULT 45,
            `starts_at` VARCHAR(50),
            `ends_at` VARCHAR(50),
            `is_active` INT DEFAULT 1,
            `created_at` INT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `exam_session_students` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `exam_session_id` INT NOT NULL,
            `student_code` VARCHAR(50) NOT NULL,
            `created_at` INT,
            UNIQUE KEY `uniq_session_student` (`exam_session_id`, `student_code`),
            INDEX (`student_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Giờ thi riêng từng sinh viên (0 = dùng giờ chung của ca thi)
    $stuCols = [];
    if ($isSqlite) {
        foreach ($pdo->query("PRAGMA table_info(`exam_session_students`)")->fetchAll(PDO::FETCH_ASSOC) as $col) $stuCols[$col['name']] = true;
    } else {
        foreach ($pdo->query("SHOW COLUMNS FROM `exam_session_students`")->fetchAll(PDO::FETCH_ASSOC) as $col) $stuCols[$col['Field']] = true;
    }
    foreach (['start_ts', 'duration_minutes'] as $name) {
        if (!isset($stuCols[$name])) {
            $pdo->exec("ALTER TABLE `exam_session_students` ADD COLUMN `$name` " . ($isSqlite ? "INTEGER" : "INT") . " DEFAULT 0");
        }
    }

    // Các cột mới của exam_sessions (bỏ qua nếu đã có)
    $columns = [
        'code'        => $isSqlite ? "TEXT DEFAULT ''" : "VARCHAR(255) DEFAULT ''",
        'batch_name'  => $isSqlite ? "TEXT DEFAULT 'Đợt thi 1'" : "VARCHAR(100) DEFAULT 'Đợt thi 1'",
        'school_year' => $isSqlite ? "TEXT DEFAULT ''" : "VARCHAR(50) DEFAULT ''",
        'semester'    => $isSqlite ? "TEXT DEFAULT ''" : "VARCHAR(50) DEFAULT ''",
        'exam_form'   => $isSqlite ? "TEXT DEFAULT 'Trắc nghiệm'" : "VARCHAR(50) DEFAULT 'Trắc nghiệm'",
        'start_ts'    => $isSqlite ? "INTEGER DEFAULT 0" : "INT DEFAULT 0",
        'room'        => $isSqlite ? "TEXT DEFAULT ''" : "VARCHAR(255) DEFAULT ''",
    ];
    $existing = [];
    if ($isSqlite) {
        foreach ($pdo->query("PRAGMA table_info(`exam_sessions`)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existing[$col['name']] = true;
        }
    } else {
        foreach ($pdo->query("SHOW COLUMNS FROM `exam_sessions`")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existing[$col['Field']] = true;
        }
    }
    foreach ($columns as $name => $def) {
        if (!isset($existing[$name])) {
            $pdo->exec("ALTER TABLE `exam_sessions` ADD COLUMN `$name` $def");
        }
    }
}

// Chuyển chuỗi ngày giờ ("2026-10-20T13:20", "20/10/2026 - 13:20", "20-10-2026 13:20"...) sang unix timestamp
function exam_parse_datetime($str) {
    $str = trim((string)$str);
    if ($str === '') return 0;
    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd/m/Y - H:i', 'd/m/Y H:i', 'd-m-Y H:i', 'd-m-Y - H:i'];
    foreach ($formats as $f) {
        $dt = DateTime::createFromFormat($f, $str);
        if ($dt) return $dt->getTimestamp();
    }
    $ts = strtotime($str);
    return $ts ?: 0;
}

// Tách danh sách mã sinh viên (xuống dòng, dấu phẩy, chấm phẩy, khoảng trắng)
function exam_parse_student_codes($text) {
    $codes = preg_split('/[\s,;]+/u', strtoupper(trim((string)$text)));
    return array_values(array_unique(array_filter($codes, 'strlen')));
}

// Gán lại danh sách sinh viên cho ca thi, GIỮ NGUYÊN giờ thi riêng đã chỉnh của những sinh viên vẫn còn trong danh sách
function exam_set_session_students($sessionId, array $codes) {
    $old = [];
    foreach (db_fetch_all("SELECT * FROM `exam_session_students` WHERE `exam_session_id` = ?", [$sessionId]) as $row) {
        $old[strtoupper($row['student_code'])] = $row;
    }
    db_delete('exam_session_students', ['exam_session_id' => $sessionId]);
    $now = time();
    foreach ($codes as $code) {
        $prev = $old[strtoupper($code)] ?? [];
        db_insert('exam_session_students', [
            'exam_session_id' => $sessionId,
            'student_code' => $code,
            'start_ts' => (int)($prev['start_ts'] ?? 0),
            'duration_minutes' => (int)($prev['duration_minutes'] ?? 0),
            'created_at' => $now
        ]);
    }
}

// Áp giờ thi riêng của sinh viên (nếu có) đè lên giờ chung của ca thi
function exam_apply_student_override(array $row) {
    $row['default_start_ts'] = (int)$row['start_ts'];
    $row['default_duration'] = (int)$row['duration_minutes'];
    if ((int)($row['stu_start_ts'] ?? 0) > 0) {
        $row['start_ts'] = (int)$row['stu_start_ts'];
    }
    if ((int)($row['stu_duration'] ?? 0) > 0) {
        $row['duration_minutes'] = (int)$row['stu_duration'];
    }
    return $row;
}

// Các ca thi đang mở được gán cho sinh viên (đã áp giờ thi riêng)
function exam_sessions_for_student($studentCode) {
    $rows = db_fetch_all(
        "SELECT s.*, a.start_ts AS stu_start_ts, a.duration_minutes AS stu_duration FROM `exam_sessions` s
         INNER JOIN `exam_session_students` a ON a.exam_session_id = s.id
         WHERE s.is_active = 1 AND UPPER(a.student_code) = ?
         ORDER BY s.start_ts ASC, s.id ASC",
        [strtoupper(trim($studentCode))]
    );
    return array_map('exam_apply_student_override', $rows);
}

// Chuỗi đại diện danh sách ca thi + giờ thi của sinh viên, để trang portal biết khi nào cần tải lại
function exam_sessions_signature(array $sessions) {
    $parts = [];
    foreach ($sessions as $s) {
        $parts[] = $s['id'] . ':' . (int)$s['start_ts'] . ':' . (int)$s['duration_minutes'];
    }
    return md5(implode('|', $parts));
}

function exam_session_for_student($sessionId, $studentCode) {
    $row = db_fetch(
        "SELECT s.*, a.start_ts AS stu_start_ts, a.duration_minutes AS stu_duration FROM `exam_sessions` s
         INNER JOIN `exam_session_students` a ON a.exam_session_id = s.id
         WHERE s.id = ? AND s.is_active = 1 AND UPPER(a.student_code) = ?",
        [(int)$sessionId, strtoupper(trim($studentCode))]
    );
    return $row ? exam_apply_student_override($row) : null;
}

ensure_exam_schema();
