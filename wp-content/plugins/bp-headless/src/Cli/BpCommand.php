<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Cli;

use BanosPortatiles\Headless\Cache\ContentChangeListener;
use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Deploy\ContentVersion;
use BanosPortatiles\Headless\Deploy\DeployHook;
use BanosPortatiles\Headless\Deploy\DeployScheduler;
use BanosPortatiles\Headless\Headless\PreviewLinks;
use BanosPortatiles\Headless\Import\Bundle;
use BanosPortatiles\Headless\Import\ImportReport;
use BanosPortatiles\Headless\Import\SeedImporter;
use BanosPortatiles\Headless\Leads\LeadNotifier;
use BanosPortatiles\Headless\Leads\LeadRepository;

/**
 * wp bp import-seed | deploy | cache flush | preview-url | leads resend
 */
final class BpCommand
{
    public function __construct(
        private readonly SeedImporter $importer,
        private readonly DeployScheduler $scheduler,
        private readonly ResponseCache $cache,
        private readonly ContentChangeListener $listener,
        private readonly PreviewLinks $previews,
        private readonly LeadRepository $leads,
        private readonly LeadNotifier $notifier,
    ) {}

    public static function register(self $command): void
    {
        \WP_CLI::add_command('bp import-seed', [$command, 'importSeed'], [
            'shortdesc' => 'Importa (upsert idempotente) un bundle seed JSON: ajustes, ciudades, categorías, FAQs, redirecciones, equipos, posts y páginas.',
            'synopsis' => [
                ['type' => 'positional', 'name' => 'bundle', 'description' => 'Ruta al bundle.json.', 'optional' => false],
                ['type' => 'assoc', 'name' => 'assets', 'description' => 'Carpeta con las imágenes referenciadas por el seed.', 'optional' => true],
                ['type' => 'flag', 'name' => 'dry-run', 'description' => 'Muestra lo que haría sin escribir nada.', 'optional' => true],
                ['type' => 'flag', 'name' => 'force', 'description' => 'Reescribe aunque el contenido no haya cambiado.', 'optional' => true],
                ['type' => 'flag', 'name' => 'deploy', 'description' => 'Programa el deploy hook al terminar (por defecto; --no-deploy lo omite).', 'optional' => true],
            ],
        ]);
        \WP_CLI::add_command('bp deploy', [$command, 'deploy'], [
            'shortdesc' => 'Dispara ahora el deploy hook del sitio público.',
        ]);
        \WP_CLI::add_command('bp cache flush', [$command, 'flushCache'], [
            'shortdesc' => 'Vacía la caché de respuestas de la API bp/v1.',
        ]);
        \WP_CLI::add_command('bp leads resend', [$command, 'resendLeads'], [
            'shortdesc' => 'Reenvía el email de aviso de uno o varios leads (p. ej. los que quedaron en email_failed).',
            'synopsis' => [
                ['type' => 'positional', 'name' => 'id', 'description' => 'ID del lead.', 'optional' => true, 'repeating' => true],
                ['type' => 'flag', 'name' => 'failed', 'description' => 'Todos los leads con _bp_lead_status = email_failed.', 'optional' => true],
            ],
        ]);
        \WP_CLI::add_command('bp preview-url', [$command, 'previewUrl'], [
            'shortdesc' => 'Imprime la URL de preview firmada (15 min) de un contenido.',
            'synopsis' => [
                ['type' => 'positional', 'name' => 'id', 'description' => 'ID del contenido.', 'optional' => false],
            ],
        ]);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function importSeed(array $args, array $assoc): void
    {
        $assets = isset($assoc['assets']) && is_string($assoc['assets']) ? $assoc['assets'] : null;
        if ($assets !== null && ! is_dir($assets)) {
            \WP_CLI::error("La carpeta de assets no existe: {$assets}");
        }
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'dry-run', false);
        $force = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'force', false);
        $deploy = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'deploy', true);

        $report = new ImportReport;
        $this->listener->mute();
        $this->scheduler->suspend();
        try {
            $this->importer->run(Bundle::fromFile($args[0]), $assets, $dryRun, $force, $report);
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        } finally {
            $this->listener->mute(false);
            $this->scheduler->suspend(false);
        }

        \WP_CLI\Utils\format_items('table', $report->rows(), ['entidad', ...ImportReport::ACTIONS]);
        foreach ($report->warnings() as $warning) {
            \WP_CLI::warning($warning);
        }

        if ($dryRun) {
            \WP_CLI::success('Simulación completada: no se escribió nada.');

            return;
        }

        if ($report->total('created') + $report->total('updated') > 0) {
            // The listener was muted during the import: one new content version for the whole bundle.
            do_action(ContentVersion::TOUCH, 'import-seed');
        }
        $this->cache->flush();
        if ($deploy && $report->total('created') + $report->total('updated') > 0) {
            \WP_CLI::log($this->scheduler->schedule('import-seed')
                ? 'Despliegue programado en '.DeployScheduler::DELAY.' s.'
                : 'Deploy hook no configurado: no se programó despliegue.');
        }
        \WP_CLI::success(sprintf('Seed importado (%d creados, %d actualizados, %d sin cambios).', $report->total('created'), $report->total('updated'), $report->total('unchanged')));
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function deploy(array $args, array $assoc): void
    {
        $this->scheduler->runNow('wp-cli', 'wp bp deploy');
        $last = DeployHook::last();
        if ($last !== null && $last['ok']) {
            \WP_CLI::success($last['message'].' (HTTP '.$last['status'].')');

            return;
        }
        \WP_CLI::error($last['message'] ?? 'No se pudo disparar el despliegue.');
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function flushCache(array $args, array $assoc): void
    {
        $this->cache->flush();
        \WP_CLI::success('Caché de la API vaciada.');
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function resendLeads(array $args, array $assoc): void
    {
        $ids = array_map('intval', $args);
        if ((bool) \WP_CLI\Utils\get_flag_value($assoc, 'failed', false)) {
            $ids = [...$ids, ...get_posts([
                'post_type' => 'lead',
                'post_status' => 'any',
                'meta_key' => LeadRepository::META_PREFIX.'status',
                'meta_value' => LeadNotifier::STATUS_FAILED,
                'fields' => 'ids',
                'numberposts' => -1,
                'no_found_rows' => true,
            ])];
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            \WP_CLI::error('Indica uno o más IDs de lead, o usa --failed.');

            return;
        }

        $failed = 0;
        foreach ($ids as $id) {
            $stored = $this->leads->load($id);
            if ($stored === null) {
                \WP_CLI::warning("#{$id} no es un lead.");
                $failed++;

                continue;
            }
            $status = $this->notifier->notify($id, $stored['lead'], $stored['reference']);
            if ($status === LeadNotifier::STATUS_SENT) {
                \WP_CLI::log("#{$id}: enviado.");

                continue;
            }
            $failed++;
            \WP_CLI::warning("#{$id}: {$status} ".LeadRepository::meta($id, 'email_error'));
        }

        $failed === 0
            ? \WP_CLI::success(sprintf('%d email(s) reenviado(s).', count($ids)))
            : \WP_CLI::error(sprintf('%d de %d no se pudieron reenviar.', $failed, count($ids)));
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function previewUrl(array $args, array $assoc): void
    {
        $post = get_post((int) ($args[0] ?? 0));
        if (! $post instanceof \WP_Post) {
            \WP_CLI::error('No existe ese contenido.');

            return;
        }
        \WP_CLI::line($this->previews->urlFor($post));
    }
}
