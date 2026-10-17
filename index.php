<?php
session_start();

// --- XỬ LÝ AJAX (Chuyển sang ajax_handler.php tập trung) ---
if (isset($_GET['ajax_action'])) {
    require_once 'ajax_handler.php';
    exit;
}
// --- KẾT THÚC XỬ LÝ AJAX ---

// Kiểm tra đăng nhập (Session)
if (!isset($_SESSION['fullname']) || !isset($_SESSION['student_id']) || !isset($_SESSION['exam_id'])) {
    header("Location: login.php");
    exit();
}

// Đảm bảo session chat_sender tồn tại
if (!isset($_SESSION['chat_sender'])) $_SESSION['chat_sender'] = $_SESSION['fullname'];

// Lưu thời điểm bắt đầu thi vào session (chỉ lần đầu)
$duration_minutes = isset($_SESSION['duration']) ? (int)$_SESSION['duration'] : 45;
$duration_seconds = $duration_minutes * 60;

if (!isset($_SESSION['exam_start_time'])) {
    $_SESSION['exam_start_time'] = time(); // Ghi nhận thời điểm bắt đầu
}

// Tính thời gian còn lại
$elapsed = time() - $_SESSION['exam_start_time'];
$remaining_seconds = max(0, $duration_seconds - $elapsed);

// 1. LOAD DỮ LIỆU TỪ PHP (Server-Side)
$subject_file = $_SESSION['subject_file'] ?? 'dau_tu_chung_khoan.json';
$resolved_file = null;
if (file_exists(__DIR__ . '/exams/' . basename($subject_file))) {
    $resolved_file = __DIR__ . '/exams/' . basename($subject_file);
} elseif (file_exists(__DIR__ . '/' . basename($subject_file))) {
    $resolved_file = __DIR__ . '/' . basename($subject_file);
} else {
    $examFiles = glob(__DIR__ . '/exams/*.json');
    if (!empty($examFiles)) $resolved_file = $examFiles[0];
}

$json_data = $resolved_file ? file_get_contents($resolved_file) : '[]';
$questions = json_decode($json_data, true) ?: [];

// Cắt danh sách câu hỏi theo số lượng đã chọn (30 hoặc 40)
$limit = isset($_SESSION['question_limit']) ? (int)$_SESSION['question_limit'] : 30;
$questions = array_slice($questions, 0, $limit);

// Cập nhật lại JSON data để truyền xuống JS
$json_data = json_encode($questions);

$total_questions = count($questions);
$current_index = 0; // Mặc định hiển thị câu đầu tiên
$current_q = $questions[$current_index];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ thống thi trắc nghiệm</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700&display=swap" rel="stylesheet">

    <style>
        /* Hiệu ứng nhấp nháy cho nút nộp bài khi có điểm */
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
            70% { box-shadow: 0 0 0 15px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }
    </style>
