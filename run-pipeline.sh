#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)
echo "[$(date)] [1/2] Pipeline — génération posts..."
"$PHP" "$DIR/auto-pipeline.php"
echo "[$(date)] [2/2] CSV Daily..."
"$PHP" "$DIR/auto-daily-csv.php"
echo "[$(date)] Pipeline terminé."
