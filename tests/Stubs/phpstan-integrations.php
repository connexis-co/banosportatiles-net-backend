<?php

declare(strict_types=1);

/**
 * PHPStan-only stubs (phpstan.neon.dist → scanFiles; never loaded at runtime or by Pest) for the public APIs of
 * the third-party plugins bp-headless integrates with: Site Reviews 8.x, Rank Math 1.0.x and Safe SVG 2.x.
 * Only the members bp-headless uses are declared.
 */

namespace {
    /**
     * @param  array<string, mixed>  $values
     * @return \GeminiLabs\SiteReviews\Review|false
     */
    function glsr_create_review(array $values = []) {}

    /** @param array<string, mixed> $args */
    function glsr_get_reviews(array $args = []): \GeminiLabs\SiteReviews\Reviews {}

    /** @param int|string $postId */
    function glsr_get_review($postId): \GeminiLabs\SiteReviews\Review {}

    /**
     * @param  array<string, mixed>  $values
     * @return \GeminiLabs\SiteReviews\Review|false
     */
    function glsr_update_review(int $postId, array $values = []) {}

    /**
     * @param  mixed  $fallback
     * @return mixed
     */
    function glsr_get_option(string $path = '', $fallback = '', string $cast = '') {}

    /**
     * @param  string|null  $alias
     * @param  array<string, mixed>  $parameters
     * @return mixed
     */
    function glsr($alias = null, array $parameters = []) {}

    /**
     * Container with magic properties (PHPStan honors @property only on classes with __get).
     *
     * @property-read \RankMath\Replace_Variables\Manager $variables
     * @property-read \RankMath\Settings $settings
     */
    final class RankMath
    {
        /** @return mixed */
        public function __get(string $property) {}

        public function __isset(string $property): bool {}
    }

    function rank_math(): RankMath {}

    /**
     * Action Scheduler (bundled with Site Reviews).
     *
     * @param  array<string, mixed>  $args
     */
    function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false): int {}
}

namespace GeminiLabs\SiteReviews {
    /**
     * ArrayObject with ARRAY_AS_PROPS: values are also read as properties.
     *
     * @extends \ArrayObject<string, mixed>
     */
    class Arguments extends \ArrayObject
    {
        /** @return mixed */
        public function __get(string $key) {}
    }

    /**
     * @property int $ID
     * @property int $rating
     * @property string $author
     * @property string $title
     * @property string $content
     * @property string $date
     * @property string $date_gmt
     * @property string $email
     * @property string $ip_address
     * @property bool $is_approved
     * @property string $response
     * @property list<int> $assigned_posts
     * @property list<int> $assigned_terms
     */
    class Review extends Arguments
    {
        public function isValid(): bool {}
    }

    /**
     * @extends \ArrayObject<int, Review>
     */
    class Reviews extends \ArrayObject
    {
        /** @var list<Review> */
        public array $reviews;

        public int $total;
    }
}

namespace GeminiLabs\SiteReviews\Database {
    class OptionManager
    {
        /** @param mixed $value */
        public function set(string $path, $value = ''): bool {}

        /**
         * @param  mixed  $fallback
         * @return mixed
         */
        public function get(string $path = '', $fallback = '', string $cast = '') {}
    }
}

namespace RankMath {
    class Helper
    {
        /**
         * @param  mixed  $default_value
         * @return mixed
         */
        public static function get_settings(string $field_id = '', $default_value = false) {}

        /**
         * @param  mixed  $args
         * @param  array<int, string>  $exclude
         */
        public static function replace_vars(string $content, $args = [], array $exclude = []): string {}

        /** @param array<string, string> $modules */
        public static function update_modules(array $modules): void {}
    }

    class Settings
    {
        public function reset(): void {}
    }
}

namespace RankMath\Replace_Variables {
    class Manager
    {
        public function setup(): void {}
    }

    class Replacer
    {
        /** @var array<string, mixed> */
        public static $replacements_cache = [];
    }
}

namespace enshrined\svgSanitize {
    class Sanitizer
    {
        public function removeRemoteReferences(bool $removeRemoteRefs = false): void {}

        /** @return string|false */
        public function sanitize(string $dirty) {}
    }
}