</head>
<body>

    <header>
        <div class="container">
            <div class="user-info">
            <div class="avatar" onclick="toggleChatPopup()">
                <img src="img/avatar.jpg" alt="Avatar">
            </div>
            <div class="user-details">
                <h4><?php echo htmlspecialchars($_SESSION['fullname']); ?></h4>
                <span><?php echo htmlspecialchars($_SESSION['student_id']); ?></span>
            </div>
        </div>

        <a class="timer" href="logout.php" id="countdown">60:00</a>

        <button class="submit-btn" id="submit-btn" disabled style="opacity: 0.5; cursor: not-allowed;">
            <svg width="14" height="14" viewBox="0 0 512 512" fill="currentColor" style="vertical-align: middle;"><path d="M373.1 25C401.2-3.1 446.8-3.1 474.9 25L487 37.1c28.1 28.1 28.1 73.7 0 101.8L289.8 336.2c-8.7 8.7-19.4 15-31.1 18.3l-100 28.6c-8.4 2.4-17.4 .1-23.6-6.1s-8.5-15.2-6.1-23.6l28.6-100c3.4-11.8 9.7-22.5 18.3-31.1L373.1 25zM88 64C39.4 64 0 103.4 0 152V424c0 48.6 39.4 88 88 88H360c48.6 0 88-39.4 88-88V312c0-13.3-10.7-24-24-24s-24 10.7-24 24V424c0 22.1-17.9 40-40 40H88c-22.1 0-40-17.9-40-40V152c0-22.1 17.9-40 40-40H200c13.3 0 24-10.7 24-24s-10.7-24-24-24H88z"/></svg>
            NỘP BÀI
        </button>
        <!-- Thanh tiến độ làm bài -->
        <div id="header-progress-bar" class="header-progress-bar"></div>
        </div>
    </header>
    
    
    <main>
        <div class="head-main">
            <!-- 2. RENDER TIÊU ĐỀ TỪ PHP -->
            <div class="question-title" id="question-title">
                <strong>Câu <?php echo $current_q['id']; ?>:</strong><br>
                <span style="font-weight: normal;"><?php echo $current_q['question']; ?></span>
            </div>
            <label class="flag-question">
                <input     type="checkbox"> Xem lại câu này
            </label>
        </div>
        <div class="question-card">
            <!-- 3. RENDER ĐÁP ÁN TỪ PHP -->
            <div class="options-grid" id="options-container">
                <?php foreach($current_q['options'] as $opt): ?>
                    <?php 
                        // Kiểm tra trạng thái selected (thường là null khi mới load)
                        $isSelected = $current_q['userAnswer'] === $opt['id'];
                        $selectedClass = $isSelected ? 'selected' : '';
                        $checked = $isSelected ? 'checked' : '';
                    ?>
                    <div class="option-item <?php echo $selectedClass; ?>" onclick="handleSelectOption('<?php echo $opt['id']; ?>')">
                        <div class="option-label"><?php echo $opt['id']; ?></div>
                        <div class="option-content">
                            <input type="radio" name="q<?php echo $current_q['id']; ?>" value="<?php echo $opt['id']; ?>" <?php echo $checked; ?>>
                            <span><?php echo $opt['text']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <footer>
            <!-- Modal hiển thị danh sách 30 câu -->
            <!-- 4. RENDER MODAL TỪ PHP -->
            <div id="question-list-modal" class="question-list-modal">
                <?php foreach($questions as $idx => $q): ?>
                    <?php $activeClass = ($idx === $current_index) ? 'current' : ''; 
                        if (!empty($q['userAnswer'])) $activeClass .= ' answered';
                    ?>
                    <div class="q-modal-item <?php echo $activeClass; ?>" onclick="jumpToQuestion(<?php echo $idx; ?>)">
                        <?php echo $q['id']; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pagination">
                <div class="page-dot active" id="menu-btn"><svg width="14" height="14" viewBox="0 0 448 512" fill="currentColor"><path d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM0 256c0-17.7 14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32c-17.7 0-32-14.3-32-32zM448 416c0 17.7-14.3 32-32 32H32c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32z"/></svg></div>
                <!-- Container chứa các số trang được render bởi JS -->
                <!-- 5. RENDER PHÂN TRANG TỪ PHP -->
                <div id="pagination-container" style="display: flex; gap: 10px;">
                    <?php
                    $start = max(0, $current_index - 2);
                    $end = min($total_questions - 1, $current_index + 2);
                    
                    for ($i = $start; $i <= $end; $i++) {
                        $q_item = $questions[$i];
                        $dist = abs($i - $current_index);
                        $class = 'page-dot';
                        
                        if ($dist === 0) $class .= ' active';
                        elseif ($dist === 1) $class .= ' op-1';
                        elseif ($dist === 2) $class .= ' op-2';
                        
                        echo '<div class="'.$class.'">'.$q_item['id'].'</div>';
                    }
                    ?>
                </div>
            </div>
        <button class="next-btn" id="next-btn">Câu hỏi tiếp theo</button>
    </footer>

    <!-- Include Chat Popup -->
    <?php include 'chat_popup.php'; ?>

    <!-- 6. TRUYỀN DỮ LIỆU TỪ PHP SANG JS -->
    <script>
        // Nhận dữ liệu JSON trực tiếp, không cần fetch
        let questionsData = <?php echo $json_data; ?>;
        let currentQuestionIndex = <?php echo $current_index; ?>;
        const sessionChatId = "<?php echo $_SESSION['chat_id'] ?? ''; ?>"; // Lấy ID chat từ session
        const currentUser = "<?php echo htmlspecialchars($_SESSION['chat_sender']); ?>"; // Biến định danh người chat hiện tại
        const currentUserId = "<?php echo htmlspecialchars($_SESSION['student_id']); ?>";
        const wsToken = "<?php echo htmlspecialchars($_SESSION['ws_token'] ?? ''); ?>";
        const wsConfigUrl = "<?php $wsc = require 'websocket_config.php'; echo $wsc['client_url']; ?>";
        
        // Thông tin cho Chat System Message
        const examInfo = {
            student: "<?php echo htmlspecialchars($_SESSION['fullname']); ?>",
            examId: "<?php echo htmlspecialchars($_SESSION['exam_id']); ?>",
            subject: "<?php echo htmlspecialchars(basename($subject_file, '.json')); ?>"
        };
    </script>

    <script src="websocket-client.js?v=<?php echo time(); ?>"></script>
    <script src="script.js?v=<?php echo time(); ?>"></script>
    <script>
        // Hàm đếm ngược thời gian
        function startTimer(duration, display) {
            var timer = duration, minutes, seconds;
            var interval = setInterval(function () {
                minutes = parseInt(timer / 60, 10);
                seconds = parseInt(timer % 60, 10);

                minutes = minutes < 10 ? "0" + minutes : minutes;
                seconds = seconds < 10 ? "0" + seconds : seconds;

                display.textContent = minutes + ":" + seconds;

                if (--timer < 0) {
                    clearInterval(interval);
                    display.textContent = "00:00";
                    display.style.backgroundColor = "#dc3545";
                }
            }, 1000);
        }

        function requestFullScreen() {
            var el = document.documentElement;
            var requestMethod = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
            if (requestMethod) {
                requestMethod.call(el).catch(function(error) {
                    // Trình duyệt chặn tự động fullscreen nếu chưa có tương tác
                    console.log("Auto fullscreen blocked, waiting for interaction.");
                });
            }
        }

        window.onload = function () {
            // Lấy thời gian còn lại từ PHP (đã tính trừ thời gian đã trôi qua)
            var remainingSeconds = <?php echo $remaining_seconds; ?>;
            var display = document.querySelector('#countdown');
            startTimer(remainingSeconds, display);

            // Tự động kích hoạt toàn màn hình
            requestFullScreen();
            // Fallback: Kích hoạt khi người dùng click lần đầu vào trang (nếu tự động bị chặn)
            document.addEventListener('click', function() {
                if (!document.fullscreenElement) requestFullScreen();
            }, { once: true });
        };
    </script>
</body>
</html>