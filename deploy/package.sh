#!/usr/bin/env bash
# Builds the migration artefacts into backend/dist/ (nothing is uploaded anywhere):
#   bp-headless-<version>.zip          plugin (runtime files only, no Composer)
#   bp-headless-theme-<version>.zip    placeholder theme
#   export-<stamp>/db.sql.gz           DB dump with the local URL rewritten to the production CMS URL
#   export-<stamp>/uploads.tar.gz      wp-content/uploads
#   export-<stamp>/plugins.csv, wp-version.txt, SHA256SUMS
# Usage: deploy/package.sh [--url=https://admin.banosportatiles.net] [--no-export]
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
# shellcheck source=../bin/lib.sh
source bin/lib.sh

TARGET_URL="https://admin.banosportatiles.net"
EXPORT=1
for arg in "$@"; do
  case "$arg" in
    --url=*) TARGET_URL="${arg#--url=}" ;;
    --no-export) EXPORT=0 ;;
    -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

VERSION="$(sed -n 's/^ \* Version: *//p' wp-content/plugins/bp-headless/bp-headless.php | head -1 | tr -d '[:space:]')"
mkdir -p dist

bp_log "Plugin y tema v${VERSION}"
rm -f "dist/bp-headless-${VERSION}.zip" "dist/bp-headless-theme-${VERSION}.zip" dist/bp-sitio-en-venta-*.zip
(cd wp-content/plugins && COPYFILE_DISABLE=1 zip -rqX "../../dist/bp-headless-${VERSION}.zip" bp-headless -x '*.DS_Store')
VENTA_VERSION="$(sed -n 's/^ \* Version: *//p' wp-content/plugins/bp-sitio-en-venta/bp-sitio-en-venta.php | head -1 | tr -d '[:space:]')"
(cd wp-content/plugins && COPYFILE_DISABLE=1 zip -rqX "../../dist/bp-sitio-en-venta-${VENTA_VERSION}.zip" bp-sitio-en-venta -x '*.DS_Store')
(cd wp-content/themes && COPYFILE_DISABLE=1 zip -rqX "../../dist/bp-headless-theme-${VERSION}.zip" bp-headless-theme -x '*.DS_Store')

if [[ "$EXPORT" -eq 1 ]]; then
  bp_load_env
  STAMP="$(date +%Y%m%d-%H%M%S)"
  OUT="/opt/bp/dist/export-${STAMP}"
  bp_log "Export de BD + uploads (${WP_URL} → ${TARGET_URL})"
  bp_sync >/dev/null 2>&1
  docker compose run --rm -T -e SOURCE_URL="$WP_URL" -e TARGET_URL="$TARGET_URL" -e OUT="$OUT" wpcli sh -c '
    set -e
    mkdir -p "$OUT"
    wp transient delete --all --quiet
    wp search-replace "$SOURCE_URL" "$TARGET_URL" --all-tables --precise --export="$OUT/db.sql" --quiet
    gzip -9 "$OUT/db.sql"
    tar -C wp-content -czf "$OUT/uploads.tar.gz" uploads
    wp plugin list --fields=name,status,version --format=csv > "$OUT/plugins.csv"
    wp core version > "$OUT/wp-version.txt"
  '
  bp_pull_dist
  (cd "dist/export-${STAMP}" && shasum -a 256 db.sql.gz uploads.tar.gz > SHA256SUMS)
  bp_log "Export listo: backend/dist/export-${STAMP}/"
fi

(cd dist && shasum -a 256 "bp-headless-${VERSION}.zip" "bp-headless-theme-${VERSION}.zip" "bp-sitio-en-venta-${VENTA_VERSION}.zip")
bp_log "Artefactos en backend/dist/ (ver deploy/README.md para el runbook)"
