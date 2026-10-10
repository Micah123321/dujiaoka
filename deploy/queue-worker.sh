#!/bin/sh
set -eu

# A fresh stack serves the installer before the business tables exist.
while [ ! -f /app/install.lock ]; do
    sleep 3
done
exec php /app/artisan queue:work "$@"
