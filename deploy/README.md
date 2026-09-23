# Runbook — CMS headless en `api.banosportatiles.net`

> **Estado:** documentado, **no ejecutado**. Nada de este runbook se ha aplicado todavía en el VPS, en Cloudflare ni en el DNS.
> **Destino:** VPS Hostinger actual `srv727221.hstgr.cloud` (217.15.168.25), detrás de Cloudflare.
> **Regla de oro:** el WordPress actual de `banosportatiles.net` (7.1.1 + WPBakery) **no se toca**. El CMS nuevo es un sitio aparte en el subdominio `api.` y el sitio público pasa a Astro en Cloudflare Workers solo en el *cutover*.

```
banosportatiles.net ─────► Cloudflare Worker (Astro 7)  ──build/preview──►  api.banosportatiles.net
                                   │  POST /bp/v1/leads (HMAC)                  (WordPress 7.x headless,
                                   └──────────────────────────────────────────►  este runbook)
api.banosportatiles.net ── deploy hook (debounce 60 s) ──► Cloudflare Workers Builds
```

## 0. Resumen de valores

| Qué | Valor |
|---|---|
| URL del CMS (`home`/`siteurl`) | `https://api.banosportatiles.net` |
| Front público (`BP_FRONTEND_URL`) | `https://banosportatiles.net` |
| API | `https://api.banosportatiles.net/wp-json/bp/v1/` |
| PHP | 8.3 o superior (recomendado **8.4**, el mismo del entorno local) |
| BD | MariaDB 10.11+/11.x o MySQL 8.4, `utf8mb4` |
| WP-CLI | 2.12+ |
| Plugins | `secure-custom-fields`, `redirection`, `wp-nested-pages`, `two-factor` (wordpress.org) + `bp-headless` (zip propio) |
| Tema | `bp-headless-theme` (zip propio; nunca se renderiza) |

## 1. Artefactos (en local)

```bash
cd backend
deploy/package.sh                       # zips + export de BD/uploads con la URL ya reescrita
deploy/package.sh --no-export           # solo los zips del plugin y del tema
deploy/package.sh --url=https://otra.url  # si el host final cambia
```

Salida en `backend/dist/` (gitignored): `bp-headless-<versión>.zip`, `bp-headless-theme-<versión>.zip` y
`export-<fecha>/{db.sql.gz, uploads.tar.gz, plugins.csv, wp-version.txt, SHA256SUMS}`.

> **¿Export o seed?** Para el primer arranque se recomienda una **instalación limpia + `wp bp import-seed`** con el bundle que genera el frontend (sección 5A). El export de BD (5B) solo tiene sentido si ya hubo trabajo editorial en local. El dump incluye el usuario local `bp-admin`: tras importarlo, crea tu administrador y elimina ese usuario.

## 2. DNS y SSL en Cloudflare (zona `banosportatiles.net`)

1. Registro `A api → 217.15.168.25`, **proxied** (nube naranja). Aunque el DNS comodín ya apunte al VPS, crea el registro explícito.
2. **SSL/TLS → Full (strict)**. En el origen usa un **Cloudflare Origin Certificate** (15 años) instalado en el sitio, o Let's Encrypt si el panel lo gestiona.
3. **Always Use HTTPS** activo. **No** actives “Cache Everything” en `api.`: la API envía sus propios `Cache-Control` (`max-age=30`, menor que el debounce del deploy) y `ETag`.
4. WAF recomendado (reglas personalizadas sobre el host `api.banosportatiles.net`):
   - `wp-login.php` y `/wp-admin/` → **Cloudflare Access** (Zero Trust, gratis hasta 50 usuarios) o *Managed Challenge* fuera de Colombia.
   - Rate limiting: `POST /wp-json/bp/v1/leads` (p. ej. 20/min por IP) y `POST /wp-login.php`.
   - Bloquear `/xmlrpc.php`.

## 3. Sitio en el VPS

### Opción A — CloudPanel (o panel equivalente del VPS Hostinger)

1. **Add Site → WordPress** (o “PHP Site” vacío si prefieres instalar con WP-CLI): dominio `api.banosportatiles.net`, **PHP 8.4**, base de datos y usuario dedicados.
2. Extensiones PHP: `mysqli`, `curl`, `gd` (con WebP/AVIF) o `imagick`, `intl`, `mbstring`, `xml`, `zip`, `exif`, `opcache`.
3. `php.ini`: `upload_max_filesize=32M`, `post_max_size=32M`, `memory_limit=256M`, `max_execution_time=120`.
4. **Vhost (nginx)** — añadir dentro del `server {}` del sitio:

