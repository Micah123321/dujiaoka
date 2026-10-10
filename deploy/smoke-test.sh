#!/bin/bash
set -euo pipefail

# Run only with a dedicated, disposable Compose project and fixture passwords.
: "${COMPOSE_PROJECT_NAME:?Set an isolated Compose project name}"
: "${DB_PASSWORD:?Set a fixture database password}"
: "${MYSQL_ROOT_PASSWORD:?Set a fixture root password}"
if [[ "$COMPOSE_PROJECT_NAME" != dujiaoka-ci-* ]]; then
    echo 'Smoke tests require a dujiaoka-ci-* project name.' >&2
    exit 1
fi
export COMPOSE_DISABLE_ENV_FILE=1
tmp=$(mktemp -d)
cleanup() {
    result=$?
    if (( result != 0 )); then
        docker compose ps
        docker compose logs --tail=100
    fi
    docker compose down --volumes --remove-orphans
    rm -rf "$tmp"
    exit "$result"
}
trap cleanup EXIT

docker compose up -d --no-build --wait --wait-timeout 240
url="http://127.0.0.1:${HTTP_PORT:-8010}"
curl --fail --silent --show-error --cookie-jar "$tmp/cookies" "$url/install" > "$tmp/install.html"
csrf=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$tmp/install.html")
test -n "$csrf"
response=$(curl --fail --silent --show-error --cookie "$tmp/cookies" \
    --data-urlencode "_token=$csrf" --data-urlencode title=dujiaoka-ci \
    --data-urlencode "app_url=$url" --data-urlencode admin_path=admin \
    --data-urlencode db_host=mysql --data-urlencode db_port=3306 \
    --data-urlencode "db_database=${DB_DATABASE:-dujiaoka}" \
    --data-urlencode "db_username=${DB_USERNAME:-dujiaoka}" \
    --data-urlencode "db_password=$DB_PASSWORD" \
    --data-urlencode redis_host=redis --data-urlencode redis_port=6379 \
    --data-urlencode redis_password=null "$url/do-install")
if [[ "$response" != success ]]; then
    printf 'Installer failed: %s\n' "$response" >&2
    exit 1
fi
docker compose exec -T --user application web php artisan migrate --force

# Check persisted state without logging the generated application key.
docker compose exec -T --user application web sh -c \
    'test -f /data/install.lock; printf persisted > /app/public/uploads/ci-persistence.txt; sha256sum /data/.env' > "$tmp/env.sha"
docker compose up -d --no-build --force-recreate --wait --wait-timeout 240 web
docker compose exec -T --user application web sha256sum /data/.env > "$tmp/env-after.sha"
cmp "$tmp/env.sha" "$tmp/env-after.sha"
curl --fail --silent --show-error "$url/uploads/ci-persistence.txt" | grep -qx persisted
curl --fail --silent --show-error "$url/" > /dev/null
curl --fail --silent --show-error "$url/admin/auth/login" > /dev/null
curl --fail --silent --show-error "$url/assets/modern/manifest.json" > /dev/null
for service in dujiaoka-queue cron:crond; do
    docker compose exec -T web sh -c '
        attempt=0
        while :; do
            status=$(supervisorctl -c /opt/docker/etc/supervisor.conf status "$1" || true)
            case "$status" in
                *" RUNNING "*) printf "%s\n" "$status"; exit 0 ;;
                *FATAL*|*BACKOFF*) printf "%s\n" "$status" >&2; exit 1 ;;
            esac
            attempt=$((attempt + 1))
            if [ "$attempt" -ge 30 ]; then
                printf "Service did not start: %s\n" "$status" >&2
                exit 1
            fi
            sleep 1
        done
    ' sh "$service"
done
docker compose exec -T --user application web php artisan schedule:run --no-interaction
# Require an actual daemon tick in this fresh volume, in addition to manual scheduling.
docker compose exec -T web sh -c '
    attempt=0
    until grep -q "orders:expire" /app/storage/logs/scheduler.log 2>/dev/null; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 40 ]; then
            echo "Cron did not execute orders:expire within 80 seconds." >&2
            exit 1
        fi
        sleep 2
    done
    grep -q DONE /app/storage/logs/scheduler.log
    ! grep -q FAIL /app/storage/logs/scheduler.log
'
echo 'PASS: install, migrations, HTTP, assets, queue, real cron tick and volume persistence.'
