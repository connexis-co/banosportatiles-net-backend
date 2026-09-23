# banosportatiles.net — backend (WordPress headless)

WordPress 7.x **solo como CMS/API** en `admin.banosportatiles.net`. El sitio público es Astro 7 en Cloudflare
(`https://banosportatiles.net`), que consume la API `bp/v1` en el build y para las previews, y envía los leads
firmados con HMAC. Plan y contrato: [`docs/plans/2026-09-22_reestructuracion-headless.md`](../docs/plans/2026-09-22_reestructuracion-headless.md).

```
backend/
├── docker-compose.yml          db (MariaDB 11.8) · wordpress (7.1 + PHP 8.4, :8080) · cron · mailpit (:8025) · wpcli · hook-sink (tests)
├── docker-compose.sync.yml     modo "sync" automático cuando Docker no puede montar ~/Documents (TCC de macOS)
├── bin/                        setup.sh · reset.sh · sync.sh · provision.sh (dentro del contenedor) · lib.sh
├── wp-content/
│   ├── plugins/bp-headless/    el plugin del CMS headless (PSR-4 propio, sin Composer en runtime)
│   ├── plugins/bp-sitio-en-venta/  aviso «sitio en venta / alquiler» (independiente y portable)
│   └── themes/bp-headless-theme/  tema mínimo que nunca se renderiza
├── seed-sample/                bundle.json de ejemplo + assets/ (placeholder)
├── tests/                      Pest (Unit + Brain Monkey) · smoke.sh (end-to-end con curl + jq)
└── deploy/                     README.md (runbook del VPS) · package.sh (zips + export)
```

## Puesta en marcha (local)

Requisitos: Docker Desktop, `jq`, `openssl`, PHP 8.3+ y Composer (solo para las herramientas de desarrollo).

```bash
cd ~/dev/banosportatiles-net/backend   # (también accesible por el symlink de ~/Documents/JP Projects/banosportatiles.net/backend)
bin/setup.sh            # idempotente: .env con secretos aleatorios → contenedores → WP instalado → seed importado
composer install        # Pest, Pint, PHPStan (dev)
composer check          # pint --test + phpstan (nivel 8) + pest
tests/smoke.sh          # 110 comprobaciones end-to-end contra el Docker levantado
deploy/cloudpanel/sim/run.sh   # deploy a CloudPanel simulado en Docker (14 comprobaciones)
```

| URL | Qué |
|---|---|
| http://localhost:8080/wp-admin/ | Admin (usuario `bp-admin`, contraseña en `backend/.env` → `WP_ADMIN_PASSWORD`) |
| http://localhost:8080/wp-json/bp/v1/site | API |
| http://localhost:8025 | Mailpit: todos los emails (STARTTLS + AUTH obligatorios, como Brevo) |
| http://localhost:4321 | Front Astro local (destino de la redirección 301 y de las previews) |

Comandos útiles:

```bash
docker compose run --rm wpcli wp <comando>        # WP-CLI
docker compose run --rm wpcli wp bp import-seed /opt/bp/seed/bundle.json --assets=/opt/bp/seed/assets [--dry-run] [--force] [--no-deploy]
docker compose run --rm wpcli wp bp deploy         # dispara el deploy hook ahora
docker compose run --rm wpcli wp bp cache flush    # vacía la caché de la API
docker compose run --rm wpcli wp bp preview-url 12 # URL de preview firmada (15 min)
docker compose run --rm wpcli wp bp leads resend 42 [--failed]   # reenvía el email de leads (p. ej. email_failed)
bin/sync.sh                                        # en modo sync: copia el código al contenedor
bin/reset.sh --yes                                 # borra BD + uploads locales y vuelve a montar todo
deploy/package.sh                                  # zips del plugin/tema + export de BD/uploads (URL → admin.banosportatiles.net)
deploy/cloudpanel/deploy.sh --dry-run              # deploy a producción (connexis-prod): ver deploy/README.md
```

Para importar el seed real del frontend: `BP_SEED_DIR=../frontend/<carpeta-con-bundle.json-y-assets> bin/setup.sh`.

