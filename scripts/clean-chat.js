#!/usr/bin/env node
/**
 * clean-chat.js - Script tự động dọn dẹp tin nhắn chat đã quá 24 giờ
 * Có thể chạy độc lập qua CLI: node clean-chat.js
 * Hoặc thiết lập qua Crontab trên AZDIGI / cPanel: 0 * * * * node /path/to/clean-chat.js
 */

const path = require('path');
const fs = require('fs');

const dbPath = path.join(__dirname, '..', 'data', 'chat.sqlite');

if (!fs.existsSync(dbPath)) {
    console.log('[Clean-Chat] File database chưa tồn tại:', dbPath);
    process.exit(0);
}

// Hàm kết nối SQLite tương thích mọi phiên bản Node
function getDb() {
    try {
        const { DatabaseSync } = require('node:sqlite');
        const db = new DatabaseSync(dbPath);
        return {
            type: 'sync',
            exec: (sql) => db.exec(sql),
            run: (sql, params) => db.prepare(sql).run(...(params || [])),
            all: (sql, params) => db.prepare(sql).all(...(params || []))
        };
    } catch (e) {
        const sqlite3 = require('sqlite3').verbose();
        const db = new sqlite3.Database(dbPath);
        return {
            type: 'callback',
            run: (sql, params) => new Promise((res, rej) => db.run(sql, params, function(err) { if (err) rej(err); else res(this); })),
            all: (sql, params) => new Promise((res, rej) => db.all(sql, params, (err, rows) => { if (err) rej(err); else res(rows); }))
        };
    }
}

async function runCleanup() {
    const db = getDb();
    const cutoff = Math.floor(Date.now() / 1000) - 86400; // 24 giờ trước

    try {
        if (db.type === 'sync') {
            const countBefore = db.all("SELECT COUNT(*) as count FROM messages WHERE created_at < ?", [cutoff])[0].count;
            db.run("DELETE FROM messages WHERE created_at < ?", [cutoff]);
            console.log(`[Clean-Chat] [${new Date().toISOString()}] Đã xóa ${countBefore} tin nhắn cũ hơn 24 giờ.`);
        } else {
            const rows = await db.all("SELECT COUNT(*) as count FROM messages WHERE created_at < ?", [cutoff]);
            const countBefore = rows[0] ? rows[0].count : 0;
            await db.run("DELETE FROM messages WHERE created_at < ?", [cutoff]);
            console.log(`[Clean-Chat] [${new Date().toISOString()}] Đã xóa ${countBefore} tin nhắn cũ hơn 24 giờ.`);
        }
    } catch (err) {
        console.error('[Clean-Chat] Lỗi khi dọn dẹp tin nhắn:', err.message);
    }
}

runCleanup();
