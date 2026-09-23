#!/usr/bin/env bash
# Smoke tests against the LOCAL Docker stack (run bin/setup.sh first). Requires curl, jq and openssl.
# Usage: tests/smoke.sh
set -uo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
# shellcheck source=../bin/lib.sh
source bin/lib.sh
bp_load_env

BASE="${WP_URL:-http://localhost:8080}"
API="$BASE/wp-json/bp/v1"
VENTA="$BASE/wp-json/bp-venta/v1"
FRONT="${BP_FRONTEND_URL:-http://localhost:4321}"
MAILPIT="http://localhost:${MAILPIT_PORT:-8025}"
TMP="$(mktemp -d)"
PASS=0
FAIL=0
SMOKE_IP="203.0.113.$((RANDOM % 250 + 1))"

ok() { PASS=$((PASS + 1)); printf '  \033[32m✔\033[0m %s\n' "$1"; }
ko() { FAIL=$((FAIL + 1)); printf '  \033[31m✘\033[0m %s\n' "$1"; [[ -n "${2-}" ]] && printf '      %s\n' "$2"; }
section() { printf '\n\033[1m%s\033[0m\n' "$1"; }
wp() { docker compose run --rm -T wpcli wp "$@" 2>/dev/null | tr -d '\r'; }

# http <name> <expected-status> <curl args…>: body → $TMP/body, headers → $TMP/headers.
http() {
  local name="$1" expected="$2" status
  shift 2
  status="$(curl -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' "$@")"
  if [[ "$status" == "$expected" ]]; then ok "$name → $status"; else ko "$name → $status (esperado $expected)" "$(head -c 300 "$TMP/body")"; fi
}
expect_json() { if jq -e "$2" "$TMP/body" >/dev/null 2>&1; then ok "$1"; else ko "$1" "jq: $2"; fi; }
expect_header() { if grep -qi "^$2" "$TMP/headers"; then ok "$1"; else ko "$1" "falta header: $2"; fi; }
expect_no_header() { if grep -qi "^$2" "$TMP/headers"; then ko "$1" "header inesperado: $2"; else ok "$1"; fi; }

sign() { printf '%s.%s' "$1" "$2" | openssl dgst -sha256 -hmac "$BP_LEADS_SECRET" -r | cut -d' ' -f1; }
post_lead() { # post_lead <name> <expected> <body> [timestamp] [signature-override]
  local ts="${4:-$(date +%s)}" sig
  sig="${5:-sha256=$(sign "$ts" "$3")}"
  http "$1" "$2" -X POST "$API/leads" -H 'Content-Type: application/json' \
    -H "X-BP-Timestamp: $ts" -H "X-BP-Signature: $sig" -H "X-BP-Client-IP: $SMOKE_IP" --data-binary "$3"
}

cleanup() {
  wp config delete BP_LEADS_EMAIL --type=constant >/dev/null
  wp config delete BP_SMTP_PASS --type=constant >/dev/null
  wp option delete bp_site_deploy_hook_url >/dev/null
  wp option delete bp_site_forms_leads_webhook_url >/dev/null
  local leads
  leads="$(wp post list --post_type=lead --post_status=any --meta_key=_bp_lead_pagina --meta_value=/smoke-test/ --format=ids)"
  [[ -n "$leads" ]] && wp post delete $leads --force >/dev/null
  docker compose --profile test stop hook-sink >/dev/null 2>&1
  rm -rf "$TMP"
}
trap cleanup EXIT

command -v jq >/dev/null && command -v openssl >/dev/null || { echo "jq y openssl son necesarios" >&2; exit 1; }
curl -fsS -o /dev/null "$API/site" || { echo "La API no responde en $API: ejecuta bin/setup.sh" >&2; exit 1; }

section "0. Preparación"
bp_sync >/dev/null 2>&1
docker compose --profile test up -d hook-sink >/dev/null 2>&1
docker compose exec -T hook-sink sh -c ': > /sink/requests.log' >/dev/null 2>&1
curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null
wp bp import-seed /opt/bp/seed/bundle.json --assets=/opt/bp/seed/assets --no-deploy >/dev/null && ok "seed importado (idempotente)" || ko "seed importado"
wp transient delete --all >/dev/null
wp bp cache flush >/dev/null
wp option update bp_site_deploy_hook_url 'http://hook-sink:9000/deploy' >/dev/null
wp option update bp_site_forms_leads_webhook_url 'http://hook-sink:9000/leads' >/dev/null

