#!/bin/bash
set -e

# Platforms that only support one volume mount per instance (e.g. Fly Machines)
# mount it at /var/www/html/fly_data instead of mounting config/ and my_files/
# separately. Seed it from the image defaults on first boot, then symlink the
# app's expected paths into it. Docker Compose never creates fly_data, so this
# is a no-op there.
if [ -d /var/www/html/fly_data ]; then
    [ -d /var/www/html/fly_data/config ]   || cp -a /var/www/html/.defaults-config   /var/www/html/fly_data/config
    [ -d /var/www/html/fly_data/my_files ] || cp -a /var/www/html/.defaults-my_files /var/www/html/fly_data/my_files
    chown -R www-data:www-data /var/www/html/fly_data
    rm -rf /var/www/html/config /var/www/html/my_files
    ln -s /var/www/html/fly_data/config   /var/www/html/config
    ln -s /var/www/html/fly_data/my_files /var/www/html/my_files
else
    # Docker Compose mounts config/ and my_files/ as separate named volumes, and
    # a volume mounts OVER the image's directory — so the Dockerfile's build-time
    # `chown www-data:www-data includes/ my_files/` never reaches what is
    # actually served. Anything root puts there afterwards (docker cp, a restore,
    # provisioning through docker exec) therefore lands root-owned, and PHP runs
    # as www-data: backups, uploads and emailed attachments all fail on it. The
    # fly branch above already chowns unconditionally; this is the same
    # guarantee for the other mount layout rather than a second mechanism.
    #
    # Only what is wrong is corrected, so a healthy tree costs nothing — the
    # scan is ~50ms over 10k files, and it stays proportional to the damage
    # rather than to the size of the volume.
    find /var/www/html/config /var/www/html/my_files \
         ! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
fi

cron
exec apache2-foreground
