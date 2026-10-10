#!/bin/sh
set -eu
cd /app
php --version > /probe/php-version.txt 2> /probe/php-version.stderr
php --ini > /probe/php-ini.txt 2> /probe/php-ini.stderr
composer --version > /probe/composer-version.txt 2> /probe/composer-version.stderr
composer validate --strict --no-check-all --check-lock --no-check-publish --no-plugins > /probe/composer-validate.stdout 2> /probe/composer-validate.stderr
composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins --optimize-autoloader > /probe/composer-install.stdout 2> /probe/composer-install.stderr
composer check-platform-reqs --no-dev --no-plugins > /probe/composer-platform.stdout 2> /probe/composer-platform.stderr
php -d error_reporting=32767 -d display_errors=1 -d log_errors=0 /probe/image-contract.php > /probe/image-contract.stdout 2> /probe/image-contract.stderr
printf '%s\n' 'Original image CLI completed' > /probe/native.completed
