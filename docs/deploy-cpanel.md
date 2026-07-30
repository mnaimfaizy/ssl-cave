# Deploy SSL Cave on cPanel shared hosting

Supported model: **cPanel shared hosting**. Hosts such as Namecheap are examples of that class, not product branding.

Replace `<user>` with your cPanel username and `<docroot>` with your subdomain folder name (for example `ssl-cave.example.com`).

## Why document root is the subdomain folder (not `public/`)

On cPanel, the subdomain folder (`/home/<user>/<docroot>/`) is the correct **Document Root**.

HTTP-01 SSL validation (AutoSSL or `acme.sh -w …`) writes challenge files to:

```text
<document-root>/.well-known/acme-challenge/
```

If Document Root is moved to a nested `public/` folder:

- challenges land in the wrong place, or
- AutoSSL sees an empty/wrong root and certificate issuance/renewal fails.

Keep Document Root as the subdomain folder cPanel created. Do not point it at a nested `public/`.

A nested `public/` + rewrite setup can also blank the site when `.htaccess` rewrites every request into `public/` (breaking `.well-known` and often producing HTTP 500 / empty responses on LiteSpeed).

## Deploy steps

1. Leave subdomain Document Root as the folder cPanel created (cPanel → Domains → manage the subdomain — do **not** append `/public`).

2. Upload/clone this repo **into that folder** so you have:

   - `.../<docroot>/index.php`
   - `.../<docroot>/assets/`
   - `.../<docroot>/src/`
   - `.../<docroot>/.htaccess`
   - `.../<docroot>/.well-known/`

3. In cPanel → **Select PHP Version** (or MultiPHP), set this account/domain to **PHP 8.1+** (8.2/8.3 preferred) and enable **pdo_mysql**.

4. Create MySQL database + user; import `schema.sql`.

5. Copy `config.php.example` → `config.php` and set:

   - DB credentials
   - `paths.cpanel_user`, `paths.home`, `paths.acme_home`, `paths.acme_bin`
   - `auth.username`
   - `auth.password_hash` from `php bin/hash-password.php 'your-password'`
   - `base_url` and `timezone` for your instance
   - optional default `alerts.email` / `alerts.from` (From should be a mailbox on this cPanel account)

6. Issue HTTPS for the portal subdomain with webroot:

   ```bash
   acme.sh --issue -d <your-subdomain> -w /home/<user>/<docroot>
   acme.sh --deploy --deploy-hook cpanel_uapi --domain <your-subdomain>
   ```

7. Prefer disabling AutoSSL auto-management for *other* portal-managed domains so drift is meaningful. For the portal subdomain itself, either AutoSSL or the acme flow above is fine as long as webroot = this folder.

## If the site is blank

| Check | Expected |
|-------|----------|
| File Manager shows `index.php` in the subdomain folder | yes |
| Document Root | `…/<docroot>` (no `/public`) |
| Visit site | login form, or a **plain-text** error (missing `config.php`, bad DB, old PHP) |
| PHP version | 8.1+ with `pdo_mysql` |
| Temporary test | `index.html` works = Apache OK; blank only with PHP usually means PHP version / missing config / `.htaccess` 500 |

Remove any leftover nested `public/` directory from older deploys so you are not editing the wrong tree.

## Cron jobs

Keep the existing `acme.sh --cron` entry. Add **two** portal jobs only (worker + alerts). Do **not** add a portal renew-all cron.

Typical cPanel shared-hosting constraints (example: [Namecheap — run scripts via cron](https://www.namecheap.com/support/knowledgebase/article.aspx/9453/2188/how-to-run-a-php-script-via-cron-jobs/)):

- Open **cPanel → Advanced → Cron Jobs**.
- Cron runs on **server time** (not your local timezone).
- Minimum interval on many shared servers is **5 minutes**; some hosts also cap the number of cron jobs.
- Use `/usr/local/bin/php` (not `/usr/bin/php`) for PHP scripts when that is what the host documents.
- End commands with `>/dev/null 2>&1` unless you want an email every run (set the Cron email field only while testing).

### Jobs to register

| Purpose | Schedule | Command |
|---------|----------|---------|
| Job worker | Every 5 minutes (`*/5 * * * *`) | `/usr/local/bin/php /home/<user>/<docroot>/bin/worker.php >/dev/null 2>&1` |
| Alerts | Once per day (e.g. `20 8 * * *`) | `/usr/local/bin/php /home/<user>/<docroot>/bin/alert.php >/dev/null 2>&1` |

### How to add each job in cPanel

1. Sign in to cPanel for the account that hosts the portal.
2. Go to **Advanced → Cron Jobs**.
3. (Optional, for testing) set **Cron Email** to an address you monitor; clear it or keep `>/dev/null 2>&1` once stable.
4. Under **Add New Cron Job**:
   - **Worker:** choose **Every 5 minutes** (or Custom: Minute `*/5`, Hour/Day/Month/Weekday `*`).
   - Paste the worker **Command** from the table above → **Add New Cron Job**.
5. Add the alerts job the same way (daily schedule + alert command).
6. Confirm the existing acme renew cron is still present, for example:

```bash
15 0 * * * "/home/<user>/.acme.sh"/acme.sh --cron --home "/home/<user>/.acme.sh" > /dev/null
```

7. Optional check: in cPanel **Terminal** / SSH, run the same command once manually and confirm it prints `No pending jobs.` or processes a queued job.
8. In the portal **Jobs** page, confirm “Worker last ran …” updates within ~5–15 minutes after the cron is added. If a job stays `pending` with no worker heartbeat, the cron is not installed or the PHP path/command is wrong.
