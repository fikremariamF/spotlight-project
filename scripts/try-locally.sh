#!/usr/bin/env bash
# Run the marketplace on your own computer for a quick try, with demo data.
#
#   ./scripts/try-locally.sh
#
# Uses SQLite (backend/database/database.sqlite), so no MySQL is needed. Safe to run again:
# an existing backend/.env, frontend/.env or database is kept, and demo data is only added once.
# Needs PHP 8.3+, Composer 2 and Node 22+. On a Mac: brew install php composer node
# Stop with Ctrl+C. Local use only: the demo passwords below are public.
set -euo pipefail

API_PORT="${API_PORT:-8000}"
WEB_PORT="${WEB_PORT:-5173}"
DEMO_PASSWORD="Demo-pass-123"
ADMIN_EMAIL="admin@demo.local"
ADMIN_PASSWORD="Admin-pass-123!"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

fail() { echo "Error: $*" >&2; exit 1; }
step() { printf '\n==> %s\n' "$*"; }

step "Checking tools"
missing=""
for tool in php composer node npm; do
  command -v "$tool" >/dev/null 2>&1 || missing="$missing $tool"
done
if [ -n "$missing" ]; then
  fail "missing:$missing. On a Mac install them with: brew install php composer node"
fi
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || fail "PHP 8.3 or newer is needed (found $(php -r 'echo PHP_VERSION;')). Run: brew upgrade php"
for ext in pdo_sqlite mbstring fileinfo gd; do
  php -r "exit(extension_loaded('$ext') ? 0 : 1);" || fail "the PHP extension '$ext' is not enabled"
done
node -e 'const [a,b]=process.versions.node.split(".").map(Number); process.exit(a>22||(a===22&&b>=12)||(a===20&&b>=19)?0:1)' \
  || fail "Node 22.12 or newer is needed (found $(node -v)). Run: brew upgrade node"
for port in "$API_PORT" "$WEB_PORT"; do
  if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
    fail "port $port is already in use. Stop whatever runs there, or set API_PORT / WEB_PORT."
  fi
done

step "Backend: installing PHP packages"
cd "$BACKEND"
composer install --no-interaction --no-progress

if [ ! -f .env ]; then
  step "Backend: writing .env for SQLite"
  # Switch the example from MySQL to SQLite and use the local demo password.
  perl -pe '
    s/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/;
    s/^(DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=)/# $1/;
    s/^DEMO_USER_PASSWORD=.*/DEMO_USER_PASSWORD='"$DEMO_PASSWORD"'/;
    s{^APP_URL=.*}{APP_URL=http://localhost:'"$WEB_PORT"'};
    s{^CORS_ALLOWED_ORIGINS=.*}{CORS_ALLOWED_ORIGINS=http://localhost:'"$WEB_PORT"'};
    s/^# PHP_CLI_SERVER_WORKERS=.*/PHP_CLI_SERVER_WORKERS=4/;
    s/^MARKETPLACE_PAYMENT_GATEWAY=.*/MARKETPLACE_PAYMENT_GATEWAY=fake/;
  ' .env.example > .env
  php artisan key:generate --no-interaction
else
  echo "Keeping your existing backend/.env"
fi

DB_KIND="$(php artisan tinker --execute='echo config("database.default");' 2>/dev/null | tail -n 1)"
if [ "$DB_KIND" = "sqlite" ]; then
  DB_FILE="$(php artisan tinker --execute='echo config("database.connections.sqlite.database");' 2>/dev/null | tail -n 1)"
  [ -f "$DB_FILE" ] || touch "$DB_FILE"
fi

step "Backend: creating tables"
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
php artisan storage:link --no-interaction >/dev/null 2>&1 || true

user_exists() {
  php artisan tinker --execute="echo \App\Models\User::where('email', '$1')->exists() ? 'yes' : 'no';" 2>/dev/null | tail -n 1
}
if [ "$(user_exists customer@demo.local)" != "yes" ]; then
  step "Backend: adding demo sellers and listings"
  DEMO_USER_PASSWORD="$DEMO_PASSWORD" php artisan db:seed --class=DemoSeeder --no-interaction
else
  echo "Demo sellers and listings already there"
  # Older demo data has plain colored pictures; swap them for drawn furniture (demo pictures only).
  php artisan marketplace:demo-pictures --no-interaction
fi
if [ "$(user_exists "$ADMIN_EMAIL")" != "yes" ]; then
  step "Backend: adding the demo admin"
  printf '%s\n' "$ADMIN_PASSWORD" | php artisan marketplace:create-admin "$ADMIN_EMAIL" --name="Demo Admin"
fi

step "Frontend: installing packages"
cd "$FRONTEND"
if [ ! -f .env ]; then
  perl -pe 's{^VITE_DEV_API_TARGET=.*}{VITE_DEV_API_TARGET=http://127.0.0.1:'"$API_PORT"'}' .env.example > .env
fi
npm install --no-fund --no-audit

step "Starting"
cd "$BACKEND"
# Online payment uses the test payment page locally, unless your .env picks a gateway.
grep -q '^MARKETPLACE_PAYMENT_GATEWAY=' .env || export MARKETPLACE_PAYMENT_GATEWAY=fake
php artisan serve --port="$API_PORT" >"$BACKEND/storage/logs/serve.log" 2>&1 &
API_PID=$!
# Sends queued email and SMS alerts (both only logged locally: MAIL_MAILER and the SMS driver are "log").
php artisan queue:work --sleep=1 --tries=3 >"$BACKEND/storage/logs/queue.log" 2>&1 &
QUEUE_PID=$!
# Cancels online orders that were not paid in time and releases their stock.
php artisan schedule:work >"$BACKEND/storage/logs/schedule.log" 2>&1 &
SCHEDULE_PID=$!
trap 'kill "$API_PID" "$QUEUE_PID" "$SCHEDULE_PID" 2>/dev/null || true' EXIT INT TERM

cat <<EOF

  Open http://localhost:$WEB_PORT

  Customer   customer@demo.local      $DEMO_PASSWORD
  Sellers    seller1@demo.local to seller4@demo.local   $DEMO_PASSWORD
  Admin      $ADMIN_EMAIL         $ADMIN_PASSWORD

  Emails are written to backend/storage/logs/laravel.log; text messages show in Admin > Text messages.
  Paying online opens a test payment page: no real money moves.
  API log: backend/storage/logs/serve.log. Press Ctrl+C to stop.

EOF

cd "$FRONTEND"
npm run dev -- --port "$WEB_PORT" --strictPort
