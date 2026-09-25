<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Deploy\BuildInfo;
use BanosPortatiles\Headless\Deploy\ContentVersion;
use BanosPortatiles\Headless\Deploy\PublishStatus;

const PUBLISH_NOW = 1_790_300_000;

/** @return array{version: string, at: int} */
function publishContent(string $version = 'v2', int $at = PUBLISH_NOW - 300): array
{
    return ['version' => $version, 'at' => $at];
}

/** @return array{at: int, ok: bool, status: int} */
function hookCall(int $at, bool $ok = true, int $status = 200): array
{
    return ['at' => $at, 'ok' => $ok, 'status' => $status];
}

/** @return array{version: string, builtAt: ?int} */
function buildJson(string $version, ?int $builtAt): array
{
    return ['version' => $version, 'builtAt' => $builtAt];
}

it('counts down while a deploy is queued (debounce)', function (): void {
    $status = PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 20), true, PUBLISH_NOW + 45, hookCall(PUBLISH_NOW - 3600), buildJson('v1', PUBLISH_NOW - 3500), PUBLISH_NOW);

    expect($status)->toMatchArray(['state' => 'scheduled', 'label' => 'Publicación programada', 'detail' => 'en 45 s', 'until' => PUBLISH_NOW + 45, 'poll' => true])
        ->and(PublishStatus::resolve(publishContent(), true, PUBLISH_NOW - 5, null, null, PUBLISH_NOW)['detail'])->toBe('en unos segundos');
});

it('is publishing after a 2xx hook call until build.json shows the new version, with an estimated progress', function (): void {
    $status = PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 200), true, null, hookCall(PUBLISH_NOW - 80), buildJson('v1', PUBLISH_NOW - 3600), PUBLISH_NOW);

    expect($status)->toMatchArray(['state' => 'publishing', 'label' => 'Publicando…', 'detail' => '1 min 20 s', 'since' => PUBLISH_NOW - 80, 'progress' => 0.44, 'poll' => true])
        ->and(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 900), true, null, hookCall(PUBLISH_NOW - 600), null, PUBLISH_NOW)['progress'])->toBe(0.95);
});

it('is published when build.json has the current version', function (): void {
    $status = PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 400), true, null, hookCall(PUBLISH_NOW - 380), buildJson('v2', PUBLISH_NOW - 180), PUBLISH_NOW);

    expect($status)->toMatchArray(['state' => 'published', 'label' => 'Sitio publicado ✓', 'detail' => 'hace 3 min', 'since' => PUBLISH_NOW - 180, 'poll' => false]);
});

it('shows a manual or daily rebuild of unchanged content as publishing until the new build is live', function (): void {
    $running = PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 86_400), true, null, hookCall(PUBLISH_NOW - 30), buildJson('v2', PUBLISH_NOW - 80_000), PUBLISH_NOW);
    $done = PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 86_400), true, null, hookCall(PUBLISH_NOW - 200), buildJson('v2', PUBLISH_NOW - 10), PUBLISH_NOW);
    $failed = PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 86_400), true, null, hookCall(PUBLISH_NOW - 1000), buildJson('v2', PUBLISH_NOW - 80_000), PUBLISH_NOW);

    expect($running['state'])->toBe('publishing')
        ->and($done['state'])->toBe('published')
        ->and($failed['state'])->toBe('published');
});

it('reports errors: hook without 2xx, unreachable hook and no new version after 15 min', function (): void {
    expect(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 100), true, null, hookCall(PUBLISH_NOW - 60, false, 403), buildJson('v1', PUBLISH_NOW - 3600), PUBLISH_NOW))
        ->toMatchArray(['state' => 'error', 'label' => 'Error al publicar', 'detail' => 'El deploy hook respondió HTTP 403', 'poll' => false])
        ->and(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 100), true, null, hookCall(PUBLISH_NOW - 60, false, 0), null, PUBLISH_NOW)['detail'])->toBe('No se pudo contactar el deploy hook')
        ->and(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 2000), true, null, hookCall(PUBLISH_NOW - 1000), buildJson('v1', PUBLISH_NOW - 3600), PUBLISH_NOW))
        ->toMatchArray(['state' => 'error', 'detail' => 'El sitio público no se actualizó en 15 min']);
});

