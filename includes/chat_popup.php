<style>
    /* --- Chat Popup Styles --- */
    .chat-popup {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 280px;
        height: 350px;
        background-color: rgba(255, 255, 255, 0.96);
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        z-index: 1000;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid #ced4da;
        opacity: 0.85;
        transition: opacity 0.3s, box-shadow 0.3s;
    }
    .chat-popup:hover { opacity: 1; box-shadow: 0 6px 22px rgba(0,0,0,0.22); }
    .chat-popup.active { display: flex; }
    .chat-popup.left-side { right: auto; left: 20px; }
    
    .chat-header {
        background-color: #003366;
        color: white;
        padding: 10px 14px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 13px;
    }
    .chat-header-title {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .online-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #28a745;
        display: inline-block;
        box-shadow: 0 0 4px #28a745;
    }
    .online-indicator.offline {
        background-color: #6c757d;
        box-shadow: none;
    }
    
    .chat-body {
        flex: 1;
        padding: 12px;
        overflow-y: auto;
        background-color: #f8f9fa;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .chat-msg {
        padding: 8px 12px;
        border-radius: 12px;
        max-width: 82%;
        font-size: 13px;
        word-wrap: break-word;
        position: relative;
        line-height: 1.4;
    }
    .chat-msg.me {
        align-self: flex-end;
        background: #0056b3;
        color: white;
        border-bottom-right-radius: 2px;
    }
    .chat-msg.me .msg-meta {
        color: rgba(255,255,255,0.75);
        font-size: 10px;
        text-align: right;
        margin-top: 4px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 4px;
    }
    .chat-msg.other {
        align-self: flex-start;
        background: white;
        color: #212529;
        border: 1px solid #dee2e6;
        border-bottom-left-radius: 2px;
    }
    .chat-msg.other .msg-meta {
        color: #6c757d;
        font-size: 10px;
        margin-top: 4px;
    }
    .chat-msg.system {
        align-self: center;
        background: #e9ecef;
        color: #495057;
        font-style: italic;
        font-size: 11px;
        text-align: center;
        max-width: 90%;
        border-radius: 8px;
    }
    
    .status-tick {
        font-size: 11px;
        line-height: 1;
        user-select: none;
    }
    .status-tick.read { color: #66b0ff !important; font-weight: bold; }
    .status-tick.delivered { color: #e0e0e0; font-weight: bold; }
    .status-tick.sent { color: #ced4da; }
    
    .chat-footer {
        padding: 8px 10px;
        border-top: 1px solid #dee2e6;
        display: flex;
        gap: 6px;
        background: white;
        align-items: center;
    }
    .chat-footer input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 18px;
        outline: none;
        font-size: 13px;
    }
    .chat-footer input:focus {
        border-color: #0056b3;
    }
    .chat-footer button {
        background: #0056b3;
        color: white;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        transition: background 0.2s;
    }
    .chat-footer button:hover { background: #003366; }
</style>

<div id="chat-popup" class="chat-popup">
    <div class="chat-header">
        <div class="chat-header-title">
            <span id="support-status-dot" class="online-indicator offline" title="Người hỗ trợ đang Offline"></span>
            <span id="chat-title">Hỗ trợ</span>
        </div>
        <div>
            <span onclick="toggleChatPosition()" style="cursor:pointer; margin-right:10px;" title="Đổi bên">↔️</span>
            <span onclick="toggleChatPopup()" style="cursor:pointer; font-size: 18px;">&times;</span>
        </div>
    </div>
    <div class="chat-body" id="chat-body">
        <div class="chat-msg system">Kênh hỗ trợ trực tuyến đã sẵn sàng.</div>
    </div>
    <div class="chat-footer">
        <input type="text" id="chat-input" placeholder="Nhập tin nhắn hỗ trợ..." onkeypress="handleChatKey(event)">
        <button onclick="sendChatMessage()">➤</button>
    </div>
</div>