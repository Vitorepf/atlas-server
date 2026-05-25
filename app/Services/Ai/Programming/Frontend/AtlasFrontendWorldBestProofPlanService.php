<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendWorldBestProofPlanService
{
    public const SCHEMA_VERSION = 'atlas.frontend.world_best_proof_plan.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input = []): array
    {
        $rivalEvidence = $this->nullableString($input['rival_evidence'] ?? null);
        $replayHarness = app(AtlasFrontendRivalReplayHarnessService::class);
        $replay = $replayHarness->inspect($rivalEvidence);
        $operatorPacketVerification = $rivalEvidence === null
            ? $this->missingOperatorPacketVerification()
            : $replayHarness->verifyOperatorPacket($rivalEvidence);
        $proof = app(AtlasFrontendProductProofRuntimeService::class)->catalog();
        $publication = $this->publication($input);
        $controlPlane = app(AtlasFrontendControlPlaneService::class)->snapshot($input);

        $evidencePackReadiness = (array) ($replay['evidence_pack_readiness'] ?? []);
        $competitiveDimensionGaps = $this->competitiveDimensionGaps((array) data_get($replay, 'competitive_diagnostics.cases', []));
        $competitiveRepairPlan = $competitiveDimensionGaps !== []
            ? app(AtlasFrontendRepairPlannerService::class)->plan([
                'blockers' => ['competitive_dimension_gap'],
                'dimension_gaps' => $competitiveDimensionGaps,
            ])
            : null;
        $evidenceWorklist = app(AtlasFrontendRivalReplayHarnessService::class)
            ->compileEvidenceWorklist($rivalEvidence);
        $replayWorkItems = $this->replayWorkItems((array) ($replay['runs'] ?? []), $evidencePackReadiness);
        $publicationWorkItems = $this->publicationWorkItems($publication);
        $operatorPacketVerified = ($operatorPacketVerification['status'] ?? null) === 'passed';
        $worldBestClaimAllowed = (bool) data_get($replay, 'claim_policy.may_claim_world_best_frontend_system')
            && $operatorPacketVerified
            && (bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed');
        $publicationAttestation = app(AtlasFrontendPublicationAttestationService::class)->attest($publication, [
            'rerun_action' => 'rerun_atlas_frontend_world_best_plan',
            'world_best_claim_allowed' => $worldBestClaimAllowed,
        ]);

        $blockers = $this->blockers($replay, $publication, $operatorPacketVerification, $rivalEvidence !== null);
        $status = $worldBestClaimAllowed
            ? 'ready'
            : ($blockers !== [] ? 'blocked' : 'ready_for_execution');
        $evidencePackReadinessSummary = [
            'schema_version' => data_get($evidencePackReadiness, 'schema_version'),
            'status' => data_get($evidencePackReadiness, 'status', 'pending'),
            'summary' => data_get($evidencePackReadiness, 'summary', []),
            'required_artifact_kinds' => data_get($evidencePackReadiness, 'required_artifact_kinds', []),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'proof_plan_type' => 'world_best_frontend_market_proof',
            'source' => self::class,
            'input_scope' => [
                'rival_evidence_directory_hash' => $this->hashNullable($input['rival_evidence'] ?? null),
                'publication_bundle_hash' => $this->hashNullable($input['bundle'] ?? null),
                'publication_receipt_hash' => $this->hashNullable($input['publication_receipt'] ?? null),
            ],
            'readiness' => [
                'runtime_certified' => (bool) data_get($controlPlane, 'readiness_levels.runtime_contract_ready'),
                'external_rival_replay_completed' => (bool) data_get($replay, 'summary.external_replay_completed'),
                'operator_packet_verification_status' => $operatorPacketVerification['status'] ?? 'blocked',
                'evidence_pack_readiness' => $evidencePackReadinessSummary,
                'competitive_diagnostics_status' => data_get($replay, 'competitive_diagnostics.status', 'not_evaluated'),
                'competitive_losing_case_count' => (int) data_get($replay, 'competitive_diagnostics.losing_case_count', 0),
                'competitive_tied_case_count' => (int) data_get($replay, 'competitive_diagnostics.tied_case_count', 0),
                'competitive_dimension_gap_case_count' => (int) data_get($replay, 'competitive_diagnostics.dimension_gap_case_count', 0),
                'atlas_wins_replay' => (bool) data_get($replay, 'claim_policy.may_claim_world_best_frontend_system'),
                'product_proof_catalog_ready' => ($proof['status'] ?? null) === 'ready',
                'public_distribution_verified' => (bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed'),
                'publication_attestation_status' => $publicationAttestation['status'],
                'world_best_claim_allowed' => $worldBestClaimAllowed,
            ],
            'workstreams' => [
                [
                    'id' => 'external_rival_replay',
                    'status' => $replayWorkItems === [] ? 'ready' : 'pending',
                    'objective' => 'Run the same product cases against Atlas Frontend and rival systems, then attach provider-safe manifest evidence.',
                    'required_artifacts' => [
                        'manifest.json_per_case_system',
                        'evidence_pack_ref',
                        'run_packet_hash',
                        'output_artifact_hash',
                        'screenshot_hashes',
                        'anti_slop_report_hash',
                        'verification_hashes',
                        'competitive_score_breakdown',
                        'score_attestation',
                        'external_rival_execution_receipts',
                    ],
                    'evidence_pack_readiness' => $evidencePackReadinessSummary,
                    'evidence_worklist' => [
                        'schema_version' => $evidenceWorklist['schema_version'] ?? null,
                        'status' => $evidenceWorklist['status'] ?? 'pending',
                        'work_item_count' => $evidenceWorklist['work_item_count'] ?? 0,
                        'worklist_hash' => $evidenceWorklist['worklist_hash'] ?? null,
                        'write_performed' => $evidenceWorklist['write_performed'] ?? false,
                        'commands' => $evidenceWorklist['commands'] ?? [],
                        'claim_policy' => $evidenceWorklist['claim_policy'] ?? [],
                    ],
                    'operator_packet_verification' => [
                        'schema_version' => $operatorPacketVerification['schema_version'] ?? AtlasFrontendRivalReplayHarnessService::OPERATOR_PACKET_VERIFICATION_SCHEMA_VERSION,
                        'status' => $operatorPacketVerification['status'] ?? 'blocked',
                        'checks' => $operatorPacketVerification['checks'] ?? [],
                        'blockers' => $operatorPacketVerification['blockers'] ?? [],
                        'warnings' => $operatorPacketVerification['warnings'] ?? [],
                        'operator_packet_verification_hash' => $operatorPacketVerification['operator_packet_verification_hash'] ?? null,
                        'claim_policy' => $operatorPacketVerification['claim_policy'] ?? [],
                    ],
                    'competitive_diagnostics' => data_get($replay, 'competitive_diagnostics', []),
                    'competitive_repair_plan' => $competitiveRepairPlan,
                    'work_items' => $replayWorkItems,
                    'commands' => [
                        'php artisan atlas:frontend:replay operator-packet --evidence=<dir> --json',
                        'php artisan atlas:frontend:replay operator-packet-verify --evidence=<dir> --json',
                        'php artisan atlas:frontend:replay runner-kit --output=<dir> --json',
                        'php artisan atlas:frontend:replay evidence-worklist --evidence=<dir> --output=<worklist.json> --json',
                        'php artisan atlas:frontend:replay inspect --evidence=<dir> --json',
                    ],
                ],
                [
                    'id' => 'public_product_distribution',
                    'status' => $publicationWorkItems === [] ? 'ready' : 'pending',
                    'objective' => 'Publish the Atlas Frontend product proof bundle and verify it with a public receipt.',
                    'required_artifacts' => [
                        'atlas.frontend.product_proof_bundle.v1',
                        'public_https_url',
                        'http_200_receipt',
                        'bundle_hash_match',
                        'publication_attestation',
                        'operator_approval',
                    ],
                    'publication_attestation' => $publicationAttestation,
                    'work_items' => $publicationWorkItems,
                    'command' => 'php artisan atlas:frontend:publish verify --bundle=<bundle> --receipt=<receipt> --json',
                ],
                [
                    'id' => 'claim_audit',
                    'status' => $worldBestClaimAllowed ? 'ready' : 'pending',
                    'objective' => 'Re-run control-plane and certification after replay and public proof are attached.',
                    'required_artifacts' => [
                        'atlas.frontend.control_plane.v1',
                        'atlas.frontend.design_runtime_certification.v1',
                    ],
                    'work_items' => $worldBestClaimAllowed ? [] : [
                        [
                            'id' => 'rerun_control_plane_after_market_proof',
                            'status' => 'pending',
                            'command' => 'php artisan atlas:frontend:control-plane --rival-evidence=<dir> --bundle=<bundle> --publication-receipt=<receipt> --json --strict',
                        ],
                    ],
                ],
            ],
            'claim_policy' => [
                'world_best_claim_allowed' => $worldBestClaimAllowed,
                'world_best_requires_external_rival_replay' => true,
                'world_best_requires_verified_operator_packet' => true,
                'world_best_requires_public_distribution_receipt' => true,
                'world_best_requires_decisive_lead_each_case' => true,
                'world_best_requires_no_tied_cases' => true,
                'world_best_requires_no_dimension_gaps_against_best_rival' => true,
                'local_publication_report_is_not_public_distribution' => true,
                'world_best_requires_provider_safe_hash_refs' => true,
                'documentation_only_claim_forbidden' => true,
                'raw_prompt_source_customer_data_forbidden' => true,
            ],
            'blockers' => $blockers,
            'warnings' => $this->warnings($replay, $publication, $controlPlane, $operatorPacketVerification),
            'required_next_actions' => $this->nextActions($replayWorkItems, $publicationWorkItems, $worldBestClaimAllowed, $evidencePackReadiness, (array) data_get($replay, 'competitive_diagnostics', []), $operatorPacketVerified),
            'evidence_hashes' => [
                'replay_hash' => $replay['replay_hash'] ?? null,
                'operator_packet_verification_hash' => $operatorPacketVerification['operator_packet_verification_hash'] ?? null,
                'competitive_repair_plan_hash' => $competitiveRepairPlan['repair_plan_hash'] ?? null,
                'evidence_worklist_hash' => $evidenceWorklist['worklist_hash'] ?? null,
                'product_proof_hash' => $proof['product_proof_hash'] ?? null,
                'publication_hash' => $publication['publication_hash'] ?? null,
                'publication_attestation_hash' => $publicationAttestation['attestation_hash'] ?? null,
                'control_plane_hash' => $controlPlane['control_plane_hash'] ?? null,
            ],
        ];
        $payload['proof_plan_hash'] = MissionCanonicalHash::sha256($payload);

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
     * @param  array<int,array<string,mixed>>  $runs
     * @param  array<string,mixed>  $evidencePackReadiness
     * @return array<int,array<string,mixed>>
     */
    private function replayWorkItems(array $runs, array $evidencePackReadiness): array
    {
        $evidencePacksByRun = collect((array) data_get($evidencePackReadiness, 'packs', []))
            ->keyBy(fn (array $pack): string => ($pack['case_id'] ?? '').'|'.($pack['system'] ?? ''));

        return collect($runs)
            ->reject(fn (array $run): bool => ($run['status'] ?? null) === 'complete')
            ->map(function (array $run) use ($evidencePacksByRun): array {
                $caseId = (string) ($run['case_id'] ?? '');
                $system = (string) ($run['system'] ?? '');
                $pack = (array) ($evidencePacksByRun->get($caseId.'|'.$system) ?? []);

                return [
                    'id' => 'complete_replay_'.$run['case_id'].'_'.$run['system'],
                    'case_id' => $run['case_id'] ?? null,
                    'system' => $run['system'] ?? null,
                    'status' => $run['status'] ?? 'missing',
                    'issues' => $run['issues'] ?? [],
                    'evidence_pack_status' => $pack['status'] ?? 'missing',
                    'evidence_pack_blockers' => $pack['blockers'] ?? ['evidence_pack_missing'],
                    'evidence_pack_warnings' => $pack['warnings'] ?? [],
                    'required_manifest_fields' => $this->requiredReplayManifestFields($system),
                    'required_receipts' => $system === 'atlas_frontend'
                        ? ['score_attestation']
                        : ['score_attestation', 'external_execution_receipt'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function requiredReplayManifestFields(string $system): array
    {
        $fields = [
            'case_id',
            'system',
            'status',
            'run_id',
            'run_packet_hash',
            'task_spec_hash',
            'task_spec_ref',
            'evidence_pack_ref',
            'output_artifact_ref',
            'output_artifact_hash',
            'screenshot_hashes',
            'anti_slop_report_hash',
            'verification_hashes',
            'score_breakdown',
            'score_total',
            'score_max',
            'score_attestation',
            'completed_at',
        ];

        if ($system !== 'atlas_frontend') {
            $fields[] = 'external_execution_receipt';
        }

        return $fields;
    }

    /**
     * @param  array<string,mixed>  $publication
     * @return array<int,array<string,mixed>>
     */
    private function publicationWorkItems(array $publication): array
    {
        if ((bool) data_get($publication, 'claim_policy.public_distribution_claim_allowed')) {
            return [];
        }

        return [
            [
                'id' => 'build_or_verify_public_product_proof',
                'status' => $publication['status'] ?? 'not_requested',
                'blockers' => $publication['blockers'] ?? [],
                'warnings' => $publication['warnings'] ?? [],
                'commands' => [
                    'php artisan atlas:frontend:proof build --output=<bundle> --json',
                    'php artisan atlas:frontend:publish receipt-template --bundle=<bundle> --output=<bundle> --json',
                    'php artisan atlas:frontend:publish attest --bundle=<bundle> --receipt=<receipt> --json',
                    'php artisan atlas:frontend:publish verify --bundle=<bundle> --receipt=<receipt> --json',
                ],
            ],
        ];
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
                    'public_distribution_claim_allowed' => false,
                ],
                'warnings' => ['publication_bundle_not_supplied'],
                'blockers' => [],
            ];
        }

        return app(AtlasFrontendPublicationVerifierService::class)->verify(
            $bundle,
            $this->nullableString($input['publication_receipt'] ?? null),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function missingOperatorPacketVerification(): array
    {
        $payload = [
            'schema_version' => AtlasFrontendRivalReplayHarnessService::OPERATOR_PACKET_VERIFICATION_SCHEMA_VERSION,
            'status' => 'pending',
            'verification_type' => 'external_rival_replay_operator_packet_integrity',
            'checks' => [
                'operator_packet_present' => false,
                'operator_packet_hash_valid' => false,
                'runner_kit_hash_matches' => false,
                'worklist_hash_matches' => false,
                'proof_contract_file_hash_matches' => false,
                'uses_replay_evidence_env_placeholder' => false,
                'raw_absolute_path_not_embedded' => true,
            ],
            'claim_policy' => [
                'verification_is_not_external_replay_evidence' => true,
                'world_best_claim_allowed' => false,
                'raw_absolute_path_returned' => false,
                'external_provider_dispatch_performed' => false,
            ],
            'blockers' => [],
            'warnings' => ['operator_packet_verification_requires_rival_evidence_directory'],
        ];
        $payload['operator_packet_verification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $publication
     * @return array<int,string>
     */
    private function blockers(array $replay, array $publication, array $operatorPacketVerification, bool $rivalEvidenceProvided): array
    {
        $blockers = [];
        if ((int) data_get($replay, 'summary.invalid', 0) > 0) {
            $blockers[] = 'invalid_rival_replay_manifest';
        }
        if (($publication['status'] ?? null) === 'blocked') {
            $blockers[] = 'publication_verification_blocked';
        }
        if ($rivalEvidenceProvided && ($operatorPacketVerification['status'] ?? null) !== 'passed') {
            $blockers[] = 'operator_packet_verification_blocked';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $replay
     * @param  array<string,mixed>  $publication
     * @param  array<string,mixed>  $controlPlane
     * @return array<int,string>
     */
    private function warnings(array $replay, array $publication, array $controlPlane, array $operatorPacketVerification): array
    {
        return array_values(array_unique(array_filter(array_merge(
            (array) ($replay['remaining_gaps'] ?? []),
            (array) ($publication['warnings'] ?? []),
            (array) ($controlPlane['warnings'] ?? []),
            (array) ($operatorPacketVerification['warnings'] ?? []),
        ))));
    }

    /**
     * @param  array<int,array<string,mixed>>  $replayWorkItems
     * @param  array<int,array<string,mixed>>  $publicationWorkItems
     * @param  array<string,mixed>  $evidencePackReadiness
     * @return array<int,string>
     */
    private function nextActions(array $replayWorkItems, array $publicationWorkItems, bool $worldBestClaimAllowed, array $evidencePackReadiness, array $competitiveDiagnostics, bool $operatorPacketVerified): array
    {
        if ($worldBestClaimAllowed) {
            return ['claim_world_best_only_with_attached_proof_plan_hash'];
        }

        $actions = [];
        if (! $operatorPacketVerified) {
            $actions[] = 'generate_and_verify_rival_replay_operator_packet';
        }
        if ($replayWorkItems !== []) {
            $actions[] = 'generate_rival_replay_runner_kit';
            $actions[] = 'complete_external_rival_replay_manifests';
        }
        if (($evidencePackReadiness['status'] ?? null) !== 'ready') {
            $actions[] = 'fill_and_verify_rival_replay_evidence_packs';
        }
        if ((int) ($competitiveDiagnostics['losing_case_count'] ?? 0) > 0) {
            $actions[] = 'improve_atlas_frontend_until_replay_wins_every_case';
        }
        if ((int) ($competitiveDiagnostics['tied_case_count'] ?? 0) > 0) {
            $actions[] = 'improve_atlas_frontend_until_replay_leads_every_case';
        }
        if ((int) ($competitiveDiagnostics['dimension_gap_case_count'] ?? 0) > 0) {
            $actions[] = 'improve_atlas_frontend_until_replay_closes_dimension_gaps';
        }
        if ($publicationWorkItems !== []) {
            $actions[] = 'verify_public_product_proof_distribution';
        }
        $actions[] = 'rerun_world_best_proof_plan_and_control_plane';

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
