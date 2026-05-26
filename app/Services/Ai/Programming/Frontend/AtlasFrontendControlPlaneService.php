<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendControlPlaneService
{
    public const SCHEMA_VERSION = 'atlas.frontend.control_plane.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $certification = app(AtlasFrontendDesignRuntimeService::class)->certify();
        $benchmark = app(AtlasFrontendBenchmarkRuntimeService::class)->run($this->nullableString($input['rival_evidence'] ?? null));
        $replay = app(AtlasFrontendRivalReplayHarnessService::class)->inspect($this->nullableString($input['rival_evidence'] ?? null));
        $competitiveDimensionGaps = $this->competitiveDimensionGaps((array) data_get($replay, 'competitive_diagnostics.cases', []));
        $competitiveRepairPlan = $competitiveDimensionGaps !== []
            ? app(AtlasFrontendRepairPlannerService::class)->plan([
                'blockers' => ['competitive_dimension_gap'],
                'dimension_gaps' => $competitiveDimensionGaps,
            ])
            : null;
        $proof = app(AtlasFrontendProductProofRuntimeService::class)->catalog();
        $publication = $this->publication($input);
        $gauntlet = $this->gauntlet($input);
        $publicationAttestation = app(AtlasFrontendPublicationAttestationService::class)->attest($publication, [
            'rerun_action' => 'rerun_atlas_frontend_control_plane',
        ]);

        $readiness = [
            'runtime_contract_ready' => ($certification['status'] ?? null) === 'ready',
            'company_repo_ready' => ($gauntlet['status'] ?? null) === 'not_requested' || ($gauntlet['status'] ?? null) === 'ready',
            'competitive_contract_claim_ready' => (bool) data_get($benchmark, 'claims.atlas_more_complete_than_impeccable_on_governed_delivery_contract')
                && (bool) data_get($benchmark, 'claims.atlas_more_complete_than_claude_design_plugin_on_governed_delivery_contract'),
            'external_replay_ready' => (bool) data_get($replay, 'summary.external_replay_completed'),
            'competitive_diagnostics_status' => data_get($replay, 'competitive_diagnostics.status', 'not_evaluated'),
            'competitive_tied_case_count' => (int) data_get($replay, 'competitive_diagnostics.tied_case_count', 0),
            'competitive_dimension_gap_case_count' => (int) data_get($replay, 'competitive_diagnostics.dimension_gap_case_count', 0),
            'competitive_decisive_lead_ready' => (bool) data_get($replay, 'claim_policy.may_claim_world_best_frontend_system'),
            'product_proof_catalog_ready' => ($proof['status'] ?? null) === 'ready',
            'public_distribution_ready' => (bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed'),
            'publication_attestation_status' => $publicationAttestation['status'],
            'world_best_claim_ready' => (bool) data_get($replay, 'claim_policy.may_claim_world_best_frontend_system')
                && (bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed'),
        ];

        $blockers = $this->blockers($certification, $gauntlet);
        $warnings = $this->warnings($readiness, $benchmark, $replay, $proof, $publication);
        $status = $blockers !== [] ? 'blocked' : ($warnings !== [] ? 'warning' : 'ready');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'control_plane_type' => 'atlas_frontend_market_and_execution_readiness',
            'source' => self::class,
            'input_scope' => [
                'task_hash' => $this->hashNullable($input['task'] ?? null),
                'workspace_hash' => $this->hashNullable($input['workspace'] ?? null),
                'frontend_app_hash' => $this->hashNullable($input['frontend_app'] ?? null),
                'surface' => $this->nullableString($input['surface'] ?? null) ?: 'programming.frontend',
                'gauntlet_requested' => $this->shouldRunGauntlet($input),
                'rival_evidence_directory_hash' => $this->hashNullable($input['rival_evidence'] ?? null),
                'publication_bundle_hash' => $this->hashNullable($input['bundle'] ?? null),
            ],
            'readiness_levels' => $readiness,
            'signals' => [
                'runtime_certification' => [
                    'schema_version' => AtlasFrontendDesignRuntimeService::CERTIFICATION_SCHEMA_VERSION,
                    'status' => $certification['status'] ?? null,
                    'total_checks' => data_get($certification, 'summary.total'),
                    'failed_checks' => data_get($certification, 'summary.fail'),
                    'certification_hash' => $certification['certification_hash'] ?? null,
                ],
                'gauntlet' => [
                    'schema_version' => AtlasFrontendGauntletService::SCHEMA_VERSION,
                    'status' => $gauntlet['status'] ?? null,
                    'frontend_app_scope' => $gauntlet['frontend_app_scope'] ?? null,
                    'provider_dispatch_allowed' => (bool) data_get($gauntlet, 'claim_policy.provider_dispatch_allowed'),
                    'gauntlet_hash' => $gauntlet['gauntlet_hash'] ?? null,
                ],
                'benchmark' => [
                    'schema_version' => AtlasFrontendBenchmarkRuntimeService::SCHEMA_VERSION,
                    'status' => $benchmark['status'] ?? null,
                    'benchmark_hash' => $benchmark['benchmark_hash'] ?? null,
                    'scope' => $benchmark['scope'] ?? [],
                    'claims' => $benchmark['claims'] ?? [],
                ],
                'rival_replay' => [
                    'schema_version' => AtlasFrontendRivalReplayHarnessService::SCHEMA_VERSION,
                    'status' => $replay['status'] ?? null,
                    'summary' => $replay['summary'] ?? [],
                    'claim_policy' => $replay['claim_policy'] ?? [],
                    'competitive_diagnostics' => $replay['competitive_diagnostics'] ?? [],
                    'replay_hash' => $replay['replay_hash'] ?? null,
                ],
                'competitive_repair_plan' => $competitiveRepairPlan,
                'product_proof' => [
                    'schema_version' => AtlasFrontendProductProofRuntimeService::SCHEMA_VERSION,
                    'status' => $proof['status'] ?? null,
                    'demo_count' => $proof['demo_count'] ?? null,
                    'product_proof_hash' => $proof['product_proof_hash'] ?? null,
                ],
                'publication' => [
                    'schema_version' => AtlasFrontendPublicationVerifierService::SCHEMA_VERSION,
                    'status' => $publication['status'] ?? null,
                    'claim_policy' => $publication['claim_policy'] ?? [],
                    'publication_hash' => $publication['publication_hash'] ?? null,
                ],
                'publication_attestation' => $publicationAttestation,
            ],
            'evidence_hashes' => [
                'competitive_repair_plan_hash' => $competitiveRepairPlan['repair_plan_hash'] ?? null,
            ],
            'claim_policy' => [
                'provider_dispatch_allowed' => $status !== 'blocked'
                    && (($gauntlet['status'] ?? null) === 'not_requested' || (bool) data_get($gauntlet, 'claim_policy.provider_dispatch_allowed')),
                'premium_frontend_claim_allowed' => $readiness['runtime_contract_ready'] && $readiness['competitive_contract_claim_ready'],
                'may_claim_more_complete_than_impeccable' => false,
                'may_claim_more_complete_than_claude_design_plugin' => false,
                'private_benchmark_for_internal_improvement_only' => true,
                'external_replay_claim_allowed' => $readiness['external_replay_ready'],
                'public_distribution_claim_allowed' => $readiness['public_distribution_ready'],
                'world_best_claim_allowed' => $readiness['world_best_claim_ready'],
                'world_best_requires_real_rival_replay_and_public_distribution' => true,
                'world_best_requires_decisive_lead_each_replay_case' => true,
                'world_best_requires_no_tied_replay_cases' => true,
                'world_best_requires_no_dimension_gaps_against_best_rival' => true,
                'local_publication_report_is_not_public_distribution' => true,
                'documentation_only_claim_forbidden' => true,
                'honest_claim_boundary' => $readiness['world_best_claim_ready']
                    ? 'Private benchmark evidence is complete; public superiority claims remain disabled for this operator-owned Atlas runtime.'
                    : 'Atlas Frontend can use private benchmarks to drive improvements, but public superiority claims remain disabled.',
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'required_next_actions' => $this->requiredNextActions($blockers, $warnings, $gauntlet),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $cases
     * @return array<int,array<string,mixed>>
     */
    private function competitiveDimensionGaps(array $cases): array
    {
        return collect($cases)
            ->filter(fn (mixed $case): bool => is_array($case))
            ->flatMap(fn (array $case): array => array_map(
                fn (array $gap): array => [
                    ...$gap,
                    'case_id' => $case['case_id'] ?? null,
                    'best_rival_system' => $case['best_rival_system'] ?? null,
                ],
                array_filter((array) ($case['dimension_gaps'] ?? []), 'is_array'),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function gauntlet(array $input): array
    {
        if (! $this->shouldRunGauntlet($input)) {
            return [
                'schema_version' => AtlasFrontendGauntletService::SCHEMA_VERSION,
                'status' => 'not_requested',
                'claim_policy' => [
                    'provider_dispatch_allowed' => true,
                    'premium_frontend_claim_allowed' => false,
                    'world_best_claim_allowed' => false,
                ],
                'blockers' => [],
                'warnings' => [],
                'required_next_actions' => [],
            ];
        }

        return app(AtlasFrontendGauntletService::class)->run([
            'task' => $this->nullableString($input['task'] ?? null) ?? '',
            'surface' => $this->nullableString($input['surface'] ?? null) ?? 'programming.frontend',
            'workspace' => $this->nullableString($input['workspace'] ?? null) ?? '',
            'frontend_app' => $this->nullableString($input['frontend_app'] ?? null) ?? '',
            'acceptance_criteria' => (bool) ($input['acceptance_criteria'] ?? false),
            'asset_context' => (bool) ($input['asset_context'] ?? false),
            'company_profile_ready' => (bool) ($input['company_profile_ready'] ?? false),
            'prototype' => (bool) ($input['prototype'] ?? false),
            'live' => (bool) ($input['live'] ?? false),
            'test_plan' => (bool) ($input['test_plan'] ?? false),
            'visual_quality_plan' => (bool) ($input['visual_quality_plan'] ?? false),
            'evidence_plan' => (bool) ($input['evidence_plan'] ?? false),
            'senior_design_review' => (bool) ($input['senior_design_review'] ?? false),
            'benchmark_run' => (bool) ($input['benchmark_run'] ?? false),
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function publication(array $input): array
    {
        $bundle = $this->nullableString($input['bundle'] ?? null);
        if ($bundle === null) {
            return [
                'schema_version' => AtlasFrontendPublicationVerifierService::SCHEMA_VERSION,
                'status' => 'not_requested',
                'claim_policy' => [
                    'local_bundle_claim_allowed' => false,
                    'public_distribution_claim_allowed' => false,
                    'world_best_claim_allowed' => false,
                    'public_distribution_requires_verified_receipt' => true,
                    'raw_customer_source_returned' => false,
                ],
                'blockers' => [],
                'warnings' => ['publication_bundle_not_supplied'],
            ];
        }

        return app(AtlasFrontendPublicationVerifierService::class)->verify(
            $bundle,
            $this->nullableString($input['publication_receipt'] ?? null),
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function shouldRunGauntlet(array $input): bool
    {
        return $this->nullableString($input['task'] ?? null) !== null
            || $this->nullableString($input['workspace'] ?? null) !== null
            || (bool) ($input['force_gauntlet'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $certification
     * @param  array<string,mixed>  $gauntlet
     * @return array<int,string>
     */
    private function blockers(array $certification, array $gauntlet): array
    {
        $blockers = [];
        if (($certification['status'] ?? null) !== 'ready') {
            $blockers[] = 'runtime_certification_not_ready';
        }
        if (($gauntlet['status'] ?? null) === 'blocked') {
            $blockers[] = 'company_repo_gauntlet_blocked';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,bool>  $readiness
     * @param  array<string,mixed>  $benchmark
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $proof
     * @param  array<string,mixed>  $publication
     * @return array<int,string>
     */
    private function warnings(array $readiness, array $benchmark, array $replay, array $proof, array $publication): array
    {
        $warnings = [];
        if (! $readiness['external_replay_ready']) {
            $warnings[] = 'external_rival_replay_not_completed';
        }
        if (! $readiness['product_proof_catalog_ready']) {
            $warnings[] = 'product_proof_catalog_partial';
        }
        if (! $readiness['public_distribution_ready']) {
            $warnings[] = 'public_distribution_receipt_not_verified';
        }
        if (! $readiness['world_best_claim_ready']) {
            $warnings[] = 'world_best_claim_not_allowed';
        }
        foreach ((array) ($benchmark['remaining_gaps'] ?? []) as $gap) {
            $warnings[] = (string) $gap;
        }
        foreach ((array) ($replay['remaining_gaps'] ?? []) as $gap) {
            $warnings[] = (string) $gap;
        }
        foreach ((array) ($proof['remaining_gaps'] ?? []) as $gap) {
            $warnings[] = (string) $gap;
        }
        foreach ((array) ($publication['warnings'] ?? []) as $warning) {
            $warnings[] = (string) $warning;
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<string,mixed>  $gauntlet
     * @return array<int,string>
     */
    private function requiredNextActions(array $blockers, array $warnings, array $gauntlet): array
    {
        $actions = [];
        if (in_array('runtime_certification_not_ready', $blockers, true)) {
            $actions[] = 'run_php_artisan_atlas_frontend_certify_json_strict';
        }
        if (in_array('company_repo_gauntlet_blocked', $blockers, true)) {
            $actions = array_merge($actions, (array) ($gauntlet['required_next_actions'] ?? []));
        }
        if (in_array('external_rival_replay_not_completed', $warnings, true)) {
            $actions[] = 'collect_external_rival_replay_manifests';
        }
        if (in_array('public_distribution_receipt_not_verified', $warnings, true)) {
            $actions[] = 'build_product_proof_bundle_and_verify_public_receipt';
        }
        if (in_array('world_best_claim_not_allowed', $warnings, true)) {
            $actions[] = 'do_not_claim_world_best_until_replay_and_public_distribution_are_verified';
        }
        if (in_array('atlas_does_not_lead_every_complete_case', $warnings, true)) {
            $actions[] = 'improve_atlas_frontend_until_replay_leads_every_case';
        }
        if (in_array('atlas_has_dimension_gaps_against_best_rival', $warnings, true)) {
            $actions[] = 'improve_atlas_frontend_until_replay_closes_dimension_gaps';
        }

        return array_values(array_unique($actions));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function hashNullable(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : hash('sha256', $value);
    }
}
