#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)
echo "[$(date)] YouTube Auto Post..."
"$PHP" "$DIR/yt-auto-post.php"
