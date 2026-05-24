# FreeBooks Docker

Docker deployment for [FreeBooks ERP](https://github.com/billj9000/freebooks), a fork of PhreeBooks.

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/)
- [Docker Compose](https://docs.docker.com/compose/install/)

## Installation

### 1. Get the code

Clone this repository:

```bash
git clone https://github.com/billj9000/freebooks-docker
cd freebooks-docker
```

Clone the FreeBooks application source and copy it into place:

```bash
git clone https://github.com/billj9000/freebooks
cp -r freebooks/trunk php-app
rm -rf freebooks
```

### 2. Configure credentials

Copy the example environment file and choose your database credentials. These are used to create a new database, so you can set them to anything you like:

```bash
cp .env.example .env
```

Edit `.env`:

```
DB_ROOT_PASSWORD=your_root_password
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
```

### 3. Build and start

```bash
docker compose up -d --build
```

### 4. Run the installer

Navigate to http://localhost — on a fresh installation this redirects automatically to the installer. Database credentials are pre-configured from your `.env` file and do not need to be entered.

Once installation is complete, the installer directory should be removed or protected — see [Post-install security](#post-install-security).

### 5. Log in

After installation completes, navigate to http://localhost and log in with the admin credentials you set during installation.

## Services

| Service    | URL                        | Notes                              |
|------------|----------------------------|------------------------------------|
| FreeBooks  | http://localhost      |                                    |
| phpMyAdmin | http://localhost:40002     | DB admin interface                 |
| MariaDB    | localhost:33060            | Direct DB access (e.g. from host)  |

## HTTPS with Traefik

If Traefik is running as an SSL-terminating reverse proxy, remove the direct port binding from `php-app` and add Traefik labels instead. Traefik must be on a Docker network shared with this stack — adjust the network name to match your Traefik setup.

In `docker-compose.yml`, replace the `ports` entry on `php-app`:

```yaml
services:
  php-app:
    # Remove:  ports: ["80:80"]
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.freebooks.rule=Host(`your.domain.com`)"
      - "traefik.http.routers.freebooks.entrypoints=websecure"
      - "traefik.http.routers.freebooks.tls=true"
    networks:
      - db
      - traefik        # shared network Traefik listens on

networks:
  db:
    driver: bridge
  traefik:
    external: true     # must already exist; created by your Traefik stack
```

After installing FreeBooks, update `ENABLE_SSL_ADMIN` in `trunk/includes/configure.php` (inside the container volume) from `'false'` to `'true'`:

```php
define('ENABLE_SSL_ADMIN', 'true');
```

This tells FreeBooks to set the `Secure` flag on all cookies and to generate `https://` links. Without it, cookies are issued without `Secure` even when the browser is connecting over HTTPS.

## Updating the application

Pull the latest FreeBooks source and rebuild:

```bash
git clone https://github.com/billj9000/freebooks
cp -r freebooks/trunk php-app
rm -rf freebooks
docker compose up -d --build php-app
```

Database and company data are stored in Docker volumes and are preserved across rebuilds.

## Post-install security

After completing installation, **remove the `install/` directory**. Leaving it accessible allows anyone to re-initialise the database.

The installer will refuse to run if it detects the application is already configured, but the directory should still be removed:

```bash
docker compose exec php-app rm -rf /var/www/html/install
```

## Troubleshooting

PHP errors are logged to `/var/log/apache2/php_errors.log` inside the container:

```bash
docker compose exec php-app cat /var/log/apache2/php_errors.log
```
