<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Family #2 — Readiness Packet Projection (SCOS compaction seam).
 *
 * Extracted from AtlasSelfConstructionReadinessService::metaSddPacket()
 * without schema drift. Mother delegates here.
 */
final class ReadinessPacketProjection
{
    /**
     * @param  array{workspace?: string|null, target?: string|null, packet?: string|null}  $options
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function metaSddPacket(array $options, array $snapshot): array
    {
        $target = ($options['target'] ?? null) ?: 'meta_sdd_artifact_generator';

        return [
            'schema_version' => 'atlas.self_construction_meta_sdd.v1',
            'status' => data_get($snapshot, 'summary.missing_doc_count') === 0 ? 'candidate_ready' : 'blocked_missing_docs',
            'mode' => 'read_only_candidate',
            'execution_allowed' => false,
            'meta_spec' => [
                'id' => 'META-SDD-SELF-CONSTRUCTION-PHASE-3',
                'title' => 'Meta-SDD artifact generator for Atlas Self-Construction OS',
                'target_layer' => '0.8-self-construction',
                'target_capability' => $target,
                'current_maturity' => data_get($snapshot, 'maturity.runtime'),
                'target_maturity' => 'L2_meta_sdd_artifact_generator',
                'problem' => 'Atlas has read-only Self-Construction readiness, but still needs structured Meta-SDD candidate packets for safe next-step planning.',
                'goal' => 'Generate a read-only Meta-SDD packet with assumptions, priority, build graph, tasks, gates and safety boundaries.',
                'non_goals' => [
                    'no code patch execution',
                    'no self-programming authorization',
                    'no auto-merge',
                    'no critical policy mutation',
                ],
                'risk_level' => 'low',
                'autonomy_allowed' => 'read_only_candidate_generation',
                'rollback_strategy' => 'remove generated candidate output; no persistent mutation is performed',
            ],
            'assumptions' => [
                [
                    'id' => 'A1',
                    'text' => 'The required Self-Construction OS docs are present and indexed.',
                    'confidence' => data_get($snapshot, 'summary.missing_doc_count') === 0 ? 0.95 : 0.2,
                    'blocking' => data_get($snapshot, 'summary.missing_doc_count') !== 0,
                    'evidence' => ['required_docs.missing_doc_count'],
                ],
                [
                    'id' => 'A2',
                    'text' => 'The next safe implementation should stay read-only until receipt preview and drift checks exist.',
                    'confidence' => 0.93,
                    'blocking' => false,
                    'evidence' => ['self-programming safety contract', 'runtime implementation roadmap'],
                ],
            ],
            'priority' => [
                'p_level' => 'P0',
                'score' => 9.1,
                'rationale' => 'Meta-SDD packets unlock safer future self-construction without enabling writes.',
                'unlocks' => data_get($snapshot, 'build_graph.unlocks'),
                'blocked_by' => [],
                'smallest_safe_slice' => 'read-only packet generation via CLI/service with focused tests',
            ],
            'build_graph' => [
                'target_capability' => $target,
                'prerequisites' => data_get($snapshot, 'build_graph.prerequisites'),
                'downstream_capabilities' => data_get($snapshot, 'build_graph.unlocks'),
                'maturity_before' => data_get($snapshot, 'maturity.runtime'),
                'maturity_after' => 'L2_meta_sdd_artifact_generator',
            ],
            'tasks' => [
                [
                    'id' => 'T1',
                    'title' => 'Generate Meta-SDD candidate packet from readiness snapshot',
                    'type' => 'read_only_runtime',
                    'allowed_files' => [
                        'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                        'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                    ],
                ],
                [
                    'id' => 'T2',
                    'title' => 'Cover candidate packet schema and safety fields',
                    'type' => 'test',
                    'allowed_files' => [
                        'tests/Feature/Ai/SelfConstruction/',
                    ],
                ],
            ],
            'gates' => [
                'docs_present' => data_get($snapshot, 'summary.missing_doc_count') === 0,
                'write_tools_allowed' => false,
                'self_programming_allowed' => false,
            ],
            'safety_boundaries' => data_get($snapshot, 'safety_contract'),
            'recommended_commands' => data_get($snapshot, 'recommended_commands'),
        ];
    }
}