section "1. GET /site"
http "GET /site" 200 "$API/site"
expect_json "brand, contacto y legal (NIT/razón social/dirección)" '.brand.name != "" and (.contact | has("whatsapp")) and (.legal | has("nit") and has("razon_social") and has("direccion"))'
expect_json "sale_banner con enabled booleano" '.sale_banner.enabled | type == "boolean"'
expect_json "menús header (con hijos) y footer" '(.menus.header | length > 0) and (.menus.header[0].children | type == "array") and (.menus.footer[0].links | type == "array")'
expect_json "ciudades con coordenadas y categorías con pilar" '(.ciudades | length == 2) and (.ciudades[0].lat | type == "number") and (.categorias[0].pillarUri == "/blog/pozo-septico-guia/")'
expect_json "ciudades con autoridad_ambiental" '(.ciudades | map(select(.slug == "medellin"))[0].autoridad_ambiental) == "Área Metropolitana del Valle de Aburrá (AMVA)"'
expect_header "Cache-Control público" 'Cache-Control: public, max-age=30'
expect_header "X-Robots-Tag en REST" 'X-Robots-Tag: noindex, nofollow'
etag="$(grep -i '^ETag:' "$TMP/headers" | cut -d' ' -f2 | tr -d '\r')"
http "GET /site con If-None-Match → 304" 304 -H "If-None-Match: $etag" "$API/site"

section "2. GET /routes"
http "GET /routes" 200 "$API/routes"
expect_json "home y 6 rutas con {uri,type,id,template,modified,noindex}" 'length == 6 and (map(select(.uri == "/" and .template == "home")) | length == 1) and all(.[]; has("uri") and has("type") and has("id") and has("template") and has("modified") and has("noindex"))'
expect_json "URIs relativas con slash inicial y final" 'all(.[]; .uri | startswith("/") and endswith("/"))'

section "3. GET /content"
NODE_KEYS='all(.[]; has("id") and has("type") and has("template") and has("uri") and has("slug") and has("title") and has("excerpt") and has("contentHtml") and has("seo") and has("sections") and has("faqs") and has("breadcrumbs") and has("children") and has("terms") and has("published") and has("modified") and (.terms | type == "object") and (.seo | has("title") and has("description") and has("noindex")))'
http "GET /content?type=page" 200 "$API/content?type=page&per_page=50"
expect_header "X-WP-Total" 'X-WP-Total: 4'
expect_json "4 páginas con la forma Node" "length == 4 and $NODE_KEYS"
expect_json "secciones normalizadas {layout,…} con referencias resueltas" '[.[] | select(.uri == "/") | .sections[] | select(.layout == "equipment_grid") | .items[0]] == ["bano-portatil-estandar"]'
expect_json "hero con claves del seed" '[.[] | select(.uri == "/")][0].hero | has("h1") and has("bullets") and has("cta_primario") and (.mostrar_formulario | type == "boolean") and (.image.width == 1200)'
http "GET /content?type=page&per_page=1&page=2" 200 "$API/content?type=page&per_page=1&page=2"
expect_header "paginación X-WP-TotalPages" 'X-WP-TotalPages: 4'
http "GET /content?type=post" 200 "$API/content?type=post"
expect_json "post pilar con bloque blog" "$NODE_KEYS and .[0].blog.pillar == true and .[0].blog.readingMinutes >= 1 and (.[0].blog.sources | length == 1) and .[0].terms.category[0].uri == \"/blog/tema/pozos-septicos/\""
http "GET /content?type=equipo" 200 "$API/content?type=equipo"
expect_json "equipo con specs, modalidad e imagen" "$NODE_KEYS and (.[0].equipo.specs | length == 2) and .[0].equipo.modalidad == [\"alquiler\",\"venta\"] and .[0].image.src != null"
http "GET /content?type=lead (no permitido)" 400 "$API/content?type=lead"

