<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * Collapses 73 ACOS subsystems into ~15 deep modules for scorecard v4.
 */
final class AtlasCognitionScoreCardV4Grouper
{
    public const SCHEMA_VERSION = 'atlas.cognition.scorecard.v4';

    private const CONSUMER_GROUPS = [
        'self_improvement',
        'self_construction',
        'cartography',
        'programming',
        'research_domain',
    ];

    /**
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    public function group(array $subsystems): array
    {
        return $this->groupRows($subsystems, consumers: false);
    }

    /**
     * Consumers remain visible for integration readiness without being counted
     * as modules inside the ACOS cognitive boundary.
     *
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    public function groupConsumers(array $subsystems): array
    {
        return $this->groupRows($subsystems, consumers: true);
    }

    /**
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    private function groupRows(array $subsystems, bool $consumers): array
    {
        $buckets = [];
        foreach ($subsystems as $row) {
            $group = (string) ($row['group'] ?? 'unknown');
            $isConsumer = in_array($group, self::CONSUMER_GROUPS, true);
            if ($isConsumer !== $consumers) {
                continue;
            }

            $module = $this->moduleKey($group);
            $buckets[$module]['acronym'] ??= $module;
            $buckets[$module]['name'] ??= $this->moduleName($module);
            $buckets[$module]['subsystem_count'] = ($buckets[$module]['subsystem_count'] ?? 0) + 1;
            foreach (['code_status', 'doc_status', 'pipeline_status'] as $dim) {
                $buckets[$module][$dim][] = (string) ($row[$dim] ?? 'blocked');
            }
            $buckets[$module]['members'][] = (string) ($row['acronym'] ?? '');
        }

        $modules = [];
        foreach ($buckets as $key => $bucket) {
            $modules[] = [
                'acronym' => $key,
                'name' => $bucket['name'],
                'subsystem_count' => $bucket['subsystem_count'],
                'code_status' => $this->rollup($bucket['code_status'] ?? []),
                'doc_status' => $this->rollup($bucket['doc_status'] ?? []),
                'pipeline_status' => $this->rollup($bucket['pipeline_status'] ?? []),
                'members' => $bucket['members'] ?? [],
                'boundary' => $consumers ? 'consumer' : 'acos',
            ];
        }

        usort($modules, fn (array $a, array $b): int => strcmp((string) $a['acronym'], (string) $b['acronym']));

        return $modules;
    }

    private function moduleKey(string $group): string
    {
        return match ($group) {
            'cognitive_immune' => 'IMMUNE',
            'memory_core' => 'MEMORY',
            'aucri' => 'CONTEXT',
            'self_improvement', 'self_construction', 'cartography', 'programming', 'research_domain' => 'CONSUMERS',
            'governance' => 'GOVERNANCE',
            'atlas_decide' => 'DECIDE',
            'compounding' => 'COMPOUND',
            'reality', 'cross_domain' => 'REALITY',
            'teos' => 'TEOS',
            'cognition' => 'COGNITION',
            'autonomy' => 'AUTONOMY',
            'patamar4', 'patamar_4', 'integration' => 'PATAMAR4',
            'context_cache' => 'CONTEXT-CACHE',
            'context_intelligence' => 'CONTEXT-INTELLIGENCE',
            'persistent_context' => 'PERSISTENT-CONTEXT',
            'aemor' => 'AEMOR',
            'long_horizon' => 'LONG-HORIZON',
            'verified_context' => 'VERIFIED-CONTEXT',
            'context_quality' => 'CONTEXT-QUALITY',
            'open_brain' => 'OPEN-BRAIN',
            'evidence' => 'EVIDENCE',
            default => 'OTHER',
        };
    }

    private function moduleName(string $key): string
    {
        return match ($key) {
            'IMMUNE' => 'Cognitive Immune G0-G8',
            'MEMORY' => 'Memory Core',
            'CONTEXT' => 'Context Runtime (AUCRI policies)',
            'CONSUMERS' => 'ACOS Consumers and Legacy Projections',
            'GOVERNANCE' => 'Constitutional Governance',
            'DECIDE' => 'Atlas Decide + Swarm',
            'COMPOUND' => 'Compounding',
            'REALITY' => 'Reality Graph + Cross-Domain',
            'TEOS' => 'TEOS Counterfactuals',
            'COGNITION' => 'Cognitive Function Atlas',
            'AUTONOMY' => 'Autonomous Reconciliation',
            'PATAMAR4' => 'Patamar 4 Integration',
            'CONTEXT-CACHE' => 'Context Cache Compiler Runtime',
            'CONTEXT-INTELLIGENCE' => 'Context Intelligence Engine',
            'PERSISTENT-CONTEXT' => 'Persistent Context Runtime',
            'AEMOR' => 'Execution Memory Outcome Runtime',
            'LONG-HORIZON' => 'TEOS-I1 Long-Horizon Intelligence',
            'VERIFIED-CONTEXT' => 'Verified Context Execution Loop',
            'CONTEXT-QUALITY' => 'Context Quality Certification Gate',
            'OPEN-BRAIN' => 'Open Brain Gateway',
            'EVIDENCE' => 'Evidence Ledger Memory Side',
            default => 'Other ACOS',
        };
    }

    /**
     * @param  list<string>  $statuses
     */
    private function rollup(array $statuses): string
    {
        if ($statuses === []) {
            return AtlasCognitionScoreCardService::STATUS_BLOCKED;
        }
        if (count(array_filter($statuses, fn (string $s): bool => $s !== AtlasCognitionScoreCardService::STATUS_READY)) === 0) {
            return AtlasCognitionScoreCardService::STATUS_READY;
        }
        if (in_array(AtlasCognitionScoreCardService::STATUS_BLOCKED, $statuses, true)) {
            return AtlasCognitionScoreCardService::STATUS_PARTIAL;
        }

        return AtlasCognitionScoreCardService::STATUS_PARTIAL;
    }
}
