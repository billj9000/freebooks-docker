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

The application itself is not in this repository. It is the published image
`ghcr.io/billj9000/freebooks`, which Docker Compose downloads on first start.

### 2. Configure credentials

Copy the example environment file and choose your database credentials. These are used to create a new database, so you can set them to anything you like:

```bash
cp .env.example .env
```

Edit `.env`:

```
FREEBOOKS_VERSION=1.0
DB_ROOT_PASSWORD=your_root_password
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
```

`FREEBOOKS_VERSION` picks the release: an exact version such as `1.0.0`, a release line such as `1.0` (the newest `1.0.x`), or `edge` for the latest development build.

### 3. Start

```bash
docker compose up -d
```

### 4. Run the installer

Navigate to http://localhost — on a fresh installation this redirects automatically to the installer. Database credentials are pre-configured from your `.env` file and do not need to be entered.

Once FreeBooks is configured, the installer is closed automatically the next time the container starts — see [Post-install security](#post-install-security).

### 5. Log in

After installation completes, navigate to http://localhost and log in with the admin credentials you set during installation.

## Services

| Service    | URL                        | Notes                              |
|------------|----------------------------|------------------------------------|
| FreeBooks  | http://localhost      |                                    |
| phpMyAdmin | http://localhost:40002     | DB admin interface                 |
| MariaDB    | localhost:33060            | Direct DB access (e.g. from host)  |

## Deploying to Fly.io

For a managed, container-native deployment with automatic HTTPS (no Traefik/reverse-proxy setup needed), see [FLY.md](FLY.md). It runs the same published image, using the configurations in `fly/`.

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

After installing FreeBooks, update `ENABLE_SSL_ADMIN` in `config/configure.php` (in the `config` volume) from `'false'` to `'true'`:

```php
define('ENABLE_SSL_ADMIN', 'true');
```

This tells FreeBooks to set the `Secure` flag on all cookies and to generate `https://` links. Without it, cookies are issued without `Secure` even when the browser is connecting over HTTPS.

## Updating the application

Set `FREEBOOKS_VERSION` in `.env` to the release you want (or leave it on a release line such as `1.0` to take its newest version), then:

```bash
docker compose pull php-app
docker compose up -d php-app
```

Database and company data are stored in Docker volumes and are preserved across updates. Back them up before moving to a new release. The database is upgraded when an administrator next logs in to each company.

## Adding optional modules

The image contains the standard FreeBooks installation only. Optional modules, payment methods and shipping methods from the FreeBooks repository's `extensions/` directory are added by building a small image of your own on top of it. For example, with a checkout of the FreeBooks repository beside this one:

```dockerfile
# custom/Dockerfile
FROM ghcr.io/billj9000/freebooks:1.0
COPY extensions/Modules/work_orders/ /var/www/html/modules/work_orders/
COPY extensions/ShippingMethods/ups/ /var/www/html/modules/shipping/methods/ups/
```

```bash
docker build -f custom/Dockerfile -t freebooks-custom ../freebooks
```

Then set `image: freebooks-custom` for `php-app` in a `docker-compose.override.yml`. A copied-in module is installed per company from Company → Modules, as with any installation.

## Post-install security

Once `configure.php` exists, the container denies all web access to `install/` every time it starts, so after the first restart following installation the installer is unreachable:

```bash
docker compose restart php-app
```

The installer also refuses to run on an application that is already configured.

## Troubleshooting

PHP errors are logged to `/var/log/apache2/php_errors.log` inside the container:

```bash
docker compose exec php-app cat /var/log/apache2/php_errors.log
```
