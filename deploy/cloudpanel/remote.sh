# shellcheck shell=bash
# Runs ON the CloudPanel v2 server, as root, streamed by deploy/cloudpanel/deploy.sh (ssh … bash -s).
# deploy.sh prepends the configuration as exported variables. Idempotent: safe to run again.
set -euo pipefail
umask 027

log() { printf '\033[1;34m▸ %s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*" >&2; }
die() { printf '\033[1;31m✘ %s\033[0m\n' "$*" >&2; exit 1; }
retry() { # retry <attempts> <command…>: network operations against wordpress.org
  local attempts="$1" n=1
  shift
  until "$@"; do
    ((n >= attempts)) && return 1
    warn "Reintentando ($n/$attempts): $*"
    sleep $((n * 5))
    n=$((n + 1))
  done
}

[[ "$(id -u)" == "0" ]] || die "Se requiere root (clpctl solo corre como root)."
command -v clpctl >/dev/null || die "clpctl no encontrado: este script es para servidores CloudPanel v2."
for var in CMS_DOMAIN FRONTEND_URL PHP_VERSION SITE_USER SITE_USER_PASSWORD DB_NAME DB_USER DB_PASSWORD \
  WP_TITLE WP_ADMIN_USER WP_ADMIN_EMAIL WP_ADMIN_PASSWORD WP_LOCALE WP_TIMEZONE WP_TABLE_PREFIX \
  VHOST_TEMPLATE BP_LEADS_SECRET BP_PREVIEW_SECRET BP_LEADS_EMAIL STAGE_DIR; do
  [[ -n "${!var:-}" ]] || die "Falta la variable $var."
done

main() {
  SITE_HOME="/home/$SITE_USER"
  DOCROOT="$SITE_HOME/htdocs/$CMS_DOMAIN"
  VHOST_FILE="/etc/nginx/sites-enabled/$CMS_DOMAIN.conf"
  PRIVATE_DIR="$SITE_HOME/.bp-deploy"
  PLUGINS=(secure-custom-fields redirection wp-nested-pages two-factor)
  # Integrations of bp-headless (ratings/reviews, SEO meta, SVG logo). Activated after bp-headless so their
  # installers see the "equipo" post type; bp-headless works without them (plan 2026-09-24 §9).
  INTEGRATIONS=(site-reviews seo-by-rank-math safe-svg)

  # ---------------------------------------------------------------------------------------------
  log "1/12 Plantilla de vhost «$VHOST_TEMPLATE»"
  templates="$(clpctl vhost-templates:list 2>/dev/null || true)"
  if grep -Fq -- "$VHOST_TEMPLATE" <<<"$templates"; then
    ok "ya existe"
  else
    # clpctl solo acepta una URL en --file: se sirve la plantilla un instante por HTTP en 127.0.0.1.
    tpl_port=18999
    (cd "$STAGE_DIR" && python3 -m http.server "$tpl_port" --bind 127.0.0.1 >/dev/null 2>&1) &
    tpl_pid=$!
    for _ in 1 2 3 4 5 6 7 8 9 10; do curl -fsS -o /dev/null "http://127.0.0.1:$tpl_port/bp-headless-wordpress.tpl" && break; sleep 0.5; done
    clpctl vhost-template:add --name="$VHOST_TEMPLATE" --file="http://127.0.0.1:$tpl_port/bp-headless-wordpress.tpl" || { kill "$tpl_pid" 2>/dev/null; die "No se pudo registrar la plantilla de vhost."; }
    kill "$tpl_pid" 2>/dev/null || true
    ok "creada"
  fi

  # ---------------------------------------------------------------------------------------------
  log "2/12 Sitio PHP $PHP_VERSION: $CMS_DOMAIN (usuario $SITE_USER)"
  if [[ -d "$DOCROOT" || -f "$VHOST_FILE" ]]; then
    owner="$(stat -c %U "$DOCROOT" 2>/dev/null || echo '?')"
    [[ "$owner" == "$SITE_USER" ]] || die "El sitio $CMS_DOMAIN ya existe pero $DOCROOT pertenece a «$owner» (se esperaba $SITE_USER)."
    ok "ya existe"
  else
    clpctl site:add:php --domainName="$CMS_DOMAIN" --phpVersion="$PHP_VERSION" \
      --vhostTemplate="$VHOST_TEMPLATE" --siteUser="$SITE_USER" --siteUserPassword="$SITE_USER_PASSWORD"
    ok "creado con la plantilla $VHOST_TEMPLATE"
  fi
  [[ -d "$DOCROOT" ]] || die "No existe $DOCROOT después de crear el sitio."
  if [[ -f "$VHOST_FILE" ]]; then
    if grep -q 'bp-headless' "$VHOST_FILE"; then
      ok "vhost con los ajustes bp-headless (noindex, uploads sin PHP, todo a index.php)"
    else
      warn "El vhost de $CMS_DOMAIN no tiene los ajustes bp-headless (el sitio se creó con otra plantilla)."
      warn "Pégalos desde $STAGE_DIR/bp-headless-wordpress.tpl en CloudPanel → Sites → $CMS_DOMAIN → Vhost."
    fi
  fi

  # ---------------------------------------------------------------------------------------------
  log "3/12 Base de datos $DB_NAME"
  db_ready() { MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u "$DB_USER" -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1; }
  if db_ready; then
    ok "ya existe y las credenciales funcionan"
  else
    clpctl db:add --domainName="$CMS_DOMAIN" --databaseName="$DB_NAME" \
      --databaseUserName="$DB_USER" --databaseUserPassword="$DB_PASSWORD"
    db_ready || die "No se puede conectar a $DB_NAME con $DB_USER (¿la BD ya existía con otra contraseña?)."
    ok "creada"
  fi

  # ---------------------------------------------------------------------------------------------
  log "4/12 PHP y WP-CLI"
  PHP_BIN="$(command -v "php$PHP_VERSION" || true)"
  [[ -n "$PHP_BIN" ]] || die "No existe el binario php$PHP_VERSION."
  WP_BIN="$(command -v wp || true)"
  if [[ -z "$WP_BIN" ]]; then
    curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
    chmod 755 /usr/local/bin/wp
    WP_BIN=/usr/local/bin/wp
  fi
  # Run from the site user's home: the SSH session cwd (/root) is not readable by the site user and
  # WP-CLI subprocesses (e.g. `rewrite structure` → `rewrite flush`) would fail with EACCES.
  as_site() { (cd "$SITE_HOME" && runuser -u "$SITE_USER" -- env HOME="$SITE_HOME" "$@"); }
  # memory_limit: the PHP CLI default (often 128M) is not enough to unpack WordPress.
  wp() { as_site "$PHP_BIN" -d memory_limit=512M "$WP_BIN" --path="$DOCROOT" "$@"; }
  ok "$("$PHP_BIN" -r 'echo "PHP ", PHP_VERSION;') · $(wp cli version)"

  # ---------------------------------------------------------------------------------------------
  log "5/12 WordPress ($WP_LOCALE)"
  if [[ -f "$DOCROOT/wp-load.php" ]]; then
    ok "core presente ($(wp core version))"
  else
    retry 3 wp core download --locale="$WP_LOCALE"
    ok "descargado"
  fi
  install -d -o "$SITE_USER" -g "$SITE_USER" -m 755 "$DOCROOT/wp-content/plugins" "$DOCROOT/wp-content/themes"
  install -d -o "$SITE_USER" -g "$SITE_USER" -m 700 "$PRIVATE_DIR"

  # ---------------------------------------------------------------------------------------------
  log "6/12 wp-config.php (se regenera en cada deploy desde backend/.env.deploy)"
  php_str() { local s="${1//\\/\\\\}"; s="${s//\'/\\\'}"; printf "'%s'" "$s"; }
  php_define() { printf "define( '%s', %s );\n" "$1" "$(php_str "$2")"; }

  if [[ ! -s "$DOCROOT/wp-salts.php" ]]; then
    "$PHP_BIN" -r 'echo "<?php\n// Generated once on the server by deploy.sh. Rotating these logs everybody out.\n"; foreach (["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k) { printf("define( %s, %s );\n", var_export($k, true), var_export(bin2hex(random_bytes(32)), true)); }' >"$DOCROOT/wp-salts.php"
    chown "$SITE_USER:$SITE_USER" "$DOCROOT/wp-salts.php"
    chmod 640 "$DOCROOT/wp-salts.php"
    ok "salts generadas"
  fi

  config_tmp="$(mktemp)"
  {
    printf '<?php\n'
    printf '/**\n * Generated by backend/deploy/cloudpanel/deploy.sh — do not edit here: change backend/.env.deploy and deploy again.\n */\n\n'
    php_define DB_NAME "$DB_NAME"
    php_define DB_USER "$DB_USER"
    php_define DB_PASSWORD "$DB_PASSWORD"
    php_define DB_HOST '127.0.0.1:3306'
    php_define DB_CHARSET 'utf8mb4'
    php_define DB_COLLATE ''
    printf "\$table_prefix = %s;\n" "$(php_str "$WP_TABLE_PREFIX")"
    printf "require __DIR__ . '/wp-salts.php';\n\n"
    php_define WP_ENVIRONMENT_TYPE production
    php_define WP_HOME "https://$CMS_DOMAIN"
    php_define WP_SITEURL "https://$CMS_DOMAIN"
    printf "define( 'FORCE_SSL_ADMIN', true );\ndefine( 'DISALLOW_FILE_EDIT', true );\ndefine( 'DISABLE_WP_CRON', true );\n"
    printf "define( 'WP_POST_REVISIONS', 20 );\ndefine( 'WP_DEBUG', false );\ndefine( 'WP_DEBUG_LOG', false );\ndefine( 'WP_DEBUG_DISPLAY', false );\n\n"
    printf "// bp-headless\n"
    php_define BP_FRONTEND_URL "$FRONTEND_URL"
    php_define BP_LEADS_SECRET "$BP_LEADS_SECRET"
    php_define BP_PREVIEW_SECRET "$BP_PREVIEW_SECRET"
    printf "define( 'BP_LEADS_EMAIL', %s );\n" "$([[ "$BP_LEADS_EMAIL" == "true" ]] && echo true || echo false)"
    php_define BP_LEADS_EMAIL_TO "${BP_LEADS_EMAIL_TO:-contacto@banosportatiles.net}"
    php_define BP_LEADS_EMAIL_CC "${BP_LEADS_EMAIL_CC:-connexis.co@gmail.com}"
    [[ -n "${BP_DEPLOY_HOOK_URL:-}" ]] && php_define BP_DEPLOY_HOOK_URL "$BP_DEPLOY_HOOK_URL"
    [[ -n "${BP_CORS_ORIGINS:-}" ]] && php_define BP_CORS_ORIGINS "$BP_CORS_ORIGINS"
    [[ -n "${BP_LEADS_WEBHOOK_URL:-}" ]] && php_define BP_LEADS_WEBHOOK_URL "$BP_LEADS_WEBHOOK_URL"
    if [[ -n "${BP_SMTP_USER:-}" && -n "${BP_SMTP_PASS:-}" ]]; then
      printf "\n// SMTP (Brevo): STARTTLS on 587, applied by bp-headless on phpmailer_init\n"
      php_define BP_SMTP_HOST "${BP_SMTP_HOST:-smtp-relay.brevo.com}"
      printf "define( 'BP_SMTP_PORT', %d );\n" "${BP_SMTP_PORT:-587}"
      php_define BP_SMTP_USER "$BP_SMTP_USER"
      php_define BP_SMTP_PASS "$BP_SMTP_PASS"
      php_define BP_SMTP_FROM "${BP_SMTP_FROM:-no-reply@banosportatiles.net}"
      php_define BP_SMTP_FROM_NAME "${BP_SMTP_FROM_NAME:-BañosPortátiles.net}"
    fi
    printf "\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n"
  } >"$config_tmp"
  "$PHP_BIN" -l "$config_tmp" >/dev/null || die "wp-config.php generado no es PHP válido."
  install -o "$SITE_USER" -g "$SITE_USER" -m 640 "$config_tmp" "$DOCROOT/wp-config.php"
  rm -f "$config_tmp"
  ok "escrito (BP_LEADS_EMAIL=$BP_LEADS_EMAIL)"
  [[ -n "${BP_SMTP_USER:-}" && -n "${BP_SMTP_PASS:-}" ]] || warn "Sin BP_SMTP_USER/BP_SMTP_PASS: wp_mail usará mail() del servidor. Completa las credenciales de Brevo en .env.deploy."

  # ---------------------------------------------------------------------------------------------
  log "7/12 Instalación"
  if wp core is-installed 2>/dev/null; then
    ok "WordPress ya estaba instalado"
  else
    wp core install --url="https://$CMS_DOMAIN" --title="$WP_TITLE" --admin_user="$WP_ADMIN_USER" \
      --admin_email="$WP_ADMIN_EMAIL" --skip-email >/dev/null
    # The password never goes through argv: it is read from a 600 file by the site user.
    printf '%s' "$WP_ADMIN_PASSWORD" >"$PRIVATE_DIR/admin-pass"
    cat >"$PRIVATE_DIR/set-admin-pass.php" <<'PHP'
  <?php
  $user = get_user_by('login', $args[1] ?? '');
  if (! $user instanceof WP_User) {
      WP_CLI::error('Administrador no encontrado.');
  }
  wp_set_password(trim((string) file_get_contents($args[0])), $user->ID);
PHP
    chown "$SITE_USER:$SITE_USER" "$PRIVATE_DIR/admin-pass" "$PRIVATE_DIR/set-admin-pass.php"
    chmod 600 "$PRIVATE_DIR/admin-pass" "$PRIVATE_DIR/set-admin-pass.php"
    wp eval-file "$PRIVATE_DIR/set-admin-pass.php" "$PRIVATE_DIR/admin-pass" "$WP_ADMIN_USER"
    rm -f "$PRIVATE_DIR/admin-pass" "$PRIVATE_DIR/set-admin-pass.php"
    # Fresh install: sample post (1), sample page (2) and the draft privacy page, whatever the locale.
    privacy="$(wp option get wp_page_for_privacy_policy 2>/dev/null || echo 0)"
    for id in 1 2 "$privacy"; do
      if [[ "$id" != "0" ]] && wp post get "$id" --field=ID >/dev/null 2>&1; then wp post delete "$id" --force --quiet; fi
    done
    ok "instalado (usuario $WP_ADMIN_USER; contraseña en backend/.env.deploy)"
  fi

  # ---------------------------------------------------------------------------------------------
  log "8/12 Tema, plugins de wordpress.org, bp-headless y bp-sitio-en-venta (rsync)"
  sync_dir() {
    if command -v rsync >/dev/null; then
      rsync -a --delete "$1/" "$2/"
    else
      rm -rf "$2" && cp -a "$1" "$2"
    fi
    chown -R "$SITE_USER:$SITE_USER" "$2"
    find "$2" -type d -exec chmod 755 {} + && find "$2" -type f -exec chmod 644 {} +
  }
  sync_dir "$STAGE_DIR/bp-headless-theme" "$DOCROOT/wp-content/themes/bp-headless-theme"
  sync_dir "$STAGE_DIR/bp-headless" "$DOCROOT/wp-content/plugins/bp-headless"
  sync_dir "$STAGE_DIR/bp-sitio-en-venta" "$DOCROOT/wp-content/plugins/bp-sitio-en-venta"
  for plugin in "${PLUGINS[@]}" "${INTEGRATIONS[@]}"; do
    wp plugin is-installed "$plugin" || retry 3 wp plugin install "$plugin" --quiet
  done
  wp plugin activate "${PLUGINS[@]}" --quiet
  for plugin in akismet hello; do
    if wp plugin is-installed "$plugin"; then wp plugin delete "$plugin" --quiet; fi
  done
  wp theme activate bp-headless-theme --quiet
  for theme in $(wp theme list --status=inactive --field=name); do wp theme delete "$theme" --quiet; done
  wp plugin activate bp-headless bp-sitio-en-venta --quiet
  wp plugin activate "${INTEGRATIONS[@]}" --quiet
  wp redirection database install >/dev/null 2>&1 || true
  # Idempotent configuration of the integrations for the headless site.
  wp bp setup reviews >/dev/null && ok "Site Reviews configurado (wp bp setup reviews)"
  wp bp setup rankmath >/dev/null && ok "Rank Math configurado (wp bp setup rankmath)"
  ok "bp-headless $(wp plugin get bp-headless --field=version) y bp-sitio-en-venta $(wp plugin get bp-sitio-en-venta --field=version) activos"
  ok "Site Reviews $(wp plugin get site-reviews --field=version), Rank Math $(wp plugin get seo-by-rank-math --field=version) y Safe SVG $(wp plugin get safe-svg --field=version) activos"

  # ---------------------------------------------------------------------------------------------
  log "9/12 Ajustes: idioma, zona horaria, permalinks, noindex, comentarios"
  wp language core is-installed "$WP_LOCALE" || retry 3 wp language core install "$WP_LOCALE" --quiet
  wp site switch-language "$WP_LOCALE" --quiet
  wp language plugin install --all "$WP_LOCALE" --quiet >/dev/null 2>&1 || true
  wp option update timezone_string "$WP_TIMEZONE" --quiet
  wp option update start_of_week 1 --quiet
  wp option update blog_public 0 --quiet
  wp option update default_comment_status closed --quiet
  wp option update default_ping_status closed --quiet
  wp option update users_can_register 0 --quiet
  wp option update home "https://$CMS_DOMAIN" --quiet
  wp option update siteurl "https://$CMS_DOMAIN" --quiet
  wp rewrite structure '/%postname%/' --quiet
  ok "listo"

  # ---------------------------------------------------------------------------------------------
  log "10/12 Contenido de ejemplo"
  for slug in hello-world sample-page privacy-policy hola-mundo pagina-ejemplo politica-privacidad politica-de-privacidad; do
    ids="$(wp post list --post_type=post,page --post_status=any --name="$slug" --format=ids)"
    for id in $ids; do
      # Nunca borrar contenido importado del seed (p. ej. nuestra /politica-de-privacidad/ en es_CO).
      if [[ -z "$(wp post meta get "$id" _bp_seed_key 2>/dev/null || true)" ]]; then wp post delete "$id" --force --quiet; fi
    done
  done
  comments="$(wp comment list --format=ids)"
  if [[ -n "$comments" ]]; then wp comment delete $comments --force --quiet; fi
  wp option update wp_page_for_privacy_policy 0 --quiet
  if [[ -f "$STAGE_DIR/seed/bundle.json" ]]; then
    rm -rf "$PRIVATE_DIR/seed"
    cp -a "$STAGE_DIR/seed" "$PRIVATE_DIR/seed"
    chown -R "$SITE_USER:$SITE_USER" "$PRIVATE_DIR/seed"
    if [[ -d "$PRIVATE_DIR/seed/assets" ]]; then
      wp bp import-seed "$PRIVATE_DIR/seed/bundle.json" --assets="$PRIVATE_DIR/seed/assets" --no-deploy
    else
      wp bp import-seed "$PRIVATE_DIR/seed/bundle.json" --no-deploy
    fi
    rm -rf "$PRIVATE_DIR/seed"
  fi
  ok "limpio"

  # ---------------------------------------------------------------------------------------------
  log "11/12 Cron real (DISABLE_WP_CRON): debounce del deploy hook y webhook de leads"
  cron_line="* * * * * $PHP_BIN -d memory_limit=512M $WP_BIN --path=$DOCROOT cron event run --due-now --quiet >/dev/null 2>&1 # bp-headless"
  current_cron="$(crontab -u "$SITE_USER" -l 2>/dev/null || true)"
  { grep -v '# bp-headless$' <<<"$current_cron" | grep -v '^$' || true; echo "$cron_line"; } | crontab -u "$SITE_USER" -
  ok "crontab de $SITE_USER"

  # ---------------------------------------------------------------------------------------------
  log "12/12 Certificado Let's Encrypt"
  if [[ "${SKIP_CERT:-0}" == "1" ]]; then
    warn "Omitido (--skip-cert)."
  else
    cert_file="$(awk '$1 == "ssl_certificate" { gsub(/;/, "", $2); print $2; exit }' "$VHOST_FILE" 2>/dev/null || true)"
    issuer=""
    valid=1
    if [[ -n "$cert_file" && -f "$cert_file" ]]; then
      issuer="$(openssl x509 -in "$cert_file" -noout -issuer 2>/dev/null || true)"
      openssl x509 -in "$cert_file" -noout -checkend $((30 * 86400)) >/dev/null 2>&1 && valid=0
    fi
    if grep -qi "let's encrypt" <<<"$issuer" && [[ "$valid" == "0" ]]; then
      ok "vigente (más de 30 días)"
    elif clpctl lets-encrypt:install:certificate --domainName="$CMS_DOMAIN"; then
      ok "instalado"
    else
      warn "No se pudo emitir: el registro A de $CMS_DOMAIN debe apuntar a este servidor (DNS only o SSL Full durante la emisión). Vuelve a ejecutar el deploy."
    fi
  fi

  # ---------------------------------------------------------------------------------------------
  wp rewrite flush --quiet
  wp bp cache flush --quiet
  log "Verificación local (sin pasar por Cloudflare)"
  check() { curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' --resolve "$CMS_DOMAIN:443:127.0.0.1" "https://$CMS_DOMAIN$1" || true; }
  printf '  /wp-json/bp/v1/site → %s\n' "$(check /wp-json/bp/v1/site)"
  printf '  /wp-login.php       → %s\n' "$(check /wp-login.php)"
  printf '  / (front)           → %s\n' "$(check /)"
  printf '\n\033[1;32m✔ CMS desplegado en https://%s (admin: /wp-admin/, API: /wp-json/bp/v1/)\033[0m\n' "$CMS_DOMAIN"
}

# The script arrives on stdin: run it with stdin detached so no child command can consume it.
main </dev/null
