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
     * @return array{schema:string, rows:list<array<string,mixed>>, abstentions:list<array<string,mixed>>}
     */
    public function mineGiveBackShapes(?array $rows = null, ?int $minSample = null): array
    {
        $configuredFloor = config('atlas.maestro.adaptive.miner_min_sample', 5);
        $minSample ??= is_numeric($configuredFloor) && (int) $configuredFloor > 0 ? (int) $configuredFloor : 5;
        $groups = [];
        $groupBuckets = []; // shape_key => [bucket => count]

        foreach (($rows ?? $this->rows ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $shapeKey = $this->shapeKey($row);
            $groups[$shapeKey] ??= ['shape_key' => $shapeKey, 'give_back_count' => 0, 'served_count' => 0];
            $groups[$shapeKey]['served_count'] += $this->eventCount($row, 'served');
            $giveBacks = $this->eventCount($row, 'give_back');
            $groups[$shapeKey]['give_back_count'] += $giveBacks;
            if ($giveBacks > 0) {
                $bucket = $this->classifyGiveBackReason($row);
                $groupBuckets[$shapeKey][$bucket] = ($groupBuckets[$shapeKey][$bucket] ?? 0) + $giveBacks;
            }
        }

        $facts = [];
        $abstentions = [];
        foreach ($groups as $shapeKey => $group) {
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
                'bucket' => $this->dominantBucket($groupBuckets[$shapeKey] ?? []),
                'respec_hint' => $this->respecHint($this->dominantBucket($groupBuckets[$shapeKey] ?? [])),
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
     * Classify a give_back row's root cause from deterministic local fields only.
     *
     * Buckets: missing_impl_file, forbidden_target, contradictory_acceptance,
     * schema_mismatch, duplicate_or_noop, unknown.
     *
     * @param  array<string,mixed>  $row
     */
    public function classifyGiveBackReason(array $row): string
    {
        $reason = strtolower(trim((string) ($row['give_back_reason'] ?? $row['reason'] ?? '')));
        $commitReason = strtolower(trim((string) ($row['commit_failed_reason'] ?? $row['commit_reason'] ?? '')));

        // forbidden_target — scoped commit refused a pétreo/property_gated file.
        foreach ([$reason, $commitReason] as $haystack) {
            if (str_contains($haystack, 'forbidden_self_target') || str_contains($haystack, 'forbidden_target') || str_contains($haystack, 'property_gated')) {
                return 'forbidden_target';
            }
        }

        // missing_impl_file — the implementation allowed_file does not exist.
        $allowedFiles = (array) ($row['allowed_files'] ?? []);
        $implFile = '';
        foreach ($allowedFiles as $f) {
            if (! str_contains((string) $f, 'Test.php')) {
                $implFile = (string) $f;
                break;
            }
        }
        $implMissing = (bool) ($row['impl_file_missing'] ?? false);
        if ($implMissing || (str_contains($reason, 'missing') && str_contains($reason, 'impl'))) {
            return 'missing_impl_file';
        }

        // contradictory_acceptance — self-contradictory acceptance criteria.
        foreach ([$reason] as $haystack) {
            if (str_contains($haystack, 'contradictory') || str_contains($haystack, 'self-contradictory') || str_contains($haystack, 'contradictory_acceptance')) {
                return 'contradictory_acceptance';
            }
        }

        // schema_mismatch — packet schema mismatch.
        foreach ([$reason] as $haystack) {
            if (str_contains($haystack, 'schema') && (str_contains($haystack, 'mismatch') || str_contains($haystack, 'invalid'))) {
                return 'schema_mismatch';
            }
        }

        // duplicate_or_noop — duplicate or no-op task.
        foreach ([$reason] as $haystack) {
            if (str_contains($haystack, 'duplicate') || str_contains($haystack, 'noop') || str_contains($haystack, 'no_op') || str_contains($haystack, 'nothing_to_commit')) {
                return 'duplicate_or_noop';
            }
        }

        return 'unknown';
    }

    /** @param array<string,int> $buckets */
    private function dominantBucket(array $buckets): string
    {
        if ($buckets === []) {
            return 'unknown';
        }
        arsort($buckets);

        return (string) array_key_first($buckets);
    }

    private function respecHint(string $bucket): string
    {
        return match ($bucket) {
            'missing_impl_file'         => 'respec: ensure implementation file exists before enqueue',
            'forbidden_target'          => 'respec: remove pétreo/property_gated files from scope or give to operator',
            'contradictory_acceptance'  => 'respec: rewrite acceptance criteria to be mutually satisfiable',
            'schema_mismatch'           => 'respec: align packet schema with the expected version',
            'duplicate_or_noop'         => 'respec: verify task is not a duplicate or no-op before enqueue',
            default                     => 'respec: inspect give_back reason manually',
        };
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
