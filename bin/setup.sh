#!/usr/bin/env bash
# Idempotent local setup: .env with random secrets → Docker up → WordPress provisioned → seed imported.
# Usage: bin/setup.sh [--no-seed]
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$BACKEND_DIR"

SKIP_SEED=0
for arg in "$@"; do
  case "$arg" in
    --no-seed) SKIP_SEED=1 ;;
    -h|--help) sed -n '2,3p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

# shellcheck source=lib.sh
source bin/lib.sh
log() { bp_log "$@"; }

command -v docker >/dev/null || { echo "Docker is required" >&2; exit 1; }
command -v openssl >/dev/null || { echo "openssl is required" >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "Docker daemon is not running (open Docker Desktop)" >&2; exit 1; }

# --- 1. backend/.env with random secrets (never overwrites existing values) ---
if [[ ! -f .env ]]; then
  cp .env.example .env
  chmod 600 .env
  log "Created backend/.env from .env.example"
fi

ensure_secret() {
  local key="$1" kind="$2" value tmp
  if grep -Eq "^${key}=.+" .env; then return 0; fi
  case "$kind" in
    hex) value="$(openssl rand -hex 32)" ;;
    password) value="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 28)" ;;
  esac
  tmp="$(mktemp)"
  if grep -Eq "^${key}=" .env; then
    awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k {print k "=" v; next} {print}' .env >"$tmp"
  else
    cat .env >"$tmp"; printf '%s=%s\n' "$key" "$value" >>"$tmp"
  fi
  cat "$tmp" >.env && rm -f "$tmp"
  log "Generated ${key}"
}

ensure_secret DB_PASSWORD password
ensure_secret DB_ROOT_PASSWORD password
ensure_secret WP_ADMIN_PASSWORD password
ensure_secret BP_LEADS_SECRET hex
ensure_secret BP_PREVIEW_SECRET hex

bp_load_env
mkdir -p dist

# --- 2. Mount mode: live bind mounts, or named volumes + bin/sync.sh (macOS TCC on ~/Documents) ---
if bp_can_bind_mount; then
  bp_set_env COMPOSE_FILE ""
  unset COMPOSE_FILE
else
  bp_warn "Docker cannot bind-mount $(pwd) (macOS privacy on ~/Documents). Using sync mode:"
  bp_warn "code is copied into named volumes; run bin/sync.sh after editing the plugin."
  bp_warn "To use live mounts: System Settings → Privacy & Security → Files and Folders → Docker → Documents, then re-run bin/setup.sh."
  bp_set_env COMPOSE_FILE "$BP_SYNC_COMPOSE_FILE"
  export COMPOSE_FILE="$BP_SYNC_COMPOSE_FILE"
fi

# --- 3. Containers ---
log "Starting containers (db, wordpress, cron, mailpit)…"
docker compose up -d --wait db mailpit
bp_sync
docker compose up -d --wait wordpress
docker compose up -d cron

# --- 4. Provision WordPress inside the WP-CLI container ---
log "Provisioning WordPress…"
docker compose run --rm -T \
  -e WP_URL="${WP_URL}" \
  -e WP_TITLE="${WP_TITLE}" \
  -e WP_ADMIN_USER="${WP_ADMIN_USER}" \
  -e WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL}" \
  -e WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD}" \
  -e BP_SKIP_SEED="${SKIP_SEED}" \
  wpcli sh /opt/bp/bin/provision.sh

cat <<EOF

✔ CMS listo
  Admin:      ${WP_URL}/wp-admin/  (usuario: ${WP_ADMIN_USER}; contraseña en backend/.env → WP_ADMIN_PASSWORD)
  API:        ${WP_URL}/wp-json/bp/v1/site
  Front:      ${BP_FRONTEND_URL} (todo el front del CMS redirige 301 aquí)
  Mailpit:    http://localhost:${MAILPIT_PORT:-8025}
  WP-CLI:     docker compose run --rm wpcli wp <comando>
EOF
