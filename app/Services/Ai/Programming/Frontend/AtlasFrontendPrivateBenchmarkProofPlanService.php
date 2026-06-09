<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendPrivateBenchmarkProofPlanService
{
    public const SCHEMA_VERSION = 'atlas.frontend.private_benchmark_proof_plan.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        $publicProofPlan = app(AtlasFrontendWorldBestProofPlanService::class)->plan($input);
        $externalReplayCompleted = (bool) data_get($publicProofPlan, 'readiness.external_rival_replay_completed');
        $operatorPacketVerified = data_get($publicProofPlan, 'readiness.operator_packet_verification_status') === 'passed';
        $diagnosticsStatus = (string) data_get($publicProofPlan, 'readiness.competitive_diagnostics_status', 'not_evaluated');
        $noCompetitiveGaps = ((int) data_get($publicProofPlan, 'readiness.competitive_losing_case_count', 0)) === 0
            && ((int) data_get($publicProofPlan, 'readiness.competitive_tied_case_count', 0)) === 0
            && ((int) data_get($publicProofPlan, 'readiness.competitive_dimension_gap_case_count', 0)) === 0
            && in_array($diagnosticsStatus, ['atlas_decisively_leads_verified_rival_replay', 'ready', 'complete'], true);
        $privateBenchmarkReady = $externalReplayCompleted && $operatorPacketVerified && $noCompetitiveGaps;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $privateBenchmarkReady ? 'private_benchmark_ready' : ((array) ($publicProofPlan['blockers'] ?? []) === [] ? 'ready_for_private_execution' : 'blocked'),
            'plan_type' => 'private_competitive_benchmark_proof_and_improvement_loop',
            'source' => self::class,
            'legacy_runtime' => [
                'runtime_alias' => 'legacy_public_proof_plan',
                'status' => $publicProofPlan['status'] ?? null,
                'proof_plan_hash' => $publicProofPlan['proof_plan_hash'] ?? null,
                'used_for_private_benchmark_projection_only' => true,
            ],
            'readiness' => [
                'external_rival_replay_completed' => $externalReplayCompleted,
                'operator_packet_verification_status' => data_get($publicProofPlan, 'readiness.operator_packet_verification_status', 'pending'),
                'evidence_pack_readiness' => data_get($publicProofPlan, 'readiness.evidence_pack_readiness', []),
                'competitive_diagnostics_status' => $diagnosticsStatus,
                'competitive_losing_case_count' => (int) data_get($publicProofPlan, 'readiness.competitive_losing_case_count', 0),
                'competitive_tied_case_count' => (int) data_get($publicProofPlan, 'readiness.competitive_tied_case_count', 0),
                'competitive_dimension_gap_case_count' => (int) data_get($publicProofPlan, 'readiness.competitive_dimension_gap_case_count', 0),
                'private_benchmark_ready' => $privateBenchmarkReady,
                'public_distribution_verified' => (bool) data_get($publicProofPlan, 'readiness.public_distribution_verified'),
            ],
            'workstreams' => $this->privateWorkstreams((array) ($publicProofPlan['workstreams'] ?? [])),
            'private_improvement_queue' => $this->privateImprovementQueue($publicProofPlan, $privateBenchmarkReady),
            'claim_policy' => [
                'private_benchmark_for_internal_improvement_only' => true,
                'public_superiority_claims_disabled' => true,
                'world_best_claim_allowed' => false,
                'may_claim_more_complete_than_impeccable' => false,
                'may_claim_more_complete_than_claude_design_plugin' => false,
                'documentation_only_claim_forbidden' => true,
                'raw_prompt_source_customer_data_forbidden' => true,
                'raw_absolute_paths_returned' => false,
            ],
            'blockers' => array_values(array_filter((array) ($publicProofPlan['blockers'] ?? []), 'is_string')),
            'warnings' => array_values(array_unique(array_merge(
                $this->privateStrings((array) ($publicProofPlan['warnings'] ?? [])),
                ['public_superiority_claims_disabled_for_private_atlas_runtime'],
            ))),
            'required_next_actions' => $privateBenchmarkReady
                ? ['record_private_benchmark_outcome_memory', 'select_next_private_frontend_improvement_packet']
                : $this->privateNextActions((array) ($publicProofPlan['required_next_actions'] ?? [])),
            'evidence_hashes' => $publicProofPlan['evidence_hashes'] ?? [],
        ];
        $payload['private_benchmark_plan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $workstreams
     * @return array<int,array<string,mixed>>
     */
    private function privateWorkstreams(array $workstreams): array
    {
        return collect($workstreams)
            ->filter(fn (mixed $workstream): bool => is_array($workstream))
            ->map(function (array $workstream): array {
                $id = (string) ($workstream['id'] ?? 'unknown');

                return [
                    'id' => $id === 'public_product_distribution' ? 'optional_publication_receipt' : $id,
                    'status' => (string) ($workstream['status'] ?? 'pending'),
                    'objective' => $id === 'public_product_distribution'
                        ? 'Optional publication receipt may be used for audit, but it does not authorize public superiority claims.'
                        : (string) ($workstream['objective'] ?? ''),
                    'work_item_count' => count((array) ($workstream['work_items'] ?? [])),
                    'commands' => array_values(array_filter((array) ($workstream['commands'] ?? []), 'is_string')),
                    'claim_policy' => [
                        'private_benchmark_for_internal_improvement_only' => true,
                        'public_superiority_claims_disabled' => true,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $publicProofPlan
     * @return array<int,array<string,mixed>>
     */
    private function privateImprovementQueue(array $publicProofPlan, bool $privateBenchmarkReady): array
    {
        if ($privateBenchmarkReady) {
            return [[
                'id' => 'record_private_benchmark_learning',
                'status' => 'ready',
                'suggested_action' => 'persist_driver_effectiveness_and_choose_next_frontend_capability_gap',
            ]];
        }

        return collect((array) ($publicProofPlan['required_next_actions'] ?? []))
            ->filter(fn (mixed $action): bool => is_string($action) && $action !== '')
            ->map(fn (string $action): array => [
                'id' => $this->privateString($action),
                'status' => 'pending',
                'suggested_action' => $this->privateString($action),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $actions
     * @return array<int,string>
     */
    private function privateNextActions(array $actions): array
    {
        return collect($actions)
            ->filter(fn (mixed $action): bool => is_string($action) && $action !== '')
            ->map(fn (string $action): string => $this->privateString($action))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function privateStrings(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => $this->privateString($value))
            ->values()
            ->all();
    }

    private function privateString(string $value): string
    {
        return str_replace(['world_best', 'world-best'], ['private_benchmark', 'private-benchmark'], $value);
    }
}
