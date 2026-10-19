-- clean_orphan_codes.sql - Xóa các dòng "Thí sinh đang thi & Mã ca thi đã nhập" không thuộc ca thi nào còn tồn tại
-- (VD: dòng hiện "Ca #1 (không tồn tại)" do bản portal.php cũ tự tạo ca thi giả id = 1)
-- Chạy: php scripts/run_sql.php scripts/clean_orphan_codes.sql

DELETE FROM `exam_codes` WHERE `exam_session_id` NOT IN (SELECT `id` FROM `exam_sessions`);
