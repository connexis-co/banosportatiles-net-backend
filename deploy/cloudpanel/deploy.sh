#!/usr/bin/env bash
# Idempotent deploy of the headless CMS (WordPress + bp-headless) to a CloudPanel v2 server over SSH.
#
#   deploy/cloudpanel/deploy.sh --dry-run      # prints every command (secrets masked); touches nothing
#   deploy/cloudpanel/deploy.sh                # deploys to $SSH_HOST (default: connexis-prod)
#   deploy/cloudpanel/deploy.sh --seed=<dir>   # also imports <dir>/bundle.json (+ <dir>/assets)
#   deploy/cloudpanel/deploy.sh --skip-cert    # skips Let's Encrypt (e.g. DNS not ready yet)
#
# Configuration and secrets: backend/.env.deploy (gitignored, chmod 600). It is created on the first real
# run from deploy/cloudpanel/deploy.env.example, with random passwords/secrets. Nothing is written to the repo.
# SSH_BIN (default: ssh) selects the SSH client used by ssh and rsync (e.g. a wrapper with extra options).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/../.." && pwd -P)"
ENV_FILE="$BACKEND_DIR/.env.deploy"
DRY_RUN=0
OPT_SKIP_CERT=0
SEED_DIR=""

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --skip-cert) OPT_SKIP_CERT=1 ;;
    --seed=*) SEED_DIR="${arg#--seed=}" ;;
    --env-file=*) ENV_FILE="${arg#--env-file=}" ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $arg (usa --help)" >&2; exit 2 ;;
  esac
done

log() { printf '\033[1;34m▸ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

SECRETS=(SITE_USER_PASSWORD DB_PASSWORD WP_ADMIN_PASSWORD BP_LEADS_SECRET BP_PREVIEW_SECRET)
PUBLIC_VARS=(CMS_DOMAIN FRONTEND_URL PHP_VERSION SITE_USER DB_NAME DB_USER VHOST_TEMPLATE WP_TITLE WP_ADMIN_USER
  WP_ADMIN_EMAIL WP_LOCALE WP_TIMEZONE WP_TABLE_PREFIX BP_LEADS_EMAIL BP_LEADS_EMAIL_TO BP_LEADS_EMAIL_CC BP_DEPLOY_HOOK_URL BP_CORS_ORIGINS
  BP_LEADS_WEBHOOK_URL BP_SMTP_HOST BP_SMTP_PORT BP_SMTP_USER BP_SMTP_FROM BP_SMTP_FROM_NAME SKIP_CERT STAGE_DIR)

# --- 1. Configuration: ONLY backend/.env.deploy → defaults -------------------------------------------
# Same-named variables of the calling shell are discarded on purpose: backend/.env (local Docker) uses
# DB_PASSWORD, WP_ADMIN_PASSWORD, BP_LEADS_SECRET and BP_SMTP_* too, and must never reach production.
for var in SSH_HOST SERVER_IP "${PUBLIC_VARS[@]}" "${SECRETS[@]}" BP_SMTP_PASS; do
  unset "$var"
done
SKIP_CERT="$OPT_SKIP_CERT"
if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi
: "${SSH_HOST:=connexis-prod}"
: "${SERVER_IP:=95.216.153.73}"
: "${CMS_DOMAIN:=admin.banosportatiles.net}"
: "${FRONTEND_URL:=https://banosportatiles.net}"
: "${PHP_VERSION:=8.4}"
: "${SITE_USER:=banosportatiles-cms}"
: "${DB_NAME:=banosportatiles_cms}"
: "${DB_USER:=banosportatiles_cms}"
: "${VHOST_TEMPLATE:=BP-Headless-WordPress-v1}"
: "${WP_TITLE:=BañosPortátiles.net CMS}"
: "${WP_ADMIN_USER:=bp-admin}"
: "${WP_ADMIN_EMAIL:=contacto@banosportatiles.net}"
: "${WP_LOCALE:=es_CO}"
: "${WP_TIMEZONE:=America/Bogota}"
: "${WP_TABLE_PREFIX:=wp_}"
: "${BP_LEADS_EMAIL:=true}"
: "${BP_LEADS_EMAIL_TO:=contacto@banosportatiles.net}"
: "${BP_LEADS_EMAIL_CC:=connexis.co@gmail.com}"
: "${BP_SMTP_HOST:=smtp-relay.brevo.com}"
: "${BP_SMTP_PORT:=587}"
: "${BP_SMTP_FROM:=no-reply@banosportatiles.net}"
: "${BP_SMTP_FROM_NAME:=BañosPortátiles.net}"
for var in BP_DEPLOY_HOOK_URL BP_CORS_ORIGINS BP_LEADS_WEBHOOK_URL BP_SMTP_USER BP_SMTP_PASS "${SECRETS[@]}"; do
  printf -v "$var" '%s' "${!var:-}"
done
STAGE_REL="bp-deploy/$CMS_DOMAIN"
STAGE_DIR="~/$STAGE_REL" # replaced by the absolute remote path once connected

set_env_value() { # set_env_value KEY VALUE → backend/.env.deploy (replaces or appends; never echoed)
  local key="$1" value="$2" tmp
  tmp="$(mktemp)"
  grep -Ev "^${key}=" "$ENV_FILE" >"$tmp" || true
  printf '%s=%s\n' "$key" "$value" >>"$tmp"
  cat "$tmp" >"$ENV_FILE" && rm -f "$tmp"
}
generate() { # generate hex|password
  if [[ "$1" == "hex" ]]; then openssl rand -hex 32; else openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 32; fi
}

for var in "${SECRETS[@]}"; do
  [[ -n "${!var}" ]] && continue
  if [[ "$DRY_RUN" == "1" ]]; then
    printf -v "$var" '%s' "<se genera al desplegar>"
    continue
  fi
  if [[ ! -f "$ENV_FILE" ]]; then
    cp "$SCRIPT_DIR/deploy.env.example" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    log "Creado $ENV_FILE (gitignored). Completa BP_SMTP_USER/BP_SMTP_PASS de Brevo."
  fi
  case "$var" in
    BP_*_SECRET) printf -v "$var" '%s' "$(generate hex)" ;;
    *) printf -v "$var" '%s' "$(generate password)" ;;
  esac
  set_env_value "$var" "${!var}"
  log "Generado $var (guardado en $(basename "$ENV_FILE"))"
