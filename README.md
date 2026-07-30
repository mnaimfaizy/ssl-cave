# SSL Cave

Single-user PHP portal for SSL inventory, expiry/drift visibility, and controlled `acme.sh` issue/renew jobs on **cPanel shared hosting**.

Routine auto-renewal stays with your existing `acme.sh --cron` job. This portal does **not** add a competing renew-all cron.

Licensed under [MIT](LICENSE).

## Stack

- PHP 8.1+ (no framework)
- MySQL/MariaDB
- Pico.css + `assets/app.css`
- Local `acme.sh` + cPanel `uapi` (same Unix user)

## Quick start (Docker)

```bash
docker compose up --build
```

Open **http://localhost:8088** — username `admin`, password `sslcave`.

## Deploy on cPanel

See **[docs/deploy-cpanel.md](docs/deploy-cpanel.md)** for document-root layout, config, HTTPS, cron, and troubleshooting.

Copy `config.php.example` → `config.php`, set DB credentials and auth hash (`php bin/hash-password.php 'your-password'`), and point paths at your cPanel account.

## Layout

```
index.php          # front controller (subdomain document root)
assets/            # CSS
.well-known/       # AutoSSL / acme.sh HTTP-01 challenges
src/               # app (blocked from web by .htaccess)
templates/
bin/worker.php     # process queued jobs
bin/alert.php      # expiry/drift/failure emails
config.php.example # copy to config.php (gitignored)
schema.sql
docker-compose.yml # local/dev
storage/           # sessions + job logs
```

## v1 behaviour

| Area | Behaviour |
|------|-----------|
| Live status | `uapi SSL installed_hosts` |
| Managed status | `acme.sh --list` + domain confs; cert expiry from `fullchain.cer` |
| Sync | Union of both sources; prune only when both reads succeed |
| Actions | Queue via worker: `issue`, `renew`, `deploy`, `remove` |
| Issue / renew | Always followed by `--deploy --deploy-hook cpanel_uapi` |
| Alerts | In-portal attention + email at 30/14/7 days; job failure; acme↔cPanel drift |
| Privacy | `X-Robots-Tag: noindex, nofollow`, `robots.txt` Disallow |

## Local PHP without Docker

1. PHP 8.1+ with `pdo_mysql`, plus MySQL/MariaDB.
2. Import `schema.sql` (optionally `docker/seed.sql`).
3. Copy `config.php.example` → `config.php` and set DB + auth hash.
4. For Sync demos without cPanel, point `paths.*` at the stubs in `docker/fixtures/`.
5. Serve with docroot = repo root: `php -S localhost:8080 -t .`

Queued issue/renew/deploy against real certificates belongs on the cPanel host. Local stubs are for UI and sync flow only.
