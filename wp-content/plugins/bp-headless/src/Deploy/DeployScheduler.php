<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Cache\ContentChangeListener;
use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Debounced deploys: every content change pushes a single WP-Cron event 60 s into the future, so a
 * burst of edits triggers one build. The pending reasons are kept in an option (cron args stay empty
 * so the event can always be cleared).
 */
final class DeployScheduler implements Hookable
{
    public const HOOK = 'bp_headless_deploy';

    public const DELAY = 60;

    public const PENDING_OPTION = 'bp_headless_deploy_pending';

    /** @var list<string> */
    private array $reasons = [];

    private bool $suspended = false;

    public function __construct(
        private readonly Config $config,
        private readonly DeployHook $hook,
    ) {}

    public function register(): void
    {
        add_action(ContentChangeListener::ACTION, [$this, 'markDirty']);
        add_action('shutdown', [$this, 'flush']);
        add_action(self::HOOK, [$this, 'run']);
    }

    public function suspend(bool $suspended = true): void
    {
        $this->suspended = $suspended;
    }

    public function markDirty(string $reason): void
    {
        if (! $this->suspended) {
            $this->reasons[] = $reason;
        }
    }

    /** Schedules at most one deploy per request (on shutdown). */
    public function flush(): void
    {
        if ($this->reasons === []) {
            return;
        }
        $reasons = array_values(array_unique($this->reasons));
        $this->reasons = [];
        $this->schedule(implode(', ', array_slice($reasons, 0, 10)).(count($reasons) > 10 ? '…' : ''));
    }

    public function schedule(string $reason): bool
    {
        if ($this->config->deployHookUrl() === '') {
            return false;
        }

        $pending = get_option(self::PENDING_OPTION);
        $previous = is_array($pending) && is_string($pending['reason'] ?? null) ? $pending['reason'] : '';
        $merged = trim($previous === '' ? $reason : mb_substr($previous.', '.$reason, 0, 300), ', ');

        wp_clear_scheduled_hook(self::HOOK);
        $at = time() + self::DELAY;
        $scheduled = wp_schedule_single_event($at, self::HOOK);
        update_option(self::PENDING_OPTION, ['reason' => $merged, 'at' => $at], false);

        return $scheduled === true;
    }

    public function run(): void
    {
        $pending = get_option(self::PENDING_OPTION);
        delete_option(self::PENDING_OPTION);
        $reason = is_array($pending) && is_string($pending['reason'] ?? null) ? $pending['reason'] : 'cambios de contenido';
        $this->hook->fire('cron', $reason);
    }

    /** Fires immediately (manual button / WP-CLI) and cancels the pending debounce. */
    public function runNow(string $trigger, string $reason): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        delete_option(self::PENDING_OPTION);
        $this->hook->fire($trigger, $reason);
    }

    public function nextRun(): ?int
    {
        $next = wp_next_scheduled(self::HOOK);

        return is_int($next) ? $next : null;
    }
}
