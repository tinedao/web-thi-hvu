-- iot_session.sql - XÓA toàn bộ ca thi cũ và tạo lại đúng ca thi "Phát triển ứng dụng IoT"
-- Hiển thị trên Cổng thi:
--   1 | MDS_CPS318_20_10_2026_13_30_A35_Phát triển ứng dụng IoT / Đợt thi 1
--     | 2026 - 2027 / Học kỳ 1 | 20-10-2026 / 1:20 PM | Trắc nghiệm | 60 phút
--
-- Chạy được cho cả MySQL và SQLite:
--   php scripts/run_sql.php scripts/iot_session.sql
--
-- TRƯỚC KHI CHẠY: sửa danh sách mã sinh viên ở cuối file (thay MA_SV_1, MA_SV_2...).
-- start_ts = 1792477200 = 20-10-2026 13:20 giờ Việt Nam (UTC+07)

DELETE FROM `exam_session_students`;
DELETE FROM `exam_codes`;
DELETE FROM `exam_sessions`;

INSERT INTO `exam_sessions`
    (`id`, `name`, `code`, `batch_name`, `school_year`, `semester`, `exam_form`, `room`,
     `exam_json`, `duration_minutes`, `start_ts`, `starts_at`, `ends_at`, `is_active`, `created_at`)
VALUES
    (1, 'Phát triển ứng dụng IoT', 'MDS_CPS318_20_10_2026_13_30_A35_Phát triển ứng dụng IoT', 'Đợt thi 1', '2026 - 2027', 'Học kỳ 1', 'Trắc nghiệm', 'A35',
     'lap_trinh_iot.json', 60, 1792477200, '20/10/2026 - 13:20', '', 1, 1792477200);

-- Danh sách sinh viên được thi (start_ts = 0, duration_minutes = 0 nghĩa là dùng giờ chung 1:20 PM, 60 phút)
INSERT INTO `exam_session_students` (`exam_session_id`, `student_code`, `start_ts`, `duration_minutes`, `created_at`) VALUES
    (1, 'MA_SV_1', 0, 0, 1792477200),
    (1, 'MA_SV_2', 0, 0, 1792477200);
