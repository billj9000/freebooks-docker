# Deploying to Fly.io

This deploys FreeBooks as three separate Fly apps in the same organization, talking to each other over Fly's private networking:

| App | Purpose | Public? |
|---|---|---|
| `<app>` | Apache + PHP + cron (combined — see [Why cron runs inside the app container](#why-cron-runs-inside-the-app-container)) | Yes, HTTPS |
| `<db-app>` | MariaDB | No — private only, reachable at `<db-app>.internal:3306` |
| `<pma-app>` | phpMyAdmin (optional) | Yes, HTTPS |

The `fly.toml` configs already exist in this repo (`php-app/fly.toml`, `fly/db.toml`, `fly/pma.toml`), each with the exact one-time setup commands as a header comment. This doc walks through using them.

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

fly deploy --config fly/db.toml
```

No build context needed — it deploys the public `mariadb:11.4` image directly, and is never exposed publicly.

## 2. Main app

```bash
cd php-app
fly apps create <app>
fly volumes create freebooks_data --app <app> --region lhr --size 6
fly secrets set -a <app> \
  DB_USER=freebooks \
  DB_PASSWORD=<same-as-db-app>

fly deploy
```

Must be run from `php-app/` so the Dockerfile's build context picks up the application source.

## 3. Custom domain + HTTPS

```bash
fly certs add fb.your.domain.com -a <app>
fly certs show fb.your.domain.com -a <app>
```

`fly certs show` prints the exact DNS records to add (A + AAAA recommended). Once DNS resolves, Fly issues and auto-renews the Let's Encrypt cert — no Traefik/certbot setup needed. If DNS is already correct but the cert is stuck on "Not verified", force a re-check with `fly certs check fb.your.domain.com -a <app>`.

While waiting on DNS, `<app>.fly.dev` (see `fly status -a <app>`) works immediately over HTTPS via Fly's own managed wildcard cert.

## 4. Run the installer

Visit `https://fb.your.domain.com/install/index.php`. Database credentials are pre-filled from the `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` set on `<app>` (`DB_HOST`/`DB_NAME` are already in `fly.toml`; `DB_USER`/`DB_PASSWORD` from the secrets above).

## 5. Harden after install

```bash
fly ssh console -a <app> -C "rm -rf /var/www/html/install"
fly ssh console -a <app> -C "sed -i \"s/ENABLE_SSL_ADMIN', 'false'/ENABLE_SSL_ADMIN', 'true'/\" /var/www/html/config/configure.php"
```

The second command sets the `Secure` flag on cookies now that you're on real HTTPS.

## 6. Optional: phpMyAdmin

```bash
fly apps create <pma-app>
fly certs add pma.your.domain.com -a <pma-app>

fly deploy --config fly/pma.toml
```

Stateless, no volumes, and set to auto-stop when idle (`min_machines_running = 0` in `fly/pma.toml`) since it's admin-only tooling — worth doing for any Machine you don't need running 24/7, since Fly bills per-second while a Machine is up.

## Why cron runs inside the app container

Fly Machines only support **one volume mount per Machine**. The Docker Compose setup mounts `config/` and `my_files/` as two separate volumes across two containers (`php-app` and `cron`); neither is possible as-is on Fly. Instead:

- `php-app/Dockerfile`'s entrypoint (`php-app/cron/entrypoint.sh`) starts `cron` in the background, then execs `apache2-foreground` — one process, one Machine, one volume.
- The single Fly volume mounts at `/var/www/html/fly_data`; on first boot, `config/` and `my_files/` are seeded from the image's defaults and symlinked into place there. This logic only activates when `/var/www/html/fly_data` exists, so Docker Compose (which never creates that path) is unaffected — the same image and Dockerfile serve both deployment targets.

## Starting over

If an install attempt fails partway through, `install/index.php` only blocks re-running once `includes/configure.php` exists — so every failed attempt before that point writes to the same live database. If you hit repeated install failures, the most reliable fix is a clean slate rather than patching around partial state:

```bash
fly ssh console -a <db-app> -C 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS $MYSQL_DATABASE; CREATE DATABASE $MYSQL_DATABASE;"'
fly ssh console -a <app> -C "rm -f /var/www/html/fly_data/config/configure.php"
fly ssh console -a <app> -C "rm -rf /var/www/html/fly_data/my_files/*"
```

Then reload `https://fb.your.domain.com/install/index.php`.

## Troubleshooting

- **`fly ssh console` fails with an SSH handshake/auth error**: usually a local `flyctl` agent issue, not an app problem. Try `fly agent restart`, confirm `fly auth whoami`, or `fly ssh issue --agent`.
- **`fly certs show` reports "Not verified"**: check the exact DNS records with `fly certs setup fb.your.domain.com -a <app>`, confirm they're live with `dig` or `curl "https://dns.google/resolve?name=fb.your.domain.com&type=A"`, then `fly certs check`.
- **PHP errors**: `fly logs -a <app>` streams Apache/PHP output.
