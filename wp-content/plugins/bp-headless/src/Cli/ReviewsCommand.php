<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Cli;

use BanosPortatiles\Headless\Cache\ResponseCache;
use BanosPortatiles\Headless\Reviews\RatingService;
use BanosPortatiles\Headless\Reviews\RatingSummary;
use BanosPortatiles\Headless\Reviews\ReviewRecord;
use BanosPortatiles\Headless\Reviews\SiteReviewsGateway;
use BanosPortatiles\Headless\Reviews\SiteReviewsSetup;
use BanosPortatiles\Headless\Routing\NodeLocator;

/**
 * wp bp setup reviews | wp bp reviews stats | wp bp reviews purge
 */
final class ReviewsCommand
{
    public function __construct(
        private readonly RatingService $ratings,
        private readonly NodeLocator $nodes,
        private readonly ResponseCache $cache,
    ) {}

    public static function register(self $command): void
    {
        \WP_CLI::add_command('bp setup reviews', [$command, 'setup'], [
            'shortdesc' => 'Configura Site Reviews para el sitio headless (moderación, aviso al admin, categorías «Calificación» y «Comentario»). Idempotente.',
        ]);
        \WP_CLI::add_command('bp reviews stats', [$command, 'stats'], [
            'shortdesc' => 'Valoraciones por página: votos, promedio, opiniones con texto y pendientes de moderación.',
            'synopsis' => [
                ['type' => 'flag', 'name' => 'all', 'description' => 'Incluye las páginas sin valoraciones todavía.', 'optional' => true],
                ['type' => 'assoc', 'name' => 'format', 'description' => 'table, csv, json o yaml.', 'optional' => true, 'default' => 'table'],
            ],
        ]);
        \WP_CLI::add_command('bp reviews purge', [$command, 'purge'], [
            'shortdesc' => 'Borra (definitivamente) las valoraciones de un votante o de una IP, p. ej. las de prueba.',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'voter', 'description' => 'Hash del votante (SHA-256 en hexadecimal).', 'optional' => true],
                ['type' => 'assoc', 'name' => 'ip', 'description' => 'IP del visitante.', 'optional' => true],
                ['type' => 'assoc', 'name' => 'uri', 'description' => 'Solo en esta página (/ruta/).', 'optional' => true],
                ['type' => 'flag', 'name' => 'dry-run', 'description' => 'Lista lo que borraría sin borrar nada.', 'optional' => true],
                ['type' => 'flag', 'name' => 'yes', 'description' => 'No pide confirmación.', 'optional' => true],
            ],
        ]);
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function setup(array $args, array $assoc): void
    {
        $gateway = $this->ratings->gateway();
        if (! $gateway instanceof SiteReviewsGateway) {
            \WP_CLI::error('Site Reviews no está activo: wp plugin activate site-reviews.');

            return;
        }
        try {
            $rows = (new SiteReviewsSetup($gateway))->apply();
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        \WP_CLI\Utils\format_items('table', $rows, ['ajuste', 'valor', 'estado']);
        $this->cache->flush();
        \WP_CLI::success('Site Reviews listo para el sitio headless (assets y JSON-LD del plugin desactivados por bp-headless).');
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function stats(array $args, array $assoc): void
    {
        if (! $this->ratings->available()) {
            \WP_CLI::error('Site Reviews no está activo.');

            return;
        }
        $all = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'all', false);
        $gateway = $this->ratings->gateway();
        $rows = [];
        $totals = ['votos' => 0, 'opiniones' => 0, 'pendientes' => 0];
        foreach ($this->nodes->all() as $post) {
            $flags = $this->ratings->flags($post);
            if (! $flags->any()) {
                continue;
            }
            $summary = RatingSummary::build($flags, $gateway->approved($post->ID));
            $pending = $gateway->pendingCount($post->ID);
            $count = is_int($summary['count']) ? $summary['count'] : 0;
            $reviews = is_int($summary['reviewCount']) ? $summary['reviewCount'] : 0;
            $totals['votos'] += $count;
            $totals['opiniones'] += $reviews;
            $totals['pendientes'] += $pending;
            if (! $all && $count === 0 && $pending === 0) {
                continue;
            }
            $rows[] = [
                'uri' => $this->nodes->uriOf($post) ?? '#'.$post->ID,
                'id' => $post->ID,
                'estrellas' => $flags->stars ? 'sí' : 'no',
                'opiniones' => $flags->reviews ? 'sí' : 'no',
                'valoraciones' => $count,
                'promedio' => is_float($summary['average']) ? number_format($summary['average'], 1, ',', '') : '0',
                'con_texto' => $reviews,
                'pendientes' => $pending,
            ];
        }

        $format = is_string($assoc['format'] ?? null) ? $assoc['format'] : 'table';
        if ($rows !== []) {
            \WP_CLI\Utils\format_items($format, $rows, ['uri', 'id', 'estrellas', 'opiniones', 'valoraciones', 'promedio', 'con_texto', 'pendientes']);
        }
        \WP_CLI::log(sprintf('Total: %d valoraciones aprobadas, %d con texto, %d pendientes de moderación.', $totals['votos'], $totals['opiniones'], $totals['pendientes']));
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|bool>  $assoc
     */
    public function purge(array $args, array $assoc): void
    {
        $voter = is_string($assoc['voter'] ?? null) ? strtolower(trim($assoc['voter'])) : null;
        $ip = is_string($assoc['ip'] ?? null) ? trim($assoc['ip']) : null;
        if (($voter === null || $voter === '') && ($ip === null || $ip === '')) {
            \WP_CLI::error('Indica --voter=<hash> o --ip=<ip>.');

            return;
        }
        if ($voter !== null && preg_match('/^[a-f0-9]{64}$/', $voter) !== 1) {
            \WP_CLI::error('--voter debe ser un SHA-256 en hexadecimal (64 caracteres).');
        }
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            \WP_CLI::error('--ip no es una IP válida.');
        }

        $postId = null;
        if (is_string($assoc['uri'] ?? null)) {
            $post = $this->nodes->find($assoc['uri']);
            if ($post === null) {
                \WP_CLI::error('No existe contenido publicado en '.$assoc['uri']);

                return;
            }
            $postId = $post->ID;
        }

        $gateway = $this->ratings->gateway();
        $records = $gateway->find($voter, $ip, $postId);
        if ($records === []) {
            \WP_CLI::success('No hay valoraciones que coincidan.');

            return;
        }
        \WP_CLI\Utils\format_items('table', array_map(static fn (ReviewRecord $r): array => [
            'id' => $r->id,
            'páginas' => implode(',', $r->postIds),
            'estrellas' => $r->rating,
            'tipo' => $r->hasText() ? 'opinión' : 'voto',
            'estado' => $r->approved ? 'aprobada' : 'pendiente',
            'fecha' => $r->date?->format('Y-m-d H:i') ?? '',
        ], $records), ['id', 'páginas', 'estrellas', 'tipo', 'estado', 'fecha']);

        if ((bool) \WP_CLI\Utils\get_flag_value($assoc, 'dry-run', false)) {
            \WP_CLI::success(sprintf('Simulación: se borrarían %d valoraciones.', count($records)));

            return;
        }
        \WP_CLI::confirm(sprintf('¿Borrar definitivamente %d valoraciones?', count($records)), $assoc);

        $deleted = 0;
        foreach ($records as $record) {
            $deleted += $gateway->delete($record->id) ? 1 : 0;
        }
        $this->cache->flush();
        \WP_CLI::success(sprintf('%d de %d valoraciones borradas.', $deleted, count($records)));
    }
}
