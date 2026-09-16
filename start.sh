#!/bin/sh
set -eu

php -v
php -m | grep -qi '^pdo_pgsql$' || { echo 'ERROR: PHP extension pdo_pgsql is missing.'; exit 1; }
php -m | grep -qi '^curl$' || { echo 'ERROR: PHP extension curl is missing.'; exit 1; }
php -l index.php
php -l setup_webhook.php

# Give Railway's PHP server a moment to start, then register Telegram webhook.
php -S 0.0.0.0:${PORT:-8080} index.php > /tmp/php-server.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' INT TERM EXIT
sleep 2
php setup_webhook.php || true
cat /tmp/php-server.log
wait $SERVER_PID
