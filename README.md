<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/brand/lockup-dark.svg">
    <img src="assets/brand/lockup-light.svg" alt="SSL Cave" width="280">
  </picture>
</p>

# SSL Cave

Single-user PHP portal for SSL inventory, expiry/drift visibility, and controlled `acme.sh` issue/renew jobs on **cPanel shared hosting**.

Routine auto-renewal stays with your existing `acme.sh --cron` job. This portal does **not** add a competing renew-all cron.

[CONTRIBUTING](CONTRIBUTING.md) · [SECURITY](SECURITY.md) · [LICENSE](LICENSE)

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

## Quality checks

Install Composer require-dev tools and run the same commands CI runs. Exact commands live in **[CONTRIBUTING.md](CONTRIBUTING.md)** (`composer quality`, `composer test`).

## Deploy on cPanel

See **[docs/deploy-cpanel.md](docs/deploy-cpanel.md)** for document-root layout, config, HTTPS, cron, and troubleshooting.

Copy `config.php.example` → `config.php`, set DB credentials and auth hash (`php bin/hash-password.php 'your-password'`), and point paths at your cPanel account.

## Releases

Maintainers tag annotated SemVer versions (`vMAJOR.MINOR.PATCH`) on **CI-green** `main` only (quality + test jobs). Contributors do not tag; there is no Actions auto-tagging.

Each tag gets a **GitHub Release**. Release notes are the changelog source of truth (no required in-repo `CHANGELOG.md`): what changed, cPanel upgrade notes (config / schema / cron / paths as needed), and breaking changes called out.

**Deployers:** download the GitHub **release archive** for that tag — not a branch zip. Archives are shaped by `.gitattributes` `export-ignore` (no `docker/`, Dockerfile/compose, `.github/`, or `docs/research/`) so the tree is upload-ready for cPanel.

### Private-install cutover

If you previously treated a private install/repo as upstream:

1. Back up live `config.php` and cron job definitions; note docroot paths.
2. Deploy the public **release archive** into the docroot (or staging subtree, then swap).
3. Restore the **same** untracked `config.php` (never from git).
4. Confirm cron still points at `bin/worker.php` / `bin/alert.php`.
5. Smoke portal login + one sync; **then** archive the private repo (read-only).

Never port live config, host overrides (`docker-compose.override.yml`, dumps, cert/key material), or private-only hotfixes into the public tree. Fixes land on public `main` first, then a new tag; the host only consumes release archives.

## Layout

```
index.php          # front controller (subdomain document root)
assets/            # CSS + brand SVGs
.well-known/       # AutoSSL / acme.sh HTTP-01 challenges
src/               # app (blocked from web by .htaccess)
templates/
bin/worker.php     # process queued jobs
bin/alert.php      # expiry/drift/failure emails
config.php.example # copy to config.php (gitignored)
schema.sql
docker-compose.yml # local/dev (excluded from release archives)
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
