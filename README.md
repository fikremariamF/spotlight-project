# Furniture Marketplace (working name: Betoch Furniture)

A multi-vendor furniture marketplace for Addis Ababa. Customers browse and compare furniture from verified local sellers; sellers apply, get approved, and manage listings; administrators approve sellers, moderate listings and manage categories.

- `backend/` Laravel 13 REST API (PHP 8.3+, MySQL 8 / MariaDB 10.6+, Sanctum token auth)
- `frontend/` React 19 + Vite 8 + Tailwind CSS 4 single-page app
- `docs/ARCHITECTURE.md` schema, relationship diagram, business rules, security notes, roadmap

The brand name is configurable (`MARKETPLACE_NAME` in `backend/.env`). Nothing else in the code depends on it.

## Status

Milestone 1 (foundation and catalog), milestone 2 (in-app inquiries, custom quotes, customer addresses, delivery zones) milestone 3 (cart, checkout with one order per seller, stock reservation, cash on delivery, seller and admin order handling, commission settings) milestone 4 (seller commission balances and settlements, reviews, admin user management, sales reports with CSV export) milestone 5 (in-app, email and SMS alerts for orders, messages, quotes, approvals and payments), a My account page, a refreshed storefront (compare, quick view, recently viewed, similar items, sharing, phone filter sheet), and online payment through Chapa (telebirr, CBE Birr, M-Pesa, bank apps, cards) with refunds and store payouts are implemented. See `docs/ARCHITECTURE.md` for what is done and what comes next. Online payment runs in test mode until real Chapa keys are added. Disputes are **not built yet**.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `gd`, `exif`, `fileinfo`, `intl`
- Composer 2
- Node 22+ and npm
- MySQL 8 or MariaDB 10.6+ (SQLite works for quick local tries and is used by the test suite)

## Quick try with demo data

```bash
./scripts/try-locally.sh     # needs PHP 8.3+, Composer and Node 22+; on a Mac: brew install php composer node
```

On Windows, in PowerShell (Composer is downloaded if missing, and PHP extensions are switched on for this script only):

```powershell
winget install -e --id PHP.PHP.8.3
winget install -e --id OpenJS.NodeJS.LTS
powershell -ExecutionPolicy Bypass -File scripts\try-locally.ps1
```

It uses SQLite (no MySQL needed), adds demo sellers, listings and an admin, builds the site, starts both servers and prints the logins. Open http://localhost:5173 and press Ctrl+C to stop. The site is served as built for production, which loads several times faster than development mode; set `TRY_DEV=1` first (`$env:TRY_DEV=1` in PowerShell) for the development server with instant reload while you edit code. Running it again keeps your data (and redraws old plain demo pictures as furniture). Paying online opens a test payment page where you choose whether the payment goes through; no real money moves. The demo passwords are public, so use it on your own computer only.

## Local setup

```bash
# 1. Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Create an empty database and user, then fill DB_* in .env, for example:
#   CREATE DATABASE marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
php artisan db:seed                       # Addis Ababa sub-cities and furniture categories
php artisan storage:link                  # serves uploaded photos from /storage
php artisan marketplace:create-admin you@example.com --name="Your Name"   # prompts for a password
php artisan db:seed --class=DemoSeeder    # optional sample sellers and listings (refuses to run in production)
php artisan serve                         # http://localhost:8000

# 2. Frontend (new terminal)
cd frontend
cp .env.example .env
npm install
npm run dev                               # http://localhost:5173, proxies /api and /storage to :8000
```

Demo accounts created by `DemoSeeder`: `seller1@demo.local` to `seller4@demo.local` and `customer@demo.local`. The password is `DEMO_USER_PASSWORD` from `.env`, or a random one printed once by the seeder.

## Checks

```bash
cd backend
php artisan test                 # feature and unit tests (SQLite in memory by default)
DB_CONNECTION=mysql DB_DATABASE=marketplace_test DB_USERNAME=... DB_PASSWORD=... php artisan test   # same suite on MySQL
./vendor/bin/pint --test         # code style

cd ../frontend
npm run lint                     # oxlint
npm run build                    # production build into dist/
```

## Deployment notes

