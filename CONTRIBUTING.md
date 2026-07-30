# Contributing to SSL Cave

Thanks for helping improve SSL Cave. This guide covers local setup, the same quality checks CI runs, config hygiene, and pull-request norms.

## Dev setup

1. Clone the repo and start the stack:

   ```bash
   docker compose up --build
   ```

2. Open **http://localhost:8088** — default login is username `admin`, password `sslcave`.

3. Fixtures under `docker/fixtures/` stub `acme.sh` and `uapi` so Sync and UI flows work without a real cPanel host. Point `paths.*` at those stubs when running PHP outside Docker (see the README “Local PHP without Docker” section).

Queued issue/renew/deploy against real certificates belongs on a cPanel host. Local stubs are for UI and sync flow only.

## Quality checks

Run the same toolchain CI uses. From the repo root:

```bash
composer install
composer quality   # PHP-CS-Fixer check + PHPStan level 5
composer test      # PHPUnit (needs MySQL; Docker Compose DB or CI-equivalent)
```

Individual commands (same as CI):

```bash
vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/phpunit
```

Keep these green before opening a PR. CI runs quality on PHP 8.3 and tests on PHP 8.1 and 8.3 with MySQL.

## Config hygiene

- Never commit a real `config.php` (or other `/config*.php` variants). They are gitignored.
- Copy `config.php.example` → `config.php` locally. Keep the example file as placeholders / [RFC 2606](https://www.rfc-editor.org/rfc/rfc2606) domains only — no live credentials, hostnames, or mail settings.
- Host-only material (cert/key dumps, `docker-compose.override.yml`, private hotfixes) stays off the public tree.

## Pull requests

- Target `main`.
- Keep CI green on the PR (quality + tests).
- Do not commit secrets or a real `config.php`.
- Update docs when behaviour or setup changes.

## Out of scope for this guide

This file does not cover a code of conduct, install/support help for end hosts, or release tagging. Releases are maintainer-only (see the README Releases section). Report security issues only via [SECURITY.md](SECURITY.md).
