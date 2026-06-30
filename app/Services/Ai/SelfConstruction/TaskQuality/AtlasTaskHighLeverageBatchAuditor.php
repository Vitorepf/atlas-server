<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Non-petrified batch auditor for brain-seeded task specs.
 *
 * audit(specs) evaluates a batch as a whole and returns:
 *   - creditable (bool)        : false when any anti-proxy pattern fires
 *   - anti_proxy_facts (list)  : one fact record per detected pattern
 *   - remediation_hints (list) : actionable human-readable strings
 *
 * Detected patterns (each fires independently; creditable=false if any fire):
 *   - homogeneous_dormant_cli_arm      : ≥50% of specs have dormant_cli_arm_proxy=true
 *   - singleton_microtest_batch        : batch is exactly 1 spec with test_only_has_contract=true
 *   - duplicate_target_family          : 2+ specs share the exact same allowed_files set
 *   - thin_quota_farming               : ≥50% of specs have objectives under MIN_WORD_COUNT words
 *                                        or match known thin-farming phrases
 *   - low_diversity_leverage_class     : ≥80% of specs map to the same leverage class (batch ≥ 3)
 *   - non_self_sufficient_spec         : any spec has packet_quality.self_sufficient=false
 *
 * Pure: no I/O, no DB, no providers, no queue writes.
 */
final class AtlasTaskHighLeverageBatchAuditor
{
    public const SCHEMA = 'atlas.task_quality.high_leverage_batch_auditor.v1';

    private const DORMANT_ARM_RATIO = 0.5;

    private const LOW_DIVERSITY_RATIO = 0.8;

    private const THIN_FARMING_RATIO = 0.5;

    private const MIN_WORD_COUNT = 8;

    private const MIN_BATCH_FOR_DIVERSITY = 3;

    /** @var array<string,list<string>> */
    private const LEVERAGE_CLASS_KEYWORDS = [
        'bug_fix'             => ['fix', 'bug', 'repair', 'patch', 'correct'],
        'gate_hardening'      => ['gate', 'guard', 'harden', 'certif', 'verif'],
        'runtime_integration' => ['wire', 'wiring', 'integrat', 'connect', 'register'],
        'docs_sync'           => ['doc', 'wiki', 'readme', 'journal', 'canon'],
        'refactor'            => ['refactor', 'restructure', 'reorganiz'],
        'test_authoring'      => ['test', 'spec', 'contract', 'assert'],
        'feature'             => ['implement', 'creat', 'add', 'build', 'extend'],
    ];

    private const THIN_FARMING_PHRASES = [
        'add return type', 'add type hint', 'add phpdoc', 'add docblock',
        'remove trailing', 'fix whitespace', 'add blank line', 'add null check',
    ];

