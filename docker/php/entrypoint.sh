#!/bin/sh
# Shared entrypoint for every PHP service (backend/queue/websocket/migrate).
#   1. *_FILE shim: read mounted Docker secrets into plain env (E-1).
#   2. Fail-fast: assert required vars are present, naming any that is missing (E-2, CR-5).
#   3. Warm this container's OWN caches for the long-running serving roles (E-5/F2).
#   4. exec the CMD as PID 1 so signals reach the app (E-3).
# Never prints a secret value — only the NAME of a failed variable (E-4).
set -eu

# --- 1. *_FILE -> env shim (E-1) ------------------------------------------------
# For each known secret file var: read the file, export the de-suffixed var, drop *_FILE.
for var in APP_KEY DB_PASSWORD OPENSTREET_API_KEY REVERB_APP_SECRET; do
    file_var="${var}_FILE"
    eval "file_path=\${${file_var}:-}"
    if [ -n "${file_path}" ]; then
        if [ ! -r "${file_path}" ]; then
            echo "entrypoint: ${file_var} points at unreadable file" >&2
            exit 1
        fi
        # Strip a single trailing newline; keep the value otherwise intact.
        value="$(cat "${file_path}")"
        export "${var}=${value}"
        unset "${file_var}"
    fi
done

# --- role detection (drives required-var set + cache warming) --------------------
role="other"
case "$*" in
    *php-fpm*)        role="fpm" ;;
    *queue:work*)     role="queue" ;;
    *reverb:start*)   role="reverb" ;;
    *artisan*migrate*) role="init" ;;
esac

# --- 2. fail-fast required-var assertion (E-2, CR-5) ----------------------------
required="APP_KEY APP_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET REVERB_HOST"
# The websocket (reverb) role never calls the OpenStreet API, so it is not given
# that secret — only require it where it is actually used.
if [ "${role}" != "reverb" ]; then
    required="${required} OPENSTREET_API_URL OPENSTREET_API_KEY"
fi

missing=""
for var in ${required}; do
    eval "val=\${${var}:-}"
    if [ -z "${val}" ]; then
        missing="${missing} ${var}"
    fi
done
if [ -n "${missing}" ]; then
    echo "entrypoint: missing required configuration:${missing}" >&2
    exit 1
fi

# --- 3. warm per-container caches for the serving roles (E-5/F2) -----------------
# These write to THIS container's filesystem, where PHP will read them. The `init`
# one-shot skips warming — its fs is thrown away (F2). Env-driven, no rebuild (FR-012).
if [ "${role}" = "fpm" ] || [ "${role}" = "queue" ] || [ "${role}" = "reverb" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    php artisan view:cache
    php artisan storage:link 2>/dev/null || true
fi

# --- 4. hand off to the container command as PID 1 (E-3) -------------------------
exec "$@"
