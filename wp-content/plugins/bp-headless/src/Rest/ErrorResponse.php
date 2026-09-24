<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest;

/**
 * Error bodies of the ratings and reviews endpoints: the WP_Error shape ({code, message, data: {status}})
 * plus the contract keys at the top level (e.g. 422 {errors: {campo: mensaje}}, 429 {retry_after}).
 */
final class ErrorResponse
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function make(string $code, string $message, int $status, array $extra = []): \WP_REST_Response
    {
        $response = new \WP_REST_Response(['code' => $code, 'message' => $message] + $extra + ['data' => ['status' => $status] + $extra], $status);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param  array<string, string>  $errors
     */
    public static function invalid(array $errors): \WP_REST_Response
    {
        return self::make('bp_invalid', 'Hay campos inválidos.', 422, ['errors' => $errors === [] ? new \stdClass : $errors]);
    }

    public static function rateLimited(int $retryAfter, string $message = 'Recibimos varias solicitudes seguidas. Intenta de nuevo en unos minutos.'): \WP_REST_Response
    {
        $response = self::make('bp_rate_limited', $message, 429, ['retry_after' => $retryAfter]);
        $response->header('Retry-After', (string) max(1, $retryAfter));

        return $response;
    }

    public static function fromWpError(\WP_Error $error): \WP_REST_Response
    {
        $data = $error->get_error_data();
        $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : 500;

        return self::make((string) $error->get_error_code(), $error->get_error_message(), $status);
    }
}
