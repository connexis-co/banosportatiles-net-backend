<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Reviews;

use BanosPortatiles\Headless\Leads\LeadValidator;
use BanosPortatiles\Headless\Routing\UriResolver;

/**
 * Validates and sanitizes the signed payloads (pure PHP, no WordPress calls). Contract §3.3:
 *
 *   POST /ratings  {uri, rating: 1–5, voter, ip, ua?, country?}
 *   POST /reviews  {uri, rating: 1–5, title?, content (20–2000), name (2–60), email, consent: true, voter, ip, ua?}
 *
 * Reviews reject more than 2 URLs (spam) and require consent === true (Ley 1581 de 2012).
 */
final class ReviewInputValidator
{
    public const MIN_CONTENT = 20;

    public const MAX_CONTENT = 2000;

    public const MIN_NAME = 2;

    public const MAX_NAME = 60;

    public const MAX_TITLE = 120;

    public const MAX_EMAIL = 190;

    public const MAX_URLS = 2;

    public const MAX_UA = 255;

    private const URL = '~(?:https?://|www\.)[^\s<>"\']+~iu';

    private const BARE_DOMAIN = '~\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:com|net|org|co|info|biz|xyz|io|me|ru|cn|top|online|site|shop|store|link|click|live|app)(?:/[^\s]*)?(?=[\s.,;:!?)]|$)~iu';

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function vote(array $input): ReviewValidation
    {
        $errors = [];
        $uri = self::uri($input, $errors);
        $rating = self::rating($input, $errors);
        $voter = self::voter($input, $errors);

        return new ReviewValidation($errors, $uri, $rating, $errors === [] ? $voter : null);
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public function review(array $input): ReviewValidation
    {
        $errors = [];
        $uri = self::uri($input, $errors);
        $rating = self::rating($input, $errors);
        $voter = self::voter($input, $errors);

        $title = LeadValidator::text($input['title'] ?? null);
        if (mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = sprintf('El título es demasiado largo (máx. %d caracteres).', self::MAX_TITLE);
        }

        $content = LeadValidator::text($input['content'] ?? null, true);
        $length = mb_strlen($content);
        if ($length < self::MIN_CONTENT || $length > self::MAX_CONTENT) {
            $errors['content'] = sprintf('Escribe tu opinión (%d a %d caracteres).', self::MIN_CONTENT, self::MAX_CONTENT);
        } elseif (self::countUrls($title.' '.$content) > self::MAX_URLS) {
            $errors['content'] = sprintf('Incluye como máximo %d enlaces.', self::MAX_URLS);
        }

        $name = LeadValidator::text($input['name'] ?? null);
        if (mb_strlen($name) < self::MIN_NAME || mb_strlen($name) > self::MAX_NAME) {
            $errors['name'] = sprintf('Escribe tu nombre (%d a %d caracteres).', self::MIN_NAME, self::MAX_NAME);
        } elseif (self::countUrls($name) > 0) {
            $errors['name'] = 'El nombre no puede contener enlaces.';
        }

        $email = strtolower(LeadValidator::text($input['email'] ?? null));
        if ($email === '' || mb_strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Escribe un email válido (no se publica).';
        }

        if (($input['consent'] ?? null) !== true) {
            $errors['consent'] = 'Debes aceptar la política de tratamiento de datos.';
        }

        if ($errors !== []) {
            return new ReviewValidation($errors, $uri, $rating);
        }

        return new ReviewValidation([], $uri, $rating, $voter, new ReviewSubmission($rating, $title, $content, $name, $email));
    }

    /** URLs in a text: http(s)://…, www.… and bare domains with common TLDs (example.com/x). */
    public static function countUrls(string $text): int
    {
        $count = (int) preg_match_all(self::URL, $text);
        $rest = (string) preg_replace(self::URL, ' ', $text);

        return $count + (int) preg_match_all(self::BARE_DOMAIN, $rest);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string>  $errors
     */
    private static function uri(array $input, array &$errors): string
    {
        $uri = is_string($input['uri'] ?? null) ? trim($input['uri']) : '';
        if ($uri === '' || ! str_starts_with($uri, '/') || mb_strlen($uri) > 255 || preg_match('/\s/', $uri) === 1) {
            $errors['uri'] = 'Falta la ruta de la página (/…/).';

            return '';
        }

        return UriResolver::normalize($uri);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string>  $errors
     */
    private static function rating(array $input, array &$errors): int
    {
        $value = $input['rating'] ?? null;
        $rating = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^\s*\d\s*$/', $value) === 1 => (int) $value,
            default => 0,
        };
        if ($rating < RatingSummary::WORST || $rating > RatingSummary::BEST) {
            $errors['rating'] = 'La calificación debe ser un número entero de 1 a 5.';

            return 0;
        }

        return $rating;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  array<string, string>  $errors
     */
    private static function voter(array $input, array &$errors): ?VoterContext
    {
        $voter = is_string($input['voter'] ?? null) ? strtolower(trim($input['voter'])) : '';
        if (preg_match('/^[a-f0-9]{64}$/', $voter) !== 1) {
            $errors['voter'] = 'Identificador de votante inválido (SHA-256 en hexadecimal).';
        }

        $ip = is_string($input['ip'] ?? null) ? trim($input['ip']) : '';
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $errors['ip'] = 'IP del visitante inválida.';
        }

        $ua = mb_substr(LeadValidator::text($input['ua'] ?? null), 0, self::MAX_UA);
        $country = is_string($input['country'] ?? null) ? strtoupper(trim($input['country'])) : '';
        if ($country !== '' && preg_match('/^[A-Z0-9]{2}$/', $country) !== 1) {
            $errors['country'] = 'País inválido (código de 2 letras).';
        }

        return isset($errors['voter']) || isset($errors['ip']) || isset($errors['country'])
            ? null
            : new VoterContext($voter, $ip, $ua, $country);
    }
}
