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
fi

cron
exec apache2-foreground
