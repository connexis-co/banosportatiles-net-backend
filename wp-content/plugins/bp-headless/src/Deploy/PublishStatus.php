<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Deploy;

/**
 * Publication state of the public site (pure). Inputs: the content version and its last change, whether the deploy
 * hook is configured, the queued deploy, the last hook call and build.json. States:
 *
 * - scheduled:  a deploy is queued (debounce), with a countdown.
 * - publishing: the hook answered 2xx after the last change and build.json does not show that build yet.
 * - published:  build.json has the current content version.
 * - error:      the hook did not answer 2xx, or more than 15 min went by without the new version.
 * - pending:    changes that no deploy will publish (nothing queued; e.g. no hook, or saved without publishing).
 * - unknown:    build.json cannot be read and nothing tells the state.
 */
final class PublishStatus
{
    /** Estimated Workers build + deploy (progress bar). */
    public const BUILD_SECONDS = 180;

    /** A build that is not visible after this long is an error. */
    public const ERROR_AFTER = 900;

    public const STATES = [
        'scheduled' => ['label' => 'Publicación programada', 'color' => '#2271b1'],
        'publishing' => ['label' => 'Publicando…', 'color' => '#2271b1'],
        'published' => ['label' => 'Sitio publicado ✓', 'color' => '#00a32a'],
        'error' => ['label' => 'Error al publicar', 'color' => '#d63638'],
        'pending' => ['label' => 'Cambios sin publicar', 'color' => '#dba617'],
        'unknown' => ['label' => 'Estado del sitio público desconocido', 'color' => '#8c8f94'],
    ];

    /**
     * @param  array{version: string, at: int}  $content
     * @param  array{at: int, ok: bool, status: int}|null  $trigger  last call to the deploy hook
     * @param  array{version: string, builtAt: ?int, commit?: ?string}|null  $build  build.json (null: unreadable)
     * @return array{state: string, label: string, detail: string, color: string, poll: bool, since?: int, until?: int, progress?: float}
     */
    public static function resolve(array $content, bool $hook, ?int $scheduledFor, ?array $trigger, ?array $build, int $now): array
    {
        $fresh = $build !== null && $build['version'] === $content['version'];
        $covers = $trigger !== null && $trigger['at'] >= $content['at'];
        $rebuilt = $fresh && $trigger !== null && $build['builtAt'] !== null && $build['builtAt'] >= $trigger['at'];

        if ($scheduledFor !== null) {
            $left = $scheduledFor - $now;

            return self::state('scheduled', $left > 0 ? 'en '.self::duration($left) : 'en unos segundos', ['until' => $scheduledFor]);
        }
        if ($hook && $covers && $trigger['ok'] && ! $rebuilt && $now - $trigger['at'] <= self::ERROR_AFTER) {
            $elapsed = max(0, $now - $trigger['at']);

            return self::state('publishing', self::duration($elapsed), [
                'since' => $trigger['at'],
                'progress' => round(min(0.95, $elapsed / self::BUILD_SECONDS), 2),
            ]);
        }
        if ($fresh) {
            $at = $build['builtAt'] ?? ($trigger !== null ? $trigger['at'] : null);

            return self::state('published', $at !== null ? self::ago($now - $at) : '', $at !== null ? ['since' => $at] : []);
        }
        if (! $hook) {
            return self::state('pending', 'Falta el deploy hook (BP_DEPLOY_HOOK_URL): el sitio no se actualiza solo');
        }
        if ($covers && ! $trigger['ok']) {
            return self::state('error', $trigger['status'] > 0 ? 'El deploy hook respondió HTTP '.$trigger['status'] : 'No se pudo contactar el deploy hook', ['since' => $trigger['at']]);
        }
        if ($covers) {
            return $build === null
                ? self::state('unknown', 'Publicación enviada; no se pudo confirmar en el sitio público', ['since' => $trigger['at']])
                : self::state('error', 'El sitio público no se actualizó en '.intdiv(self::ERROR_AFTER, 60).' min', ['since' => $trigger['at']]);
        }
        if ($build === null && $trigger === null) {
            return self::state('unknown', 'No se pudo leer build.json del sitio público');
        }

        return self::state('pending', 'Usa «Publicar ahora»', ['since' => $content['at']]);
    }

    /** «~4 min» until a change saved now is live: debounce + estimated build. */
    public static function estimateMinutes(int $delay = DeployScheduler::DELAY): int
    {
        return (int) ceil(($delay + self::BUILD_SECONDS) / 60);
    }

    /** 45 → «45 s», 80 → «1 min 20 s», 3900 → «1 h 5 min». */
    public static function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.' s';
        }
        if ($seconds < 3600) {
            $rest = $seconds % 60;

            return intdiv($seconds, 60).' min'.($rest > 0 ? ' '.$rest.' s' : '');
        }
        $minutes = intdiv($seconds % 3600, 60);

        return intdiv($seconds, 3600).' h'.($minutes > 0 ? ' '.$minutes.' min' : '');
    }

    /** «hace unos segundos», «hace 3 min», «hace 2 h», «hace 1 día». */
    public static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => 'hace unos segundos',
            $seconds < 3600 => 'hace '.intdiv($seconds, 60).' min',
            $seconds < 86400 => 'hace '.intdiv($seconds, 3600).' h',
            default => 'hace '.intdiv($seconds, 86400).(intdiv($seconds, 86400) === 1 ? ' día' : ' días'),
        };
    }

    /**
     * @param  array{since?: int, until?: int, progress?: float}  $extra
     * @return array{state: string, label: string, detail: string, color: string, poll: bool, since?: int, until?: int, progress?: float}
     */
    private static function state(string $state, string $detail, array $extra = []): array
    {
        return [
            'state' => $state,
            'label' => self::STATES[$state]['label'],
            'detail' => $detail,
            'color' => self::STATES[$state]['color'],
            'poll' => in_array($state, ['scheduled', 'publishing'], true),
        ] + $extra;
    }
}