> **Montajes y macOS.** El código vive en `~/dev/banosportatiles-net/backend` y los scripts resuelven siempre la ruta física
> (`pwd -P`), así que Docker monta el plugin y el tema **en vivo**. Si el repo se clona dentro de `~/Documents`, Docker Desktop
> no puede montarlo (privacidad TCC): `setup.sh` lo detecta y activa el **modo sync** (volúmenes con nombre + `bin/sync.sh`).
>
> **wordpress.org y `localhost:8080`.** La API y las descargas de wordpress.org responden **434** a las peticiones cuyo
> User-Agent es `WordPress/x; http://localhost:8080`. Por eso `provision.sh` descarga los zips públicos (plugins y
> traducciones es_CO) con `wget` y los instala desde archivo. En el servidor, `wp plugin install` funciona normal.

## Arquitectura del plugin `bp-headless`

Namespace `BanosPortatiles\Headless`, PHP 8.3+ con `strict_types`, clases `final` de una sola responsabilidad y
una raíz de composición (`Plugin::boot()`) que registra módulos `Hookable`.

| Carpeta | Responsabilidad |
|---|---|
| `Content/` | CPT `equipo` (público, `/equipos/{slug}/`), `faq` (sin URL; REST sí) y `lead` (privado, sin REST, no se crea desde el admin) · taxonomías `ciudad` y `tema_faq` · plantillas virtuales (`theme_page_templates`): home, hub-servicio, servicio, ciudad, equipos, legal, landing, venta-sitio, contacto, cotizar, blog-index |
| `Fields/` | Grupos SCF **en código** con keys estables derivadas de la ruta (`field_bp_hero_cta_primario_label`): SEO, Hero, Secciones (12 layouts), FAQs, Blog, Equipo, Ciudad, Categoría y la options page «Ajustes del sitio». Los nombres de campo = claves del contrato seed |
| `Normalizer/` | `WP_Post` → `Node` (contrato §5). Los normalizadores de secciones, hero, SEO y FAQs son puros; las relaciones se resuelven con `ReferenceResolver` |
| `Html/` | `ContentRenderer` (bloques/autop/shortcodes → `wp_kses`) + `HtmlCleaner` (DOM puro: seguridad, enlaces internos relativos, ids en h2/h3) |
| `Routing/` | `UriResolver`: URIs públicas ⇄ objetos WP (`/`, `/{padres}/{slug}/`, `/blog/{slug}/`, `/equipos/{slug}/`, `/blog/tema/{cat}/`) |
| `Rest/` | Rutas `bp/v1`, `HttpCache` (Cache-Control + ETag/304 + X-Robots-Tag) y `Cors` (lista blanca) |
| `Cache/` | Caché de respuestas en transients con versión, invalidada por `ContentChangeListener` (posts, términos, opciones, redirecciones) |
| `Leads/` | Validación pura, rate limit con transients, repositorio CPT, email (`wp_mail`) y webhook firmado vía WP-Cron |
| `Security/` | HMAC, tokens de preview, XML-RPC/emojis/oEmbed off, comentarios off (UI + REST), `/wp/v2/users` con auth, headers de seguridad en el admin |
| `Headless/` | Redirección 301 del front al sitio público, `noindex` total del host y links de preview al front |
| `Deploy/` | Deploy hook con debounce de 60 s (WP-Cron), botón «Publicar cambios en el sitio» y widget del dashboard |
| `Import/` + `Cli/` | `wp bp import-seed` (upsert idempotente en dos pasadas + sideload de imágenes) y comandos de soporte |
| `Admin/`, `Mail/` | Columnas Plantilla/URI, admin de leads con estado comercial, aviso headless y SMTP opcional (Mailpit en local) |

### Configuración (constante en `wp-config.php` → variable de entorno → opción)

