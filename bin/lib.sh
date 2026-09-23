#!/usr/bin/env bash
# Shared helpers for bin/*.sh and tests/smoke.sh (sourced, not executed).

BP_SYNC_COMPOSE_FILE="docker-compose.yml:docker-compose.sync.yml"

bp_log() { printf '\033[1;34m▸ %s\033[0m\n' "$*"; }
bp_warn() { printf '\033[1;33m! %s\033[0m\n' "$*" >&2; }

# Loads backend/.env into the environment.
bp_load_env() {
  [[ -f .env ]] || { echo "backend/.env not found: run bin/setup.sh" >&2; exit 1; }
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
}

# Sets or replaces KEY=VALUE in backend/.env (empty VALUE removes the key).
bp_set_env() {
  local key="$1" value="${2-}" tmp
  tmp="$(mktemp)"
  grep -Ev "^${key}=" .env >"$tmp" || true
  [[ -n "$value" ]] && printf '%s=%s\n' "$key" "$value" >>"$tmp"
  cat "$tmp" >.env && rm -f "$tmp"
}

bp_is_sync_mode() { [[ "${COMPOSE_FILE-}" == "$BP_SYNC_COMPOSE_FILE" ]]; }

# Can Docker bind-mount this folder? (fails under macOS TCC when the repo lives in ~/Documents).
bp_can_bind_mount() {
  docker run --rm -v "$PWD/bin:/probe:ro" --entrypoint true "wordpress:${WP_CLI_IMAGE_TAG:-cli-2.12-php8.4}" >/dev/null 2>&1
}

# Streams a host directory into a path of the wpcli container (named volumes in sync mode).
bp_push_dir() {
  local src="$1" dst="$2"
  COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -C "$src" -cf - . | docker compose run --rm -T --no-deps --user 0 --entrypoint sh wpcli -c \
    "set -e; mkdir -p '$dst'; find '$dst' -mindepth 1 -delete; tar -xf - -C '$dst'; chmod -R a+rX '$dst'"
}

# Pushes plugin, theme, scripts, seed and test fixtures into the named volumes (no-op with bind mounts).
bp_sync() {
  bp_is_sync_mode || return 0
  bp_push_dir wp-content/plugins/bp-headless /var/www/html/wp-content/plugins/bp-headless
  bp_push_dir wp-content/themes/bp-headless-theme /var/www/html/wp-content/themes/bp-headless-theme
  bp_push_dir bin /opt/bp/bin
  bp_push_dir "${BP_SEED_DIR:-./seed-sample}" /opt/bp/seed
  bp_push_dir tests/fixtures/hook-sink /opt/bp/sink
  docker compose run --rm -T --no-deps --user 0 --entrypoint sh wpcli -c \
    'rm -f /opt/bp/sink/requests.log; chown -R 33:33 /opt/bp/dist /opt/bp/sink'
}

# Copies /opt/bp/dist from the container back to backend/dist (no-op with bind mounts).
bp_pull_dist() {
  bp_is_sync_mode || return 0
  mkdir -p dist
  docker compose run --rm -T --no-deps --entrypoint sh wpcli -c 'tar -C /opt/bp/dist -cf - .' | tar -xf - -C dist
}
