<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Security;

use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Comments and pingbacks disabled everywhere: post type support, front, admin UI and REST.
 */
final class CommentsOff implements Hookable
{
    public function register(): void
    {
        add_action('init', [$this, 'removeSupport'], 100);
        add_filter('comments_open', '__return_false', 20);
        add_filter('pings_open', '__return_false', 20);
        add_filter('comments_array', '__return_empty_array', 10);
        add_filter('rest_allow_anonymous_comments', '__return_false');
        add_filter('rest_endpoints', [$this, 'removeEndpoints']);
        add_action('admin_menu', [$this, 'removeMenus'], 999);
        add_action('admin_init', [$this, 'blockScreens']);
        add_action('wp_dashboard_setup', static function (): void {
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
        });
        add_action('admin_bar_menu', static function (\WP_Admin_Bar $bar): void {
            $bar->remove_node('comments');
        }, 999);
    }

    public function removeSupport(): void
    {
        foreach (get_post_types() as $type) {
            remove_post_type_support($type, 'comments');
            remove_post_type_support($type, 'trackbacks');
        }
    }

    /**
     * @param  array<string, mixed>  $endpoints
     * @return array<string, mixed>
     */
    public function removeEndpoints(array $endpoints): array
    {
        foreach (array_keys($endpoints) as $route) {
            if (str_starts_with($route, '/wp/v2/comments')) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    public function removeMenus(): void
    {
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public function blockScreens(): void
    {
        global $pagenow;
        if (in_array($pagenow, ['edit-comments.php', 'comment.php', 'options-discussion.php'], true)) {
            wp_safe_redirect(admin_url());
            exit;
        }
    }
}
