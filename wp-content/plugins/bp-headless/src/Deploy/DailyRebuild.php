<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Scheduled daily rebuild of the public site (default 04:00, site timezone): refreshes what changes without an
 * edit, such as the AggregateRating of the JSON-LD. Configured in «Ajustes del sitio → Despliegue»; the WP-Cron
 * event is kept in sync with the setting on every request (cheap: two autoloaded options).
 */
final class DailyRebuild implements Hookable
{
    public const HOOK = 'bp_headless_daily_rebuild';

    /** "HH:MM" the current event was scheduled for (detects a changed time). */
    public const SCHEDULED_OPTION = 'bp_headless_daily_rebuild_at';

    public function __construct(
        private readonly Config $config,
        private readonly DeployHook $hook,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('init', [$this, 'sync'], 99);
        add_action('acf/save_post', [$this, 'onSettingsSaved'], 30);
    }

    public function onSettingsSaved(int|string $postId): void
    {
        if ($postId === Config::OPTIONS_ID) {
            $this->sync();
        }
    }

    public function sync(): void
    {
        $time = $this->config->dailyRebuildTime();
        $next = wp_next_scheduled(self::HOOK);
        if ($time === null) {
            if ($next !== false) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            delete_option(self::SCHEDULED_OPTION);

            return;
        }
        if ($next !== false && get_option(self::SCHEDULED_OPTION) === $time) {
            return;
        }

        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(DailySchedule::nextRun($time, wp_timezone(), time()), 'daily', self::HOOK);
        update_option(self::SCHEDULED_OPTION, $time, false);
    }

    public function run(): void
    {
        if ($this->config->deployHookUrl() !== '') {
            $this->hook->fire('daily', 'rebuild diario (valoraciones y fechas del JSON-LD)');
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::SCHEDULED_OPTION);
    }
}
