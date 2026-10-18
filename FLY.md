# Deploying to Fly.io

This deploys FreeBooks as three separate Fly apps in the same organization, talking to each other over Fly's private networking. Choose a name for each - Fly app names are unique across all of Fly - and use it wherever `<app>`, `<db-app>` and `<pma-app>` appear below. The `.toml` files do not name their app, so every command passes it.

| App | Purpose | Public? |
|---|---|---|
| `<app>` | FreeBooks: the published image, `ghcr.io/billj9000/freebooks`, running Apache, PHP and cron | Yes, HTTPS |
| `<db-app>` | MariaDB | No — private only, reachable at `<db-app>.internal:3306` |
| `<pma-app>` | phpMyAdmin (optional) | Yes, HTTPS |

The configurations are in `fly/`: `app.toml`, `db.toml` and `pma.toml`, each with its one-time setup commands as a header comment. None of them builds anything; each runs a published image.

## Prerequisites

```bash
curl -L https://fly.io/install.sh | sh
fly auth login
```

## 1. Database

```bash
fly apps create <db-app>
fly volumes create mariadb_data --app <db-app> --region lhr --size 5
fly secrets set -a <db-app> \
  MYSQL_ROOT_PASSWORD=<pick-one> \
  MYSQL_USER=freebooks \
  MYSQL_PASSWORD=<pick-one>

fly deploy --config fly/db.toml --app <db-app>
```

It runs the public `mariadb:11.4` image and is never exposed publicly.

## 2. FreeBooks

```bash
fly apps create <app>
fly volumes create freebooks_data --app <app> --region lhr --size 6
fly secrets set -a <app> \
  DB_HOST=<db-app>.internal \
  DB_USER=freebooks \
  DB_PASSWORD=<same-as-db-app>

fly deploy --config fly/app.toml --app <app>
```

