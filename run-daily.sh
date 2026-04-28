#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)
echo "[$(date)] CSV Daily..."
"$PHP" "$DIR/auto-daily-csv.php"
