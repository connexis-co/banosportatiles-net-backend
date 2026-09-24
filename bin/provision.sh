#!/bin/sh
# Runs INSIDE the wpcli container (called by bin/setup.sh). Idempotent.
set -eu
cd /var/www/html

say() { printf '  · %s\n' "$*"; }

# WordPress.org answers 434 to requests whose User-Agent is "WordPress/x; http://localhost:8080",
# so plugins and translations are fetched as public zips with wget and installed from file.
fetch() { wget -q -T 90 -O "$2" "$1"; }

install_plugin() {
  if ! wp plugin is-installed "$1"; then
    fetch "https://downloads.wordpress.org/plugin/$1.latest-stable.zip" "/tmp/$1.zip"
    wp plugin install "/tmp/$1.zip" --quiet
  fi
}

install_translations() {
  set -- "$(wp core version)"
  for plugin in $(wp plugin list --field=name --status=active); do
    set -- "$@" "$plugin:$(wp plugin get "$plugin" --field=version)"
  done
  php /opt/bp/bin/wporg-l10n.php es_CO "$@" | while read -r kind url; do
    dest="wp-content/languages"
    [ "$kind" = "plugins" ] && dest="wp-content/languages/plugins"
    mkdir -p "$dest"
    fetch "$url" /tmp/l10n.zip && unzip -oq /tmp/l10n.zip -d "$dest"
  done
}

i=0
until [ -f wp-config.php ]; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { echo "wp-config.php was not created by the wordpress container" >&2; exit 1; }
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  say "Installing WordPress core"
  wp core install --url="$WP_URL" --title="$WP_TITLE" --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" --admin_email="$WP_ADMIN_EMAIL" --skip-email --quiet
fi
wp option update home "$WP_URL" --quiet
wp option update siteurl "$WP_URL" --quiet

say "Timezone America/Bogota, permalinks /%postname%/"
wp option update timezone_string America/Bogota --quiet
wp option update start_of_week 1 --quiet
wp rewrite structure '/%postname%/' --quiet

say "Reading/discussion settings (noindex host, comments closed)"
wp option update blog_public 0 --quiet
wp option update default_comment_status closed --quiet
wp option update default_ping_status closed --quiet
wp option update users_can_register 0 --quiet

say "Plugins from wordpress.org"
# Integrations of bp-headless: site-reviews (ratings and reviews), seo-by-rank-math (SEO meta read by the API)
# and safe-svg (sanitized SVG uploads for the brand logo). They are activated AFTER bp-headless so their
# installers see the "equipo" post type.
INTEGRATIONS="site-reviews seo-by-rank-math safe-svg"
for plugin in secure-custom-fields redirection wp-nested-pages two-factor $INTEGRATIONS; do
  install_plugin "$plugin"
done
wp plugin activate secure-custom-fields redirection wp-nested-pages two-factor --quiet
for plugin in akismet hello; do
  if wp plugin is-installed "$plugin"; then wp plugin delete "$plugin" --quiet; fi
done

say "Headless theme + bp-headless plugin"
wp theme activate bp-headless-theme --quiet
for theme in $(wp theme list --status=inactive --field=name); do
  wp theme delete "$theme" --quiet
done
wp plugin activate bp-headless bp-sitio-en-venta --quiet

say "Site Reviews, Rank Math and Safe SVG"
# shellcheck disable=SC2086 # word splitting is intended
wp plugin activate $INTEGRATIONS --quiet

say "Redirection database"
wp redirection database install >/dev/null 2>&1 || true

say "Translations (es_CO)"
[ -f wp-content/languages/es_CO.mo ] || install_translations
wp site switch-language es_CO --quiet

say "Removing sample content"
for slug in hello-world sample-page privacy-policy; do
  ids=$(wp post list --post_type=post,page --post_status=any --name="$slug" --format=ids)
  if [ -n "$ids" ]; then wp post delete $ids --force --quiet; fi
done
comments=$(wp comment list --format=ids)
if [ -n "$comments" ]; then wp comment delete $comments --force --quiet; fi
wp option update wp_page_for_privacy_policy 0 --quiet

if [ "${BP_SKIP_SEED:-0}" != "1" ] && [ -f /opt/bp/seed/bundle.json ]; then
  say "Importing seed bundle"
  if [ -d /opt/bp/seed/assets ]; then
    wp bp import-seed /opt/bp/seed/bundle.json --assets=/opt/bp/seed/assets --no-deploy
  else
    wp bp import-seed /opt/bp/seed/bundle.json --no-deploy
  fi
fi

wp rewrite flush --quiet
wp bp cache flush --quiet
say "Done"
