<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Import\AssetFingerprint;
use BanosPortatiles\Headless\Import\ImportReport;
use BanosPortatiles\Headless\Import\MediaImporter;

beforeEach(function (): void {
    $this->assets = sys_get_temp_dir().'/bp-fingerprint-test-'.bin2hex(random_bytes(4));
    mkdir($this->assets.'/images/generated', 0777, true);
    file_put_contents($this->assets.'/images/generated/hero.jpg', 'foto-original');
    file_put_contents($this->assets.'/images/generated/figura.webp', 'figura');
    $this->fingerprint = new AssetFingerprint((new MediaImporter($this->assets, true, new ImportReport))->resolveLocal(...));
});

afterEach(function (): void {
    @unlink($this->assets.'/images/generated/hero.jpg');
    @unlink($this->assets.'/images/generated/figura.webp');
    @rmdir($this->assets.'/images/generated');
    @rmdir($this->assets.'/images');
    @rmdir($this->assets);
});

/** @return array<string, mixed> */
function fingerprintItem(): array
{
    return [
        'title' => 'Alquiler de baños portátiles',
        'hero' => ['image' => ['src' => 'images/generated/hero.jpg', 'alt' => 'Baños en un evento']],
        'sections' => [['layout' => 'steps', 'image' => 'https://cdn.example/remota.jpg']],
        'contentHtml' => '<figure><img src="images/generated/figura.webp?w=800" alt="Figura"><figcaption>Pie</figcaption></figure><img src="data:image/png;base64,AAAA">',
    ];
}

it('finds local image references in fields and in <img> tags, never remote URLs or data URIs', function (): void {
    expect(AssetFingerprint::references(fingerprintItem()))->toBe(['images/generated/hero.jpg', 'images/generated/figura.webp'])
        ->and(AssetFingerprint::references(['title' => 'Guía.png de pozos', 'n' => 3, 'logo' => '']))->toBe([]);
});

it('changes the item hash when an image is replaced in place and keeps it otherwise', function (): void {
    $item = fingerprintItem();
    $before = $this->fingerprint->hash($item);

    expect($this->fingerprint->hash($item))->toBe($before);

    file_put_contents($this->assets.'/images/generated/hero.jpg', 'foto-nueva');
    $after = (new AssetFingerprint((new MediaImporter($this->assets, true, new ImportReport))->resolveLocal(...)))->hash($item);

    expect($after)->not->toBe($before);
});

it('keeps the plain item hash for items without local images (no re-import after the upgrade)', function (): void {
    $faq = ['q' => '¿Cada cuánto se limpia un pozo séptico?', 'a' => 'Depende del uso.', 'image' => 'images/generated/no-existe.jpg'];

    expect($this->fingerprint->hash($faq))->toBe(md5(serialize($faq)))
        ->and((new AssetFingerprint(static fn (string $src): ?string => null))->hash(fingerprintItem()))->toBe(md5(serialize(fingerprintItem())));
});
