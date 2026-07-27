# Use the official PHP image
FROM php:8.3-apache-bookworm

# Install MySQL, zip, and bzip2 extensions
RUN apt-get update && apt-get install -y libbz2-dev libzip-dev libpng-dev libjpeg-dev cron && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure gd --with-jpeg && docker-php-ext-install pdo pdo_mysql zip bz2 gd

# Set the working directory
WORKDIR /var/www/html

# Enable rewrite engine
RUN a2enmod rewrite

# Copy the application code
#COPY * .
#COPY ckeditor/ ./ckeditor/
#COPY includes/ ./includes/
#COPY modules/ ./modules/
#COPY my_files/ ./my_files/
#COPY soap/ ./soap/
#COPY themes/ ./themes/
COPY . .

# Install Composer dependencies (PHPMailer 7)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Set upload_tmp_dir explicitly so the installer's ini_get() check passes
RUN echo "upload_tmp_dir = /tmp" > /usr/local/etc/php/conf.d/uploads.ini
# Company backup restore accepts a single archive (SQL + files) up to 1GB;
# post_max_size is padded above upload_max_filesize to cover multipart overhead
RUN echo "upload_max_filesize = 1024M\npost_max_size = 1088M\nmemory_limit = 256M" > /usr/local/etc/php/conf.d/restore_uploads.ini
# Phreeform report editor submits large forms; raise the input variable limit
RUN echo "max_input_vars = 5000" > /usr/local/etc/php/conf.d/input_vars.ini
# Ensure PHP errors are always logged and displayed regardless of runtime ini_set
RUN echo "display_errors = Off\nerror_reporting = E_ALL & ~E_NOTICE\nlog_errors = On\nerror_log = /var/log/apache2/php_errors.log" > /usr/local/etc/php/conf.d/errors.ini
# Store configure.php in a dedicated volume directory and symlink it into includes/.
# PHP's file_exists() returns false on a broken symlink, so the installer won't
# error before the file is written.
RUN mkdir /var/www/html/config && \
    chown www-data:www-data /var/www/html/config && \
    ln -s /var/www/html/config/configure.php /var/www/html/includes/configure.php
# Allow the web server to write into includes/ and my_files/, plus TCPDF's image
# cache (modules/ is otherwise root-owned — TCPDF fatals with "Can't open image
# file" when it can't write a downloaded image into this directory)
RUN chown www-data:www-data includes/ my_files/ modules/phreeform/includes/tcpdf/cache/
# Stash pristine copies of config/ and my_files/ so a single volume can be
# seeded with them on first boot on platforms that only support one volume
# mount per instance (see entrypoint.sh) — Docker Compose doesn't need these,
# it mounts config/ and my_files/ directly as separate volumes.
RUN cp -a /var/www/html/config /var/www/html/.defaults-config && \
    cp -a /var/www/html/my_files /var/www/html/.defaults-my_files
# Install crontab and the entrypoint that runs cron alongside Apache in this container
COPY --chown=root:root cron/crontab /etc/cron.d/freebooks
RUN chmod 0644 /etc/cron.d/freebooks
COPY --chown=root:root cron/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

# Expose port 80
EXPOSE 80


