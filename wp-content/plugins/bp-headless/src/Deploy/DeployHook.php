<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Config;

/**
 * POSTs to the deploy hook (Cloudflare Workers Builds) and records the last result for the admin UI.
 */
final class DeployHook
{
    public const LAST_OPTION = 'bp_headless_deploy_last';

    public function __construct(private readonly Config $config) {}

    /**
     * @return array{at: int, ok: bool, status: int, message: string, trigger: string, reason: string, user: string}
     */
    public function fire(string $trigger, string $reason = ''): array
    {
        $url = $this->config->deployHookUrl();
        $user = wp_get_current_user();
        $record = [
            'at' => time(),
            'ok' => false,
            'status' => 0,
            'message' => '',
            'trigger' => $trigger,
            'reason' => mb_substr($reason, 0, 300),
            'user' => $user->exists() ? $user->display_name : ($trigger === 'cron' ? 'WP-Cron' : 'sistema'),
        ];

        if ($url === '') {
            $record['message'] = 'Deploy hook no configurado (BP_DEPLOY_HOOK_URL o Ajustes del sitio → Despliegue).';
            update_option(self::LAST_OPTION, $record, false);

            return $record;
        }

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'user-agent' => 'bp-headless/'.\BanosPortatiles\Headless\VERSION,
            'reject_unsafe_urls' => ! $this->config->isLocal(),
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) wp_json_encode([
                'source' => 'bp-headless',
                'trigger' => $trigger,
                'reason' => $record['reason'],
                'site' => home_url('/'),
                'at' => gmdate(DATE_ATOM),
            ]),
        ]);

        if (is_wp_error($response)) {
            $record['message'] = $response->get_error_message();
        } else {
            $record['status'] = (int) wp_remote_retrieve_response_code($response);
            $record['ok'] = $record['status'] >= 200 && $record['status'] < 300;
            $record['message'] = $record['ok'] ? 'Reconstrucción solicitada.' : 'Respuesta '.$record['status'].': '.mb_substr(wp_strip_all_tags(wp_remote_retrieve_body($response)), 0, 200);
        }

        update_option(self::LAST_OPTION, $record, false);
        do_action('bp_headless/deploy_fired', $record);

        return $record;
    }

    /**
     * @return array{at: int, ok: bool, status: int, message: string, trigger: string, reason: string, user: string}|null
     */
    public static function last(): ?array
    {
        $last = get_option(self::LAST_OPTION);
        if (! is_array($last) || ! isset($last['at'])) {
            return null;
        }

        return [
            'at' => (int) $last['at'],
            'ok' => (bool) ($last['ok'] ?? false),
            'status' => (int) ($last['status'] ?? 0),
            'message' => (string) ($last['message'] ?? ''),
            'trigger' => (string) ($last['trigger'] ?? ''),
            'reason' => (string) ($last['reason'] ?? ''),
            'user' => (string) ($last['user'] ?? ''),
        ];
    }
}
