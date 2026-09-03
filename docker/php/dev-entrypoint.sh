#!/bin/sh
set -eu

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

if [ -f public/index.php ]; then
    exec php -S 0.0.0.0:8000 -t public
fi

exec sleep infinity
