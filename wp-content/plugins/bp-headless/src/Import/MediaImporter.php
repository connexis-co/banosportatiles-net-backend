<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

use BanosPortatiles\Headless\Support\Arr;

/**
 * Sideloads seed images into the media library, deduplicated by content hash (local files) or URL.
 * Local "src" values are resolved against --assets: relative prefixes (../, ./, /), common roots
 * (src/assets/, assets/, public/) and, as a last resort, the file name anywhere inside the folder.
 */
final class MediaImporter
{
    public const SOURCE_META = '_bp_seed_source';

    /** @var array<string, int> */
    private array $cache = [];

    public function __construct(
        private readonly ?string $assetsDir,
        private readonly bool $dryRun,
        private readonly ImportReport $report,
    ) {}

    public function import(mixed $image, int $parentId = 0): ?int
    {
        [$src, $alt] = is_array($image)
            ? [Arr::string($image, 'src'), Arr::string($image, 'alt')]
            : [is_string($image) ? trim($image) : '', ''];
        if ($src === '') {
            return null;
        }

        $isRemote = preg_match('#^https?://#i', $src) === 1;
        $path = $isRemote ? null : $this->resolveLocal($src);
        if (! $isRemote && $path === null) {
            $this->report->warn("Imagen no encontrada en --assets: {$src}");
            $this->report->count('media', 'error');

            return null;
        }

        $sourceKey = $isRemote ? 'url:'.$src : 'sha1:'.sha1_file((string) $path);
        $existing = $this->cache[$sourceKey] ?? $this->findBySource($sourceKey);
        if ($existing !== null) {
            $this->cache[$sourceKey] = $existing;
            if ($alt !== '' && ! $this->dryRun) {
                update_post_meta($existing, '_wp_attachment_image_alt', $alt);
            }
            $this->report->count('media', 'unchanged');

            return $existing;
        }

        if ($this->dryRun) {
            $this->report->count('media', 'created');

            return null;
        }

        $id = $this->sideload($isRemote ? $src : (string) $path, $isRemote, $parentId);
        if ($id === null) {
            return null;
        }

        update_post_meta($id, self::SOURCE_META, $sourceKey);
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        $this->cache[$sourceKey] = $id;
        $this->report->count('media', 'created');

        return $id;
    }

    public function resolveLocal(string $src): ?string
    {
        if ($this->assetsDir === null) {
            return null;
        }
        $root = realpath($this->assetsDir);
        if ($root === false) {
            return null;
        }

        $clean = ltrim((string) preg_replace('#^((\.{1,2})/)+#', '', (string) parse_url($src, PHP_URL_PATH)), '/');
        $candidates = [$clean];
        foreach (['src/assets/', 'assets/', 'public/'] as $prefix) {
            if (str_starts_with($clean, $prefix)) {
                $candidates[] = substr($clean, strlen($prefix));
            }
        }

        foreach ($candidates as $candidate) {
            $path = realpath($root.'/'.$candidate);
            if ($path !== false && is_file($path) && str_starts_with($path, $root.'/')) {
                return $path;
            }
        }

        $name = basename($clean);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getFilename() === $name) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function sideload(string $source, bool $isRemote, int $parentId): ?int
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        if ($isRemote) {
            $tmp = download_url($source, 30);
            if (is_wp_error($tmp)) {
                $this->report->warn("No se pudo descargar {$source}: ".$tmp->get_error_message());
                $this->report->count('media', 'error');

                return null;
            }
            $name = basename((string) parse_url($source, PHP_URL_PATH));
        } else {
            $tmp = wp_tempnam($source);
            copy($source, $tmp);
            $name = basename($source);
        }

        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], $parentId);
        if (is_wp_error($id)) {
            @unlink($tmp);
            $this->report->warn("No se pudo importar {$name}: ".$id->get_error_message());
            $this->report->count('media', 'error');

            return null;
        }

        return $id;
    }

    private function findBySource(string $sourceKey): ?int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => self::SOURCE_META,
            'meta_value' => $sourceKey,
            'fields' => 'ids',
            'numberposts' => 1,
            'no_found_rows' => true,
        ]);
        $id = $ids[0] ?? null;

        return is_int($id) ? $id : null;
    }
}