```nginx
# Todo el host del CMS fuera de los buscadores (incluye uploads, que no pasan por PHP)
add_header X-Robots-Tag "noindex, nofollow" always;

# IP real del visitante detrás de Cloudflare (el rate limit de /leads y los logs la necesitan)
# Rangos actualizados: https://www.cloudflare.com/ips/
set_real_ip_from 173.245.48.0/20;  set_real_ip_from 103.21.244.0/22;  set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;    set_real_ip_from 141.101.64.0/18;  set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;  set_real_ip_from 188.114.96.0/20;  set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;  set_real_ip_from 162.158.0.0/15;   set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;    set_real_ip_from 172.64.0.0/13;    set_real_ip_from 131.0.72.0/22;
real_ip_header CF-Connecting-IP;

location = /xmlrpc.php { deny all; }
location ~* /wp-content/uploads/.*\.php$ { deny all; }
location ~ /\.(?!well-known) { deny all; }
```

### Opción B — Docker

Usa la imagen oficial `wordpress:7.1-php8.4-apache` (o `-fpm` detrás de Caddy/Traefik/nginx) con volúmenes para
`/var/www/html` y la BD, y pasa las constantes de la sección 4 con `WORDPRESS_CONFIG_EXTRA`.
**No** reutilices `backend/docker-compose.yml` tal cual: está pensado para local (debug activo, Mailpit, sin TLS).
Monta el mismo `add_header X-Robots-Tag` en el proxy.

## 4. `wp-config.php`

Genera los secretos **en el servidor** y guárdalos también en el gestor de secretos del Worker:

```bash
openssl rand -hex 32   # BP_LEADS_SECRET  (mismo valor en el Worker: wrangler secret put WP_LEADS_SECRET)
openssl rand -hex 32   # BP_PREVIEW_SECRET
```

```php
// --- Entorno y seguridad ---
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'WP_HOME', 'https://api.banosportatiles.net' );
define( 'WP_SITEURL', 'https://api.banosportatiles.net' );
define( 'FORCE_SSL_ADMIN', true );
define( 'DISALLOW_FILE_EDIT', true );          // el plugin también lo fuerza, pero déjalo explícito
define( 'DISABLE_WP_CRON', true );             // cron real (sección 6)
define( 'WP_POST_REVISIONS', 20 );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );

// --- bp-headless ---
define( 'BP_FRONTEND_URL', 'https://banosportatiles.net' );
define( 'BP_LEADS_SECRET', '…64 hex…' );
define( 'BP_PREVIEW_SECRET', '…64 hex…' );
define( 'BP_DEPLOY_HOOK_URL', 'https://api.cloudflare.com/client/v4/workers/builds/deploy_hooks/…' ); // Workers Builds → Deploy hooks
// define( 'BP_CORS_ORIGINS', 'https://banosportatiles-net.<subdominio>.workers.dev' ); // preview en workers.dev
// define( 'BP_LEADS_WEBHOOK_URL', 'https://…' );   // opcional (n8n, Make, CRM)

// --- SMTP para los avisos de leads (ejemplo: correo de Hostinger) ---
define( 'BP_SMTP_HOST', 'smtp.hostinger.com' );
define( 'BP_SMTP_PORT', 465 );
define( 'BP_SMTP_SECURE', 'ssl' );
define( 'BP_SMTP_USER', 'no-reply@banosportatiles.net' );
define( 'BP_SMTP_PASS', '…' );
define( 'BP_SMTP_FROM', 'no-reply@banosportatiles.net' );
```

Si el proxy no marca HTTPS (bucle de redirecciones en `/wp-admin`), añade antes de `wp-settings.php`:
`if ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' ) { $_SERVER['HTTPS'] = 'on'; }`

## 5. Instalación

```bash
cd /home/<usuario>/htdocs/api.banosportatiles.net
wp core download --locale=es_CO             # última 7.x
wp config create --dbname=<db> --dbuser=<user> --dbpass='<pass>' --dbhost=127.0.0.1 --locale=es_CO
#   → pega las constantes de la sección 4
wp core install --url=https://api.banosportatiles.net --title='BañosPortátiles.net CMS' \
  --admin_user=<tu-usuario> --admin_email=<tu-email> --prompt=admin_password --skip-email
wp option update timezone_string America/Bogota
wp option update blog_public 0
wp option update default_comment_status closed
wp rewrite structure '/%postname%/' --hard
wp plugin install secure-custom-fields redirection wp-nested-pages two-factor --activate
wp plugin delete akismet hello
wp theme install /tmp/bp-headless-theme-1.0.0.zip --activate
wp plugin install /tmp/bp-headless-1.0.0.zip --activate
wp redirection database install
wp language plugin install --all es_CO
```