| Nombre | Uso | Default |
|---|---|---|
| `BP_FRONTEND_URL` | Sitio público (redirección, previews, CORS) | `https://banosportatiles.net` (local: `http://localhost:4321`) |
| `BP_LEADS_SECRET` | HMAC de `POST /leads` y del webhook de leads | — (sin él `/leads` responde 503) |
| `BP_PREVIEW_SECRET` | Tokens de preview | derivado de los salts de WP |
| `BP_DEPLOY_HOOK_URL` | Deploy hook de Workers Builds | opción «Ajustes → Despliegue» |
| `BP_CORS_ORIGINS` | Orígenes CORS extra, separados por comas (p. ej. `*.workers.dev`) | — |
| `BP_LEADS_WEBHOOK_URL` | Webhook opcional por lead | opción «Ajustes → Formularios» |
| `BP_LEADS_EMAIL` | `false` = guarda los leads sin enviar email | `true` |
| `BP_LEADS_EMAIL_TO` | Destinatarios del email de cada lead (coma) | opción «Ajustes → Formularios» → `contacto@banosportatiles.net` |
| `BP_LEADS_EMAIL_CC` | Copias (coma; `none` las desactiva) | `connexis.co@gmail.com` |
| `BP_SMTP_HOST`, `BP_SMTP_PORT`, `BP_SMTP_USER`, `BP_SMTP_PASS`, `BP_SMTP_FROM`, `BP_SMTP_FROM_NAME` | SMTP de **todo** `wp_mail` vía `phpmailer_init` (sin plugin). Producción: Brevo `smtp-relay.brevo.com:587` | local: Mailpit |
| `BP_SMTP_SECURE` | `tls` (STARTTLS), `ssl` o `none`; vacío = según el puerto (587 → STARTTLS, 465 → SSL) | — |

## Plugin `bp-sitio-en-venta` (aviso de venta o alquiler)

Plugin **independiente** (no requiere bp-headless ni SCF; el sitio `.co` también puede usarlo). Namespace
`BanosPortatiles\SitioEnVenta`, PHP 8.3 strict, JS vanilla en el admin.

**Admin → «Sitio en venta»** (icono megáfono, `manage_options`):
- **Tarjetas:** estado y modo (`venta`, `alquiler` o `venta_o_alquiler`; cambia los textos sugeridos que no hayas personalizado), mensaje con contadores, WhatsApp, botón secundario, colores, ubicaciones y comportamiento.
- **WhatsApp:** número E.164 validado en vivo (`300 123 4567` → `+573001234567`) y mensaje con `{sitio}` y `{url}`. Es el único número de WhatsApp del sitio.
- **Colores:** selector de color de WordPress con advertencia WCAG AA en vivo (4,5:1 textos, 3:1 botón sobre fondo).
- **Vista previa en vivo** de la barra superior y de la tarjeta lateral.
- **«Guardar cambios»** o **«Guardar y publicar en el sitio»** (pide un rebuild a bp-headless si está activo; si no, solo guarda).

**Opción única** `bp_sitio_en_venta`, saneada y validada (los valores inválidos se rechazan con un mensaje por campo):

| Campo | Tipo / regla |
|---|---|
| `enabled`, `show_whatsapp`, `show_secondary`, `dismissible` | bool (`show_whatsapp` se apaga si no hay número) |
| `modo` | `venta` \| `alquiler` \| `venta_o_alquiler` |
| `headline` (≤ 90), `message` (≤ 220), `cta_whatsapp_label`, `secondary_label` (≤ 40) | texto plano; vacío = texto por defecto del modo |
| `whatsapp_number` | E.164 (`+57 3XX XXX XXXX`) |
| `whatsapp_message` (≤ 500) | con `{sitio}` y `{url}` |
| `secondary_url` | ruta del sitio (`/sitio-en-venta/`) o URL `https://` |
| `colors` | `{bg, text, accent, accent_text}` hex `#rrggbb` |
| `placements` | subconjunto de `top_bar`, `bottom_bar`, `home_after_hero`, `sidebar_card`, `footer_block` |
| `dismiss_days` | 1–30 |
| `exclude_paths` | lista de rutas (`/cotizar/` exacta, `/blog/*` sección); default `/cotizar/` |

**API:** `GET /wp-json/bp-venta/v1/config[?path=/ruta/]` (pública; transient invalidado al guardar, `Cache-Control: public, max-age=60`, ETag = `version` y 304). Devuelve la opción más `version`, un hash que cambia con cada edición y sirve para volver a mostrar avisos cerrados. Con `?path=` añade `visible` (activo y no excluido). `GET /bp/v1/site → sale_banner` es **el mismo objeto** (fuente única); el `sale_banner` de SCF desapareció.

