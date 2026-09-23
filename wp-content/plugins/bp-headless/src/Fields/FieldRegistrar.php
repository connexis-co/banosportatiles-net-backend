<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Contracts\Hookable;

/**
 * Registers the options page and local field groups on acf/init, and protects admin-only settings.
 */
final class FieldRegistrar implements Hookable
{
    public function __construct(private readonly Config $config) {}

    public function register(): void
    {
        add_action('acf/init', [$this, 'registerFields']);
        // Fields live in code: the SCF field-group UI is only useful locally.
        add_filter('acf/settings/show_admin', fn (): bool => $this->config->isLocal());
        add_filter('acf/prepare_field/key='.SiteSettings::DEPLOY_HOOK_FIELD, [$this, 'prepareDeployHookField']);
        add_filter('acf/update_value/key='.SiteSettings::DEPLOY_HOOK_FIELD, [$this, 'guardDeployHookValue'], 10, 3);
    }

    public function registerFields(): void
    {
        if (function_exists('acf_add_options_page')) {
            acf_add_options_page(SiteSettings::page());
        }

        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        foreach (FieldGroups::all() as $group) {
            acf_add_local_field_group($group);
        }
    }

    /**
     * @param  array<string, mixed>|false  $field
     * @return array<string, mixed>|false
     */
    public function prepareDeployHookField(array|false $field): array|false
    {
        if ($field === false || ! current_user_can('manage_options')) {
            return false;
        }

        if ($this->config->deployHookSource() === 'wp-config') {
            $field['instructions'] = 'Definido en wp-config.php (BP_DEPLOY_HOOK_URL): este campo se ignora.';
        }

        return $field;
    }

    public function guardDeployHookValue(mixed $value, int|string $postId, mixed $field): mixed
    {
        if (current_user_can('manage_options') || (defined('WP_CLI') && WP_CLI)) {
            return $value;
        }

        return get_option(Config::OPTIONS_ID.'_deploy_hook_url', '');
    }
}
