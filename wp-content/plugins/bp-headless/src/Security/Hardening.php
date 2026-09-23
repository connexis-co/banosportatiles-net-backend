<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * XML-RPC off, emojis off, oEmbed discovery off, head/header noise removed, file editor disabled.
 */
final class Hardening implements Hookable
{
    public function register(): void
    {
        if (! defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }

        // XML-RPC: no methods, no pingback header, and 403 for any direct call.
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('xmlrpc_methods', static fn (): array => []);
        add_filter('wp_headers', static function (array $headers): array {
            unset($headers['X-Pingback']);

            return $headers;
        });
        add_action('init', [$this, 'blockXmlRpc'], 0);

        // Emojis.
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
        remove_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');

        // oEmbed discovery (the editor's oEmbed proxy keeps working).
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        add_filter('embed_oembed_discover', '__return_false');

        // Head / header noise.
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('template_redirect', 'rest_output_link_header', 11);
        remove_action('template_redirect', 'wp_shortlink_header', 11);
        add_filter('the_generator', '__return_empty_string');
    }

    public function blockXmlRpc(): void
    {
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'XML-RPC está deshabilitado.';
            exit;
        }
    }
}