    /**
     * @param  list<array<string,mixed>>  $specs  raw task packet arrays
     * @return array{schema:string, creditable:bool, anti_proxy_facts:list<array<string,mixed>>, remediation_hints:list<string>}
     */
    public function audit(array $specs): array
    {
        if ($specs === []) {
            return $this->result(true, [], []);
        }

        $total = count($specs);
        $antiProxy = [];
        $hints = [];

        // 1. Homogeneous dormant CLI arm
        $dormantCount = count(array_filter($specs, static fn (array $s): bool =>
            (bool) ($s['packet_quality']['facts']['dormant_cli_arm_proxy'] ?? false)));
        if ($dormantCount / $total >= self::DORMANT_ARM_RATIO) {
            $antiProxy[] = [
                'pattern' => 'homogeneous_dormant_cli_arm',
                'dormant_count' => $dormantCount,
                'total' => $total,
                'ratio' => round($dormantCount / $total, 2),
            ];
            $hints[] = "Remove or wire the {$dormantCount} dormant CLI arm wrapper(s) before submitting as a creditable batch.";
        }

        // 2. Singleton characterisation microtest
        if ($total === 1 && (bool) ($specs[0]['packet_quality']['facts']['test_only_has_contract'] ?? false)) {
            $antiProxy[] = [
                'pattern' => 'singleton_microtest_batch',
                'spec_id' => (string) ($specs[0]['task_packet_id'] ?? ''),
            ];
            $hints[] = 'A single test-only characterisation spec is not a creditable batch; pair it with an implementation spec.';
        }

        // 3. Duplicate target families
        $targetCounts = [];
        foreach ($specs as $s) {
            $files = is_array($s['allowed_files'] ?? null) ? (array) $s['allowed_files'] : [];
            sort($files, SORT_STRING);
            $key = implode('|', $files);
            if ($key !== '') {
                $targetCounts[$key] = ($targetCounts[$key] ?? 0) + 1;
            }
        }
        $duplicateSets = array_filter($targetCounts, static fn (int $c): bool => $c >= 2);
        if ($duplicateSets !== []) {
            $antiProxy[] = [
                'pattern' => 'duplicate_target_family',
                'duplicate_file_sets' => array_keys($duplicateSets),
                'collision_counts' => array_values($duplicateSets),
            ];
            $hints[] = 'Multiple specs share the same allowed_files set; consolidate or ensure distinct scopes.';
        }

        // 4. Thin quota farming
        $thinCount = 0;
        foreach ($specs as $s) {
            $obj = strtolower(trim((string) ($s['objective'] ?? '')));
            $wordCount = count(array_filter(preg_split('/\s+/', $obj) ?: []));
            $thin = $wordCount < self::MIN_WORD_COUNT;
            if (! $thin) {
                foreach (self::THIN_FARMING_PHRASES as $phrase) {
                    if (str_contains($obj, $phrase)) {
                        $thin = true;
                        break;
                    }
                }
            }
            if ($thin) {
                $thinCount++;
            }
        }
        if ($thinCount / $total >= self::THIN_FARMING_RATIO) {
            $antiProxy[] = [
                'pattern' => 'thin_quota_farming',
                'thin_count' => $thinCount,
                'total' => $total,
            ];
            $hints[] = "Batch has {$thinCount}/{$total} thin objectives (proxy metrics, not real compounding work).";
        }

        // 5. Low-diversity leverage classes (only meaningful for batches ≥ 3)
        if ($total >= self::MIN_BATCH_FOR_DIVERSITY) {
            $classCounts = [];
            foreach ($specs as $s) {
                $class = $this->leverageClass((string) ($s['objective'] ?? ''));
                $classCounts[$class] = ($classCounts[$class] ?? 0) + 1;
            }
            $topCount = max($classCounts);
            if ($topCount / $total >= self::LOW_DIVERSITY_RATIO) {
                $topClass = (string) array_search($topCount, $classCounts, true);
                $antiProxy[] = [
                    'pattern' => 'low_diversity_leverage_class',
                    'dominant_class' => $topClass,
                    'dominant_count' => $topCount,
                    'total' => $total,
                ];
                $hints[] = "Batch is {$topCount}/{$total} '{$topClass}' specs; diversify across leverage classes for real compounding.";
            }
        }

        // 6. Non-self-sufficient spec
        foreach ($specs as $s) {
            if (($s['packet_quality']['self_sufficient'] ?? null) === false) {
                $antiProxy[] = [
                    'pattern' => 'non_self_sufficient_spec',
                    'spec_id' => (string) ($s['task_packet_id'] ?? ''),
                ];
                $hints[] = 'One or more specs carry blocking deficiencies (not self-sufficient); fix before crediting.';
                break;
            }
        }

        return $this->result($antiProxy === [], $antiProxy, $hints);
    }

    /**
     * @param  list<array<string,mixed>>  $antiProxy
     * @param  list<string>  $hints
     * @return array{schema:string, creditable:bool, anti_proxy_facts:list<array<string,mixed>>, remediation_hints:list<string>}
     */
    private function result(bool $creditable, array $antiProxy, array $hints): array
    {
        return [
            'schema' => self::SCHEMA,
            'creditable' => $creditable,
            'anti_proxy_facts' => array_values($antiProxy),
            'remediation_hints' => array_values($hints),
        ];
    }

    private function leverageClass(string $objective): string
    {
        $lower = strtolower($objective);
        foreach (self::LEVERAGE_CLASS_KEYWORDS as $class => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    return $class;
                }
            }
        }

        return 'unclassified';
    }
}
