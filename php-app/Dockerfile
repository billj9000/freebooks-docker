# Use the official PHP image
FROM php:8.3-apache

# Install MySQL, zip, and bzip2 extensions
RUN apt-get update && apt-get install -y libbz2-dev libzip-dev cron && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql zip bz2

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
# Ensure PHP errors are always logged and displayed regardless of runtime ini_set
RUN echo "display_errors = Off\nerror_reporting = E_ALL & ~E_NOTICE\nlog_errors = On\nerror_log = /var/log/apache2/php_errors.log" > /usr/local/etc/php/conf.d/errors.ini
# Store configure.php in a dedicated volume directory and symlink it into includes/.
# PHP's file_exists() returns false on a broken symlink, so the installer won't
# error before the file is written.
RUN mkdir /var/www/html/config && \
    chown www-data:www-data /var/www/html/config && \
    ln -s /var/www/html/config/configure.php /var/www/html/includes/configure.php
# Allow the web server to write into includes/ and my_files/
RUN chown www-data:www-data includes/ my_files/
# Expose port 80
# Install crontab (used by the cron service in docker-compose.yml)
COPY --chown=root:root cron/crontab /etc/cron.d/freebooks
RUN chmod 0644 /etc/cron.d/freebooks

EXPOSE 80