`fly/app.toml` names the image to run, `ghcr.io/billj9000/freebooks:1.0`; see [Updating](#updating) for choosing a version.

## 3. Custom domain + HTTPS

```bash
fly certs add fb.your.domain.com -a <app>
fly certs show fb.your.domain.com -a <app>
```

`fly certs show` prints the exact DNS records to add (A + AAAA recommended). Once DNS resolves, Fly issues and auto-renews the Let's Encrypt cert — no Traefik/certbot setup needed. If DNS is already correct but the cert is stuck on "Not verified", force a re-check with `fly certs check fb.your.domain.com -a <app>`.

While waiting on DNS, `<app>.fly.dev` (see `fly status -a <app>`) works immediately over HTTPS via Fly's own managed wildcard cert.

## 4. Run the installer

Visit `https://fb.your.domain.com/install/`. Database credentials are pre-filled from `DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASSWORD` (`DB_NAME` is in `fly/app.toml`, the rest are the secrets set above).

## 5. After installing

```bash
fly apps restart <app>
fly ssh console -a <app> -C "sed -i \"s/ENABLE_SSL_ADMIN', 'false'/ENABLE_SSL_ADMIN', 'true'/\" /var/www/html/config/configure.php"
```

Once FreeBooks is configured, the container denies all web access to `install/` every time it starts; the restart makes that take effect. The second command sets the `Secure` flag on cookies now that you're on real HTTPS.

## 6. Optional: phpMyAdmin

```bash
fly apps create <pma-app>
fly secrets set -a <pma-app> PMA_HOST=<db-app>.internal
fly certs add pma.your.domain.com -a <pma-app>

fly deploy --config fly/pma.toml --app <pma-app>
```

Stateless, no volumes, and set to auto-stop when idle (`min_machines_running = 0` in `fly/pma.toml`) since it's admin-only tooling — worth doing for any Machine you don't need running 24/7, since Fly bills per-second while a Machine is up.

## Updating

The image tag in `fly/app.toml` picks the version:

| Tag | Runs |
|---|---|
| `1.0` | The newest `1.0.x` release, taken on each deploy |
| `1.0.0` | Exactly that release |
| `sha-<commit>` | The build of one `master` commit, which never changes |
| `edge` | The newest `master` build |

Take a backup, change the tag if you need to, and deploy again:

```bash
fly deploy --config fly/app.toml --app <app>
```

Company data and settings are on the volume and the database, so they carry over. The database is upgraded when an administrator next logs in to each company. To rebuild the Help index for every company afterwards:

```bash
fly ssh console -a <app> -C "php /opt/freebooks/tools/reindex_help.php --all"
```

To go back, deploy the previous tag the same way, or `fly releases -a <app>` and `fly deploy --app <app> --image <image of an earlier release>`.

## Adding extensions and scheduled tasks

The image contains the standard FreeBooks installation only. Optional modules, shipping and payment methods from the FreeBooks repository's `extensions/` directory, and further scheduled tasks, are added by building a small image of your own on top of it. Put it in `fly/site/`, which git ignores:

```dockerfile
# syntax=docker/dockerfile:1
# fly/site/Dockerfile
FROM ghcr.io/billj9000/freebooks:1.0.0
ARG REPO=https://github.com/billj9000/freebooks.git#v1.0.0

ADD ${REPO}:extensions/Modules/work_orders /var/www/html/modules/work_orders
ADD ${REPO}:extensions/ShippingMethods/ups /var/www/html/modules/shipping/methods/ups

# Zen Cart: the module and its inventory page additions, plus its order import every 15 minutes.
ADD ${REPO}:extensions/Modules/zencart/PhreeBooksFiles/modules /var/www/html/modules
RUN printf '*/15 * * * * root /usr/local/bin/php /var/www/html/cron/zencart_sync.php >> /proc/1/fd/1 2>&1\n' \
      > /etc/cron.d/freebooks-zencart && chmod 0644 /etc/cron.d/freebooks-zencart
```

`ADD` takes each directory straight from the FreeBooks repository at the release tag, so name the same version in `FROM` and in `REPO`. A file in `/etc/cron.d/` gives the user name before the command, names PHP by its full path, `/usr/local/bin/php`, and sends its output to `/proc/1/fd/1`, so that it appears in `fly logs`. Cron's own search path does not include PHP, and a `PATH` set in another cron file does not carry over. The file's name must not contain a dot, or cron ignores it.

Copy `fly/app.toml` to `fly/site/app.toml`, and in its `[build]` section replace the `image` line with:

```toml
  dockerfile = "Dockerfile"
```

Then deploy from that directory:

```bash
cd fly/site
fly deploy --app <app>
```

A copied-in module is installed per company from Company → Modules, as with any installation.

## Why cron runs inside the app container

Fly Machines only support **one volume mount per Machine**, and Docker Compose mounts `config/` and `my_files/` as two separate volumes. The image handles both: its entrypoint starts `cron` in the background, then runs Apache - one process, one Machine, one volume. When `/var/www/html/fly_data` exists, as it does on Fly, the entrypoint seeds `config/` and `my_files/` there on first boot and links them into place. Docker Compose never creates that path, so the same image serves both.

## Starting over

If an install attempt fails partway through, `install/` only stops running once `config/configure.php` exists — so every failed attempt before that point writes to the same live database. If you hit repeated install failures, the most reliable fix is a clean slate rather than patching around partial state:

```bash
fly ssh console -a <db-app> -C 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS $MYSQL_DATABASE; CREATE DATABASE $MYSQL_DATABASE;"'
fly ssh console -a <app> -C "rm -f /var/www/html/fly_data/config/configure.php"
fly ssh console -a <app> -C "sh -c 'rm -rf /var/www/html/fly_data/my_files/*'"
fly apps restart <app>
```

Then reload `https://fb.your.domain.com/install/`.

## Troubleshooting

- **`fly ssh console` fails with an SSH handshake/auth error**: usually a local `flyctl` agent issue, not an app problem. Try `fly agent restart`, confirm `fly auth whoami`, or `fly ssh issue --agent`.
- **`fly certs show` reports "Not verified"**: check the exact DNS records with `fly certs setup fb.your.domain.com -a <app>`, confirm they're live with `dig` or `curl "https://dns.google/resolve?name=fb.your.domain.com&type=A"`, then `fly certs check`.
- **PHP errors**: `fly logs -a <app>` streams Apache/PHP output.
