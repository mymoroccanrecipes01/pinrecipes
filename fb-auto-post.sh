#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)
echo "[$(date)] Facebook Reels — génération et post..."
"$PHP" "$DIR/fb-auto-post.php"
echo "[$(date)] Facebook Reels terminé."
