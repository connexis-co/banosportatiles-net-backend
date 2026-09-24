<?php

declare(strict_types=1);

use BanosPortatiles\Headless\Import\ImportReport;
use BanosPortatiles\Headless\Import\MediaImporter;
use Brain\Monkey\Functions;

beforeEach(function (): void {
    $this->assets = sys_get_temp_dir().'/bp-media-test-'.bin2hex(random_bytes(4));
    mkdir($this->assets.'/images/generated', 0777, true);
    file_put_contents($this->assets.'/images/generated/pasos.jpg', 'contenido-de-prueba');
});

afterEach(function (): void {
    @unlink($this->assets.'/images/generated/pasos.jpg');
    @rmdir($this->assets.'/images/generated');
    @rmdir($this->assets.'/images');
    @rmdir($this->assets);
});

it('resolves the image paths of the seed bundle inside --assets', function (string $src): void {
    $importer = new MediaImporter($this->assets, true, new ImportReport);

    expect($importer->resolveLocal($src))->toBe(realpath($this->assets.'/images/generated/pasos.jpg'));
})->with([
    'bundle path' => ['images/generated/pasos.jpg'],
    'relative' => ['../../assets/images/generated/pasos.jpg'],
    'src/assets root' => ['/src/assets/images/generated/pasos.jpg'],
    'file name only' => ['pasos.jpg'],
]);

it('never resolves paths outside --assets', function (): void {
    expect((new MediaImporter($this->assets, true, new ImportReport))->resolveLocal('../../../../etc/passwd'))->toBeNull();
});

it('reuses an attachment with the same SHA-1 and updates its alt from the seed', function (): void {
    $meta = [];
    Functions\when('get_posts')->alias(static function (array $args): array {
        return ($args['meta_key'] ?? '') === MediaImporter::SHA1_META && $args['meta_value'] === sha1('contenido-de-prueba') ? [42] : [];
    });
    Functions\when('update_post_meta')->alias(static function (int $id, string $key, mixed $value) use (&$meta): bool {
        $meta[$id][$key] = $value;

        return true;
    });
    Functions\expect('media_handle_sideload')->never();
    $report = new ImportReport;
    $importer = new MediaImporter($this->assets, false, $report);

    expect($importer->import(['src' => 'images/generated/pasos.jpg', 'alt' => 'Técnico instalando un baño portátil']))->toBe(42)
        ->and($importer->import('images/generated/pasos.jpg'))->toBe(42)
        ->and($meta[42]['_wp_attachment_image_alt'])->toBe('Técnico instalando un baño portátil')
        ->and($meta[42][MediaImporter::SHA1_META])->toBe(sha1('contenido-de-prueba'))
        ->and($report->total('unchanged'))->toBe(2);
});
