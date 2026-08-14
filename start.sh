#!/usr/bin/env bash
#
# One-command setup for the subscriber API.
#
# Brings up the whole dockerized stack from a clean clone, runs the test suite,
# and prints the URL to open. Safe to re-run at any time.
#
#   ./start.sh              full setup + tests
#   ./start.sh --browser    also install Playwright and run browser tests
#   ./start.sh --stress     also run a load test and watch the DB insert rate
#   ./start.sh --no-tests   skip the test suite
#   ./start.sh --fresh      wipe volumes and start from scratch
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

RUN_TESTS=1
FRESH=0
WITH_BROWSER=0
STRESS=0
# Default kept under the 120/minute per-IP limiter so a plain --stress run
# reports throughput rather than a wall of 429s.
STRESS_REQUESTS=100
for arg in "$@"; do
    case "$arg" in
        --no-tests) RUN_TESTS=0 ;;
        --fresh) FRESH=1 ;;
        --browser) WITH_BROWSER=1 ;;
        --stress) STRESS=1 ;;
        --stress=*) STRESS=1; STRESS_REQUESTS="${arg#*=}" ;;
        -h|--help) sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
    esac
done

if [ -t 1 ]; then
    BOLD=$(printf '\033[1m'); GREEN=$(printf '\033[32m'); YELLOW=$(printf '\033[33m')
    RED=$(printf '\033[31m'); CYAN=$(printf '\033[36m'); RESET=$(printf '\033[0m')
else
    BOLD=""; GREEN=""; YELLOW=""; RED=""; CYAN=""; RESET=""
fi

