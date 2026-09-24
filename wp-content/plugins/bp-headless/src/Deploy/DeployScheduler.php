<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Cache\ContentChangeListener;
use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Debounced deploys through a single WP-Cron event. The pending reasons are kept in an option (cron args stay
 * empty so the event can always be cleared). Two debounce modes, chosen by the delay:
 *
 * - Content (DELAY, 60 s): every change pushes the event 60 s into the future, so a burst of edits triggers one
 *   build once the editor stops.
 * - Window (longer delays, e.g. REVIEWS_DELAY for ratings and reviews): the first change opens a window and the
 *   later ones only join it (no reset), so steady review traffic still builds every 15 min at most. A deploy that
 *   is already queued, whatever its origin, absorbs the change.
 */
final class DeployScheduler implements Hookable
{
    public const HOOK = 'bp_headless_deploy';

    public const DELAY = 60;

    /** Ratings and reviews: the JSON-LD AggregateRating may wait; the live widget reads the API. */
    public const REVIEWS_DELAY = 900;

    public const PENDING_OPTION = 'bp_headless_deploy_pending';

    /** @var array<int, list<string>> delay → reasons collected during this request */
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
        // Public API for other plugins: apply_filters('bp_headless/request_deploy', false, $reason[, $delay]) → true when scheduled.
        add_filter('bp_headless/request_deploy', fn (mixed $scheduled, mixed $reason = '', mixed $delay = null): bool => $this->schedule(
            is_string($reason) && $reason !== '' ? $reason : 'solicitud externa',
            is_int($delay) && $delay > 0 ? $delay : self::DELAY
        ) || $scheduled === true, 10, 3);
    }

    public function suspend(bool $suspended = true): void
    {
        $this->suspended = $suspended;
    }

    public function markDirty(string $reason, int $delay = self::DELAY): void
    {
        if (! $this->suspended) {
            $this->reasons[max(1, $delay)][] = $reason;
        }
    }

    /** Schedules at most one deploy per request (on shutdown), with the shortest delay requested. */
    public function flush(): void
    {
        if ($this->reasons === []) {
            return;
        }
        $delay = min(array_keys($this->reasons));
        $reasons = array_values(array_unique(array_merge(...array_values($this->reasons))));
        $this->reasons = [];
        $this->schedule(implode(', ', array_slice($reasons, 0, 10)).(count($reasons) > 10 ? '…' : ''), $delay);
    }

    public function schedule(string $reason, int $delay = self::DELAY): bool
    {
        if ($this->config->deployHookUrl() === '') {
            return false;
        }

        $pending = get_option(self::PENDING_OPTION);
        $previous = is_array($pending) && is_string($pending['reason'] ?? null) ? $pending['reason'] : '';
        $merged = trim($previous === '' ? $reason : mb_substr($previous.', '.$reason, 0, 300), ', ');

        $next = $this->nextRun();
        if (self::joinsQueued($delay, $next)) {
            update_option(self::PENDING_OPTION, ['reason' => $merged, 'at' => (int) $next], false);

            return true;
        }

        wp_clear_scheduled_hook(self::HOOK);
        $at = time() + max(1, $delay);
        $scheduled = wp_schedule_single_event($at, self::HOOK);
        update_option(self::PENDING_OPTION, ['reason' => $merged, 'at' => $at], false);

        return $scheduled === true;
    }

    /** Window mode (delay longer than the content debounce) never resets a deploy that is already queued. */
    public static function joinsQueued(int $delay, ?int $next): bool
    {
        return $delay > self::DELAY && $next !== null;
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