**Integración solo por hooks** (cada plugin funciona sin el otro):

| Hook | Quién lo usa |
|---|---|
| filtro `bp_headless/sale_banner` | bp-headless lo pide para `/site` (se omite si el plugin no está activo) |
| acción `bp_sitio_en_venta/updated` | se dispara en cada cambio de la opción; bp-headless vacía su caché (sin deploy) |
| filtro `bp_headless/request_deploy` | «Guardar y publicar» pide el rebuild (debounce de 60 s) |
| filtro `bp_sitio_en_venta/import` | `wp bp import-seed` guarda `site.sale_banner` aquí |

## API `bp/v1`

Base: `https://admin.banosportatiles.net/wp-json/bp/v1` (local: `http://localhost:8080/wp-json/bp/v1`).
Los GET públicos envían `Cache-Control: public, max-age=30` + `ETag` (y responden **304** a `If-None-Match`). Las previews y
los leads envían `private, no-store`. Todo el host lleva `X-Robots-Tag: noindex, nofollow`.

**Convenciones:** las URIs son relativas, con slash inicial y final. Las propiedades opcionales se **omiten** (nunca
`null`). `hero`, `sections`, `faqs` y `/site` conservan las claves del contrato seed; el nivel superior de `Node` usa
camelCase. Las imágenes son `{src, width, height, alt}` con `src` absoluto al CMS.

| Método y ruta | Auth | Respuesta |
|---|---|---|
| `GET /site` | pública | ajustes, menús, banner de venta, contacto, legal, analítica, `ciudades[]`, `categorias[]` |
| `GET /routes` | pública | `[{uri, type, id, template, modified, noindex}]` |
| `GET /content?type=page\|post\|equipo&page=1&per_page=50` | pública | `Node[]` + `X-WP-Total` / `X-WP-TotalPages` (máx. 100 por página) |
| `GET /node?uri=/ruta/` | pública; `?token=` → borradores | un `Node` (`404` si no está publicado; `400` sin `uri`) |
| `GET /faqs` | pública | `[{id, q, a, temas[]}]` |
| `GET /redirects` | pública | `[{from, to, code}]` (Redirection, sin regex) |
| `POST /leads` | HMAC | `201 {ok, reference}` · `401` firma · `422` validación · `429` límite · `503` sin secreto |

### Ejemplos (salida real del seed de ejemplo, recortada)

`GET /site`
```json
{
  "brand": { "name": "BañosPortátiles.net", "tagline": "Baños portátiles y saneamiento en Colombia" },
  "contact": { "whatsapp": "", "phone": "", "email": "", "horario": "Lun–Sáb 7:00–18:00" },
  "social": [],
  "legal": { "responsable": "", "razon_social": "", "nit": "", "direccion": "", "ciudad": "", "email_datos": "" },
  "analytics": { "ga4": "", "gtm": "" },
  "forms": { "turnstile_site_key": "" },
  "sale_banner": { "enabled": true, "modo": "venta_o_alquiler", "headline": "Este sitio web está en venta o alquiler", "message": "…", "whatsapp_number": "", "show_whatsapp": false, "…": "…", "version": "a6010a78843c" },
  "menus": {
    "header": [{ "label": "Alquiler", "href": "/alquiler-de-banos-portatiles/", "children": [{ "label": "Medellín", "href": "/alquiler-de-banos-portatiles/medellin/" }] }],
    "footer": [{ "title": "Servicios", "links": [{ "label": "Alquiler de baños portátiles", "href": "/alquiler-de-banos-portatiles/" }] }]
  },
  "ciudades": [{ "slug": "medellin", "name": "Medellín", "departamento": "Antioquia", "autoridad_ambiental": "Área Metropolitana del Valle de Aburrá (AMVA)", "lat": 6.2442, "lng": -75.5812, "cercanos": ["Envigado", "Bello"], "nota": "…" }],
  "categorias": [{ "slug": "pozos-septicos", "name": "Pozos y tanques sépticos", "description": "…", "uri": "/blog/tema/pozos-septicos/", "count": 1, "pillar": "pozo-septico-guia", "pillarUri": "/blog/pozo-septico-guia/" }]
}
```

