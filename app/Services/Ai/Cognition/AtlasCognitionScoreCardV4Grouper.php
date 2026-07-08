<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * Collapses 73 ACOS subsystems into ~15 deep modules for scorecard v4.
 */
final class AtlasCognitionScoreCardV4Grouper
{
    public const SCHEMA_VERSION = 'atlas.cognition.scorecard.v4';

    /**
     * @param  list<array<string, mixed>>  $subsystems
     * @return list<array<string, mixed>>
     */
    public function group(array $subsystems): array
    {
        $buckets = [];
        foreach ($subsystems as $row) {
            $module = $this->moduleKey((string) ($row['group'] ?? 'unknown'));
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
            'self_improvement' => 'SELF-IMPROVE',
            'self_construction' => 'SELF-BUILD',
            'governance' => 'GOVERNANCE',
            'atlas_decide' => 'DECIDE',
            'compounding' => 'COMPOUND',
            'reality', 'cross_domain' => 'REALITY',
            'teos' => 'TEOS',
            'cognition' => 'COGNITION',
            'autonomy' => 'AUTONOMY',
            'programming' => 'PROGRAMMING',
            'patamar4', 'integration' => 'PATAMAR4',
            default => 'OTHER',
        };
    }

    private function moduleName(string $key): string
    {
        return match ($key) {
            'IMMUNE' => 'Cognitive Immune G0-G8',
            'MEMORY' => 'Memory Core',
            'CONTEXT' => 'Context Runtime (AUCRI policies)',
            'SELF-IMPROVE' => 'Self-Improvement L7',
            'SELF-BUILD' => 'Self-Construction',
            'GOVERNANCE' => 'Constitutional Governance',
            'DECIDE' => 'Atlas Decide + Swarm',
            'COMPOUND' => 'Compounding',
            'REALITY' => 'Reality Graph + Cross-Domain',
            'TEOS' => 'TEOS Counterfactuals',
            'COGNITION' => 'Cognitive Function Atlas',
            'AUTONOMY' => 'Autonomous Reconciliation',
            'PROGRAMMING' => 'Programming Surfaces',
            'PATAMAR4' => 'Patamar 4 Integration',
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
