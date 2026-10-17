<style>
    /* --- Chat Popup Styles (Copy từ login.php) --- */
    .chat-popup {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 260px; /* Kích thước nhỏ gọn */
        height: 320px;
        background-color: rgba(255, 255, 255, 0.95); /* Nền trắng đục */
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        z-index: 1000;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid #ddd;
        opacity: 0.8; /* Mờ nhẹ khi không dùng */
        transition: opacity 0.3s, box-shadow 0.3s;
    }
    .chat-popup:hover { opacity: 1; box-shadow: 0 5px 20px rgba(0,0,0,0.2); }
    .chat-popup.active { display: flex; }
    .chat-popup.left-side { right: auto; left: 20px; } /* Hỗ trợ đổi bên cho index.php */
    
    .chat-header {
        background-color: #e0e0e0;
        color: #555;
        padding: 10px 15px;
        font-weight: bold;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 14px;
    }
    
    .chat-body {
        flex: 1;
        padding: 10px;
        overflow-y: auto;
        background-color: transparent;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .chat-msg { padding: 8px 12px; border-radius: 15px; max-width: 80%; font-size: 13px; word-wrap: break-word; }
    .chat-msg.me { align-self: flex-end; background: #d1d1d1; color: #333; }
    .chat-msg.other { align-self: flex-start; background: #fff; border: 1px solid #ddd; }
    .chat-msg.system { align-self: center; background: #e2e2e2; color: #555; font-style: italic; font-size: 11px; text-align: center; }
    
    .chat-footer { padding: 10px; border-top: 1px solid #eee; display: flex; gap: 5px; background: transparent; }
    .chat-footer input { flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 20px; outline: none; font-size: 13px; }
    .chat-footer button { background: #999; color: white; border: none; width: 35px; height: 35px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .chat-footer button:hover { background: #777; }
</style>

<div id="chat-popup" class="chat-popup">
    <div class="chat-header">
        <span id="chat-title"><?php echo (basename($_SERVER['PHP_SELF']) == 'login.php') ? 'Chat Support' : 'Hỗ trợ trực tuyến'; ?></span>
        <div>
            <span onclick="requestChatRecovery()" style="cursor:pointer; margin-right:10px;" title="Tạo chat mới">🔄</span>
            <span onclick="toggleChatPosition()" style="cursor:pointer; margin-right:10px;" title="Đổi bên">↔️</span>
            <span onclick="toggleChatPopup()" style="cursor:pointer; font-size: 18px;">&times;</span>
        </div>
    </div>
    <div class="chat-body" id="chat-body"></div>
    <div class="chat-footer">
        <input type="text" id="chat-input" placeholder="Nhập tin nhắn..." onkeypress="handleChatKey(event)">
        <button onclick="sendChatMessage()">➤</button>
    </div>
</div>