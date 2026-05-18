#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP=$(which php)

# Get BASE_URL from config
BASE_URL=$("$PHP" -r "chdir('$DIR'); require 'config.php'; echo rtrim(BASE_URL, '/');" 2>/dev/null)

echo "[$(date)] CSV Daily — BASE_URL: $BASE_URL"

if [ -z "$BASE_URL" ]; then
    echo "[$(date)] ERROR: BASE_URL not found in config"
    exit 1
fi

# Build localhost URL: replace host with 127.0.0.1 so REMOTE_ADDR = 127.0.0.1
# satellite=1 bypass requires REMOTE_ADDR to be 127.0.0.1 or ::1
HOST=$(echo "$BASE_URL" | sed -E 's|https?://([^/]+).*|\1|')
PATH_PART=$(echo "$BASE_URL" | sed -E 's|https?://[^/]+||')
LOCAL_URL="http://127.0.0.1${PATH_PART}/auto-daily-csv.php"

echo "[$(date)] Local URL: $LOCAL_URL (Host: $HOST)"

# Call via HTTP with original Host header so nginx routes correctly
RESPONSE=$(curl -s --max-time 600 --connect-timeout 15 \
    -X POST \
    -H "Host: $HOST" \
    -d "satellite=1" \
    "$LOCAL_URL" 2>&1)

echo "[$(date)] Response: $RESPONSE"

# Check success
if echo "$RESPONSE" | grep -q '"success":true'; then
    echo "[$(date)] ✅ CSV Daily OK"
else
    echo "[$(date)] ❌ CSV Daily failed"
    exit 1
fi
