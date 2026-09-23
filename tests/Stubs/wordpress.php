<?php

/**
 * Minimal WordPress constants and value objects for unit tests (functions are mocked with Brain Monkey).
 */

declare(strict_types=1);

namespace {
    defined('ABSPATH') || define('ABSPATH', sys_get_temp_dir().'/wordpress/');
    defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
    defined('OBJECT') || define('OBJECT', 'OBJECT');
    defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

    if (! class_exists('WP_Post')) {
        final class WP_Post
        {
            public int $ID = 0;

            public string $post_type = 'post';

            public string $post_name = '';

            public string $post_title = '';

            public string $post_status = 'publish';

            public string $post_password = '';

            public string $post_content = '';

            public string $post_excerpt = '';

            public int $post_parent = 0;

            public int $menu_order = 0;

            /** @param array<string, mixed> $data */
            public function __construct(array $data = [])
            {
                foreach ($data as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }
    }

    if (! class_exists('WP_Term')) {
        final class WP_Term
        {
            public int $term_id = 0;

            public string $slug = '';

            public string $name = '';

            public string $taxonomy = '';

            /** @param array<string, mixed> $data */
            public function __construct(array $data = [])
            {
                foreach ($data as $key => $value) {
                    $this->{$key} = $value;
                }
            }
        }
    }
}