section "4. GET /node"
http "GET /node?uri=/alquiler-de-banos-portatiles/medellin/" 200 "$API/node?uri=/alquiler-de-banos-portatiles/medellin/"
expect_json "parent, breadcrumbs y ciudad" '.parent.uri == "/alquiler-de-banos-portatiles/" and (.breadcrumbs | map(.uri)) == ["/","/alquiler-de-banos-portatiles/","/alquiler-de-banos-portatiles/medellin/"] and .terms.ciudad[0].slug == "medellin"'
expect_json "terms.ciudad con autoridad_ambiental" '.terms.ciudad[0].autoridad_ambiental == "Área Metropolitana del Valle de Aburrá (AMVA)"'
expect_json "contentHtml: ids en h2 y enlaces internos relativos" '(.contentHtml | test("<h2 id=\"banos-portatiles-en-medellin\"")) and (.contentHtml | test("href=\"/alquiler-de-banos-portatiles/\"")) and (.contentHtml | test("localhost:8080/alquiler") | not)'
http "GET /node?uri=/alquiler-de-banos-portatiles/ (FAQs inline + banco)" 200 "$API/node?uri=/alquiler-de-banos-portatiles/"
expect_json "FAQs inline + refs, hijos ordenados e ids únicos" '(.faqs | length == 3) and (.children | map(.uri)) == ["/alquiler-de-banos-portatiles/medellin/","/alquiler-de-banos-portatiles/cali/"] and (.contentHtml | test("que-debe-incluir-el-alquiler-2"))'
http "GET /node?uri=/blog/pozo-septico-guia/" 200 "$API/node?uri=/blog/pozo-septico-guia/"
expect_json "breadcrumbs de post con categoría" '(.breadcrumbs | length == 4) and .breadcrumbs[2].uri == "/blog/tema/pozos-septicos/"'
http "GET /node?uri=/no-existe/" 404 "$API/node?uri=/no-existe/"
http "GET /node sin uri" 400 "$API/node"

section "5. GET /faqs y /redirects"
http "GET /faqs" 200 "$API/faqs"
expect_json "banco de 3 FAQs con temas" 'length == 3 and all(.[]; has("id") and has("q") and has("a") and (.temas | type == "array"))'
http "GET /redirects" 200 "$API/redirects"
expect_json "redirecciones 301 desde Redirection" 'length == 2 and (map(select(.from == "/medellin/" and .to == "/alquiler-de-banos-portatiles/medellin/" and .code == 301)) | length == 1)'

section "6. Invalidación de caché al guardar"
cali_id="$(wp post list --post_type=page --name=cali --field=ID)"
curl -s "$API/node?uri=/alquiler-de-banos-portatiles/cali/" >/dev/null
wp post update "$cali_id" --post_title='Cali (smoke)' >/dev/null
http "GET /node tras editar" 200 "$API/node?uri=/alquiler-de-banos-portatiles/cali/"
expect_json "el cambio se ve de inmediato" '.title == "Cali (smoke)"'
wp post update "$cali_id" --post_title='Alquiler de baños portátiles en Cali' >/dev/null