`GET /routes`
```json
[
  { "uri": "/", "type": "page", "id": 10, "template": "home", "modified": "2026-09-22T21:41:28-05:00", "noindex": false },
  { "uri": "/blog/pozo-septico-guia/", "type": "post", "id": 9, "template": "post", "modified": "2026-09-22T08:00:00-05:00", "noindex": false }
]
```

`GET /node?uri=/alquiler-de-banos-portatiles/medellin/`
```json
{
  "id": 12, "type": "page", "template": "ciudad",
  "uri": "/alquiler-de-banos-portatiles/medellin/", "slug": "medellin",
  "title": "Alquiler de baños portátiles en Medellín", "excerpt": "Alquiler de baños portátiles en Medellín y el Valle de Aburrá.",
  "order": 20,
  "contentHtml": "<h2 id=\"banos-portatiles-en-medellin\">Baños portátiles en Medellín</h2>\n<p>… <a href=\"/alquiler-de-banos-portatiles/\">alquiler de baños portátiles</a> …</p>",
  "seo": { "title": "Alquiler de Baños Portátiles en Medellín | Cotiza", "description": "…", "noindex": false, "keyword": "alquiler de baños portátiles medellín" },
  "hero": {
    "eyebrow": "Medellín y Valle de Aburrá", "h1": "Alquiler de baños portátiles en Medellín", "lead": "…",
    "bullets": ["Accesos en ladera", "Eventos y obras"],
    "image": { "src": "https://admin.banosportatiles.net/wp-content/uploads/2026/09/placeholder-hero.webp", "width": 1200, "height": 630, "alt": "…" },
    "cta_primario": { "label": "Cotizar en Medellín", "href": "/cotizar/" },
    "cta_secundario": { "label": "WhatsApp", "href": "whatsapp" },
    "mostrar_formulario": true
  },
  "sections": [
    { "layout": "contenido" },
    { "layout": "coverage", "title": "Municipios cercanos", "items": ["medellin"] },
    { "layout": "gallery", "title": "Galería de ejemplo", "items": [{ "src": "…", "width": 1200, "height": 630, "alt": "…" }] }
  ],
  "faqs": [{ "q": "¿Llegan a zonas de ladera?", "a": "Se confirma en cada caso: …" }],
  "breadcrumbs": [
    { "name": "Inicio", "uri": "/" },
    { "name": "Alquiler de baños portátiles", "uri": "/alquiler-de-banos-portatiles/" },
    { "name": "Alquiler de baños portátiles en Medellín", "uri": "/alquiler-de-banos-portatiles/medellin/" }
  ],
  "parent": { "id": 11, "uri": "/alquiler-de-banos-portatiles/", "title": "Alquiler de baños portátiles" },
  "children": [],
  "terms": { "ciudad": [{ "id": 2, "slug": "medellin", "name": "Medellín", "autoridad_ambiental": "Área Metropolitana del Valle de Aburrá (AMVA)" }] },
  "image": { "src": "…/placeholder-hero.webp", "width": 1200, "height": 630, "alt": "…" },
  "published": "2026-09-22T21:41:28-05:00", "modified": "2026-09-22T21:41:28-05:00"
}
```

Un post añade `"blog": {"pillar": true, "keyPoints": [...], "sources": [{"title","url"}], "readingMinutes": 1, "related": [], "relatedServices": ["/alquiler-de-banos-portatiles/"], "author": "…"}` (y `pillarUri` en los satélites).
Un equipo añade `"equipo": {"specs": [{"k","v"}], "modalidad": ["alquiler","venta"], "usos": ["Obras","Eventos"], "gallery": []}` (y `fichaPdf` si existe).
Una preview añade `"preview": true`.

`GET /faqs` → `[{"id": "faq-cuantos-banos-por-persona", "q": "¿Cuántos baños portátiles necesito?", "a": "Depende del tipo de uso…", "temas": ["eventos", "obras"]}]`
(`a` es un fragmento HTML saneado; puede ser texto plano).

