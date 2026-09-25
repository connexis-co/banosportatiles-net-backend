<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

/**
 * Validates and sanitizes the POST /bp/v1/leads payload (pure PHP, no WordPress calls).
 * Required: nombre, telefono, consentimiento=true (data processing, Ley 1581 de 2012).
 * Optional: consentimiento_comercial (marketing communications). Unknown keys are ignored.
 *
 * Attribution (optional) never rejects a lead: origen, servicio_uri, referrer, landing, utm_* and click ids with
 * a wrong format are dropped and listed in LeadValidationResult::$ignored; long utm_* texts are cut to 150.
 */
final class LeadValidator
{
    /** Keys of LeadData::$utm (stored and sent as utm_source… for the utm_* ones). */
    public const UTM_KEYS = ['source', 'medium', 'campaign', 'term', 'content', 'gclid', 'gbraid', 'wbraid', 'fbclid'];

    public const CLICK_IDS = ['gclid', 'gbraid', 'wbraid', 'fbclid'];

    public const MAX_ORIGEN = 40;

    public const MAX_URI = 255;

    public const MAX_URL = 500;

    public const MAX_UTM = 150;

    public const MAX_CLICK_ID = 255;

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function validate(array $input): LeadValidationResult
    {
        $errors = [];

        $nombre = self::text($input['nombre'] ?? null);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 120) {
            $errors['nombre'] = 'Escribe tu nombre (2 a 120 caracteres).';
        }

        $telefono = self::phone($input['telefono'] ?? null);
        if ($telefono === null) {
            $errors['telefono'] = 'Escribe un teléfono válido (7 a 15 dígitos, puede iniciar con +).';
        }

