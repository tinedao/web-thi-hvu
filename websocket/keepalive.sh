#!/bin/bash
# keepalive.sh - keep the WebSocket server alive on 127.0.0.1:8085 (cPanel / AZDIGI)
# cPanel cron, every minute:  * * * * * /bin/bash $HOME/public_html/websocket/keepalive.sh >/dev/null 2>&1
# Each run watches the port for ~55s (every 5s). Pass "once" to check a single time.

DIR="$(cd "$(dirname "$0")" && pwd)"
PORT="${WS_PORT:-8085}"
DATA="$DIR/../data"
LOG="$DATA/ws-server.log"
mkdir -p "$DATA"

node_ok() {
    [ -x "$1" ] || return 1
    local v; v="$("$1" -p 'process.versions.node.split(".")[0]' 2>/dev/null)"
    [ -n "$v" ] && [ "$v" -ge 16 ]
}

find_node() {
    local c
    for c in "$WS_NODE_BIN" \
        $(ls -d "$HOME"/nodevenv/*/websocket/*/bin/node 2>/dev/null | sort -rV) \
        $(ls -d /opt/alt/alt-nodejs*/root/usr/bin/node 2>/dev/null | sort -rV) \
        "$(command -v node 2>/dev/null)"; do
        if [ -n "$c" ] && node_ok "$c"; then echo "$c"; return 0; fi
    done
    return 1
}

is_up() { (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; }

start_server() {
    local n; n="$(find_node)"
    if [ -z "$n" ]; then echo "[$(date '+%F %T')] no Node.js >= 16 found" >> "$LOG"; return 1; fi
    echo "[$(date '+%F %T')] port $PORT down, starting with $n" >> "$LOG"
    cd "$DIR" || return 1
    env -u PORT WS_PORT="$PORT" WS_HOST=127.0.0.1 setsid nohup "$n" "$DIR/websocket-server.js" >> "$LOG" 2>&1 < /dev/null &
    for _ in 1 2 3 4 5 6; do sleep 1; is_up && return 0; done
}

# keep the log under ~2MB
if [ -f "$LOG" ] && [ "$(wc -c < "$LOG")" -gt 2097152 ]; then
    tail -c 524288 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

if [ "$1" = "once" ]; then is_up || start_server; exit 0; fi

# only one watcher at a time
exec 9>"$DATA/ws-keepalive.lock"
if command -v flock >/dev/null 2>&1; then flock -n 9 || exit 0; fi

END=$((SECONDS + 55))
while [ "$SECONDS" -lt "$END" ]; do
    is_up || start_server
    sleep 5
done