section "7. POST /leads (HMAC + anti-replay + validación + rate limit)"
VALID='{"nombre":"Prueba Smoke","telefono":"+57 300 000 0000","email":"smoke@example.com","ciudad":"medellin","servicio":"Alquiler para evento","mensaje":"Prueba automática","cantidad":2,"pagina":"/smoke-test/","utm":{"source":"smoke"},"consentimiento":true}'
http "sin firma → 401" 401 -X POST "$API/leads" -H 'Content-Type: application/json' --data-binary "$VALID"
post_lead "firma inválida → 401" 401 "$VALID" "$(date +%s)" "sha256=deadbeef"
post_lead "timestamp de hace 10 min → 401" 401 "$VALID" "$(($(date +%s) - 600))"
ts="$(date +%s)"
post_lead "lead válido → 201" 201 "$VALID" "$ts"
expect_json "respuesta con referencia" '.ok == true and (.reference | test("^[0-9a-f-]{36}$"))'
expect_header "Cache-Control: no-store" 'Cache-Control: no-store'
post_lead "reenvío idéntico (replay) → 401" 401 "$VALID" "$ts"
post_lead "lead inválido firmado → 422" 422 '{"nombre":"X","pagina":"/smoke-test/","consentimiento":false}'
expect_json "errores por campo" '.data.errors | has("nombre") and has("telefono") and has("consentimiento")'
lead_count="$(wp post list --post_type=lead --post_status=any --meta_key=_bp_lead_pagina --meta_value=/smoke-test/ --format=count)"
[[ "$lead_count" == "1" ]] && ok "lead guardado como CPT privado" || ko "lead guardado ($lead_count)"
sleep 1
curl -s "$MAILPIT/api/v1/messages" > "$TMP/body"
expect_json "email del lead: asunto «Nueva cotización: … en Medellín — …»" '.messages[0].Subject == "Nueva cotización: Alquiler para evento en Medellín — Prueba Smoke"'
expect_json "destinatarios: To contacto@, Cc connexis.co@gmail.com, Reply-To del prospecto" '(.messages[0].To | map(.Address)) == ["contacto@banosportatiles.net"] and (.messages[0].Cc | map(.Address)) == ["connexis.co@gmail.com"] and (.messages[0].ReplyTo | map(.Address)) == ["smoke@example.com"]'
msg_id="$(jq -r '.messages[0].ID' "$TMP/body")"
curl -s "$MAILPIT/api/v1/message/$msg_id" > "$TMP/body"
expect_json "cuerpo HTML con todos los campos, URL de origen, UTM y hora de Colombia" "(.HTML | test(\"Comunicaciones comerciales\")) and (.HTML | contains(\"$FRONT/smoke-test/\")) and (.HTML | test(\"source=smoke\")) and (.HTML | test(\"hora de Colombia\")) and (.Text | contains(\"Teléfono: +573000000000\"))"
lead_id="$(wp post list --post_type=lead --post_status=any --meta_key=_bp_lead_telefono --meta_value=+573000000000 --field=ID | head -1)"
[[ "$(wp post meta get "$lead_id" _bp_lead_status)" == "email_sent" ]] && ok "meta del lead: status=email_sent" || ko "meta _bp_lead_status (email_sent)"
wp cron event run bp_headless_lead_webhook >/dev/null
sink="$(docker compose exec -T hook-sink cat /sink/requests.log 2>/dev/null)"
wh="$(printf '%s\n' "$sink" | grep '"path":"/leads"' | tail -1)"
if [[ -n "$wh" ]]; then
  body="$(jq -r '.body' <<<"$wh")"; wts="$(jq -r '.headers["X-Bp-Timestamp"] // .headers["X-BP-Timestamp"]' <<<"$wh")"
  wsig="$(jq -r '.headers["X-Bp-Signature"] // .headers["X-BP-Signature"]' <<<"$wh")"
  [[ "$wsig" == "sha256=$(sign "$wts" "$body")" && "$(jq -r .event <<<"$body")" == "lead.created" ]] && ok "webhook de lead entregado y firmado (HMAC verificado)" || ko "firma del webhook"
else
  ko "webhook de lead entregado"
fi
limited=0
for i in 1 2 3 4 5 6; do
  body="${VALID/Prueba automática/Rate $i}"
  ts="$(date +%s)"
  code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/leads" -H 'Content-Type: application/json' -H "X-BP-Timestamp: $ts" -H "X-BP-Signature: sha256=$(sign "$ts" "$body")" -H "X-BP-Client-IP: $SMOKE_IP" --data-binary "$body")"
  if [[ "$code" == "429" ]]; then limited=$i; break; fi
done
[[ "$limited" -gt 0 ]] && ok "rate limit por IP → 429 en el intento $limited" || ko "rate limit por IP"

