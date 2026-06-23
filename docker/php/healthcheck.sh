#!/bin/sh
# Per-role container healthcheck (R7, FR-013). Usage: healthcheck.sh <fpm|queue|reverb>
set -eu

role="${1:-}"
case "${role}" in
    fpm)
        # php-fpm ping endpoint over FastCGI on :9000 (cgi-fcgi ships via the `fcgi` pkg).
        SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET \
            cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null 2>&1
        ;;
    reverb)
        # Reverb accepts TCP connections on :8080 once it is up.
        nc -z 127.0.0.1 8080
        ;;
    queue)
        # The worker has no socket — assert the queue:work process is alive.
        pgrep -f "queue:work" >/dev/null
        ;;
    *)
        echo "healthcheck: unknown role '${role}'" >&2
        exit 2
        ;;
esac
