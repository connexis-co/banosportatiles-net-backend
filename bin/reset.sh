#!/usr/bin/env bash
# Destroys the local stack (containers + DB + uploads volumes) and rebuilds it with bin/setup.sh.
# backend/.env is kept. Usage: bin/reset.sh [--yes] [--down-only]
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$BACKEND_DIR"

ASSUME_YES=0
DOWN_ONLY=0
for arg in "$@"; do
  case "$arg" in
    -y|--yes) ASSUME_YES=1 ;;
    --down-only) DOWN_ONLY=1 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

if [[ "$ASSUME_YES" -ne 1 ]]; then
  read -r -p "This deletes the LOCAL database and uploads (volumes). Continue? [y/N] " answer
  [[ "$answer" =~ ^[yYsS]$ ]] || { echo "Aborted"; exit 1; }
fi

# shellcheck source=lib.sh
source bin/lib.sh
[[ -f .env ]] && bp_load_env
docker compose --profile cli --profile test down --volumes --remove-orphans
rm -f tests/fixtures/hook-sink/requests.log

if [[ "$DOWN_ONLY" -eq 0 ]]; then
  exec "$BACKEND_DIR/bin/setup.sh"
fi
