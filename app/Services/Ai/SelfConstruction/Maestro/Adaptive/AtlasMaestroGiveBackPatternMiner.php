<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

final class AtlasMaestroGiveBackPatternMiner
{
    public const SCHEMA = 'atlas.maestro.adaptive.giveback_pattern_facts.v1';

    /**
     * @param  list<array<string,mixed>>|null  $rows
     */
    public function __construct(private readonly ?array $rows = null)
    {
    }

    /**
     * @param  list<array<string,mixed>>|null  $rows
     * @return array{schema:string, rows:list<array{shape_key:string,give_back_count:int,served_count:int}>, abstentions:list<array{shape_key:string,served_count:int,abstain_reason:string}>}
     */
    public function mineGiveBackShapes(?array $rows = null, ?int $minSample = null): array
    {
        $configuredFloor = config('atlas.maestro.adaptive.miner_min_sample', 5);
        $minSample ??= is_numeric($configuredFloor) && (int) $configuredFloor > 0 ? (int) $configuredFloor : 5;
        $groups = [];

        foreach (($rows ?? $this->rows ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $shapeKey = $this->shapeKey($row);
            $groups[$shapeKey] ??= ['shape_key' => $shapeKey, 'give_back_count' => 0, 'served_count' => 0];
            $groups[$shapeKey]['served_count'] += $this->eventCount($row, 'served');
            $groups[$shapeKey]['give_back_count'] += $this->eventCount($row, 'give_back');
        }

        $facts = [];
        $abstentions = [];
        foreach ($groups as $group) {
            if ($group['served_count'] < $minSample) {
                $abstentions[] = [
                    'shape_key' => $group['shape_key'],
                    'served_count' => (int) $group['served_count'],
                    'abstain_reason' => 'insufficient_sample',
                ];

                continue;
            }
            $facts[] = [
                'shape_key' => $group['shape_key'],
                'give_back_count' => (int) $group['give_back_count'],
                'served_count' => (int) $group['served_count'],
            ];
        }

        usort($facts, static fn (array $left, array $right): int => [-(int) $left['give_back_count'], (string) $left['shape_key']] <=> [-(int) $right['give_back_count'], (string) $right['shape_key']]);
        usort($abstentions, static fn (array $left, array $right): int => strcmp((string) $left['shape_key'], (string) $right['shape_key']));

        return [
            'schema' => self::SCHEMA,
            'rows' => $facts,
            'abstentions' => $abstentions,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function eventCount(array $row, string $event): int
    {
        $delta = $row[$event.'_delta'] ?? null;
        if (is_numeric($delta)) {
            return max(0, (int) $delta);
        }
        if (($row['last_event'] ?? null) === $event) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function shapeKey(array $row): string
    {
        return implode('|', [
            'task_class:'.$this->text($row['task_class'] ?? 'unknown'),
            'allowed_files:'.$this->allowedFilesBucket($row),
            'scope:'.$this->scopePrefix($row),
            'evidence:'.$this->evidenceKind($row),
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function allowedFilesBucket(array $row): string
    {
        if (isset($row['allowed_files_count']) && is_numeric($row['allowed_files_count'])) {
            $count = (int) $row['allowed_files_count'];
        } else {
            $count = count((array) ($row['allowed_files'] ?? []));
        }

        return match (true) {
            $count <= 1 => '1',
            $count <= 3 => '2-3',
            default => '4+',
        };
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function scopePrefix(array $row): string
    {
        $paths = (array) ($row['scope_in'] ?? $row['allowed_files'] ?? []);
        $first = $this->text($paths[0] ?? 'unknown');
        $parts = explode('/', $first);

        return implode('/', array_slice($parts, 0, min(4, count($parts)))) ?: 'unknown';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function evidenceKind(array $row): string
    {
        $items = (array) ($row['required_evidence'] ?? []);
        $first = $this->text($items[0] ?? 'unknown');
        $parts = explode(':', $first);

        return $parts[0] !== '' ? $parts[0] : 'unknown';
    }

    private function text(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : 'unknown';
    }
}
