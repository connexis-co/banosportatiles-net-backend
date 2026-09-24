<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Fields;

/**
 * Builds SCF/ACF local field arrays with stable, path-derived keys:
 * root "" + "seo" → field_bp_seo; child "seo" + "title" → field_bp_seo_title;
 * flexible layouts → layout_bp_{path}_{layout}.
 */
final class FieldBuilder
{
    public function __construct(private readonly string $prefix = '') {}

    public function key(string $name): string
    {
        return 'field_bp_'.$this->path($name);
    }

    public function child(string $name): self
    {
        return new self($this->path($name));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function field(string $type, string $name, string $label, array $extra = []): array
    {
        return ['key' => $this->key($name), 'name' => $name, 'label' => $label, 'type' => $type] + $extra;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function text(string $name, string $label, array $extra = []): array
    {
        return $this->field('text', $name, $label, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function textarea(string $name, string $label, array $extra = []): array
    {
        return $this->field('textarea', $name, $label, ['rows' => 3, 'new_lines' => ''] + $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function url(string $name, string $label, array $extra = []): array
    {
        return $this->field('url', $name, $label, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function email(string $name, string $label, array $extra = []): array
    {
        return $this->field('email', $name, $label, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function number(string $name, string $label, array $extra = []): array
    {
        return $this->field('number', $name, $label, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function trueFalse(string $name, string $label, array $extra = []): array
    {
        return $this->field('true_false', $name, $label, ['ui' => 1, 'default_value' => 0] + $extra);
    }

    /**
     * @param  array<array-key, string>  $choices  Numeric keys ("2", "3") become ints in PHP arrays.
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function select(string $name, string $label, array $choices, array $extra = []): array
    {
        return $this->field('select', $name, $label, ['choices' => $choices, 'return_format' => 'value', 'allow_null' => 0, 'ui' => 0] + $extra);
    }

    /**
     * @param  array<string, string>  $choices
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function checkbox(string $name, string $label, array $choices, array $extra = []): array
    {
        return $this->field('checkbox', $name, $label, ['choices' => $choices, 'return_format' => 'value', 'layout' => 'horizontal'] + $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function image(string $name, string $label, array $extra = []): array
    {
        return $this->field('image', $name, $label, ['return_format' => 'id', 'preview_size' => 'medium', 'library' => 'all'] + $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function gallery(string $name, string $label, array $extra = []): array
    {
        return $this->field('gallery', $name, $label, ['return_format' => 'id', 'preview_size' => 'medium', 'library' => 'all', 'insert' => 'append'] + $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function file(string $name, string $label, array $extra = []): array
    {
        return $this->field('file', $name, $label, ['return_format' => 'id', 'library' => 'all'] + $extra);
    }

    /**
     * @param  list<string>  $postTypes
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function relationship(string $name, string $label, array $postTypes, array $extra = []): array
    {
        return $this->field('relationship', $name, $label, [
            'post_type' => $postTypes,
            'post_status' => ['publish'],
            'return_format' => 'id',
            'filters' => ['search'],
            'elements' => [],
        ] + $extra);
    }

    /**
     * @param  list<string>  $postTypes
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function postObject(string $name, string $label, array $postTypes, array $extra = []): array
    {
        return $this->field('post_object', $name, $label, [
            'post_type' => $postTypes,
            'return_format' => 'id',
            'allow_null' => 1,
            'multiple' => 0,
            'ui' => 1,
        ] + $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function taxonomy(string $name, string $label, string $taxonomy, array $extra = []): array
    {
        return $this->field('taxonomy', $name, $label, [
            'taxonomy' => $taxonomy,
            'field_type' => 'multi_select',
            'return_format' => 'id',
            'allow_null' => 1,
            'add_term' => 0,
            'save_terms' => 0,
            'load_terms' => 0,
        ] + $extra);
    }

    /** @return array<string, mixed> */
    public function tab(string $name, string $label): array
    {
        return $this->field('tab', $name, $label, ['placement' => 'top', 'endpoint' => 0]);
    }

    /** @return array<string, mixed> */
    public function message(string $name, string $label, string $message): array
    {
        return $this->field('message', $name, $label, ['message' => $message, 'new_lines' => 'wpautop', 'esc_html' => 0]);
    }

    /**
     * @param  callable(FieldBuilder): list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function group(string $name, string $label, callable $fields, array $extra = []): array
    {
        return $this->field('group', $name, $label, ['layout' => 'block', 'sub_fields' => $fields($this->child($name))] + $extra);
    }

    /**
     * @param  callable(FieldBuilder): list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function repeater(string $name, string $label, callable $fields, array $extra = []): array
    {
        return $this->field('repeater', $name, $label, [
            'layout' => 'table',
            'button_label' => 'Añadir fila',
            'min' => 0,
            'max' => 0,
            'sub_fields' => $fields($this->child($name)),
        ] + $extra);
    }

    /**
     * @param  array<string, array{label: string, fields: callable(FieldBuilder): list<array<string, mixed>>}>  $layouts
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function flexible(string $name, string $label, array $layouts, array $extra = []): array
    {
        $child = $this->child($name);
        $definitions = [];
        foreach ($layouts as $layoutName => $layout) {
            $key = 'layout_bp_'.$this->path($name).'_'.$layoutName;
            $definitions[$key] = [
                'key' => $key,
                'name' => $layoutName,
                'label' => $layout['label'],
                'display' => 'block',
                'sub_fields' => ($layout['fields'])($child->child($layoutName)),
                'min' => '',
                'max' => '',
            ];
        }

        return $this->field('flexible_content', $name, $label, ['layouts' => $definitions, 'button_label' => 'Añadir sección'] + $extra);
    }

    private function path(string $name): string
    {
        return $this->prefix === '' ? $name : $this->prefix.'_'.$name;
    }
}