> En un servidor con dominio real, `wp plugin install <slug>` funciona directo contra wordpress.org. El rodeo con zips que usa `bin/provision.sh` solo existe por el bloqueo 434 que aplica wordpress.org al User-Agent `WordPress/x; http://localhost:8080`.

### 5A. Contenido desde el seed (recomendado para el primer arranque)

```bash
# bundle.json + assets/ generados por el frontend (formato: docs/plans/contrato-contenido-seed.md §7)
wp bp import-seed /tmp/seed/bundle.json --assets=/tmp/seed/assets --dry-run   # revisar el plan
wp bp import-seed /tmp/seed/bundle.json --assets=/tmp/seed/assets             # idempotente: se puede repetir
```

### 5B. Migrar la BD local (si hubo trabajo editorial en local)

```bash
gunzip -c db.sql.gz | wp db import -           # URLs ya reescritas por package.sh
tar -xzf uploads.tar.gz -C wp-content/
wp user create <tu-usuario> <tu-email> --role=administrator --prompt=user_pass
wp user delete bp-admin --reassign=<tu-usuario>
wp rewrite flush --hard && wp bp cache flush
```

## 6. Cron real (obligatorio)

El deploy hook (debounce de 60 s) y el webhook de leads usan WP-Cron. Con `DISABLE_WP_CRON`, programa en el panel (CloudPanel → *Cron Jobs*) o en el crontab del usuario del sitio:

```cron
* * * * * cd /home/<usuario>/htdocs/api.banosportatiles.net && /usr/local/bin/wp cron event run --due-now --quiet >/dev/null 2>&1
```

## 7. Seguridad de cuentas

- **Two Factor**: cada administrador o editor lo activa en *Usuarios → Perfil* (TOTP y códigos de respaldo).
- Un usuario por persona. El rol *Editor* basta para el contenido; *Ajustes del sitio → Despliegue* solo lo ven los administradores.
- *Application Passwords* solo si una integración lo necesita (las previews usan tokens HMAC, no usuarios).

## 8. Backups

| Qué | Cómo | Retención |
|---|---|---|
| BD | `wp db export - \| gzip > ~/backups/db-$(date +%F).sql.gz` en cron diario | 14 días locales |
| Uploads | `tar -czf ~/backups/uploads-$(date +%F).tar.gz wp-content/uploads` semanal (o `rsync` incremental) | 8 semanas |
| Off-site | `rclone copy ~/backups r2:bp-backups` (Cloudflare R2) | 90 días |
| Panel | Backups automáticos de CloudPanel/Hostinger activos | según plan |

Prueba una restauración completa en local al menos una vez por trimestre (`bin/reset.sh` + import del dump).

## 9. Verificación post-instalación

```bash
API=https://api.banosportatiles.net/wp-json/bp/v1
curl -s $API/site | jq '.brand, .sale_banner'
curl -s $API/routes | jq 'length'
curl -sI https://api.banosportatiles.net/cualquier-ruta/ | grep -i -E '^(HTTP|location|x-robots-tag)'   # 301 → banosportatiles.net
curl -s -o /dev/null -w '%{http_code}\n' https://api.banosportatiles.net/wp-json/wp/v2/users           # 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.banosportatiles.net/wp-json/bp/v1/leads   # 401 (sin firma)
wp bp deploy                                                                                        # dispara un build del Worker
```

`backend/tests/smoke.sh` automatiza todo esto contra el entorno local, y sirve de lista de chequeo.

## 10. Cutover y rollback

1. El Worker de Astro, ya validado en `*.workers.dev` (noindex), lee `WP_API_URL=https://api.banosportatiles.net/wp-json/bp/v1`.
2. Se cargan las redirecciones 301 (`GET /bp/v1/redirects` → `_redirects` del Worker).
3. **Cutover:** se asigna la ruta o el dominio `banosportatiles.net` al Worker en Cloudflare.
4. **Rollback:** se quita la ruta del Worker y el tráfico vuelve al WordPress actual, que sigue intacto. Conviene mantenerlo al menos 30 días antes de retirarlo.
