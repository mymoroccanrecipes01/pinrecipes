#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)
echo "[$(date)] Pipeline — génération posts + CSV Pinterest..."
"$PHP" "$DIR/auto-pipeline.php"
echo "[$(date)] Pipeline terminé."