done

# --- 2. Validation -----------------------------------------------------------------------------------
[[ "$CMS_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "CMS_DOMAIN inválido: $CMS_DOMAIN"
[[ "$SITE_USER" =~ ^[a-z][a-z0-9-]{2,31}$ ]] || die "SITE_USER inválido (minúsculas, dígitos y guiones, 3–32): $SITE_USER"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ && "$DB_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die "DB_NAME/DB_USER inválidos."
[[ "$PHP_VERSION" =~ ^8\.[3-9]$ ]] || die "PHP_VERSION debe ser 8.3 o superior: $PHP_VERSION"
[[ "$BP_LEADS_EMAIL" == "true" || "$BP_LEADS_EMAIL" == "false" ]] || die "BP_LEADS_EMAIL debe ser true o false."
[[ "$FRONTEND_URL" =~ ^https://[^/]+$ ]] || die "FRONTEND_URL debe ser https://dominio sin barra final."
[[ -f "$BACKEND_DIR/wp-content/plugins/bp-headless/bp-headless.php" ]] || die "No se encuentra el plugin bp-headless."
[[ -f "$BACKEND_DIR/wp-content/themes/bp-headless-theme/style.css" ]] || die "No se encuentra el tema bp-headless-theme."
if [[ -n "$SEED_DIR" ]]; then
  SEED_DIR="$(cd "$SEED_DIR" 2>/dev/null && pwd -P)" || die "--seed: la carpeta no existe."
  [[ -f "$SEED_DIR/bundle.json" ]] || die "--seed: falta $SEED_DIR/bundle.json."
fi
if [[ -z "$BP_SMTP_USER" || -z "$BP_SMTP_PASS" ]]; then
  warn "Sin BP_SMTP_USER/BP_SMTP_PASS (Brevo) en $(basename "$ENV_FILE"): el CMS no tendrá SMTP hasta completarlos y volver a desplegar."
fi
if command -v dig >/dev/null; then
  resolved="$(dig +short A "$CMS_DOMAIN" 2>/dev/null | tr '\n' ' ' | sed 's/ $//')"
  if [[ -z "$resolved" ]]; then
    warn "$CMS_DOMAIN no resuelve: crea el registro A → $SERVER_IP (sin proxy) antes del certificado, o usa --skip-cert."
  elif [[ " $resolved " != *" $SERVER_IP "* ]]; then
    log "$CMS_DOMAIN resuelve a $resolved (proxy de Cloudflare activo o IP distinta de $SERVER_IP)."
  fi
fi

# --- 3. Remote payload ----------------------------------------------------------------------------------
remote_payload() { # remote_payload <mask 0|1>: exported configuration + remote.sh
  local var value
  printf '# --- configuration generated by deploy.sh ---\n'
  for var in "${PUBLIC_VARS[@]}"; do
    printf 'export %s=%q\n' "$var" "${!var}"
  done
  for var in "${SECRETS[@]}" BP_SMTP_PASS; do
    value="${!var}"
    [[ "$1" == "1" && -n "$value" ]] && value="********"
    printf 'export %s=%q\n' "$var" "$value"
  done
  printf '# --- deploy/cloudpanel/remote.sh ---\n'
  cat "$SCRIPT_DIR/remote.sh"
}

SSH_BIN="${SSH_BIN:-ssh}"
RSYNC=(rsync -az --delete -e "$SSH_BIN" --exclude=.DS_Store --exclude='._*')
SOURCES=("$BACKEND_DIR/wp-content/plugins/bp-headless" "$BACKEND_DIR/wp-content/themes/bp-headless-theme" "$SCRIPT_DIR/bp-headless-wordpress.tpl")

if [[ "$DRY_RUN" == "1" ]]; then
  printf '\033[1m# deploy/cloudpanel/deploy.sh --dry-run → %s (%s). No se ejecuta nada.\033[0m\n\n' "$SSH_HOST" "$CMS_DOMAIN"
  printf "ssh %s 'mkdir -p %s && echo \$(id -u) \$HOME'\n" "$SSH_HOST" "$STAGE_REL"
  printf '%q ' "${RSYNC[@]}" "${SOURCES[@]}" "$SSH_HOST:$STAGE_REL/"
  printf '\n'
  if [[ -n "$SEED_DIR" ]]; then
    printf '%q ' "${RSYNC[@]}" "$SEED_DIR/" "$SSH_HOST:$STAGE_REL/seed/"
    printf '\n'
  fi
  printf "ssh %q 'bash -s' <<'REMOTE'    # 'sudo -n bash -s' si el usuario SSH no es root\n" "$SSH_HOST"
  remote_payload 1
  printf 'REMOTE\n'
  exit 0
fi

# --- 4. Deploy ----------------------------------------------------------------------------------------------
command -v "$SSH_BIN" >/dev/null && command -v rsync >/dev/null || die "Se necesitan $SSH_BIN y rsync."
log "Conectando con $SSH_HOST"
remote_info="$("$SSH_BIN" -o BatchMode=yes "$SSH_HOST" "mkdir -p $STAGE_REL && printf '%s %s' \"\$(id -u)\" \"\$HOME\"")" || die "No se pudo conectar por SSH con $SSH_HOST."
remote_uid="${remote_info%% *}"
STAGE_DIR="${remote_info#* }/$STAGE_REL"
remote_shell="bash -s"
[[ "$remote_uid" == "0" ]] || remote_shell="sudo -n bash -s"

log "Subiendo plugin, tema y plantilla de vhost (rsync → ~/$STAGE_REL)"
"${RSYNC[@]}" "${SOURCES[@]}" "$SSH_HOST:$STAGE_REL/"
if [[ -n "$SEED_DIR" ]]; then
  log "Subiendo seed ($SEED_DIR)"
  "${RSYNC[@]}" "$SEED_DIR/" "$SSH_HOST:$STAGE_REL/seed/"
fi

log "Ejecutando el deploy en el servidor ($remote_shell)"
remote_payload 0 | "$SSH_BIN" -o BatchMode=yes "$SSH_HOST" "$remote_shell"

cat <<EOF

Siguiente:
  · Worker de Astro: WP_API_URL=https://$CMS_DOMAIN/wp-json/bp/v1 y el secreto WP_LEADS_SECRET = BP_LEADS_SECRET de $(basename "$ENV_FILE")
    (wrangler secret put WP_LEADS_SECRET).
  · Admin: https://$CMS_DOMAIN/wp-admin/ (usuario $WP_ADMIN_USER; contraseña en $(basename "$ENV_FILE")). Activa Two Factor.
EOF
