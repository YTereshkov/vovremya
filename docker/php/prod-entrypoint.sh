#!/bin/sh
set -eu

php bin/console cache:warmup --env=prod --no-debug

exec "$@"
