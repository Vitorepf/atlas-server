<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasFrontendCompetitiveBenchmarkPlanService
{
    public const SCHEMA_VERSION = 'atlas.frontend.competitive_benchmark_plan.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        $rivalEvidence = AiValueNormalizer::trimmedStringOrNull($input['rival_evidence'] ?? null);
        $benchmark = app(AtlasFrontendBenchmarkRuntimeService::class)->run($rivalEvidence);
        $legacyProofPlan = app(AtlasFrontendWorldBestProofPlanService::class)->plan($input);

        $scenarioGaps = $this->scenarioGaps((array) ($benchmark['scenarios'] ?? []));
        $improvementQueue = $this->improvementQueue($scenarioGaps, $benchmark, $legacyProofPlan);
        $nextWorkPacket = $improvementQueue !== []
            ? $this->privateImprovementWorkPacket($improvementQueue[0])
            : null;
        $externalReplayCompleted = (bool) data_get($benchmark, 'scope.external_rival_replay_completed');
        $status = $improvementQueue === [] && $externalReplayCompleted
            ? 'ready_for_private_use'
            : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'plan_type' => 'private_competitive_benchmark_and_improvement_loop',
            'source' => self::class,
            'intent' => [
                'objective' => 'Make Atlas Frontend more efficient and more capable than the selected rival systems for the operator private workflow.',
                'primary_rival' => 'pbakaus_impeccable',
                'secondary_rivals' => ['claude_design_plugin'],
                'public_marketing_claim_goal' => false,
            ],
            'input_scope' => [
                'rival_evidence_directory_supplied' => $rivalEvidence !== null,
                'rival_evidence_directory_hash' => $this->hashNullable($input['rival_evidence'] ?? null),
                'publication_bundle_hash' => $this->hashNullable($input['bundle'] ?? null),
                'publication_receipt_hash' => $this->hashNullable($input['publication_receipt'] ?? null),
            ],
            'private_policy' => [
                'atlas_is_private_operator_tool' => true,
                'benchmark_is_for_internal_improvement' => true,
                'public_claims_disabled' => true,
                'marketplace_claims_disabled' => true,
                'world_best_claim_allowed' => false,
                'do_not_publish_superiority_claim' => true,
                'raw_paths_or_customer_source_returned' => false,
            ],
            'benchmark' => [
                'schema_version' => $benchmark['schema_version'] ?? AtlasFrontendBenchmarkRuntimeService::SCHEMA_VERSION,
                'benchmark_type' => $benchmark['benchmark_type'] ?? null,
                'systems' => $benchmark['systems'] ?? [],
                'totals' => $benchmark['totals'] ?? [],
                'claims' => [
                    'atlas_more_complete_than_impeccable_on_governed_delivery_contract' => (bool) data_get($benchmark, 'claims.atlas_more_complete_than_impeccable_on_governed_delivery_contract'),
                    'atlas_more_complete_than_claude_design_plugin_on_governed_delivery_contract' => (bool) data_get($benchmark, 'claims.atlas_more_complete_than_claude_design_plugin_on_governed_delivery_contract'),
                    'atlas_world_best_frontend_system' => false,
                ],
                'benchmark_hash' => $benchmark['benchmark_hash'] ?? null,
            ],
            'scenario_gaps' => $scenarioGaps,
            'improvement_queue' => $improvementQueue,
            'next_private_improvement_action' => $improvementQueue[0] ?? null,
            'next_private_work_packet' => $nextWorkPacket,
            'replay_evidence_status' => [
                'external_rival_replay_completed' => $externalReplayCompleted,
                'competitive_diagnostics_status' => data_get($benchmark, 'scope.rival_replay_competitive_diagnostics_status'),
                'rival_replay_hash' => data_get($benchmark, 'rival_replay.replay_hash'),
                'proof_action_queue_hash' => data_get($legacyProofPlan, 'evidence_hashes.proof_action_queue_hash'),
                'next_private_work_packet_hash' => data_get($nextWorkPacket, 'work_packet_hash'),
            ],
            'required_next_actions' => $this->requiredNextActions($improvementQueue, $externalReplayCompleted),
            'evidence_hashes' => [
                'benchmark_hash' => $benchmark['benchmark_hash'] ?? null,
                'legacy_private_proof_plan_hash' => $legacyProofPlan['proof_plan_hash'] ?? null,
                'proof_action_queue_hash' => data_get($legacyProofPlan, 'evidence_hashes.proof_action_queue_hash'),
                'next_private_work_packet_hash' => data_get($nextWorkPacket, 'work_packet_hash'),
            ],
            'blockers' => $this->blockers($improvementQueue, $externalReplayCompleted),
            'warnings' => [
                'private_benchmark_does_not_dispatch_providers',
                'static_matrix_scores_are_not_external_rival_execution_receipts',
                'use_replay_evidence_to_replace_assumptions_with_receipts',
            ],
        ];
        $payload['competitive_benchmark_plan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $scenarios
     * @return array<int,array<string,mixed>>
     */
    private function scenarioGaps(array $scenarios): array
    {
        return collect($scenarios)
            ->map(function (array $scenario): array {
                $scores = (array) ($scenario['scores'] ?? []);
                $atlasScore = (int) ($scores['atlas_frontend'] ?? 0);
                $rivalScores = array_filter(
                    $scores,
                    fn (string $system): bool => $system !== 'atlas_frontend',
                    ARRAY_FILTER_USE_KEY
                );
                arsort($rivalScores);
                $bestRivalSystem = (string) array_key_first($rivalScores);
                $bestRivalScore = (int) ($rivalScores[$bestRivalSystem] ?? 0);
                $delta = $atlasScore - $bestRivalScore;

                return [
                    'scenario_id' => $scenario['id'] ?? null,
                    'weight' => (int) ($scenario['weight'] ?? 0),
                    'atlas_score' => $atlasScore,
                    'best_rival_system' => $bestRivalSystem,
                    'best_rival_score' => $bestRivalScore,
                    'atlas_delta_vs_best_rival' => $delta,
                    'status' => $delta > 0 ? 'atlas_leads' : ($delta === 0 ? 'atlas_tied' : 'atlas_lags'),
                    'minimum_points_to_lead' => max(0, 1 - $delta),
                    'reason' => $scenario['reason'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function privateImprovementWorkPacket(array $item): array
    {
        $scenarioId = (string) ($item['scenario_id'] ?? 'private_competitive_gap');
        $repairPlan = app(AtlasFrontendRepairPlannerService::class)->plan([
            'task' => 'Private Atlas Frontend competitive improvement',
            'failed_gates' => $this->repairSignalsForScenario($scenarioId),
        ]);

        $packet = [
            'schema_version' => 'atlas.frontend.private_improvement_work_packet.v1',
            'packet_id' => 'private_improvement_'.$scenarioId,
            'status' => 'ready_for_operator_or_agent_execution',
            'target_scenario_id' => $scenarioId,
            'best_rival_system' => $item['best_rival_system'] ?? null,
            'priority' => $item['priority'] ?? 'high',
            'suggested_action' => $item['suggested_action'] ?? 'improve_atlas_frontend_private_competitive_gap',
            'required_context' => [
                'selected_repository_workspace',
                'frontend_app_subscope_when_applicable',
                'same_task_spec_hash',
                'same_viewport_matrix',
                'current_component_or_route_under_test',
            ],
            'safe_execution_commands' => $this->safeCommandsForScenario($scenarioId),
            'repair_plan' => [
                'schema_version' => $repairPlan['schema_version'] ?? AtlasFrontendRepairPlannerService::SCHEMA_VERSION,
                'status' => $repairPlan['status'] ?? 'unknown',
                'repair_strategy' => $repairPlan['repair_strategy'] ?? null,
                'severity' => $repairPlan['severity'] ?? null,
                'repair_step_ids' => collect((array) ($repairPlan['repair_steps'] ?? []))->pluck('id')->values()->all(),
                'rerun_gates' => $repairPlan['rerun_gates'] ?? [],
                'evidence_required' => $repairPlan['evidence_required'] ?? [],
                'repair_plan_hash' => $repairPlan['repair_plan_hash'] ?? null,
            ],
            'success_criteria' => [
                'atlas_score_exceeds_best_rival_for_target_scenario',
                'visual_quality_gate_passed_after_patch',
                'live_patch_decision_receipt_recorded_when_source_changes',
                'rival_replay_score_attestation_updated',
                'competitive_benchmark_plan_rerun_hash_recorded',
            ],
            'claim_policy' => [
                'work_packet_is_not_completion_evidence' => true,
                'operator_private_improvement_only' => true,
                'public_claims_disabled' => true,
                'raw_source_or_absolute_paths_returned' => false,
            ],
        ];
        $packet['work_packet_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }

    /**
     * @return array<int,string>
     */
    private function repairSignalsForScenario(string $scenarioId): array
    {
        return match ($scenarioId) {
            'live_visual_iteration' => ['live_source_patch_boundary_gate', 'visual_quality_gate'],
            'anti_slop_detection' => ['anti_ai_slop_detector', 'design_review_score_below_threshold'],
            'production_patch_evidence' => ['evidence_pack_verifier', 'visual_quality_gate'],
            'multi_company_design_system_adaptation' => ['company_design_profile_required', 'design_system_drift_gate'],
            'provider_neutrality_and_portability' => ['task_spec_hash_mismatch'],
            'outcome_memory_and_learning' => ['evidence_pack_verifier'],
            'product_distribution_and_public_proof' => ['evidence_pack_verifier'],
            default => ['visual_quality_gate'],
        };
    }

    /**
     * @return array<int,string>
     */
    private function safeCommandsForScenario(string $scenarioId): array
    {
        $commands = [
            'php artisan atlas:frontend:gate --task="<private improvement>" --acceptance-criteria --test-plan --visual-quality-plan --evidence-plan --json',
        ];

        if ($scenarioId === 'live_visual_iteration') {
            $commands[] = 'php artisan atlas:frontend:live prepare --workspace=<repo> --file=<relative-file> --target=<exact-snippet> --variant=<id:path> --json';
            $commands[] = 'php artisan atlas:frontend:live accept --workspace=<repo> --session=<session-id> --variant=<variant-id> --json';
            $commands[] = 'php artisan atlas:frontend:live recover --workspace=<repo> --session=<session-id> --json';
        }

        $commands[] = 'php artisan atlas:frontend:replay inspect --evidence=<dir> --json';
        $commands[] = 'php artisan atlas:frontend:benchmark --rival-evidence=<dir> --json';

        return $commands;
    }

    /**
     * @param  array<int,array<string,mixed>>  $scenarioGaps
     * @param  array<string,mixed>  $benchmark
     * @param  array<string,mixed>  $legacyProofPlan
     * @return array<int,array<string,mixed>>
     */
    private function improvementQueue(array $scenarioGaps, array $benchmark, array $legacyProofPlan): array
    {
        $queue = collect($scenarioGaps)
            ->filter(fn (array $gap): bool => ($gap['status'] ?? null) !== 'atlas_leads')
            ->sortBy([
                fn (array $gap): int => ($gap['status'] ?? null) === 'atlas_lags' ? 0 : 1,
                fn (array $gap): int => $this->privateScenarioPriority((string) ($gap['scenario_id'] ?? '')),
                fn (array $gap): int => -1 * (int) ($gap['minimum_points_to_lead'] ?? 0),
                fn (array $gap): string => (string) ($gap['scenario_id'] ?? ''),
            ])
            ->map(fn (array $gap): array => [
                'id' => 'improve_'.$gap['scenario_id'],
                'kind' => 'competitive_capability_gap',
                'priority' => ($gap['status'] ?? null) === 'atlas_lags' ? 'critical' : 'high',
                'scenario_id' => $gap['scenario_id'],
                'best_rival_system' => $gap['best_rival_system'],
                'minimum_points_to_lead' => $gap['minimum_points_to_lead'],
                'suggested_action' => 'improve_atlas_frontend_until_private_benchmark_leads_scenario',
                'evidence_required' => [
                    'same_task_spec_hash',
                    'same_viewport_matrix',
                    'before_after_screenshots',
                    'anti_slop_report',
                    'score_attestation',
                ],
            ])
            ->values();

        if (! (bool) data_get($benchmark, 'scope.external_rival_replay_completed')) {
            $queue->push([
                'id' => 'complete_private_rival_replay_evidence',
                'kind' => 'evidence_gap',
                'priority' => 'critical',
                'suggested_action' => 'complete_private_rival_replay_before_trusting_competitive_scores',
                'evidence_required' => [
                    'external_execution_receipts_for_rivals',
                    'score_attestations_for_all_systems',
                    'evidence_pack_hashes',
                ],
                'proof_action_queue_hash' => data_get($legacyProofPlan, 'evidence_hashes.proof_action_queue_hash'),
            ]);
        }

        return $queue->all();
    }

    private function privateScenarioPriority(string $scenarioId): int
    {
        return match ($scenarioId) {
            'live_visual_iteration' => 0,
            'anti_slop_detection' => 1,
            'production_patch_evidence' => 2,
            'multi_company_design_system_adaptation' => 3,
            'provider_neutrality_and_portability' => 4,
            'outcome_memory_and_learning' => 5,
            'product_distribution_and_public_proof' => 9,
            default => 6,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $improvementQueue
     * @return array<int,string>
     */
    private function requiredNextActions(array $improvementQueue, bool $externalReplayCompleted): array
    {
        $actions = [];
        if (! $externalReplayCompleted) {
            $actions[] = 'complete_private_rival_replay_evidence';
        }
        if ($improvementQueue !== []) {
            $actions[] = 'execute_next_private_improvement_action';
            $actions[] = 'rerun_competitive_benchmark_plan';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<int,array<string,mixed>>  $improvementQueue
     * @return array<int,string>
     */
    private function blockers(array $improvementQueue, bool $externalReplayCompleted): array
    {
        $blockers = [];
        if (! $externalReplayCompleted) {
            $blockers[] = 'private_rival_replay_evidence_incomplete';
        }
        if ($improvementQueue !== []) {
            $blockers[] = 'private_competitive_improvement_queue_not_empty';
        }

        return $blockers;
    }

    private function hashNullable(mixed $value): ?string
    {
        $value = AiValueNormalizer::trimmedStringOrNull($value);
        if ($value === null) {
            return null;
        }

        return hash('sha256', $value);
    }
}