section "7b. BP_LEADS_EMAIL=false (el Worker ya envía el email)"
wp config set BP_LEADS_EMAIL false --raw --type=constant >/dev/null
sleep 3 # OPcache revalidates wp-config.php every 2 s in the official image
before="$(curl -s "$MAILPIT/api/v1/messages" | jq '.total')"
NOMAIL='{"nombre":"Prueba Sin Email","telefono":"3000000001","pagina":"/smoke-test/","consentimiento":true}'
ts="$(date +%s)"
code="$(curl -s -o "$TMP/body" -w '%{http_code}' -X POST "$API/leads" -H 'Content-Type: application/json' -H "X-BP-Timestamp: $ts" -H "X-BP-Signature: sha256=$(sign "$ts" "$NOMAIL")" -H "X-BP-Client-IP: 198.51.100.$((RANDOM % 250 + 1))" --data-binary "$NOMAIL")"
[[ "$code" == "201" ]] && ok "lead guardado con BP_LEADS_EMAIL=false → 201" || ko "lead con BP_LEADS_EMAIL=false → $code"
nomail_id="$(wp post list --post_type=lead --post_status=any --meta_key=_bp_lead_telefono --meta_value=3000000001 --field=ID | head -1)"
[[ "$(wp post meta get "$nomail_id" _bp_lead_status)" == "email_disabled" ]] && ok "meta del lead: status=email_disabled" || ko "meta _bp_lead_status (email_disabled)"
sleep 1
[[ "$(curl -s "$MAILPIT/api/v1/messages" | jq '.total')" == "$before" ]] && ok "no se envió email duplicado" || ko "se envió un email con BP_LEADS_EMAIL=false"
wp config delete BP_LEADS_EMAIL --type=constant >/dev/null
sleep 3

section "7c. Fallo de SMTP: el lead se guarda con status=email_failed y se reenvía con WP-CLI"
wp config set BP_SMTP_PASS 'clave-incorrecta' --type=constant >/dev/null
sleep 3
FAILMAIL='{"nombre":"Prueba Fallo SMTP","telefono":"3000000002","servicio":"Venta","pagina":"/smoke-test/","consentimiento":true}'
ts="$(date +%s)"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/leads" -H 'Content-Type: application/json' -H "X-BP-Timestamp: $ts" -H "X-BP-Signature: sha256=$(sign "$ts" "$FAILMAIL")" -H "X-BP-Client-IP: 192.0.2.$((RANDOM % 250 + 1))" --data-binary "$FAILMAIL")"
[[ "$code" == "201" ]] && ok "SMTP caído: el lead igual se guarda → 201" || ko "lead con SMTP caído → $code"
wp config delete BP_SMTP_PASS --type=constant >/dev/null
sleep 3
fail_id="$(wp post list --post_type=lead --post_status=any --meta_key=_bp_lead_telefono --meta_value=3000000002 --field=ID | head -1)"
[[ "$(wp post meta get "$fail_id" _bp_lead_status)" == "email_failed" && -n "$(wp post meta get "$fail_id" _bp_lead_email_error)" ]] && ok "status=email_failed con el error registrado" || ko "status email_failed"
before="$(curl -s "$MAILPIT/api/v1/messages" | jq '.total')"
wp bp leads resend "$fail_id" >/dev/null && ok "wp bp leads resend $fail_id → enviado" || ko "wp bp leads resend"
[[ "$(wp post meta get "$fail_id" _bp_lead_status)" == "email_sent" && "$(wp post meta get "$fail_id" _bp_lead_email_attempts)" == "2" ]] && ok "status=email_sent tras el reintento (2 intentos)" || ko "status tras reenvío"
sleep 1
[[ "$(curl -s "$MAILPIT/api/v1/messages" | jq '.total')" -gt "$before" ]] && ok "el reenvío llegó a Mailpit" || ko "reenvío en Mailpit"

section "7d. SMTP tipo Brevo (STARTTLS + AUTH obligatorios en Mailpit)"
curl -s -X DELETE "$MAILPIT/api/v1/messages" >/dev/null
[[ "$(wp eval 'echo retrieve_password("bp-admin") === true ? "ok" : "fail";')" == "ok" ]] && ok "wp_mail de sistema (restablecer contraseña) enviado" || ko "retrieve_password"
sleep 1
curl -s "$MAILPIT/api/v1/messages" > "$TMP/body"
expect_json "llega por SMTP con From/FromName de BP_SMTP_FROM(_NAME)" "(.messages[0].Subject | test(\"contraseña\")) and .messages[0].From.Address == \"${BP_SMTP_FROM:-no-reply@banosportatiles.net}\" and .messages[0].From.Name == \"${BP_SMTP_FROM_NAME:-BañosPortátiles.net}\""
[[ "$(wp eval 'add_action("phpmailer_init", function ($m) { $m->Password = "incorrecta"; }, 20); echo wp_mail("x@example.com", "t", "b") ? "sent" : "rejected";')" == "rejected" ]] && ok "credenciales SMTP incorrectas → rechazado (AUTH obligatorio)" || ko "AUTH SMTP no se exige"

