#!/usr/bin/env bash
# Pushes local code (plugin, theme, scripts, seed) into the containers when running in sync mode.
# With live bind mounts this is a no-op. Usage: bin/sync.sh
set -euo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
# shellcheck source=lib.sh
source bin/lib.sh
bp_load_env
if bp_is_sync_mode; then
  bp_sync
  docker compose run --rm -T wpcli wp bp cache flush --quiet 2>/dev/null || true
  bp_log "Code synced into the containers"
else
  bp_log "Live bind mounts in use: nothing to sync"
fi