it('marks changes that no deploy will publish as pending', function (): void {
    expect(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 60), true, null, hookCall(PUBLISH_NOW - 600), buildJson('v1', PUBLISH_NOW - 500), PUBLISH_NOW))
        ->toMatchArray(['state' => 'pending', 'label' => 'Cambios sin publicar', 'poll' => false])
        ->and(PublishStatus::resolve(publishContent(), false, null, null, buildJson('v1', PUBLISH_NOW - 500), PUBLISH_NOW))
        ->toMatchArray(['state' => 'pending', 'detail' => 'Falta el deploy hook (BP_DEPLOY_HOOK_URL): el sitio no se actualiza solo'])
        ->and(PublishStatus::resolve(publishContent(), false, null, null, buildJson('v2', PUBLISH_NOW - 500), PUBLISH_NOW)['state'])->toBe('published');
});

it('says unknown when build.json cannot be read and nothing else tells the state', function (): void {
    expect(PublishStatus::resolve(publishContent(), true, null, null, null, PUBLISH_NOW)['state'])->toBe('unknown')
        ->and(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 2000), true, null, hookCall(PUBLISH_NOW - 1000), null, PUBLISH_NOW))
        ->toMatchArray(['state' => 'unknown', 'detail' => 'Publicación enviada; no se pudo confirmar en el sitio público'])
        ->and(PublishStatus::resolve(publishContent(at: PUBLISH_NOW - 60), true, null, hookCall(PUBLISH_NOW - 600), null, PUBLISH_NOW)['state'])->toBe('pending');
});

it('formats durations, relative times and the save estimate in Spanish', function (): void {
    expect(PublishStatus::duration(45))->toBe('45 s')
        ->and(PublishStatus::duration(80))->toBe('1 min 20 s')
        ->and(PublishStatus::duration(120))->toBe('2 min')
        ->and(PublishStatus::duration(3900))->toBe('1 h 5 min')
        ->and(PublishStatus::ago(20))->toBe('hace unos segundos')
        ->and(PublishStatus::ago(185))->toBe('hace 3 min')
        ->and(PublishStatus::ago(7300))->toBe('hace 2 h')
        ->and(PublishStatus::ago(90_000))->toBe('hace 1 día')
        ->and(PublishStatus::ago(200_000))->toBe('hace 2 días')
        ->and(PublishStatus::estimateMinutes())->toBe(4);
});

it('parses build.json from the front', function (): void {
    expect(BuildInfo::parse('{"contentVersion":"mfx2k1-a1b2c3","builtAt":"2026-09-24T15:00:00.000Z","commit":"A1B2C3D"}'))
        ->toBe(['version' => 'mfx2k1-a1b2c3', 'builtAt' => 1_790_262_000, 'commit' => 'a1b2c3d'])
        ->and(BuildInfo::parse('{"contentVersion":"v1","builtAt":1790262000000}'))->toBe(['version' => 'v1', 'builtAt' => 1_790_262_000, 'commit' => null])
        ->and(BuildInfo::parse('{"contentVersion":"v1","commit":"no es un sha"}'))->toBe(['version' => 'v1', 'builtAt' => null, 'commit' => null])
        ->and(BuildInfo::parse('{"builtAt":"2026-09-24"}'))->toBeNull()
        ->and(BuildInfo::parse('<html>404</html>'))->toBeNull();
});

it('creates opaque, unique content versions and reads them back from the option', function (): void {
    $a = ContentVersion::newVersion();
    $b = ContentVersion::newVersion();

    expect($a)->toMatch('/^[0-9a-z]+-[0-9a-f]{6}$/')
        ->and($a)->not->toBe($b)
        ->and(ContentVersion::fromOption(['version' => 'x-1', 'at' => '1790000000']))->toBe(['version' => 'x-1', 'at' => 1_790_000_000])
        ->and(ContentVersion::fromOption(['version' => '']))->toBeNull()
        ->and(ContentVersion::fromOption(false))->toBeNull();
});