section "8. Headless: redirección del front, noindex y previews"
http "front del CMS → 301" 301 "$BASE/cualquier/ruta/?utm_source=x"
expect_header "Location conserva ruta y query" "Location: $FRONT/cualquier/ruta/?utm_source=x"
expect_header "X-Robots-Tag en el front" 'X-Robots-Tag: noindex, nofollow'
http "permalink de post en el CMS → URI pública" 301 "$BASE/pozo-septico-guia/"
expect_header "Location /blog/{slug}/" "Location: $FRONT/blog/pozo-septico-guia/"
http "robots.txt del CMS" 200 "$BASE/robots.txt"
if grep -q 'Disallow: /' "$TMP/body"; then ok "robots.txt: Disallow: /"; else ko "robots.txt: Disallow: /"; fi
http "wp-login.php no se redirige al front" 200 "$BASE/wp-login.php"
expect_header "headers de seguridad en login" 'X-Frame-Options: SAMEORIGIN'
draft_id="$(wp post create --post_type=page --post_title='Borrador smoke' --post_status=draft --porcelain)"
preview="$(wp bp preview-url "$draft_id")"
[[ "$preview" == "$FRONT/api/preview?uri=%2Fborrador-smoke%2F&token="* ]] && ok "preview_post_link → {front}/api/preview?uri=…&token=…" || ko "URL de preview" "$preview"
token="${preview##*token=}"
http "GET /node con token de preview (borrador)" 200 "$API/node?token=$token"
expect_json "borrador servido en modo preview" '.title == "Borrador smoke" and .preview == true'
expect_header "preview sin caché" 'Cache-Control: private, no-store'
http "token manipulado → 401" 401 "$API/node?token=${token}x"
http "borrador sin token → 404" 404 "$API/node?uri=/borrador-smoke/"
wp post delete "$draft_id" --force >/dev/null

section "9. Hardening"
http "/wp/v2/users sin autenticación → 401" 401 "$BASE/wp-json/wp/v2/users"
http "/wp/v2/comments eliminado → 404" 404 "$BASE/wp-json/wp/v2/comments"
http "oEmbed embed (expone autores) eliminado → 404" 404 "$BASE/wp-json/oembed/1.0/embed?url=$BASE/"
http "xmlrpc.php → 403" 403 -X POST "$BASE/xmlrpc.php" -d '<methodCall><methodName>system.listMethods</methodName></methodCall>'
http "CORS preflight desde el front" 200 -X OPTIONS "$API/site" -H "Origin: $FRONT" -H 'Access-Control-Request-Method: GET'
expect_header "Access-Control-Allow-Origin = front" "Access-Control-Allow-Origin: $FRONT"
http "CORS desde otro origen" 200 "$API/site" -H 'Origin: https://evil.example'
expect_no_header "sin Access-Control-Allow-Origin para orígenes ajenos" 'Access-Control-Allow-Origin'

section "9b. Aviso «sitio en venta» (plugin bp-sitio-en-venta)"
http "GET /bp-venta/v1/config" 200 "$VENTA/config"
expect_json "forma del objeto (16 claves + version) y tipos" '(keys | length == 17) and (.enabled | type == "boolean") and (.modo | IN("venta","alquiler","venta_o_alquiler")) and (.colors | keys) == ["accent","accent_text","bg","text"] and (.placements | type == "array") and (.dismiss_days | type == "number") and (.version | test("^[0-9a-f]{12}$"))'
expect_header "Cache-Control público" 'Cache-Control: public, max-age=60'
venta_etag="$(grep -i '^ETag:' "$TMP/headers" | cut -d' ' -f2 | tr -d '\r')"
http "config con If-None-Match → 304" 304 -H "If-None-Match: $venta_etag" "$VENTA/config"
http "config?path=/cotizar/ (ruta excluida)" 200 "$VENTA/config?path=/cotizar/"
expect_json "visible=false en una ruta excluida" '.visible == false'
http "config?path=/alquiler-de-banos-portatiles/" 200 "$VENTA/config?path=/alquiler-de-banos-portatiles/"
expect_json "visible=true en el resto" '.visible == true'
curl -s "$VENTA/config" > "$TMP/venta.json"
curl -s "$API/site" | jq '.sale_banner' > "$TMP/site-banner.json"
if jq -e --slurpfile a "$TMP/venta.json" '. == $a[0]' "$TMP/site-banner.json" >/dev/null; then ok "/site → sale_banner es el mismo objeto (fuente única)"; else ko "/site → sale_banner distinto de /bp-venta/v1/config"; fi
wp eval '
wp_set_current_user(1);
$_POST = $_REQUEST = ["_wpnonce" => wp_create_nonce("bp_sitio_en_venta_save"), "publish" => "1", "bpsev" => [
  "enabled" => "1", "modo" => "venta", "headline" => "Titular de prueba smoke", "message" => "", "whatsapp_number" => "300 000 0000",
  "show_whatsapp" => "1", "cta_whatsapp_label" => "", "show_secondary" => "1", "secondary_label" => "", "secondary_url" => "/sitio-en-venta/",
  "colors" => ["bg" => "#0f172a", "text" => "#f8fafc", "accent" => "#25d366", "accent_text" => "#052e16"],
  "placements" => ["top_bar"], "dismissible" => "1", "dismiss_days" => "5", "exclude_paths" => "/cotizar/"]];
