#!/bin/sh
set -e
export APP_ENV=testing
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export DB_CONNECTION=sqlite
export DB_DATABASE=:memory:
export QUEUE_CONNECTION=sync
export CACHE_STORE=array
export SESSION_DRIVER=array
export MAIL_MAILER=array
export HIKVISION_MOCK_MODE=true
export HIKVISION_ISAPI_USE_MOCK=true

php -d max_execution_time=0 vendor/bin/phpunit \
  --colors=never \
  --log-junit /tmp/results.xml \
  --filter "$1" \
  "$2" 2>&1

exit $?