step()  { printf '\n%s==>%s %s%s\n' "$CYAN" "$RESET" "$BOLD" "$1$RESET"; }
ok()    { printf '    %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
warn()  { printf '    %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()   { printf '\n%sFAILED:%s %s\n' "$RED" "$RESET" "$1" >&2; exit 1; }

env_value() {
    # env_value KEY DEFAULT — read a value from .env, falling back to a default.
    local key="$1" default="${2:-}" value=""
    [ -f .env ] && value="$(grep -E "^${key}=" .env | tail -1 | cut -d= -f2- | tr -d '\r' || true)"
    printf '%s' "${value:-$default}"
}

wait_for() {
    # wait_for DESCRIPTION TIMEOUT_SECONDS COMMAND...
    local what="$1" timeout="$2"; shift 2
    local waited=0
    printf '    waiting for %s' "$what"
    until "$@" >/dev/null 2>&1; do
        [ "$waited" -ge "$timeout" ] && { printf '\n'; return 1; }
        printf '.'
        sleep 3
        waited=$((waited + 3))
    done
    printf '\n'
    ok "$what ready${waited:+ (${waited}s)}"
}

# ── 0. Preflight ─────────────────────────────────────────────────────────────
step 'Checking Docker'
command -v docker >/dev/null 2>&1 || die 'Docker is not installed. Install Docker Desktop first.'
docker info >/dev/null 2>&1 || die 'Docker is installed but not running. Start Docker Desktop and re-run.'
ok 'Docker is running'

if [ "$FRESH" -eq 1 ]; then
    step 'Wiping existing containers and volumes (--fresh)'
    docker compose down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf public/build public/hot
    ok 'Clean slate'
fi

# ── 1. Environment file ──────────────────────────────────────────────────────
step 'Preparing .env'
if [ -f .env ]; then
    ok '.env already exists'
else
    cp .env.example .env
    ok 'Created .env from .env.example'
fi

# public/hot makes Laravel resolve assets from the Vite dev server instead of the
# built manifest. A file left behind by a host-side `npm run dev` points at a
# server that is not running, so every asset 404s. Delete it only when it is
# genuinely unreachable — deleting a live one breaks asset resolution, because
# Vite writes this file when it boots and never while it is already running.
if [ -f public/hot ]; then
    hot_url="$(tr -d '\r\n' < public/hot)"
    if curl -sf -o /dev/null "${hot_url}/@vite/client" 2>/dev/null; then
        ok 'Vite dev server already serving assets'
    else
        rm -f public/hot
        warn 'Removed a stale public/hot (the Vite container will recreate it)'
    fi
fi

# ── 2. PHP dependencies ──────────────────────────────────────────────────────
# Must happen before `docker compose up`: the compose build context is
# ./vendor/laravel/sail/runtimes/8.5, which does not exist until this runs.
step 'Installing PHP dependencies'
if [ -d vendor/laravel/sail ]; then
    ok 'vendor/ already installed'
else
    warn 'This takes a few minutes on first run'
    docker run --rm -v "$(pwd)":/opt -w /opt \
        laravelsail/php84-composer:latest \
        composer install --ignore-platform-reqs --no-interaction \
        || die 'composer install failed'
    ok 'Dependencies installed'
fi

# ── 3. Start the stack ───────────────────────────────────────────────────────
APP_PORT="$(env_value APP_PORT 80)"
VITE_PORT="$(env_value VITE_PORT 5173)"

step "Starting containers (app on port ${APP_PORT})"
docker compose up -d --remove-orphans 2>&1 | grep -viE '^ *(container|network|volume|running)' || true

if ! docker compose ps --services --filter status=running | grep -qx 'laravel.test'; then
    printf '\n'
    docker compose logs laravel.test --tail=15 2>&1 | sed 's/^/    /'
    die "The app container is not running. If the log above says 'port is already allocated', set APP_PORT to a free port in .env and re-run."
fi
ok 'Containers started'

artisan() { docker compose exec -T laravel.test php artisan "$@"; }

# ── 4. Wait for dependencies ─────────────────────────────────────────────────
step 'Waiting for services'
wait_for 'MySQL'  180 bash -c "docker compose ps mysql --format '{{.Status}}' | grep -q healthy" \
    || die 'MySQL never became healthy. Check: docker compose logs mysql'
wait_for 'Redis'  60  bash -c "docker compose ps redis --format '{{.Status}}' | grep -q healthy" \
    || die 'Redis never became healthy. Check: docker compose logs redis'
# The PHP server needs a moment after the container starts before it accepts
# connections; /up is the framework health route and does not depend on Vite.
wait_for 'App HTTP server' 120 curl -sf -o /dev/null "http://localhost:${APP_PORT}/up" \
    || die "The app is not answering on port ${APP_PORT}. Check: docker compose logs laravel.test"
# First boot installs node_modules into a container volume, so allow generous time.
wait_for 'Vite dev server' 420 curl -sf -o /dev/null "http://localhost:${VITE_PORT}/@vite/client" \
    || die "Vite never came up. Check: docker compose logs vite"

# Laravel reads public/hot to locate the dev server. Vite only writes it at boot,
# so if it is missing while Vite is already running, nothing will recreate it and
# every page dies with "Vite manifest not found". Restart Vite to regenerate it.
if [ ! -f public/hot ]; then
    warn 'public/hot is missing — restarting Vite to regenerate it'
    docker compose restart vite >/dev/null 2>&1 || die 'Could not restart the vite service'
    wait_for 'Vite dev server' 420 curl -sf -o /dev/null "http://localhost:${VITE_PORT}/@vite/client" \
        || die 'Vite did not come back up. Check: docker compose logs vite'
    wait_for 'Vite hot file' 60 test -f public/hot \
        || die 'Vite started but never wrote public/hot. Check: docker compose logs vite'
fi

# ── 5. Application key ───────────────────────────────────────────────────────
step 'Application key'
if grep -qE '^APP_KEY=.+' .env; then
    ok 'APP_KEY already set'
else
    artisan key:generate --force >/dev/null || die 'key:generate failed'
    ok 'Generated APP_KEY'
fi

# ── 6. Migrations ────────────────────────────────────────────────────────────
step 'Running migrations'
artisan migrate --force 2>&1 | grep -E 'DONE|Nothing to migrate|INFO' | sed 's/^/    /' || true
ok 'Database ready'

# ── 7. Tests ─────────────────────────────────────────────────────────────────
browser_ready() {
    docker compose exec -T laravel.test sh -c \
        '[ -x node_modules/.bin/playwright ] && ls -d /ms-playwright/chromium-* >/dev/null 2>&1'
}

if [ "$RUN_TESTS" -eq 1 ]; then
    if [ "$WITH_BROWSER" -eq 1 ] && ! browser_ready; then
        step 'Installing Playwright and Chromium'
        warn 'About 1GB, first time only — it is cached in a Docker volume afterwards'
        docker compose exec -T laravel.test sh -c 'npm install playwright@^1.62.1 --no-audit --no-fund' >/dev/null 2>&1 \
            || die 'Could not install the playwright npm package'
        docker compose exec -T laravel.test sh -c './node_modules/.bin/playwright install --with-deps chromium' >/dev/null 2>&1 \
            || die 'Could not install Chromium'
        ok 'Playwright ready'
    fi

    step 'Running tests'
    if browser_ready; then
        # Browser tests run Chromium inside the app container, which cannot reach
        # the vite container, so they render from the built manifest. Rebuild it
        # first or they assert against stale markup.
        docker compose exec -T vite npm run build >/dev/null 2>&1 \
            || warn 'Asset build failed — browser tests may assert against stale assets'
        test_args=''
    else
        test_args='--exclude-testsuite=Browser'
    fi

    if artisan test $test_args 2>&1 | tail -20 | sed 's/^/    /'; then
        ok 'Test suite passed'
    else
        warn 'Some tests failed — see the output above'
    fi

    browser_ready || warn 'Browser tests skipped — run ./start.sh --browser to install Playwright'
fi

# ── 8. Smoke check, then hand over the URL ───────────────────────────────────
step 'Verifying the app responds'
HOME_URL="http://localhost:${APP_PORT}/"
for path in '/' '/subscribers/create'; do
    url="http://localhost:${APP_PORT}${path}"
    status="$(curl -s -o /tmp/subscriber-page.html -w '%{http_code}' "$url")" || status='000'
    [ "$status" = "200" ] || die "Expected HTTP 200 from ${url}, got ${status}. Check: docker compose logs laravel.test"
    if grep -qiE 'ViteException|Unable to locate' /tmp/subscriber-page.html; then
        die "${url} loaded but Vite assets are missing. Check: docker compose logs vite"
    fi
    ok "${path} returns HTTP 200 with assets"
done

# ── 9. Optional load test ────────────────────────────────────────────────────
if [ "$STRESS" -eq 1 ]; then
    step "Load testing the API (${STRESS_REQUESTS} concurrent creations)"
    rate_limit="$(env_value SUBSCRIBERS_RATE_LIMIT_PER_MINUTE 120)"
    if [ "$rate_limit" != '0' ] && [ "$STRESS_REQUESTS" -gt "$rate_limit" ] 2>/dev/null; then
        warn "Sending more requests (${STRESS_REQUESTS}) than the per-IP limit allows (${rate_limit}/min) — expect 429s."
        warn 'Set SUBSCRIBERS_RATE_LIMIT_PER_MINUTE=0 in .env to measure the endpoint instead of the limiter.'
    fi
    # --watch follows the queue draining into MySQL so the insert rate is visible,
    # not just the accept rate.
    artisan subscribers:stress --requests="$STRESS_REQUESTS" --concurrency=20 --duplicates=10 --watch || true
fi

printf '\n%s────────────────────────────────────────────────────────────%s\n' "$GREEN" "$RESET"
printf '  %sReady.%s Open this in your browser:\n\n' "$BOLD" "$RESET"
printf '      %s%s%s\n\n' "$BOLD$CYAN" "$HOME_URL" "$RESET"
printf '  That lists every subscriber. Click "Add subscriber" to create one —\n'
printf '  submit the same email twice and the second attempt is rejected.\n'
printf '\n  Stop with:  ./vendor/bin/sail down\n'
printf '%s────────────────────────────────────────────────────────────%s\n\n' "$GREEN" "$RESET"