- Build the frontend (`npm run build`) and serve `frontend/dist` as static files, with every unknown path falling back to `index.html`.
- Serve the API from the same domain under `/api` (recommended), or set `VITE_API_URL` at build time and list the frontend origin in `CORS_ALLOWED_ORIGINS`.
- Production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL`, strong DB credentials, HTTPS only.
- Run `php artisan migrate --force` and `php artisan optimize` (caches config, routes, events and views) on each deploy.
- Speed: run PHP under PHP-FPM with OPcache on (`opcache.enable=1`, `opcache.memory_consumption=128`, `opcache.max_accelerated_files=20000`; with `opcache.validate_timestamps=0` restart PHP-FPM on each deploy). Turn on gzip or Brotli in the web server for HTML, CSS, JS and JSON, and send `Cache-Control: public, max-age=31536000, immutable` for `frontend/dist/assets/` (file names change on every build) but not for `index.html`.
- Product photos: uploads get a thumbnail (640 px) for cards and lists and a large copy (1600 px) for the product page, WebP when PHP's GD supports it, with location and other photo data removed. Needs the `gd` and `exif` PHP extensions (without `exif`, JPEG photos keep using the original). After the first deploy of this version run `php artisan marketplace:image-variants` once to make copies of photos uploaded earlier; running it again only handles photos still without copies.
- Run a queue worker all the time (for example under systemd or Supervisor): `php artisan queue:work --tries=3 --max-time=3600`. Emails and text messages are sent by the worker; without it they wait in the `jobs` table. In-app alerts do not need it. Restart it on each deploy with `php artisan queue:restart`.
- Email: set `MAIL_MAILER` and the `MAIL_*` settings for your mail provider, and `MAIL_FROM_ADDRESS`. With the default `log` mailer, emails are only written to `storage/logs/laravel.log`.
- Run the scheduler: a cron entry `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1`. It cancels online orders that were not paid in time (every five minutes) and releases their stock. Without it, unpaid orders hold stock until someone cancels them.
- Online payment (Chapa): set `MARKETPLACE_PAYMENT_GATEWAY=chapa`, `CHAPA_SECRET_KEY` and `CHAPA_WEBHOOK_SECRET` in `.env` only, never in the code. A `CHASECK_TEST-...` key runs Chapa's test mode; switch to the live key only after a test payment works end to end. In the Chapa dashboard set the webhook URL to `https://your-domain/api/payments/chapa/webhook` with the same secret. `APP_URL` must be the public HTTPS address, because Chapa sends customers back to `APP_URL/payments/return` and calls `APP_URL/api/payments/chapa/callback`. Admins can turn online payment on or off in Admin > Settings. `MARKETPLACE_PAYMENT_GATEWAY=fake` (a test page in the app) is refused when `APP_ENV=production`.
- Refunds and payouts are recorded by an admin, not sent automatically: send the money in the Chapa dashboard or by transfer, then mark it in Admin > Payments (refunds) or Admin > Settlements (payouts to stores).
- Text messages: `MARKETPLACE_SMS_DRIVER=log` (the default) sends nothing and only records each text in Admin > Text messages. To send for real, set `MARKETPLACE_SMS_DRIVER=afromessage` and `AFROMESSAGE_TOKEN`, `AFROMESSAGE_IDENTIFIER_ID` and `AFROMESSAGE_SENDER` from your AfroMessage account, in `.env` only, never in the code. Admins can pause all texts in Admin > Settings.
- `storage/app/public` holds seller photos: put it on persistent storage (or switch the `public` disk to S3-compatible storage) and include it in backups.

## Backups and recovery

- Database: nightly `mysqldump --single-transaction --routines marketplace | gzip` to off-server storage, keeping at least 14 daily and 8 weekly copies. Test a restore into a scratch database monthly.
- Files: back up `backend/storage/app/public` (product photos) on the same schedule.
- Restore: create an empty database, `gunzip < backup.sql.gz | mysql marketplace`, restore the photo folder, then `php artisan migrate --force` to apply any newer migrations.

## Troubleshooting

- **Images do not load**: run `php artisan storage:link` and make sure `APP_URL` matches the URL you open in the browser.
- **401 after login in production**: the frontend and API are on different origins and `VITE_API_URL` or `CORS_ALLOWED_ORIGINS` is not set.
- **429 Too Many Requests**: login is limited to 5 attempts per minute per email and IP; wait a minute.
- **Online payment option missing at checkout**: `MARKETPLACE_PAYMENT_GATEWAY` is `none` or the Chapa key is empty, the admin switch is off, or a store still has to confirm a delivery fee (customers can choose pickup or pay cash).
- **Paid but the order still says waiting for payment**: open the order page (it checks with Chapa), or look the payment up in Admin > Payments. Check that the webhook URL and secret in the Chapa dashboard match `.env`.
- **Emails or texts never arrive**: check that a queue worker is running and look at `php artisan queue:failed`. Admin > Text messages shows each text with its status and error.
- **"Specified key was too long" on old MySQL**: use MySQL 5.7.7+ / MariaDB 10.2+ with utf8mb4.