`GET /redirects` → `[{"from": "/medellin/", "to": "/alquiler-de-banos-portatiles/medellin/", "code": 301}]`

### `POST /leads` (servidor a servidor, desde la Astro Action)

Headers: `Content-Type: application/json`, `X-BP-Timestamp: <unix segundos>`,
`X-BP-Signature: sha256=<hex(HMAC-SHA256(BP_LEADS_SECRET, "{timestamp}.{cuerpo crudo}"))>` y, opcionalmente,
`X-BP-Client-IP` (IP del visitante, para el rate limit por IP; solo se acepta en peticiones firmadas).
Ventana: ±5 min. Cada firma se acepta **una sola vez** (anti-replay).

```json
{
  "nombre": "Ana Pérez", "telefono": "+57 300 123 4567", "email": "ana@example.com",
  "ciudad": "medellin", "servicio": "Alquiler para evento", "mensaje": "4 baños para 300 personas",
  "fecha_evento": "2026-10-15", "cantidad": 4, "pagina": "/alquiler-de-banos-portatiles/medellin/",
  "utm": { "source": "google", "medium": "cpc", "campaign": "medellin", "gclid": "…" },
  "consentimiento": true,
  "consentimiento_comercial": false
}
```

Obligatorios: `nombre` (2–120), `telefono` (7–15 dígitos, `+` opcional) y `consentimiento: true` (Ley 1581 de 2012).
Opcionales: `email`, `ciudad` (≤ 80; si coincide con un slug se asigna el término), `servicio` (≤ 120), `mensaje` (≤ 2000),
`fecha_evento` (AAAA-MM-DD), `cantidad` (1–10 000), `pagina` (ruta relativa), `utm` (`source, medium, campaign, term, content, gclid, gbraid, wbraid, fbclid`)
y `consentimiento_comercial` (acepta comunicaciones comerciales; opcional, por defecto `false`).

| Respuesta | Cuándo |
|---|---|
| `201 {"ok": true, "reference": "uuid"}` | lead guardado + email (aunque el email falle) + webhook programado |
| `401 bp_invalid_signature` / `bp_replayed_request` | sin firma, firma inválida, fuera de ventana o reenvío |
| `422 bp_invalid_lead` + `data.errors {campo: mensaje}` | validación (mensajes en español) |
| `429 bp_rate_limited` + `Retry-After` | > 5 leads / 10 min por IP del visitante, o > 20 firmas fallidas / 10 min por IP de red |
| `503 bp_leads_disabled` | falta `BP_LEADS_SECRET` |

Firma en el Worker (Web Crypto):

```ts
const ts = Math.floor(Date.now() / 1000).toString();
const body = JSON.stringify(payload);
const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(env.WP_LEADS_SECRET),
  { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(`${ts}.${body}`));
const signature = 'sha256=' + [...new Uint8Array(mac)].map((b) => b.toString(16).padStart(2, '0')).join('');
await fetch(`${env.WP_API_URL}/leads`, { method: 'POST', body, headers: {
  'Content-Type': 'application/json', 'X-BP-Timestamp': ts, 'X-BP-Signature': signature,
  'X-BP-Client-IP': request.headers.get('CF-Connecting-IP') ?? '' } });
```

El webhook opcional recibe `{"event": "lead.created", "reference", "created_at", "lead": {…}, "admin_url"}` firmado con el mismo esquema.

**Email de cada lead** (lo envía WordPress, no el Worker, porque Brevo solo autoriza la IP del servidor):
- A `BP_LEADS_EMAIL_TO` (`contacto@banosportatiles.net`) con copia a `BP_LEADS_EMAIL_CC` (`connexis.co@gmail.com`).
- Asunto `Nueva cotización: <servicio> en <ciudad> — <nombre>`.
- Cuerpo HTML con alternativa de texto: todos los campos, consentimientos, URL de origen, UTM, fecha en hora de Colombia, referencia y enlace al CMS.
- `Reply-To` con el email del prospecto, si lo dejó.

