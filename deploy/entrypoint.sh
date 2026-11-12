#!/bin/sh
#
# Render container entrypoint.
#
# The application reads its configuration from Render's environment variables;
# there is no .env file in the image. Migrations run here (Render's free plan
# has no shell and no one-off jobs) and are idempotent.
#
set -eu

PORT="${PORT:-10000}"

# Render and Cloudflare sit in front of the container. Trusting their forwarded
# headers is what makes request()->ip() the client and not the proxy, and what
# keeps generated URLs on https.
if [ -z "${TRUSTED_PROXIES:-}" ]; then
    export TRUSTED_PROXIES='*'
fi

# Apache cannot read PORT from the environment, so render the vhost template.
sed "s/__PORT__/${PORT}/g" /etc/apache2/sehatly-vhost.conf \
    > /etc/apache2/sites-available/000-default.conf
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# The public disk is local and ephemeral on Render's free plan, but the symlink
# still has to exist for the lifetime of the container.
php artisan storage:link >/dev/null 2>&1 || true

# Cache the merged configuration once the real environment is present.
php artisan config:cache

# TiDB Cloud may refuse the first connection while the instance wakes up.
attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 5 ]; then
        echo "sehatly: migrate failed after ${attempt} attempts" >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 3
done

exec apache2-foreground
