#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)
echo "[$(date)] Facebook Auto Post..."
"$PHP" "$DIR/fb-auto-post.php"