Si el envío falla, el lead queda guardado con `_bp_lead_status=email_failed`, el error en `_bp_lead_email_error` (también en el log de PHP) y el contador en `_bp_lead_email_attempts`. Se reintenta con `wp bp leads resend <id>` o `wp bp leads resend --failed`. Otros estados: `email_sent`, `email_disabled` (`BP_LEADS_EMAIL=false`) y `email_skipped` (sin destinatario). El listado de leads del admin muestra el estado en la columna «Aviso».

### Previews

El botón *Vista previa* abre `{BP_FRONTEND_URL}/api/preview?uri=/ruta/&token=…` (token HMAC de 15 min). El endpoint del
front llama a `GET /bp/v1/node?token=…` desde el servidor y renderiza el borrador. En contenido ya publicado se muestra el
autoguardado más reciente (título, contenido y extracto; los campos SCF se leen del post guardado).

### Deploy hook

Cualquier cambio publicado (páginas, posts, equipos, FAQs, términos, ajustes o redirecciones) programa **un** POST al
deploy hook 60 s después, reiniciando el contador con cada cambio (debounce). El admin incluye el botón «Publicar cambios en el
sitio» en la barra superior y un widget del dashboard con el último disparo, su resultado y el siguiente programado.

## Seed → WordPress

`wp bp import-seed <bundle.json> [--assets=<dir>] [--dry-run] [--force] [--no-deploy]`. El formato del bundle está en
[`docs/plans/contrato-contenido-seed.md` §7](../docs/plans/contrato-contenido-seed.md). Cómo funciona:

- **Upsert** por `_bp_seed_key` (`page:/uri/`, `post:slug`, `equipo:slug`, `faq:id`). Si no existe, adopta el objeto con la misma URI o slug.
- Si el hash del ítem no cambió, lo salta (`--force` reescribe todo). Una segunda pasada deja «0 creados, 0 actualizados».
- **Pasada 1:** ciudades → categorías → FAQs → ajustes → redirecciones → equipos → posts → páginas (por profundidad; padre por `parentUri`, `menu_order` por `order`, plantilla por `template`).
- **Pasada 2:** relaciones (secciones, FAQs del banco, pilares y relacionados del blog, pilar de cada categoría) y portada (`/`).
- Imágenes: `image`, `hero.image`, galerías, `og_image` y `<img>` locales dentro de `contentHtml` se suben a la biblioteca (deduplicadas por SHA-1) desde `--assets`.

## Calidad

| Herramienta | Resultado |
|---|---|
| `vendor/bin/pest` | 158 tests / 591 aserciones (HtmlCleaner, Slugger, UriResolver, HMAC, PreviewToken, validación, email y rate limit de leads, normalizadores, ciudades, round-trip seed → SCF → API, grupos SCF, bundle, HTTP/CORS, SMTP, flags; aviso de venta: saneamiento, E.164, contraste WCAG, forma del REST y exclusión de rutas) |
| `vendor/bin/pint --test` | preset laravel + `declare_strict_types` |
| `vendor/bin/phpstan` | **nivel 8**, `phpVersion` 8.3, con stubs de WordPress, SCF/ACF y WP-CLI, sin baseline ni ignores |
| `tests/smoke.sh` | 110 comprobaciones end-to-end (endpoints, forma del JSON, ETag/304, invalidación, leads 201/401/422/429, replay, email del lead con To/Cc/Reply-To/HTML, `BP_LEADS_EMAIL=false`, fallo SMTP → `email_failed` → `leads resend`, SMTP con STARTTLS + AUTH obligatorios, webhook firmado, 301 del front, robots, previews, hardening, CORS, deploy hook, aviso de venta: forma, 304, `?path`, fuente única con `/site`, guardar y publicar desde el admin) |
| `deploy/cloudpanel/sim/run.sh` | 14 comprobaciones del deploy real contra un CloudPanel simulado (pasada nueva + idempotente) |

## Despliegue

Producción: `https://admin.banosportatiles.net` en **connexis-prod** (Hetzner + CloudPanel), con
`deploy/cloudpanel/deploy.sh` (idempotente, `--dry-run`). Ver [`deploy/README.md`](deploy/README.md): DNS y proxy de Cloudflare,
IP autorizada en Brevo, secretos en `backend/.env.deploy`, vhost, cron real, verificación, backups y rollback.