        $email = self::text($input['email'] ?? null);
        if ($email !== '' && (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = 'El email no es válido.';
        }

        $ciudad = self::text($input['ciudad'] ?? null);
        if (mb_strlen($ciudad) > 80) {
            $errors['ciudad'] = 'La ciudad es demasiado larga (máx. 80).';
        }

        $servicio = self::text($input['servicio'] ?? null);
        if (mb_strlen($servicio) > 120) {
            $errors['servicio'] = 'El servicio es demasiado largo (máx. 120).';
        }

        $mensaje = self::text($input['mensaje'] ?? null, true);
        if (mb_strlen($mensaje) > 2000) {
            $errors['mensaje'] = 'El mensaje es demasiado largo (máx. 2000).';
        }

        $fecha = self::text($input['fecha_evento'] ?? null);
        if ($fecha !== '' && ! self::isDate($fecha)) {
            $errors['fecha_evento'] = 'La fecha debe tener el formato AAAA-MM-DD.';
        }

        $cantidadRaw = $input['cantidad'] ?? null;
        $cantidad = null;
        if ($cantidadRaw !== null && $cantidadRaw !== '') {
            $cantidad = filter_var($cantidadRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            if ($cantidad === false) {
                $errors['cantidad'] = 'La cantidad debe ser un número entre 1 y 10000.';
                $cantidad = null;
            }
        }

        $pagina = self::text($input['pagina'] ?? null);
        if ($pagina !== '' && (! str_starts_with($pagina, '/') || mb_strlen($pagina) > 255 || preg_match('/\s/', $pagina) === 1)) {
            $errors['pagina'] = 'La página de origen debe ser una ruta relativa (/…).';
        }

        if (! self::isTrue($input['consentimiento'] ?? null)) {
            $errors['consentimiento'] = 'Debes aceptar la política de tratamiento de datos.';
        }

        if ($errors !== [] || $telefono === null) {
            return new LeadValidationResult(null, $errors);
        }

        $ignored = [];
        $origen = self::attributionValue($input, 'origen', self::origen(...), $ignored);
        $servicioUri = self::attributionValue($input, 'servicio_uri', static fn (string $v): ?string => self::path($v, self::MAX_URI, false), $ignored);
        $referrer = self::attributionValue($input, 'referrer', self::referrer(...), $ignored);
        $landing = self::attributionValue($input, 'landing', static fn (string $v): ?string => self::path($v, self::MAX_URL, true), $ignored);

        return new LeadValidationResult(new LeadData(
            nombre: $nombre,
            telefono: $telefono,
            email: $email !== '' ? strtolower($email) : null,
            ciudad: $ciudad !== '' ? $ciudad : null,
            servicio: $servicio !== '' ? $servicio : null,
            mensaje: $mensaje !== '' ? $mensaje : null,
            fechaEvento: $fecha !== '' ? $fecha : null,
            cantidad: is_int($cantidad) ? $cantidad : null,
            pagina: $pagina !== '' ? $pagina : null,
            utm: self::utm($input, $ignored),
            consentimiento: true,
            consentimientoComercial: self::isTrue($input['consentimiento_comercial'] ?? null),
            origen: $origen,
            servicioUri: $servicioUri,
            referrer: $referrer,
            landing: $landing,
        ), [], $ignored);
    }

    /** UTM_KEYS entry → contract/meta name: "source" → "utm_source", click ids unchanged. */
    public static function attributionKey(string $key): string
    {
        return in_array($key, self::CLICK_IDS, true) ? $key : 'utm_'.$key;
    }

    /**
     * CTA location (hero, header_movil, pagina_cotizar…), max. 40: lowercase letters and "_", plus digits and
     * "-" like sanitizeOrigen() of the front.
     */
    public static function origen(string $value): ?string
    {
        return preg_match('/^[a-z0-9_-]{1,'.self::MAX_ORIGEN.'}$/', $value) === 1 ? $value : null;
    }

    /** Relative URI of the site ("/…"; with $query, "?…" is allowed too). */
    public static function path(string $value, int $max, bool $query): ?string
    {
        $pattern = $query ? '#^/(?!/)[^\s\#]*$#' : '#^/(?!/)[^\s?\#]*$#';

        return mb_strlen($value) <= $max && preg_match($pattern, $value) === 1 ? $value : null;
    }

    /** Absolute http(s) URL; without its query string when it is too long. */
    public static function referrer(string $value): ?string
    {
        if (preg_match('#^https?://[^\s/?\#]+#i', $value) !== 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        if (mb_strlen($value) > self::MAX_URL) {
            $value = (string) strtok($value, '?#');
        }

        return mb_strlen($value) <= self::MAX_URL ? $value : null;
    }

    /** Ad click id (gclid, gbraid, wbraid, fbclid): URL-safe characters, max. 255. */
    public static function clickId(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9._~\-]{1,'.self::MAX_CLICK_ID.'}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  callable(string): ?string  $validate
     * @param  list<string>  $ignored
     */
    private static function attributionValue(array $input, string $key, callable $validate, array &$ignored): ?string
    {
        $raw = self::text($input[$key] ?? null);
        if ($raw === '') {
            return null;
        }
        $value = $validate($raw);
        if ($value === null) {
            $ignored[] = $key;
        }

        return $value;
    }

    /** Strips tags and control characters; collapses whitespace (keeps line breaks when $multiline). */
    public static function text(mixed $value, bool $multiline = false): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $text = strip_tags((string) $value);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if ($multiline) {
            $text = (string) preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $text));
            $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        } else {
            $text = (string) preg_replace('/\s+/u', ' ', $text);
        }

        return trim($text);
    }

    public static function phone(mixed $value): ?string
    {
        $raw = self::text($value);
        $normalized = (string) preg_replace('/[\s().\-]/', '', $raw);

        return preg_match('/^\+?\d{7,15}$/', $normalized) === 1 ? $normalized : null;
    }

    private static function isDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'si', 'sí', 'yes'], true);
    }

    /**
     * UTM and click ids: top-level keys (utm_source…, gclid…) or, as before, the "utm" object ({source} or
     * {utm_source}). Keyed by UTM_KEYS.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $ignored
     * @return array<string, string>
     */
    private static function utm(array $input, array &$ignored): array
    {
        $nested = is_array($input['utm'] ?? null) ? $input['utm'] : [];
        $utm = [];
        foreach (self::UTM_KEYS as $key) {
            $name = self::attributionKey($key);
            $raw = self::text($input[$name] ?? ($nested[$key] ?? ($nested['utm_'.$key] ?? null)));
            if ($raw === '') {
                continue;
            }
            if (in_array($key, self::CLICK_IDS, true)) {
                $raw = self::clickId($raw);
                if ($raw === null) {
                    $ignored[] = $name;

                    continue;
                }
            }
            $utm[$key] = in_array($key, self::CLICK_IDS, true) ? $raw : mb_substr($raw, 0, self::MAX_UTM);
        }

        return $utm;
    }
}
