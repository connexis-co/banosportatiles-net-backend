<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Rest;

use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Rest\Controllers\ContentController;
use BanosPortatiles\Headless\Rest\Controllers\FaqsController;
use BanosPortatiles\Headless\Rest\Controllers\LeadsController;
use BanosPortatiles\Headless\Rest\Controllers\NodeController;
use BanosPortatiles\Headless\Rest\Controllers\RedirectsController;
use BanosPortatiles\Headless\Rest\Controllers\RoutesController;
use BanosPortatiles\Headless\Rest\Controllers\SiteController;

/**
 * Registers the bp/v1 namespace (contract: docs/plans/2026-09-22_reestructuracion-headless.md §5).
 */
final class RestApi implements Hookable
{
    public const NAMESPACE = 'bp/v1';

    public function __construct(
        private readonly SiteController $site,
        private readonly RoutesController $routes,
        private readonly ContentController $content,
        private readonly NodeController $node,
        private readonly FaqsController $faqs,
        private readonly RedirectsController $redirects,
        private readonly LeadsController $leads,
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $public = static fn (): bool => true;

        register_rest_route(self::NAMESPACE, '/site', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->site, 'handle'],
            'permission_callback' => $public,
        ]);

        register_rest_route(self::NAMESPACE, '/routes', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->routes, 'handle'],
            'permission_callback' => $public,
        ]);

        register_rest_route(self::NAMESPACE, '/content', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->content, 'handle'],
            'permission_callback' => $public,
            'args' => [
                'type' => ['type' => 'string', 'enum' => ContentController::TYPES, 'default' => 'page'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ContentController::MAX_PER_PAGE, 'default' => 50],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/node', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->node, 'handle'],
            'permission_callback' => $public,
            'args' => [
                'uri' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'token' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/faqs', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->faqs, 'handle'],
            'permission_callback' => $public,
        ]);

        register_rest_route(self::NAMESPACE, '/redirects', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->redirects, 'handle'],
            'permission_callback' => $public,
        ]);

        register_rest_route(self::NAMESPACE, '/leads', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this->leads, 'handle'],
            'permission_callback' => [$this->leads, 'authorize'],
        ]);
    }
}
