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

    if (! class_exists('WP_Error')) {
        final class WP_Error
        {
            /** @param array<string, mixed>|mixed $data */
            public function __construct(private string $code = '', private string $message = '', private mixed $data = null) {}

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }

            public function get_error_data(): mixed
            {
                return $this->data;
            }
        }
    }

    if (! class_exists('WP_REST_Request')) {
        /**
         * Headers are stored like WordPress does: lowercase, "-" → "_".
         */
        final class WP_REST_Request
        {
            /** @var array<string, string> */
            private array $headers = [];

            /** @var array<string, mixed> */
            private array $params = [];

            private string $body = '';

            public function __construct(private string $method = 'GET', private string $route = '') {}

            public function get_method(): string
            {
                return $this->method;
            }

            public function get_route(): string
            {
                return $this->route;
            }

            public function set_header(string $key, string $value): void
            {
                $this->headers[strtolower(str_replace('-', '_', $key))] = $value;
            }

            public function get_header(string $key): ?string
            {
                return $this->headers[strtolower(str_replace('-', '_', $key))] ?? null;
            }

            public function set_body(string $body): void
            {
                $this->body = $body;
            }

            public function get_body(): string
            {
                return $this->body;
            }

            public function set_param(string $key, mixed $value): void
            {
                $this->params[$key] = $value;
            }

            public function get_param(string $key): mixed
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (! class_exists('WP_REST_Response')) {
        final class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            public function __construct(private mixed $data = null, private int $status = 200) {}

            public function header(string $key, string $value): void
            {
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function set_status(int $status): void
            {
                $this->status = $status;
            }

            public function get_data(): mixed
            {
                return $this->data;
            }

            public function set_data(mixed $data): void
            {
                $this->data = $data;
            }
        }
    }
}
