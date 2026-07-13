#!/usr/bin/env bash
# Pinakes Docker smoke test — builds the image, brings up the full stack with a
# headless install, and asserts the running container actually serves Pinakes.
# Exits non-zero on the first failed assertion. Safe to run locally or in CI.
#
#   PINAKES_VERSION=0.7.22 tests/docker-smoke.sh
#
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Work against whatever Docker endpoint the active CLI context uses (colima,
# Docker Desktop, or the CI default socket).
if [ -z "${DOCKER_HOST:-}" ]; then
    export DOCKER_HOST="$(docker context inspect --format '{{.Endpoints.docker.Host}}' 2>/dev/null || true)"
fi

PINAKES_VERSION="${PINAKES_VERSION:-0.7.22}"
HTTP_PORT="${HTTP_PORT:-8099}"
PROJECT="pinakes-smoke"
BASE="http://localhost:${HTTP_PORT}"

pass=0; fail=0
ok()   { echo "  ✓ $1"; pass=$((pass+1)); }
ko()   { echo "  ✗ $1"; fail=$((fail+1)); }
check(){ if eval "$2"; then ok "$1"; else ko "$1 ($2)"; fi; }

compose() { docker compose -p "$PROJECT" "$@"; }

cleanup() { echo "── teardown ──"; compose down -v --remove-orphans >/dev/null 2>&1 || true; rm -f "$ROOT/.env.smoke"; }
trap cleanup EXIT

echo "── generating smoke .env ──"
cat > "$ROOT/.env.smoke" <<EOF
PINAKES_VERSION=${PINAKES_VERSION}
HTTP_PORT=${HTTP_PORT}
DB_NAME=pinakes
DB_USER=pinakes
DB_PASS=smokepass123
DB_ROOT_PASS=smokeroot123
APP_ENV=production
APP_LOCALE=it_IT
PLUGIN_ENCRYPTION_KEY=base64:c21va2VrZXlzbW9rZWtleXNtb2tla2V5c21rMTIzNDU2Nzg=
ADMIN_EMAIL=admin@smoke.test
ADMIN_PASSWORD=SmokeAdmin123!
ADMIN_NAME=Smoke
ADMIN_SURNAME=Test
EOF

echo "── build + up (PINAKES_VERSION=${PINAKES_VERSION}) ──"
compose --env-file "$ROOT/.env.smoke" up -d --build || { echo "compose up failed"; exit 1; }

echo "── waiting for app health (max 180s) ──"
healthy=0
for i in $(seq 1 60); do
    status="$(docker inspect --format '{{.State.Health.Status}}' "${PROJECT}-app-1" 2>/dev/null || echo unknown)"
    [ "$status" = "healthy" ] && { healthy=1; break; }
    sleep 3
done
[ "$healthy" = "1" ] && ok "app reached 'healthy'" || { ko "app never became healthy"; compose logs app | tail -40; exit 1; }

echo "── HTTP assertions ──"
code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
check "GET / returns 200/302"            "[[ '$(code $BASE/)' =~ ^(200|302)$ ]]"
check "GET /accedi (login) returns 200"  "[ '$(code $BASE/accedi)' = '200' ]"
check "installer shows 'Already installed'" "curl -s $BASE/installer/ | grep -qi 'già install'"

echo "── PHP runtime assertions ──"
mods="$(compose exec -T app php -m 2>/dev/null)"
for ext in mysqli pdo_mysql mbstring zip gd intl curl openssl fileinfo json; do
    check "php ext: $ext" "echo \"$mods\" | grep -qiE '^${ext}$'"
done
check "php ext: opcache" "echo \"$mods\" | grep -qi 'opcache'"
check "upload_max_filesize=512M" "compose exec -T app php -r 'exit(ini_get(\"upload_max_filesize\")===\"512M\"?0:1);'"
check "display_errors Off"        "compose exec -T app php -r 'exit(filter_var(ini_get(\"display_errors\"),FILTER_VALIDATE_BOOL)?1:0);'"

echo "── Apache assertions ──"
check "mod_rewrite enabled" "compose exec -T app apache2ctl -M 2>/dev/null | grep -q rewrite_module"
check "DocumentRoot = public/" "compose exec -T app sh -c 'grep -hq \"DocumentRoot /var/www/html/public\" /etc/apache2/sites-enabled/*.conf'"

echo "── install assertions (DB) ──"
# Direct queries (no eval/sh-c nesting) so MySQL single-quoted string literals
# don't collide with shell quoting. -N = no column headers.
q() { compose exec -T db mysql -u root -psmokeroot123 pinakes -N -e "$1" 2>/dev/null | tr -d '[:space:]'; }
check ".installed marker present" "compose exec -T app test -f /var/www/html/.installed"

admin_count="$(q "SELECT COUNT(*) FROM utenti WHERE email='admin@smoke.test'")"
[ "${admin_count:-0}" = "1" ] && ok "admin user seeded" || ko "admin user seeded (got '${admin_count}')"

table_count="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='pinakes'")"
[ "${table_count:-0}" -ge 60 ] 2>/dev/null && ok "schema imported (${table_count} tables)" || ko "schema imported (got '${table_count}')"

mobile_active="$(q "SELECT is_active FROM plugins WHERE name='mobile-api'")"
[ "${mobile_active:-0}" = "1" ] && ok "mobile-api plugin active" || ko "mobile-api plugin active (got '${mobile_active}')"

echo "── writable runtime dirs ──"
for d in storage storage/sessions storage/logs storage/cache storage/uploads storage/plugins public/uploads/copertine; do
    check "writable: $d" "compose exec -T app sh -c 'test -w /var/www/html/$d'"
done

echo "── scheduler (supercronic) ──"
check "supercronic binary present" "compose exec -T app test -x /usr/local/bin/supercronic"
check "crontab valid" "compose exec -T app supercronic -test /etc/pinakes/crontab"
check "cron scripts present" "compose exec -T app sh -c 'test -f /var/www/html/cron/automatic-notifications.php && test -f /var/www/html/cron/full-maintenance.php'"
# The scheduler is launched in the background by the entrypoint; assert it is
# actually running, so a regression that fails to start it is caught. Read
# /proc/*/comm directly — no procps (ps/pgrep) needed in the image.
check "supercronic process running" "compose exec -T app sh -c 'for c in /proc/[0-9]*/comm; do grep -qx supercronic \"\$c\" 2>/dev/null && exit 0; done; exit 1'"

echo ""
echo "════════════════════════════════════════"
echo "  PASS=$pass  FAIL=$fail"
echo "════════════════════════════════════════"
[ "$fail" -eq 0 ]
