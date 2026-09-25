<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

use BanosPortatiles\Headless\Contracts\Hookable;
use BanosPortatiles\Headless\Fields\FieldBuilder;

/**
 * «Cotización» box: the service selector only lists published hub-servicio / servicio pages, with their path so
 * pages with similar titles can be told apart.
 */
final class QuoteCtaAdmin implements Hookable
{
    public function register(): void
    {
        $key = (new FieldBuilder('lead'))->key('service');
        add_filter('acf/fields/post_object/query/key='.$key, [$this, 'query'], 10, 1);
        add_filter('acf/fields/post_object/result/key='.$key, [$this, 'result'], 10, 2);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function query(array $args): array
    {
        return self::serviceQuery($args);
    }

    /**
     * WP_Query args limited to published service pages (pure, testable).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function serviceQuery(array $args): array
    {
        $args['post_type'] = 'page';
        $args['post_status'] = 'publish';
        $args['meta_query'] = [['key' => '_wp_page_template', 'value' => QuoteCta::SERVICE_TEMPLATES, 'compare' => 'IN']];
        $args['orderby'] = ['menu_order' => 'ASC', 'title' => 'ASC'];

        return $args;
    }

    public function result(string $text, \WP_Post $post): string
    {
        $path = (string) wp_parse_url((string) get_permalink($post), PHP_URL_PATH);

        return $path !== '' ? $text.' — '.$path : $text;
    }
}
