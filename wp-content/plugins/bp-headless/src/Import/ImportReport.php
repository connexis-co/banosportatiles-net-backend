<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Import;

/**
 * Import counters per entity and action, plus warnings.
 */
final class ImportReport
{
    public const ACTIONS = ['created', 'updated', 'unchanged', 'skipped', 'error'];

    /** @var array<string, array<string, int>> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    public function count(string $entity, string $action): void
    {
        $this->counts[$entity] ??= array_fill_keys(self::ACTIONS, 0);
        $this->counts[$entity][$action] = ($this->counts[$entity][$action] ?? 0) + 1;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @return list<array<string, int|string>>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->counts as $entity => $counts) {
            $rows[] = ['entidad' => $entity] + $counts;
        }

        return $rows;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function total(string $action): int
    {
        return array_sum(array_map(static fn (array $c): int => $c[$action] ?? 0, $this->counts));
    }
}