(new BanosPortatiles\SitioEnVenta\Admin\SettingsPage(BanosPortatiles\SitioEnVenta\Plugin::store()))->save();' >/dev/null
http "config tras «Guardar y publicar» desde el admin" 200 "$VENTA/config"
expect_json "cambios visibles al instante (caché invalidada) y E.164 normalizado" '.headline == "Titular de prueba smoke" and .whatsapp_number == "+573000000000" and .show_whatsapp == true and .dismiss_days == 5 and .placements == ["top_bar"] and (.message | startswith("Dominio"))'
[[ "$(jq -r .version "$TMP/body")" != "$(jq -r .version "$TMP/venta.json")" ]] && ok "version cambia (el front reinicia los avisos cerrados)" || ko "version sin cambios"
[[ "$(curl -s "$API/site" | jq -r '.sale_banner.headline')" == "Titular de prueba smoke" ]] && ok "/site → sale_banner actualizado (caché de bp-headless invalidada)" || ko "/site → sale_banner desactualizado"
[[ "$(wp transient get bp_sitio_en_venta_notice_1 --format=json | jq -r '.published')" == "scheduled" ]] && ok "«Guardar y publicar» programó el deploy vía bp-headless" || ko "publicación no programada"
[[ "$(wp cron event list --hook=bp_headless_deploy --format=count)" == "1" ]] && ok "evento bp_headless_deploy en cola (debounce)" || ko "evento de deploy"
wp eval 'BanosPortatiles\SitioEnVenta\Plugin::store()->import(json_decode((string) file_get_contents("/opt/bp/seed/bundle.json"), true)["site"]["sale_banner"]);' >/dev/null
[[ "$(curl -s "$VENTA/config" | jq -r .version)" == "$(jq -r .version "$TMP/venta.json")" ]] && ok "aviso restaurado desde el seed" || ko "restaurar el aviso"

section "10. Deploy hook (debounce 60 s con WP-Cron)"
docker compose exec -T hook-sink sh -c ': > /sink/requests.log' >/dev/null 2>&1
wp post update "$cali_id" --post_excerpt='Alquiler de baños portátiles en Cali y municipios cercanos.' >/dev/null
scheduled="$(wp cron event list --hook=bp_headless_deploy --format=count)"
[[ "$scheduled" == "1" ]] && ok "publicar programa un único deploy (debounce)" || ko "deploy programado ($scheduled)"
wp cron event run bp_headless_deploy >/dev/null
if docker compose exec -T hook-sink cat /sink/requests.log 2>/dev/null | grep '"path":"/deploy"' | grep -q 'bp-headless'; then ok "POST al deploy hook recibido"; else ko "POST al deploy hook"; fi
last_ok="$(wp option get bp_headless_deploy_last --format=json | jq -r '.ok')"
[[ "$last_ok" == "true" ]] && ok "último disparo registrado como OK (widget del dashboard)" || ko "registro del último disparo"

printf '\n\033[1mResultado: %d OK, %d fallos\033[0m\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]]
