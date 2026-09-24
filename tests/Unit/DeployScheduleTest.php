<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Deploy\DailySchedule;
use BanosPortatiles\Headless\Deploy\DeployHook;
use BanosPortatiles\Headless\Deploy\DeployScheduler;
use Brain\Monkey\Functions;

afterEach(function (): void {
    putenv('BP_DEPLOY_HOOK_URL');
});

it('normalizes the daily rebuild time', function (string $input, ?string $expected): void {
    expect(DailySchedule::normalize($input))->toBe($expected);
})->with([
    'HH:MM' => ['04:00', '04:00'],
    'H:MM' => ['4:30', '04:30'],
    'time picker' => ['23:59:00', '23:59'],
    'spaces' => [' 05:15 ', '05:15'],
    'empty' => ['', null],
    'hour out of range' => ['24:00', null],
    'minutes out of range' => ['10:60', null],
    'text' => ['cuatro', null],
]);

it('computes the next daily run in the site timezone', function (): void {
    $bogota = new DateTimeZone('America/Bogota');
    $at = static fn (string $local): int => (new DateTimeImmutable($local, $bogota))->getTimestamp();

    expect(DailySchedule::nextRun('04:00', $bogota, $at('2026-09-24 03:00')))->toBe($at('2026-09-24 04:00'))
        ->and(DailySchedule::nextRun('04:00', $bogota, $at('2026-09-24 04:00')))->toBe($at('2026-09-25 04:00'))
        ->and(DailySchedule::nextRun('04:00', $bogota, $at('2026-09-24 22:10')))->toBe($at('2026-09-25 04:00'))
        ->and(DailySchedule::nextRun('invalid', $bogota, $at('2026-09-24 01:00')))->toBe($at('2026-09-24 04:00'));
});

it('lets long debounces join an already queued deploy instead of resetting it', function (): void {
    expect(DeployScheduler::joinsQueued(DeployScheduler::REVIEWS_DELAY, 1_800_000_000))->toBeTrue()
        ->and(DeployScheduler::joinsQueued(DeployScheduler::REVIEWS_DELAY, null))->toBeFalse()
        ->and(DeployScheduler::joinsQueued(DeployScheduler::DELAY, 1_800_000_000))->toBeFalse();
});

it('schedules one deploy per request with the shortest delay requested', function (): void {
    putenv('BP_DEPLOY_HOOK_URL=https://api.cloudflare.com/hook');
    Functions\when('get_option')->justReturn(false);
    Functions\when('update_option')->justReturn(true);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    $captured = [];
    Functions\when('wp_schedule_single_event')->alias(function (int $at) use (&$captured): bool {
        $captured[] = $at;

        return true;
    });
    $scheduler = new DeployScheduler(new Config, new DeployHook(new Config));

    $scheduler->markDirty('reseña 7', DeployScheduler::REVIEWS_DELAY);
    $scheduler->markDirty('page:12');
    $scheduler->flush();
    $scheduler->flush(); // idempotent: nothing left to schedule

    expect($captured)->toHaveCount(1)
        ->and($captured[0] - time())->toBeLessThanOrEqual(DeployScheduler::DELAY)
        ->and($captured[0] - time())->toBeGreaterThanOrEqual(DeployScheduler::DELAY - 2);
});

it('keeps a queued deploy when a review arrives and resets it on content changes', function (): void {
    $queuedAt = time() + 400;
    $options = [];
    $next = $queuedAt;
    $scheduled = [];
    putenv('BP_DEPLOY_HOOK_URL=https://api.cloudflare.com/hook');
    Functions\when('get_option')->alias(function (string $name) use (&$options): mixed {
        return $options[$name] ?? false;
    });
    Functions\when('update_option')->alias(function (string $name, mixed $value) use (&$options): bool {
        $options[$name] = $value;

        return true;
    });
    Functions\when('wp_next_scheduled')->alias(function () use (&$next): int|false {
        return $next ?? false;
    });
    Functions\when('wp_clear_scheduled_hook')->alias(function () use (&$next): int {
        $next = null;

        return 1;
    });
    Functions\when('wp_schedule_single_event')->alias(function (int $at) use (&$next, &$scheduled): bool {
        $scheduled[] = $at;
        $next = $at;

        return true;
    });
    $scheduler = new DeployScheduler(new Config, new DeployHook(new Config));

    expect($scheduler->schedule('reseña 8', DeployScheduler::REVIEWS_DELAY))->toBeTrue()
        ->and($scheduled)->toBe([])
        ->and($options[DeployScheduler::PENDING_OPTION])->toBe(['reason' => 'reseña 8', 'at' => $queuedAt]);

    expect($scheduler->schedule('page:3'))->toBeTrue()
        ->and($scheduled)->toHaveCount(1)
        ->and($scheduled[0])->toBeLessThan($queuedAt)
        ->and($options[DeployScheduler::PENDING_OPTION]['reason'])->toBe('reseña 8, page:3');
});

it('does not schedule anything without a deploy hook', function (): void {
    putenv('BP_DEPLOY_HOOK_URL');
    Functions\when('get_option')->justReturn('');
    Functions\expect('wp_schedule_single_event')->never();

    expect((new DeployScheduler(new Config, new DeployHook(new Config)))->schedule('x', DeployScheduler::REVIEWS_DELAY))->toBeFalse();
});
