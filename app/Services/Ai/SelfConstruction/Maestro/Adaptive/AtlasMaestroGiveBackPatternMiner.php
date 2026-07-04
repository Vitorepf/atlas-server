<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

final class AtlasMaestroGiveBackPatternMiner
{
    public const SCHEMA = 'atlas.maestro.adaptive.giveback_pattern_facts.v1';

    /** Below this many give_back samples, confidence is 'low' regardless of dominant-bucket proportion — a single anecdote must not drive queue repair. */
    private const MIN_GIVEBACK_SAMPLE_FOR_CONFIDENCE = 3;

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
            $buckets = $groupBuckets[$shapeKey] ?? [];
            $dominant = $this->dominantBucket($buckets);
            $giveBackCount = (int) $group['give_back_count'];
            $confidence = $this->repairConfidence($buckets, $giveBackCount);
            $facts[] = [
                'shape_key' => $group['shape_key'],
                'give_back_count' => $giveBackCount,
                'served_count' => (int) $group['served_count'],
                'bucket' => $dominant,
                'respec_hint' => $this->respecHint($dominant),
                'supporting_bucket_counts' => $buckets,
                'repair_confidence' => $confidence,
                'sample_size' => $giveBackCount,
                'confidence' => $confidence,
                'repair_action' => $this->repairAction($dominant),
                'admission_rule_hint' => $this->admissionRuleHint($dominant),
            ];
        }

        usort($facts, static fn (array $left, array $right): int => [-(int) $left['give_back_count'], (string) $left['shape_key']] <=> [-(int) $right['give_back_count'], (string) $right['shape_key']]);
        usort($abstentions, static fn (array $left, array $right): int => strcmp((string) $left['shape_key'], (string) $right['shape_key']));

        // Cluster root causes and derive action candidates
        $clusters = $this->clusterRootCauses($facts);

        return [
            'schema' => self::SCHEMA,
            'rows' => $facts,
            'abstentions' => $abstentions,
            'root_cause_clusters' => $clusters['clusters'],
            'packet_repair_candidates' => $clusters['packet_repair_candidates'],
            'reroute_candidates' => $clusters['reroute_candidates'],
            'quarantine_candidates' => $clusters['quarantine_candidates'],
        ];
    }

    /**
     * Cluster give_backs into root cause families and derive action candidates.
     *
     * Families: scope, acceptance, dependency, duplicate, worker_weakness.
     * packet_defect clusters (scope, acceptance, dependency, duplicate) are marked separately
     * from worker_routing clusters (worker_weakness).
     *
     * @param  list<array<string,mixed>>  $facts
     */
    private function clusterRootCauses(array $facts): array
    {
        $clusters = [];
        $packetRepairCandidates = [];
        $rerouteCandidates = [];
        $quarantineCandidates = [];

        // Bucket families
        $familyMap = [
            'scope' => ['scope_gap', 'missing_impl_file', 'forbidden_target'],
            'acceptance' => ['contradictory_acceptance', 'contradiction', 'schema_mismatch'],
            'dependency' => ['missing_evidence'],
            'duplicate' => ['duplicate_or_noop', 'duplicate_capability'],
            'worker_weakness' => ['worker_mismatch'],
        ];

        // Group facts by family
        $familyGroups = [];
        foreach ($facts as $fact) {
            $bucket = $fact['bucket'] ?? 'unknown';
            $family = $this->resolveFamily($bucket, $familyMap);
            $familyGroups[$family][] = $fact;
        }

        // Build clusters
        foreach ($familyGroups as $family => $groupFacts) {
            $totalGiveBacks = array_sum(array_column($groupFacts, 'give_back_count'));
            $shapeKeys = array_column($groupFacts, 'shape_key');
            $buckets = array_column($groupFacts, 'bucket');
            $isWorkerRouting = $family === 'worker_weakness';

            $cluster = [
                'family' => $family,
                'is_packet_defect' => ! $isWorkerRouting,
                'is_worker_routing' => $isWorkerRouting,
                'total_give_backs' => $totalGiveBacks,
                'shape_keys' => array_values(array_unique($shapeKeys)),
                'buckets' => array_values(array_unique($buckets)),
                'repair_action' => $this->familyRepairAction($family),
            ];
            $clusters[] = $cluster;

            // Derive candidates
            foreach ($groupFacts as $fact) {
                if ($isWorkerRouting) {
                    $rerouteCandidates[] = [
                        'shape_key' => $fact['shape_key'],
                        'bucket' => $fact['bucket'],
                        'give_back_count' => $fact['give_back_count'],
                        'reason' => 'worker_mismatch_requires_rerouting',
                    ];
                } else {
                    $packetRepairCandidates[] = [
                        'shape_key' => $fact['shape_key'],
                        'bucket' => $fact['bucket'],
                        'give_back_count' => $fact['give_back_count'],
                        'family' => $family,
                        'repair_action' => $fact['repair_action'] ?? $this->familyRepairAction($family),
                    ];
                }

                // High give_back count → quarantine candidate
                if ($fact['give_back_count'] >= 5 && $fact['confidence'] === 'high') {
                    $quarantineCandidates[] = [
                        'shape_key' => $fact['shape_key'],
                        'bucket' => $fact['bucket'],
                        'give_back_count' => $fact['give_back_count'],
                        'family' => $family,
                        'reason' => 'high_confidence_repeated_give_back',
                    ];
                }
            }
        }

        return [
            'clusters' => $clusters,
            'packet_repair_candidates' => $packetRepairCandidates,
            'reroute_candidates' => $rerouteCandidates,
            'quarantine_candidates' => $quarantineCandidates,
        ];
    }

    /**
     * Resolve a bucket to its family.
     */
    private function resolveFamily(string $bucket, array $familyMap): string
    {
        foreach ($familyMap as $family => $buckets) {
            if (in_array($bucket, $buckets, true)) {
                return $family;
            }
        }

        return 'unknown';
    }

    /**
     * Get the repair action for a family.
     */
    private function familyRepairAction(string $family): string
    {
        return match ($family) {
            'scope' => 'respec_scope_to_allowed_files',
            'acceptance' => 'rewrite_acceptance_criteria',
            'dependency' => 'attach_required_evidence',
            'duplicate' => 'deduplicate_and_reject',
            'worker_weakness' => 'reroute_to_matching_worker',
            default => 'inspect_manually',
        };
    }

    /**
     * Classify a give_back row's root cause from deterministic local fields only.
     *
     * Buckets: forbidden_target, missing_impl_file, missing_evidence, worker_mismatch,
     * duplicate_capability, scope_gap, contradictory_acceptance, contradiction,
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

        // missing_evidence — required_evidence absent or unverifiable.
        if (str_contains($reason, 'missing_evidence') || (str_contains($reason, 'missing') && str_contains($reason, 'evidence'))) {
            return 'missing_evidence';
        }

        // worker_mismatch — task routed to a worker/model tier that doesn't fit it.
        if (str_contains($reason, 'worker_mismatch') || str_contains($reason, 'wrong_worker') || str_contains($reason, 'model_tier_mismatch')
            || (str_contains($reason, 'worker') && str_contains($reason, 'mismatch'))) {
            return 'worker_mismatch';
        }

        // duplicate_capability — the capability already exists elsewhere in the codebase
        // (distinct from duplicate_or_noop below, which is about a duplicate TASK/no-op commit).
        if (str_contains($reason, 'duplicate_capability') || str_contains($reason, 'capability_already_exists')
            || (str_contains($reason, 'duplicate') && str_contains($reason, 'capability'))) {
            return 'duplicate_capability';
        }

        // scope_gap — acceptance/behavior requires files outside the declared scope.
        if (str_contains($reason, 'scope_gap') || str_contains($reason, 'out_of_scope') || str_contains($reason, 'outside_scope')
            || (str_contains($reason, 'scope') && (str_contains($reason, 'gap') || str_contains($reason, 'uncovered')))) {
            return 'scope_gap';
        }

        // contradictory_acceptance — self-contradictory acceptance criteria.
        foreach ([$reason] as $haystack) {
            if (str_contains($haystack, 'contradictory') || str_contains($haystack, 'self-contradictory') || str_contains($haystack, 'contradictory_acceptance')) {
                return 'contradictory_acceptance';
            }
        }

        // contradiction — a broader logical conflict not phrased as "contradictory acceptance".
        if (str_contains($reason, 'contradict')) {
            return 'contradiction';
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

    /**
     * 'high' only when the dominant bucket holds strict majority (>50%) support among all
     * classified give_backs for this shape AND the give_back sample itself clears
     * MIN_GIVEBACK_SAMPLE_FOR_CONFIDENCE — otherwise 'low', so the reshaper never overreacts
     * to a mixed/weak signal, or a single anecdote, as if it were a single clear root cause.
     *
     * @param  array<string,int>  $buckets
     */
    private function repairConfidence(array $buckets, int $sampleSize): string
    {
        $total = array_sum($buckets);
        if ($total === 0 || $sampleSize < self::MIN_GIVEBACK_SAMPLE_FOR_CONFIDENCE) {
            return 'low';
        }
        $dominantCount = max($buckets);

        return $dominantCount / $total > 0.5 ? 'high' : 'low';
    }

    private function respecHint(string $bucket): string
    {
        return match ($bucket) {
            'missing_impl_file'         => 'respec: ensure implementation file exists before enqueue',
            'forbidden_target'          => 'respec: remove pétreo/property_gated files from scope or give to operator',
            'missing_evidence'          => 'respec: attach required_evidence before re-enqueue',
            'worker_mismatch'           => 'respec: reroute to a worker/model tier that fits this task_class',
            'duplicate_capability'      => 'respec: verify the capability does not already exist before re-enqueue',
            'scope_gap'                 => 'respec: expand allowed_files/scope_in to cover every file acceptance requires',
            'contradictory_acceptance'  => 'respec: rewrite acceptance criteria to be mutually satisfiable',
            'contradiction'             => 'respec: resolve the conflicting dependencies or constraints',
            'schema_mismatch'           => 'respec: align packet schema with the expected version',
            'duplicate_or_noop'         => 'respec: verify task is not a duplicate or no-op before enqueue',
            default                     => 'respec: inspect give_back reason manually',
        };
    }

    /** Machine-actionable verb for the queue repair pipeline — a terser sibling of respec_hint. */
    private function repairAction(string $bucket): string
    {
        return match ($bucket) {
            'missing_impl_file'         => 'add_missing_impl_file',
            'forbidden_target'          => 'remove_forbidden_scope',
            'missing_evidence'          => 'attach_required_evidence',
            'worker_mismatch'           => 'reroute_to_matching_worker',
            'duplicate_capability'      => 'reject_duplicate_capability',
            'scope_gap'                 => 'expand_scope_coverage',
            'contradictory_acceptance'  => 'rewrite_acceptance_criteria',
            'contradiction'             => 'resolve_contradiction',
            'schema_mismatch'           => 'align_packet_schema',
            'duplicate_or_noop'         => 'dedup_check_before_enqueue',
            default                     => 'inspect_manually',
        };
    }

    /** Rule this pattern should feed into task-fabric admission so the same shape is rejected before it re-enters the queue. */
    private function admissionRuleHint(string $bucket): string
    {
        return match ($bucket) {
            'missing_impl_file'         => 'admission_rule: reject if declared allowed_files omit an implementation file',
            'forbidden_target'          => 'admission_rule: reject if allowed_files intersect a pétreo/property_gated target',
            'missing_evidence'          => 'admission_rule: reject if required_evidence is empty or unverifiable',
            'worker_mismatch'           => 'admission_rule: route by task_class/model_tier fit before enqueue',
            'duplicate_capability'      => 'admission_rule: reject if the capability already exists in the codebase',
            'scope_gap'                 => 'admission_rule: reject if acceptance references files outside allowed_files/scope_in',
            'contradictory_acceptance'  => 'admission_rule: reject if acceptance criteria are mutually unsatisfiable',
            'contradiction'             => 'admission_rule: reject if dependencies or constraints logically conflict',
            'schema_mismatch'           => 'admission_rule: reject if packet schema_version is unsupported',
            'duplicate_or_noop'         => 'admission_rule: reject if the task is a duplicate or produces no diff',
            default                     => 'admission_rule: flag for manual triage before re-enqueue',
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
        $parts = [
            'task_class:'.$this->text($row['task_class'] ?? 'unknown'),
            'allowed_files:'.$this->allowedFilesBucket($row),
            'scope:'.$this->scopePrefix($row),
            'evidence:'.$this->evidenceKind($row),
        ];

        return $this->collisionSafeImplode($parts);
    }

    /**
     * Build a collision-safe key from parts.
     * Each part is length-prefixed so a '|' inside a part cannot collide with the delimiter.
     *
     * @param  list<string>  $parts
     */
    private function collisionSafeImplode(array $parts): string
    {
        $prefixed = array_map(static fn (string $p): string => strlen($p).':'.$p, $parts);

        return implode('|', $prefixed);
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
