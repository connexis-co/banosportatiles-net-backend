# Runbook — CMS headless en `admin.banosportatiles.net`

> **Destino:** servidor Hetzner de Connexis **`connexis-prod`** (alias en `~/.ssh/config`, IP fija **95.216.153.73**), con **CloudPanel v2** (clpctl, nginx, PHP-FPM, MariaDB, Let's Encrypt, WP-CLI).
> **URL:** `https://admin.banosportatiles.net`. Por ahí entra el login y ahí vive la API REST (`/wp-json/bp/v1/`). El resto del *front* del CMS redirige (301) al sitio público `https://banosportatiles.net` (Astro en Cloudflare).
> **Estado:** desplegado en producción. Último deploy: 2026-09-23, con `bp-headless` 1.x y `bp-sitio-en-venta` 1.0.1. El script es idempotente y se puede volver a ejecutar.
> El WordPress actual de `banosportatiles.net` (VPS Hostinger) no se toca.

```
banosportatiles.net ──► Cloudflare Worker (Astro 7) ──build/preview──► admin.banosportatiles.net (connexis-prod, CloudPanel)
                              │  POST /bp/v1/leads (HMAC)                  WordPress 7.x + bp-headless
                              └───────────────────────────────────────────► └─ wp_mail → Brevo SMTP (IP 95.216.153.73 autorizada)
admin.banosportatiles.net ── deploy hook (debounce 60 s) ──► Cloudflare Workers Builds
```

## 1. Antes del primer deploy

| # | Qué | Dónde |
|---|---|---|
| 1 | Registro **A `admin` → 95.216.153.73**, **sin proxy** (nube gris), para que Let's Encrypt valide por HTTP-01. Ya existe. | Cloudflare → DNS |
| 2 | En Brevo: **Security → Authorized IPs → añadir 95.216.153.73**. Brevo rechaza SMTP desde cualquier otra IP. | Brevo |
| 3 | En Brevo: dominio `banosportatiles.net` autenticado (SPF + DKIM) para enviar como `no-reply@banosportatiles.net`, y un **SMTP key** (en *SMTP & API*, no la API key). | Brevo + Cloudflare DNS |
| 4 | Acceso SSH por clave a `connexis-prod` (`ssh connexis-prod 'id -u'`), como root o con `sudo` sin contraseña (clpctl solo corre como root). | local |

## 2. Configuración (`backend/.env.deploy`, fuera de git)

La primera ejecución real crea `backend/.env.deploy` (chmod 600) a partir de [`cloudpanel/deploy.env.example`](cloudpanel/deploy.env.example) y **genera en local** contraseñas y secretos aleatorios: usuario del sitio, BD, admin de WordPress, `BP_LEADS_SECRET` y `BP_PREVIEW_SECRET`. Luego completa:

```bash
BP_SMTP_USER=<login SMTP de Brevo>
BP_SMTP_PASS=<SMTP key de Brevo>
BP_DEPLOY_HOOK_URL=<deploy hook de Workers Builds>   # opcional; también se puede poner en «Ajustes del sitio»
```

Los defaults ya incluidos: sitio `admin.banosportatiles.net`, PHP 8.4, usuario de sitio `banosportatiles-cms`, BD y usuario `banosportatiles-cms` (CloudPanel solo admite letras, dígitos y guiones), admin `bp-admin`, `WP_ADMIN_EMAIL=contacto@banosportatiles.net`, `BP_LEADS_EMAIL=true`, `BP_LEADS_EMAIL_TO=contacto@banosportatiles.net`, `BP_LEADS_EMAIL_CC=connexis.co@gmail.com` y SMTP `smtp-relay.brevo.com:587` (STARTTLS).

> El script **solo** lee `backend/.env.deploy` e ignora a propósito las variables homónimas del shell. `backend/.env`, el del Docker local, usa los mismos nombres (`DB_PASSWORD`, `BP_SMTP_*`…) y nunca debe llegar a producción.

## 3. Ejecutar

```bash
cd ~/dev/banosportatiles-net/backend

deploy/cloudpanel/deploy.sh --dry-run            # imprime todos los comandos (secretos enmascarados); no toca nada
deploy/cloudpanel/deploy.sh                      # deploy real (idempotente: se puede repetir)
deploy/cloudpanel/deploy.sh --seed=<carpeta>     # además importa <carpeta>/bundle.json (+ assets/) del frontend
deploy/cloudpanel/deploy.sh --seed=<carpeta> --seed-force   # reescribe también los ítems sin cambios (tras cambios del importador)
deploy/cloudpanel/deploy.sh --skip-cert          # si el DNS aún no apunta al servidor
```

Qué hace:
1. **En local:** valida la configuración, avisa si el DNS de `admin.` no resuelve y sube por `rsync` los plugins `bp-headless` y `bp-sitio-en-venta`, el tema y la plantilla de vhost a `~/bp-deploy/admin.banosportatiles.net/` en el servidor.
2. **En el servidor** (script [`cloudpanel/remote.sh`](cloudpanel/remote.sh) por `ssh … bash -s`; los secretos viajan por stdin, nunca en la línea de comandos local):

| Paso | Comando / efecto | Idempotencia |
|---|---|---|
| 1 | `clpctl vhost-template:add --name=BP-Headless-WordPress-v1 --file=http://127.0.0.1:18999/…` (clpctl exige una URL: la plantilla se sirve un instante con `python3 -m http.server` en 127.0.0.1) | solo si no aparece en `vhost-templates:list` |
| 2 | `clpctl site:add:php --domainName=admin.banosportatiles.net --phpVersion=8.4 --vhostTemplate=BP-Headless-WordPress-v1 --siteUser=… --siteUserPassword=…` | solo si no existe `/home/<user>/htdocs/<dominio>` |
| 3 | `clpctl db:add --domainName=… --databaseName=… --databaseUserName=… --databaseUserPassword=…` | solo si las credenciales aún no conectan |
| 4 | WP-CLI como usuario del sitio (`runuser`, `php8.4 -d memory_limit=512M`) | — |
| 5 | `wp core download --locale=es_CO` | solo si falta `wp-load.php` |
| 6 | `wp-config.php` **regenerado** (0640) con BD, `WP_HOME`, `FORCE_SSL_ADMIN`, `DISALLOW_FILE_EDIT`, `DISABLE_WP_CRON`, `BP_*` y `BP_SMTP_*`; salts en `wp-salts.php` (se generan una vez) | siempre (declarativo) |
| 7 | `wp core install` + contraseña del admin fijada desde un archivo temporal 0600 (nunca en argv); borra el post, la página y la política de ejemplo | solo en la primera instalación |
| 8 | rsync de `bp-headless`, `bp-sitio-en-venta` y del tema; `wp plugin install secure-custom-fields redirection wp-nested-pages two-factor site-reviews seo-by-rank-math safe-svg` (3 reintentos) y activación (Site Reviews, Rank Math y Safe SVG **después** de bp-headless, para que sus instaladores vean el CPT `equipo`); `wp redirection database install`; `wp bp setup reviews` y `wp bp setup rankmath` | instala solo lo que falta; los setups son idempotentes |
| 9 | es_CO, `America/Bogota`, `blog_public=0`, comentarios cerrados, permalinks `/%postname%/` | siempre |
| 10 | `wp bp import-seed` si se pasó `--seed` (con `--force` si además se pasó `--seed-force`) | upsert idempotente; sin `--seed-force` salta los ítems cuyo hash no cambió |
| 11 | Cron real en el crontab del usuario del sitio (`wp cron event run --due-now` cada minuto) | reemplaza su propia línea |
| 12 | `clpctl lets-encrypt:install:certificate --domainName=admin.banosportatiles.net` | solo si no hay un certificado LE con más de 30 días |

Al final verifica en local (`curl --resolve …:443:127.0.0.1`) `/wp-json/bp/v1/site`, `/wp-login.php` y la redirección del front.

### Vhost

[`cloudpanel/bp-headless-wordpress.tpl`](cloudpanel/bp-headless-wordpress.tpl) es la plantilla oficial **WordPress v2** de CloudPanel con estos ajustes:
- Sin reglas *multisite*.
- `X-Robots-Tag: noindex, nofollow` en todo el host, estáticos incluidos.
- Sin PHP en `uploads`; `debug.log` y *dotfiles* bloqueados.
- `xmlrpc.php` denegado.

Todo lo que no es un archivo va a `index.php`: `/wp-json` y `/wp-admin` funcionan normal y el plugin redirige el resto al sitio público. CloudPanel no tiene CLI para editar el vhost de un sitio existente, así que la plantilla se aplica al **crear** el sitio. Si el sitio ya existía con otra plantilla, el script lo avisa: pega el bloque en *Sites → admin.banosportatiles.net → Vhost*. Para cambiar la plantilla, crea `…-v2` y cambia `VHOST_TEMPLATE`.

## 4. Después del primer deploy

1. **Cloudflare:** una vez emitido el certificado, **activa el proxy** (nube naranja) en `admin` y deja **SSL/TLS en Full (strict)**. Las renovaciones de Let's Encrypt siguen funcionando a través del proxy, porque el origen ya tiene un certificado válido.
2. **Cloudflare (recomendado):** Cloudflare Access o *Managed Challenge* para `/wp-login.php` y `/wp-admin/`, y un *rate limiting* para `POST /wp-json/bp/v1/leads`. No actives «Cache Everything» en `admin.`, porque la API ya envía `Cache-Control` y `ETag`.
3. **Worker de Astro:** `WP_API_URL=https://admin.banosportatiles.net/wp-json/bp/v1` y el secreto `WP_LEADS_SECRET` (`wrangler secret put WP_LEADS_SECRET`) con el valor de `BP_LEADS_SECRET` de `backend/.env.deploy`.
4. **WordPress:** entra a `https://admin.banosportatiles.net/wp-admin/` (usuario `bp-admin`, contraseña en `.env.deploy`), **activa Two Factor** y crea un usuario personal por editor.
5. **CloudPanel → Cron Jobs:** si alguien edita los cron del sitio en la interfaz, CloudPanel regenera el crontab. Vuelve a ejecutar el deploy o añade allí la misma línea: `* * * * * php8.4 -d memory_limit=512M /usr/local/bin/wp --path=/home/banosportatiles-cms/htdocs/admin.banosportatiles.net cron event run --due-now --quiet`.

## 5. Verificación

```bash
API=https://admin.banosportatiles.net/wp-json/bp/v1
curl -s $API/site | jq '.brand, .sale_banner'
curl -sI https://admin.banosportatiles.net/cualquier-ruta/ | grep -iE '^(HTTP|location|x-robots-tag)'  # 301 → banosportatiles.net
curl -s -o /dev/null -w '%{http_code}\n' https://admin.banosportatiles.net/wp-json/wp/v2/users           # 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST $API/leads                                            # 401 (sin firma)
ssh connexis-prod "runuser -u banosportatiles-cms -- php8.4 /usr/local/bin/wp --path=/home/banosportatiles-cms/htdocs/admin.banosportatiles.net eval 'var_dump(wp_mail(\"contacto@banosportatiles.net\", \"Prueba SMTP Brevo\", \"ok\"));'"
```

**Emails de leads:** cada lead se envía a `BP_LEADS_EMAIL_TO` con copia a `BP_LEADS_EMAIL_CC` por Brevo. Si Brevo falla (IP no autorizada, credenciales), el lead **se guarda igual** con `_bp_lead_status=email_failed` y el error en `_bp_lead_email_error`. Para reintentar:

```bash
ssh connexis-prod "runuser -u banosportatiles-cms -- php8.4 /usr/local/bin/wp --path=/home/banosportatiles-cms/htdocs/admin.banosportatiles.net bp leads resend --failed"
```

## 6. Probar el deploy sin servidor

[`cloudpanel/sim/run.sh`](cloudpanel/sim/run.sh) ejecuta el `deploy.sh` real contra un CloudPanel simulado en Docker: Debian con PHP 8.4, un `clpctl` falso, la MariaDB local en `127.0.0.1` y un `ssh` falso con `docker exec`. Hace una pasada nueva, otra idempotente y una tercera con `--seed-force`, y verifica 17 puntos: vhost, permisos, constantes, contraseña del admin, plugins (incluidos Site Reviews, Rank Math y Safe SVG), setups, ajustes, contenido de ejemplo, API, aviso de venta, nombre del sitio en Rank Math, cron y certificado. Requiere el Docker local levantado (`bin/setup.sh`). Úsalo antes de cada deploy real si cambió algo en `deploy/cloudpanel/`.

## 7. Backups

| Qué | Cómo |
|---|---|
| Panel | Backups automáticos de CloudPanel (*Admin Area → Backups*) a un almacenamiento remoto (Hetzner Storage Box, S3 o R2) |
| BD | `clpctl db:export --databaseName=banosportatiles-cms --file=/home/banosportatiles-cms/backups/db-$(date +%F).sql.gz` en un cron diario (14 días) |
| Uploads | `rsync`/`rclone` semanal de `wp-content/uploads` al mismo almacenamiento |

Prueba una restauración al menos una vez por trimestre: `clpctl db:import` y copia de `uploads` en local con `bin/reset.sh`.

## 8. Migrar datos locales (opcional)

`deploy/package.sh` (sin argumentos) genera en `backend/dist/` los zips del plugin y del tema, más `db.sql.gz` y `uploads.tar.gz` con las URLs ya reescritas a `https://admin.banosportatiles.net`. Solo hace falta si hubo trabajo editorial en local. Si no, el camino recomendado es `deploy.sh --seed=<bundle del frontend>`. Para importar un dump: `clpctl db:import --databaseName=banosportatiles-cms --file=db.sql.gz`, descomprime `uploads.tar.gz` en `wp-content/`, `wp user delete bp-admin --reassign=<tu usuario>` y `wp bp cache flush`.

## 9. Rollback

El sitio público no depende del CMS en caliente, porque Astro se construye con la API. Si un deploy del CMS falla:
- **Código:** vuelve a desplegar el commit anterior (`git checkout <sha> -- wp-content deploy && deploy/cloudpanel/deploy.sh`).
- **Datos:** restaura el último backup de CloudPanel.
- **Borrado total** del CMS (irreversible): `clpctl site:delete --domainName=admin.banosportatiles.net --force`.

## 10. Actualización a la 1.1.0 (valoraciones, precios, TOC, Rank Math, menús e imágenes)

Una sola vez, en este orden (plan `docs/plans/2026-09-24_valoraciones-toc-rankmath-precios.md` §9). `WP` abrevia el
WP-CLI del sitio en el servidor:

```bash
WP='runuser -u banosportatiles-cms -- php8.4 -d memory_limit=512M /usr/local/bin/wp --path=/home/banosportatiles-cms/htdocs/admin.banosportatiles.net'

# 1. Bundle del seed del frontend (el mismo que usa el build)
(cd ../frontend && pnpm seed:bundle --out ../backend/dist/seed-front)

# 2. Código + plugins (Site Reviews, Rank Math, Safe SVG) + setups + seed reescrito completo
deploy/cloudpanel/deploy.sh --dry-run --seed=dist/seed-front --seed-force   # revisar
deploy/cloudpanel/deploy.sh --seed=dist/seed-front --seed-force

# 3. SEO de SCF → Rank Math (lo que el seed no cubra; nunca pisa lo que ya tenga Rank Math)
ssh connexis-prod "$WP bp seo migrate-rankmath --dry-run"
ssh connexis-prod "$WP bp seo migrate-rankmath"

# 4. Comprobación
ssh connexis-prod "$WP plugin list --status=active --field=name"
ssh connexis-prod "$WP bp reviews stats"      # todo en 0: sin votos de ejemplo
API=https://admin.banosportatiles.net/wp-json/bp/v1
curl -s $API/site | jq '{seo, ratings: .ratings.enabled, toc: .toc.enabled_types}'
curl -s "$API/ratings" | jq 'length'
curl -s -o /dev/null -w '%{http_code}\n' -X POST $API/ratings              # 401 (sin firma)
```

Después, en el admin:
- **Site Reviews → Ajustes → Notificaciones:** el email que recibe los avisos de opiniones nuevas (por defecto, el del admin de WordPress).
- **Ajustes del sitio → Despliegue:** el deploy hook y el rebuild diario (activo por defecto a las 04:00).
- **Ajustes del sitio → Valoraciones:** qué tipos de página tienen estrellas u opiniones.

`--seed-force` reescribe con el seed todo el contenido importado, incluidas las ediciones hechas en el CMS después del
último import. Si alguien editó páginas en el CMS, hay que pasar esos cambios al seed antes, o desplegar sin `--seed-force`.
Así, solo se reimportan los YAML que cambiaron.

Rollback de la 1.1.0: `ssh connexis-prod "$WP plugin deactivate site-reviews seo-by-rank-math safe-svg"`.
bp-headless sigue funcionando: el SEO sale de SCF y el Node no trae `rating`. Si hace falta, vuelve a desplegar el commit anterior (§9).
