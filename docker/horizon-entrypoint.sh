#!/bin/sh
# Start Horizon once its backing services are accepting connections.
#
# Lando's `depends_on` only waits for a container to start, not for the service
# inside it to be ready, so Horizon would otherwise boot first, fail to reach
# Redis and exit. The container stays up, which makes the worker look healthy
# while no jobs are being processed.
set -eu

REDIS_HOST="${REDIS_HOST:-redis}"
REDIS_PORT="${REDIS_PORT:-6379}"
DB_HOST="${DB_HOST:-database}"
DB_PORT="${DB_PORT:-3306}"

wait_for() {
    name="$1"
    host="$2"
    port="$3"
    attempt=1

    while [ "$attempt" -le 60 ]; do
        if php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $m, 2) ? 0 : 1);' "$host" "$port"; then
            echo "horizon: $name is ready at $host:$port"
            return 0
        fi

        echo "horizon: waiting for $name at $host:$port ($attempt/60)"
        attempt=$((attempt + 1))
        sleep 2
    done

    echo "horizon: gave up waiting for $name at $host:$port" >&2
    return 1
}

wait_for redis "$REDIS_HOST" "$REDIS_PORT"
wait_for mysql "$DB_HOST" "$DB_PORT"

# Keep the worker alive across crashes and `horizon:terminate`, which exits 0
# by design so that a supervisor restarts it with the new code.
while true; do
    echo "horizon: starting worker"
    php /app/artisan horizon || echo "horizon: worker exited ($?), restarting in 3s" >&2
    sleep 3
done
