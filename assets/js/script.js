// Biến questionsData và currentQuestionIndex đã được khởi tạo từ PHP trong index.php

// --- CÁC HÀM XỬ LÝ ---

function renderQuestion(index) {

    const question = questionsData[index];
    
    // 1. Render Tiêu đề
    const titleEl = document.getElementById('question-title');
    if(titleEl) titleEl.innerHTML = `<strong>Câu ${question.id}:</strong><br><span style="font-weight: normal;">${question.question}</span>`;

    // 2. Render Đáp án
    const optionsContainer = document.getElementById('options-container');
    if(optionsContainer) {
        optionsContainer.innerHTML = ''; // Xóa nội dung cũ

        question.options.forEach(opt => {
            const isSelected = question.userAnswer === opt.id;
            
            const optionHTML = `
                <div class="option-item ${isSelected ? 'selected' : ''}" onclick="handleSelectOption('${opt.id}')">
                    <div class="option-label">${opt.id}</div>
                    <div class="option-content">
                        <input type="radio" name="q${question.id}" value="${opt.id}" ${isSelected ? 'checked' : ''}>
                        <span>${opt.text}</span>
                    </div>
                </div>
            `;
            optionsContainer.innerHTML += optionHTML;
        });
    }

    // 3. Cập nhật Pagination
    renderPagination();
    
    // 4. Cập nhật trạng thái nút Next
    updateNextButton();

    // 5. Cập nhật trạng thái nút Nộp bài
    updateSubmitButton();

    // 6. Cập nhật thanh tiến độ trên header
    updateProgressBar();
}

function renderPagination() {
    const container = document.getElementById('pagination-container');
    if(!container) return;
    container.innerHTML = '';

    // Sliding window: Current +/- 2 (Display 3-5 items)
    const start = Math.max(0, currentQuestionIndex - 2);
    const end = Math.min(questionsData.length - 1, currentQuestionIndex + 2);

    for (let i = start; i <= end; i++) {
        const q = questionsData[i];
        const dot = document.createElement('div');
        dot.className = 'page-dot';
        dot.textContent = q.id;
        
        // Tính khoảng cách từ câu hiện tại đến các câu khác
        const dist = Math.abs(i - currentQuestionIndex);

        if (dist === 0) {
            dot.classList.add('active');
        } else if (dist === 1) {
            dot.classList.add('op-1');
        } else if (dist === 2) {
            dot.classList.add('op-2');
        }
        
        // No onclick event (Read-Only)

        container.appendChild(dot);
    }
}

function handleSelectOption(selectedId) {
    // Cập nhật dữ liệu
    // Nếu click vào đáp án đang chọn thì sẽ bỏ chọn (toggle)
    if (questionsData[currentQuestionIndex].userAnswer === selectedId) {
        questionsData[currentQuestionIndex].userAnswer = null;
    } else {
        questionsData[currentQuestionIndex].userAnswer = selectedId;
    }
    
    // Render lại để thấy hiệu ứng selected
    renderQuestion(currentQuestionIndex);
}

// Hàm hỗ trợ chuyển câu hỏi (dùng cho onclick trong HTML PHP render)
function jumpToQuestion(index) {
    currentQuestionIndex = index;
    renderQuestion(index);
    const listModal = document.getElementById('question-list-modal');
    if(listModal) listModal.classList.remove('active');
}

function updateNextButton() {
    const btn = document.getElementById('next-btn');
    if(!btn) return;

    if (currentQuestionIndex >= questionsData.length - 1) {
        // Disable on last question
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    } else {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.textContent = "Câu hỏi tiếp theo";
    }
}

function updateSubmitButton() {
    const btn = document.getElementById('submit-btn');
    if(!btn) return;

    // Nếu đã có điểm (window.finalScore) thì luôn enable nút
    if ((questionsData.length > 0 && currentQuestionIndex === questionsData.length - 1) || window.finalScore !== undefined) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
}

function updateProgressBar() {
    const bar = document.getElementById('header-progress-bar');
    if (!bar || !questionsData || questionsData.length === 0) return;

    // Đếm số câu đã có câu trả lời (userAnswer khác null)
    const answeredCount = questionsData.filter(q => q.userAnswer !== null).length;
    const total = questionsData.length;
    const percent = (answeredCount / total) * 100;

    bar.style.width = `${percent}%`;
}

// Hàm render danh sách đầy đủ (cho Modal)
function renderFullList() {
    const listModal = document.getElementById('question-list-modal');
    if (!listModal) return;
    
    listModal.innerHTML = '';
    
    questionsData.forEach((q, index) => {
        const item = document.createElement('div');
        item.className = 'q-modal-item';
        item.textContent = q.id;
        
        if (index === currentQuestionIndex) {
            item.classList.add('current');
        }

        if (q.userAnswer) {
            item.classList.add('answered');
        }
        
        // Sử dụng hàm jumpToQuestion thay vì addEventListener ẩn danh
        item.setAttribute('onclick', `jumpToQuestion(${index})`);
        
        listModal.appendChild(item);
    });
}

// Event Listener for Next Button
document.addEventListener('DOMContentLoaded', () => {
    const nextBtn = document.getElementById('next-btn');
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            if (questionsData.length > 0 && currentQuestionIndex < questionsData.length - 1) {
                currentQuestionIndex++;
                renderQuestion(currentQuestionIndex);
            }
        });
    }

    // Event Listener for Menu Button
    const menuBtn = document.getElementById('menu-btn');
    const listModal = document.getElementById('question-list-modal');
    if (menuBtn && listModal) {
        menuBtn.addEventListener('click', () => {
            if (!listModal.classList.contains('active')) {
                renderFullList(); // Render lại để cập nhật trạng thái current
            }
            listModal.classList.toggle('active');
        });
    }

    // --- CHAT LOGIC ---
    initChatSystem();
});
