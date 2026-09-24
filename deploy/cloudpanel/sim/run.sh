#!/usr/bin/env bash
# End-to-end test of deploy/cloudpanel/deploy.sh against a simulated CloudPanel server (Docker):
# Debian + PHP 8.4 + a fake clpctl, sharing the network of the local MariaDB container (127.0.0.1:3306),
# reached through a fake ssh (docker exec). Runs a fresh deploy and an idempotent one, checks the result
# and cleans up (KEEP=1 keeps the simulator for inspection). Requires the local stack (bin/setup.sh).
# Usage: deploy/cloudpanel/sim/run.sh
set -euo pipefail

SIM_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
BACKEND_DIR="$(cd "$SIM_DIR/../../.." && pwd -P)"
cd "$BACKEND_DIR"
# shellcheck source=../../../bin/lib.sh
source bin/lib.sh
bp_load_env

DB_CONTAINER="$(docker compose ps -q db)"
[[ -n "$DB_CONTAINER" ]] || { echo "La base de datos local no está corriendo: ejecuta bin/setup.sh" >&2; exit 1; }
WORK="$(mktemp -d)"
ENV_FILE="$WORK/sim.env.deploy"
DOCROOT=/home/bp-sim-cms/htdocs/admin.banosportatiles.net
PASS=0
FAIL=0
check() { if eval "$2" >/dev/null 2>&1; then PASS=$((PASS + 1)); printf '  \033[32m✔\033[0m %s\n' "$1"; else FAIL=$((FAIL + 1)); printf '  \033[31m✘\033[0m %s\n' "$1"; fi; }
simwp() { docker exec -i bp-sim runuser -u bp-sim-cms -- env HOME=/home/bp-sim-cms php8.4 -d memory_limit=512M /usr/local/bin/wp --path="$DOCROOT" "$@" 2>/dev/null; }
admin_password_ok() {
  local pw
  pw="$(grep '^WP_ADMIN_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)"
  [[ "$(docker exec -i bp-sim runuser -u bp-sim-cms -- env HOME=/home/bp-sim-cms PW="$pw" php8.4 /usr/local/bin/wp --path="$DOCROOT" \
    eval 'echo wp_check_password(getenv("PW"), get_user_by("login", "bp-admin")->user_pass) ? "ok" : "no";' 2>/dev/null)" == "ok" ]]
}
venta_same_object() {
  [[ "$(simwp eval '$a = rest_do_request(new WP_REST_Request("GET", "/bp-venta/v1/config"))->get_data(); $b = rest_do_request(new WP_REST_Request("GET", "/bp/v1/site"))->get_data()["sale_banner"] ?? null; echo $a === $b ? "same" : "diff";')" == "same" ]]
}
sql() { docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mariadb -uroot -e "$1" >/dev/null; }

reset_sim() {
  docker rm -f bp-sim >/dev/null 2>&1 || true
  sql "DROP DATABASE IF EXISTS \`bp-sim-cms\`; DROP USER IF EXISTS 'bp-sim-cms'@'%';" || true
}
cleanup() {
  if [[ "${KEEP:-0}" == "1" ]]; then echo "KEEP=1: simulador y $WORK conservados"; return; fi
  reset_sim
  rm -rf "$WORK"
}
trap cleanup EXIT

bp_log "Imagen del servidor simulado"
docker build -q -t bp-cloudpanel-sim "$SIM_DIR" >/dev/null
reset_sim
docker run -d --name bp-sim --network "container:$DB_CONTAINER" -e CLP_SIM_DB_ROOT="$DB_ROOT_PASSWORD" bp-cloudpanel-sim >/dev/null
printf 'SSH_HOST=bp-sim\nSERVER_IP=127.0.0.1\nSITE_USER=bp-sim-cms\nDB_NAME=bp-sim-cms\nDB_USER=bp-sim-cms\nBP_SMTP_USER=smtp-login@example.com\nBP_SMTP_PASS="k\x27ey\\\\with\$chars"\n' >"$ENV_FILE"
chmod 600 "$ENV_FILE"

for run in 1 2; do
  bp_log "Deploy #$run"
  SSH_BIN="$SIM_DIR/fake-ssh" deploy/cloudpanel/deploy.sh --env-file="$ENV_FILE" --seed=seed-sample >"$WORK/run$run.log" 2>&1 \
    || { tail -30 "$WORK/run$run.log"; exit 1; }
done

bp_log "Comprobaciones"
check "segunda pasada idempotente (sitio, BD y WordPress ya existían)" "[[ \$(grep -c 'ya existe' '$WORK/run2.log') -ge 3 ]] && grep -q 'WordPress ya estaba instalado' '$WORK/run2.log'"
check "seed idempotente (0 creados, 0 actualizados)" "grep -q '0 creados, 0 actualizados' '$WORK/run2.log'"
check "vhost creado con la plantilla BP-Headless-WordPress-v1" "docker exec bp-sim grep -q 'bp-headless' /etc/nginx/sites-enabled/admin.banosportatiles.net.conf"
check "wp-config.php 640 del usuario del sitio" "[[ \$(docker exec bp-sim stat -c '%U %a' $DOCROOT/wp-config.php) == 'bp-sim-cms 640' ]]"
check "constantes BP_* y SMTP en wp-config" "[[ \$(docker exec bp-sim grep -cE \"define\\( '(BP_FRONTEND_URL|BP_LEADS_SECRET|BP_LEADS_EMAIL_TO|BP_SMTP_PASS|BP_SMTP_FROM_NAME)'\" $DOCROOT/wp-config.php) == 5 ]]"
check "contraseña SMTP con comillas y \$ intacta" "[[ \$(simwp eval 'echo BP_SMTP_PASS;') == \"k'ey\\\\with\\\$chars\" ]]"
check "contraseña del admin = WP_ADMIN_PASSWORD de .env.deploy" admin_password_ok
check "plugins activos (SCF, Redirection, Nested Pages, Two Factor, Site Reviews, Rank Math, Safe SVG, bp-headless, bp-sitio-en-venta)" "[[ \$(simwp plugin list --status=active --field=name | sort | tr '\\n' ' ') == 'bp-headless bp-sitio-en-venta redirection safe-svg secure-custom-fields seo-by-rank-math site-reviews two-factor wp-nested-pages ' ]]"
check "tema bp-headless-theme, es_CO, America/Bogota, noindex, /%postname%/" "[[ \$(simwp eval 'echo get_stylesheet(), \"|\", get_locale(), \"|\", get_option(\"timezone_string\"), \"|\", get_option(\"blog_public\"), \"|\", get_option(\"permalink_structure\");') == 'bp-headless-theme|es_CO|America/Bogota|0|/%postname%/' ]]"
check "sin contenido de ejemplo" "[[ -z \$(simwp post list --post_type=post,page --name=hola-mundo --format=ids) ]]"
check "API bp/v1/site responde" "[[ \$(simwp eval 'echo rest_do_request(new WP_REST_Request(\"GET\", \"/bp/v1/site\"))->get_status();') == 200 ]]"
check "bp-venta/v1/config es el mismo objeto que /site → sale_banner" venta_same_object
check "cron real en el crontab del usuario del sitio" "docker exec bp-sim crontab -u bp-sim-cms -l | grep -q 'cron event run --due-now'"
check "certificado solicitado con clpctl lets-encrypt:install:certificate" "docker exec bp-sim grep -q 'lets-encrypt:install:certificate' /var/lib/clp-sim/calls.log"

printf '\n\033[1mSimulación CloudPanel: %d OK, %d fallos\033[0m\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
