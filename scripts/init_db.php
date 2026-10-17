<?php
// init_db.php - Khởi tạo bảng cơ sở dữ liệu (MySQL / SQLite)
require_once __DIR__ . '/../config.php';

echo "Database driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . PHP_EOL;

if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    // SQLite Tables
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `exam_codes` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `exam_session_id` INTEGER NOT NULL,
        `code` TEXT UNIQUE NOT NULL,
        `student_code` TEXT,
        `status` TEXT DEFAULT 'unused',
        `used_by` TEXT,
        `used_at` INTEGER,
        `created_at` INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `chats` (
        `id` TEXT PRIMARY KEY,
        `name` TEXT NOT NULL,
        `status` TEXT DEFAULT 'waiting',
        `score` TEXT,
        `created_at` INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
        `chat_id` TEXT NOT NULL,
        `sender` TEXT NOT NULL,
        `text` TEXT NOT NULL,
        `time` TEXT NOT NULL,
        `status` TEXT DEFAULT 'sent',
        `delivered_at` INTEGER,
        `read_at` INTEGER,
        `read_by` TEXT,
        `created_at` INTEGER
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_tokens` (
        `token` TEXT PRIMARY KEY,
        `user_id` TEXT NOT NULL,
        `role` TEXT NOT NULL,
        `chat_id` TEXT,
        `created_at` INTEGER
    )");
} else {
    // MySQL Tables
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `exam_codes` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `exam_session_id` INT NOT NULL,
        `code` VARCHAR(50) UNIQUE NOT NULL,
        `student_code` VARCHAR(50),
        `status` VARCHAR(20) DEFAULT 'unused',
        `used_by` VARCHAR(50),
        `used_at` INT,
        `created_at` INT,
        INDEX (`code`),
        INDEX (`exam_session_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `chats` (
        `id` VARCHAR(50) NOT NULL PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `status` VARCHAR(20) DEFAULT 'waiting',
        `score` VARCHAR(10) DEFAULT NULL,
        `created_at` INT(11) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `chat_id` VARCHAR(50) NOT NULL,
        `sender` VARCHAR(100) NOT NULL,
        `text` TEXT NOT NULL,
        `time` VARCHAR(10) NOT NULL,
        `status` VARCHAR(20) DEFAULT 'sent',
        `delivered_at` INT(11),
        `read_at` INT(11),
        `read_by` VARCHAR(100),
        `created_at` INT(11) NOT NULL,
        INDEX (`chat_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_tokens` (
        `token` VARCHAR(64) PRIMARY KEY,
        `user_id` VARCHAR(50) NOT NULL,
        `role` VARCHAR(20) NOT NULL,
        `chat_id` VARCHAR(50),
        `created_at` INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Tự động nâng cấp bảng hiện có nếu thiếu cột room
try {
    $pdo->exec("ALTER TABLE `exam_sessions` ADD COLUMN `room` TEXT DEFAULT ''");
} catch (Exception $e) {}

// Khởi tạo Chat SQLite (data/chat.sqlite)
$chatDbFile = __DIR__ . '/../data/chat.sqlite';
$chatPdo = new PDO('sqlite:' . $chatDbFile);
$chatPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$chatPdo->exec("CREATE TABLE IF NOT EXISTS `chats` (
    `id` TEXT PRIMARY KEY,
    `name` TEXT NOT NULL,
    `student_id` TEXT,
    `status` TEXT DEFAULT 'waiting',
    `score` TEXT,
    `created_at` INTEGER,
    `updated_at` INTEGER
)");

$chatPdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `chat_id` TEXT NOT NULL,
    `sender_id` TEXT,
    `sender_role` TEXT,
    `sender_name` TEXT,
    `text` TEXT NOT NULL,
    `status` TEXT DEFAULT 'sent',
    `delivered_at` INTEGER,
    `read_at` INTEGER,
    `read_by` TEXT,
    `created_at` INTEGER
)");

$chatPdo->exec("CREATE TABLE IF NOT EXISTS `user_presence` (
    `user_id` TEXT PRIMARY KEY,
    `is_online` INTEGER DEFAULT 0,
    `last_seen` INTEGER
)");

$chatPdo->exec("CREATE TABLE IF NOT EXISTS `user_tokens` (
    `token` TEXT PRIMARY KEY,
    `user_id` TEXT NOT NULL,
    `role` TEXT NOT NULL,
    `chat_id` TEXT,
    `created_at` INTEGER
)");

// Tự động tạo ca thi mẫu nếu bảng trống
$count = (int)$pdo->query("SELECT COUNT(*) FROM `exam_sessions`")->fetchColumn();
if ($count === 0) {
    $now = time();
    $files = glob(__DIR__ . '/../exams/*.json');
    if (empty($files)) $files = glob(__DIR__ . '/../*.json');
    $sampleFile = !empty($files) ? basename($files[0]) : 'dau_tu_chung_khoan.json';

    $pdo->prepare("INSERT INTO `exam_sessions` (`name`, `room`, `exam_json`, `duration_minutes`, `starts_at`, `is_active`, `created_at`) 
                   VALUES (?, '', ?, 45, '17/10/2026 - 08:30', 1, ?)")
        ->execute(['Đầu tư chứng khoán', $sampleFile, $now]);
    
    $sid = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO `exam_codes` (`exam_session_id`, `code`, `student_code`, `status`, `created_at`) 
                   VALUES (?, 'HVU-DEMO-0001', '235D150109', 'unused', ?)")
        ->execute([$sid, $now]);

    $pdo->prepare("INSERT INTO `exam_codes` (`exam_session_id`, `code`, `student_code`, `status`, `created_at`) 
                   VALUES (?, 'HVU-DEMO-0002', '235D150052', 'unused', ?)")
        ->execute([$sid, $now]);

    $pdo->prepare("INSERT INTO `exam_codes` (`exam_session_id`, `code`, `student_code`, `status`, `created_at`) 
                   VALUES (?, 'HVU-FREE-2026', NULL, 'unused', ?)")
        ->execute([$sid, $now]);

    echo "Sample session and demo codes (HVU-DEMO-0001, HVU-DEMO-0002, HVU-FREE-2026) seeded!" . PHP_EOL;
}

echo "Database initialization completed successfully!" . PHP_EOL;
