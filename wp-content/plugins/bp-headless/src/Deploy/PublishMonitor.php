<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

use BanosPortatiles\Headless\Config;

/**
 * Gathers the inputs of PublishStatus (content version, queued deploy, last hook call, build.json) and shapes
 * GET /bp/v1/status.
 */
final class PublishMonitor
{
    public function __construct(
        private readonly Config $config,
        private readonly ContentVersion $version,
        private readonly DeployScheduler $scheduler,
        private readonly BuildInfo $build,
    ) {}

    /**
     * @param  bool  $fetch  false = never wait for build.json (admin page render; the browser refreshes it)
     * @return array{content: array{version: string, at: int}, scheduledFor: ?int, trigger: ?array{at: int, ok: bool, status: int, message: string, trigger: string, reason: string, user: string}, build: array{version: string, builtAt: ?int, commit: ?string}|null, fresh: bool, publish: array<string, mixed>}
     */
    public function snapshot(bool $fetch = true): array
    {
        $content = $this->version->current();
        $scheduledFor = $this->scheduler->nextRun();
        $trigger = DeployHook::last();
        if ($fetch) {
            $build = $this->build->get();
            $fresh = true;
        } else {
            $cached = $this->build->cached();
            $build = $cached['build'];
            $fresh = $cached['hit'];
        }

        return [
            'content' => $content,
            'scheduledFor' => $scheduledFor,
            'trigger' => $trigger,
            'build' => $build,
            'fresh' => $fresh,
            'publish' => PublishStatus::resolve($content, $this->config->deployHookUrl() !== '', $scheduledFor, $trigger, $build, time()),
        ];
    }

    /**
     * GET /bp/v1/status (public, no cache). Optional keys are omitted; dates are ISO 8601 in the site timezone.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        $snapshot = $this->snapshot();
        $trigger = $snapshot['trigger'];
        $deploy = [];
        if ($snapshot['scheduledFor'] !== null) {
            $deploy['scheduledFor'] = self::date($snapshot['scheduledFor']);
        }
        if ($trigger !== null) {
            $deploy['lastTriggerAt'] = self::date($trigger['at']);
            $deploy['lastTriggerStatus'] = $trigger['ok'] ? 'ok' : ($this->config->deployHookUrl() === '' && $trigger['status'] === 0 ? 'skipped' : 'error');
            if ($trigger['status'] > 0) {
                $deploy['lastTriggerHttp'] = $trigger['status'];
            }
        }

        $build = $snapshot['build'];
        $state = self::publish($snapshot['publish']);

        return [
            'contentVersion' => $snapshot['content']['version'],
            'lastChangeAt' => self::date($snapshot['content']['at']),
            'deploy' => (object) $deploy,
        ] + ($build !== null ? ['build' => ['contentVersion' => $build['version']]
            + ($build['builtAt'] !== null ? ['builtAt' => self::date($build['builtAt'])] : [])
            + ($build['commit'] !== null ? ['commit' => $build['commit']] : [])] : [])
            + ['publish' => $state, 'now' => self::date(time())];
    }

    /**
     * PublishStatus::resolve() for clients (API and admin script): dates as ISO 8601, plus the build estimate.
     *
     * @param  array<string, mixed>  $publish
     * @return array<string, mixed>
     */
    public static function publish(array $publish): array
    {
        $state = [];
        foreach (['state', 'label', 'detail', 'color', 'poll'] as $key) {
            $state[$key] = $publish[$key] ?? null;
        }
        foreach (['since', 'until'] as $key) {
            if (isset($publish[$key]) && is_int($publish[$key])) {
                $state[$key] = self::date($publish[$key]);
            }
        }
        if (isset($publish['progress'])) {
            $state['progress'] = $publish['progress'];
        }
        $state['estimateSeconds'] = PublishStatus::BUILD_SECONDS;

        return $state;
    }

    public static function date(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(wp_timezone())->format(DATE_ATOM);
    }
}
