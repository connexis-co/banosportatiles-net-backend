<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Redirects;

use BanosPortatiles\Headless\Config;
use BanosPortatiles\Headless\Html\HtmlCleaner;

/**
 * Reads and upserts plain URL redirects stored by the Redirection plugin (table {prefix}redirection_items).
 * Regex redirects are excluded: Cloudflare's _redirects cannot express them.
 */
final class RedirectionRepository
{
    public const CODES = [301, 302, 307, 308];

    public function __construct(private readonly Config $config) {}

    public function available(): bool
    {
        global $wpdb;

        return class_exists('Red_Item') && $wpdb instanceof \wpdb
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix.'redirection_items')) !== null;
    }

    /**
     * @return list<array{from: string, to: string, code: int}>
     */
    public function all(): array
    {
        global $wpdb;
        if (! $this->available() || ! $wpdb instanceof \wpdb) {
            return [];
        }

        $table = $wpdb->prefix.'redirection_items';
        $rows = $wpdb->get_results(
            "SELECT url, action_data, action_code FROM {$table} WHERE status = 'enabled' AND action_type = 'url' AND match_type = 'url' AND regex = 0 ORDER BY position ASC, id ASC",
            ARRAY_A
        );

        $redirects = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $code = (int) ($row['action_code'] ?? 301);
            $from = trim((string) ($row['url'] ?? ''));
            $to = $this->relativize(trim((string) ($row['action_data'] ?? '')));
            if ($from === '' || $to === '' || ! in_array($code, self::CODES, true)) {
                continue;
            }
            $redirects[] = ['from' => $from, 'to' => $to, 'code' => $code];
        }

        return $redirects;
    }

    /**
     * @return 'created'|'updated'|'unchanged'|'error'
     */
    public function upsert(string $from, string $to, int $code): string
    {
        global $wpdb;
        if (! $this->available() || ! $wpdb instanceof \wpdb) {
            return 'error';
        }

        $table = $wpdb->prefix.'redirection_items';
        $sql = $wpdb->prepare('SELECT id, action_data, action_code FROM %i WHERE url = %s AND regex = 0 LIMIT 1', $table, $from);
        $existing = is_string($sql) ? $wpdb->get_row($sql, ARRAY_A) : null;
        $details = [
            'url' => $from,
            'action_data' => ['url' => $to],
            'action_type' => 'url',
            'action_code' => $code,
            'match_type' => 'url',
            'group_id' => $this->groupId(),
            'regex' => false,
            'title' => 'seed',
        ];

        if (is_array($existing)) {
            if ((string) $existing['action_data'] === $to && (int) $existing['action_code'] === $code) {
                return 'unchanged';
            }
            $getById = ['Red_Item', 'get_by_id'];
            $item = is_callable($getById) ? $getById((int) $existing['id']) : null;
            $result = is_object($item) && method_exists($item, 'update') ? $item->update($details) : false;

            return ($result === false || $result instanceof \WP_Error) ? 'error' : 'updated';
        }

        $create = ['Red_Item', 'create'];
        $result = is_callable($create) ? $create($details) : new \WP_Error('bp_redirection_missing', 'Redirection no está disponible.');

        return $result instanceof \WP_Error ? 'error' : 'created';
    }

    private function groupId(): int
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) {
            return 1;
        }
        $id = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}redirection_groups WHERE module_id = 1 AND status = 'enabled' ORDER BY id ASC LIMIT 1");

        return is_numeric($id) ? (int) $id : 1;
    }

    private function relativize(string $url): string
    {
        $host = HtmlCleaner::authority($url);
        $fronts = [HtmlCleaner::authority($this->config->frontendUrl()), HtmlCleaner::authority(Config::DEFAULT_FRONTEND_URL)];
        if ($host !== '' && (in_array($host, $fronts, true) || in_array('www.'.$host, $fronts, true) || in_array(preg_replace('/^www\./', '', $host), $fronts, true))) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            $query = parse_url($url, PHP_URL_QUERY);

            return HtmlCleaner::withTrailingSlash($path === '' ? '/' : $path).(is_string($query) ? '?'.$query : '');
        }

        return $url;
    }
}
