<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendRivalReplayHarnessService
{
    public const SCHEMA_VERSION = 'atlas.frontend.rival_replay_harness.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.rival_replay_template.v1';

    public const TASK_SPEC_SCHEMA_VERSION = 'atlas.frontend.rival_replay_task_spec.v1';

    public const RUNNER_KIT_SCHEMA_VERSION = 'atlas.frontend.rival_replay_runner_kit.v1';

    public const EVIDENCE_WORKLIST_SCHEMA_VERSION = 'atlas.frontend.rival_replay_evidence_worklist.v1';

    public const PROOF_CONTRACT_FILE_SCHEMA_VERSION = 'atlas.frontend.rival_replay_competitive_proof_contract_file.v1';

    public const OPERATOR_PACKET_SCHEMA_VERSION = 'atlas.frontend.rival_replay_operator_packet.v1';

    public const OPERATOR_PACKET_VERIFICATION_SCHEMA_VERSION = 'atlas.frontend.rival_replay_operator_packet_verification.v1';

    public const PROOF_BUNDLE_SCHEMA_VERSION = 'atlas.frontend.rival_replay_competitive_proof_bundle.v1';

    public const EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION = 'atlas.frontend.rival_replay.external_execution_receipt.v1';

    public const EXTERNAL_EXECUTION_RECEIPT_TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.rival_replay.external_execution_receipt_template.v1';

    public const SCORE_ATTESTATION_SCHEMA_VERSION = 'atlas.frontend.rival_replay.score_attestation.v1';

    public const SCORE_ATTESTATION_TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.rival_replay.score_attestation_template.v1';

    public const MANIFEST_PATCH_APPLICATION_SCHEMA_VERSION = 'atlas.frontend.rival_replay.manifest_patch_application.v1';

    public const DECISIVE_LEAD_MINIMUM_POINTS = 2;

    /**
     * @return array<string,mixed>
     */
    public function inspect(?string $evidenceDirectory = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $cases = $this->cases();
        $systems = $this->systems();
        $requiredFields = $this->requiredManifestFields();
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $runs = [];
        foreach ($cases as $case) {
            foreach ($systems as $system) {
                $manifestPath = $directory.'/'.$case['id'].'/'.$system['id'].'/manifest.json';
                $run = $this->inspectManifest($manifestPath, $case['id'], $system['id'], $requiredFields);
                $runs[] = $run;
            }
        }

        $runs = $this->applyFairnessGates($runs);
        $complete = collect($runs)->where('status', 'complete')->count();
        $invalid = collect($runs)->where('status', 'invalid')->count();
        $missing = count($runs) - $complete - $invalid;
        $externalRuns = array_values(array_filter($runs, fn (array $run): bool => $run['system'] !== 'atlas_frontend'));
        $externalReplayCompleted = $externalRuns !== [] && collect($externalRuns)->every(fn (array $run): bool => $run['status'] === 'complete');
        $allRunsCompleted = $runs !== [] && collect($runs)->every(fn (array $run): bool => $run['status'] === 'complete');
        $scoreboard = $this->scoreboard($runs);
        $fairness = $this->fairnessSummary($runs);
        $evidencePackReadiness = $this->evidencePackReadiness($directory);
        $atlasWinsAllCompleteCases = $this->atlasWinsAllCompleteCases($runs);
        $competitiveDiagnostics = $this->competitiveDiagnostics($runs);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $allRunsCompleted ? 'ready' : 'ready_for_replay',
            'replay_type' => 'external_rival_evidence_matrix',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'cases' => $cases,
            'systems' => $systems,
            'competitive_rubric' => [
                'schema_version' => AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION,
                'score_max' => $rubric['score_max'],
                'rubric_hash' => $rubric['rubric_hash'],
            ],
            'required_manifest_fields' => $requiredFields,
            'runs' => $runs,
            'fairness' => $fairness,
            'evidence_pack_readiness' => $evidencePackReadiness,
            'competitive_diagnostics' => $competitiveDiagnostics,
            'competitive_proof_contract' => $this->competitiveProofContract(
                $allRunsCompleted,
                $externalReplayCompleted,
                $atlasWinsAllCompleteCases,
                $evidencePackReadiness,
                $fairness,
                $competitiveDiagnostics,
            ),
            'summary' => [
                'total_runs' => count($runs),
                'complete' => $complete,
                'missing_or_pending' => $missing,
                'invalid' => $invalid,
                'external_replay_completed' => $externalReplayCompleted,
                'all_runs_completed' => $allRunsCompleted,
            ],
            'scoreboard' => $scoreboard,
            'claim_policy' => [
                'may_claim_external_replay_completed' => $externalReplayCompleted,
                'may_claim_live_mode_superior_to_impeccable' => $externalReplayCompleted && $atlasWinsAllCompleteCases,
                'may_claim_world_best_frontend_system' => $allRunsCompleted && $atlasWinsAllCompleteCases,
                'world_best_requires_all_rival_runs_complete' => true,
                'world_best_requires_atlas_to_win_each_complete_case' => true,
                'world_best_requires_atlas_to_lead_each_complete_case' => true,
                'world_best_requires_minimum_decisive_lead_points' => self::DECISIVE_LEAD_MINIMUM_POINTS,
                'world_best_requires_no_tied_cases' => true,
                'world_best_requires_no_dimension_gaps_against_best_rival' => true,
                'world_best_requires_same_task_spec_hash_per_case' => true,
                'world_best_requires_external_execution_receipts' => true,
                'documentation_only_claim_forbidden' => true,
                'raw_customer_source_or_prompt_forbidden' => true,
            ],
            'provider_policy' => [
                'provider_neutral' => true,
                'paid_provider_account_optional' => true,
                'manifest_must_use_hashes_or_artifact_refs' => true,
                'raw_prompts_or_customer_source_returned' => false,
                'external_system_names_are_comparison_labels_not_runtime_dependencies' => true,
            ],
            'remaining_gaps' => $this->remainingGaps($allRunsCompleted, $atlasWinsAllCompleteCases, $evidencePackReadiness, $competitiveDiagnostics),
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function evidencePackReadiness(string $directory): array
    {
        $packs = [];
        foreach ($this->cases() as $case) {
            foreach ($this->systems() as $system) {
                $path = $directory.'/'.$case['id'].'/'.$system['id'].'/evidence/evidence-pack.json';
                if (! File::isFile($path)) {
                    $packs[] = [
                        'case_id' => $case['id'],
                        'system' => $system['id'],
                        'status' => 'missing',
                        'blockers' => ['evidence_pack_missing'],
                    ];

                    continue;
                }

                $verification = app(AtlasFrontendEvidencePackVerifierService::class)->verify($path);
                $packs[] = [
                    'case_id' => $case['id'],
                    'system' => $system['id'],
                    'status' => ($verification['status'] ?? null) === 'passed' ? 'passed' : 'blocked',
                    'verification_hash' => $verification['verification_hash'] ?? null,
                    'blockers' => array_values(array_unique((array) ($verification['blockers'] ?? []))),
                    'warnings' => array_values(array_unique((array) ($verification['warnings'] ?? []))),
                ];
            }
        }

        $present = collect($packs)->whereNotIn('status', ['missing'])->count();
        $passed = collect($packs)->where('status', 'passed')->count();
        $blocked = collect($packs)->where('status', 'blocked')->count();
        $missing = collect($packs)->where('status', 'missing')->count();

        return [
            'schema_version' => 'atlas.frontend.rival_replay_evidence_pack_readiness.v1',
            'status' => $passed === count($packs) ? 'ready' : 'pending',
            'summary' => [
                'total' => count($packs),
                'present' => $present,
                'passed' => $passed,
                'blocked' => $blocked,
                'missing' => $missing,
            ],
            'required_artifact_kinds' => app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds(),
            'packs' => $packs,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidencePackReadiness
     * @param  array<string,mixed>  $fairness
     * @param  array<string,mixed>  $competitiveDiagnostics
     * @return array<string,mixed>
     */
    private function competitiveProofContract(
        bool $allRunsCompleted,
        bool $externalReplayCompleted,
        bool $atlasWinsAllCompleteCases,
        array $evidencePackReadiness,
        array $fairness,
        array $competitiveDiagnostics,
    ): array {
        $evidenceReady = ($evidencePackReadiness['status'] ?? null) === 'ready';
        $fairnessPassed = ($fairness['status'] ?? null) === 'passed';
        $diagnosticsStatus = (string) ($competitiveDiagnostics['status'] ?? 'pending_replay');
        $worldBestReady = $allRunsCompleted
            && $externalReplayCompleted
            && $evidenceReady
            && $fairnessPassed
            && $atlasWinsAllCompleteCases
            && $diagnosticsStatus === 'atlas_leads_all_complete_cases';

        $nextActions = [];
        if (! $evidenceReady) {
            $nextActions[] = 'fill_and_hash_missing_rival_replay_evidence_packs';
        }
        if (! $externalReplayCompleted) {
            $nextActions[] = 'run_external_rivals_against_unchanged_task_specs';
            $nextActions[] = 'embed_verified_external_execution_receipts';
        }
        if (! $fairnessPassed) {
            $nextActions[] = 'restore_same_task_spec_hash_across_all_systems_per_case';
        }
        if (! $allRunsCompleted) {
            $nextActions[] = 'complete_all_replay_manifests_with_verified_hash_refs';
        }
        if ($allRunsCompleted && ! $atlasWinsAllCompleteCases) {
            $nextActions = array_merge(
                $nextActions,
                collect((array) ($competitiveDiagnostics['cases'] ?? []))
                    ->filter(fn (mixed $case): bool => is_array($case) && ($case['status'] ?? null) !== 'atlas_leads')
                    ->map(fn (array $case): string => (string) ($case['next_action'] ?? 'improve_atlas_frontend_case_until_decisive_lead'))
                    ->filter()
                    ->values()
                    ->all(),
            );
        }
        if ($worldBestReady) {
            $nextActions[] = 'preserve_verified_replay_evidence_and_publish_world_best_proof_packet';
        }

        $repairCommands = collect((array) ($competitiveDiagnostics['cases'] ?? []))
            ->flatMap(fn (mixed $case): array => is_array($case) ? (array) ($case['recommended_repair_plan_commands'] ?? []) : [])
            ->filter(fn (mixed $command): bool => is_string($command) && $command !== '')
            ->unique()
            ->values()
            ->all();

        $contract = [
            'schema_version' => 'atlas.frontend.rival_replay_competitive_proof_contract.v1',
            'status' => match (true) {
                $worldBestReady => 'world_best_proof_ready',
                ! $evidenceReady => 'evidence_packs_required',
                ! $externalReplayCompleted => 'external_replay_receipts_required',
                ! $fairnessPassed => 'fairness_repair_required',
                ! $allRunsCompleted => 'run_manifests_required',
                default => 'atlas_improvement_required',
            },
            'gates' => [
                'evidence_packs_verified' => $evidenceReady,
                'external_rival_execution_receipts_verified' => $externalReplayCompleted,
                'same_task_spec_hash_fairness_passed' => $fairnessPassed,
                'all_run_manifests_complete' => $allRunsCompleted,
                'atlas_decisively_leads_every_case' => $atlasWinsAllCompleteCases,
                'no_dimension_gaps_against_best_rival' => ((int) ($competitiveDiagnostics['dimension_gap_case_count'] ?? 0)) === 0,
            ],
            'minimum_decisive_lead_points' => self::DECISIVE_LEAD_MINIMUM_POINTS,
            'diagnostics_status' => $diagnosticsStatus,
            'next_minimum_actions' => array_values(array_unique($nextActions)),
            'recommended_repair_plan_commands' => $repairCommands,
            'claim_policy' => [
                'contract_is_a_proof_index_not_the_artifacts' => true,
                'may_claim_external_replay_completed' => $externalReplayCompleted,
                'may_claim_world_best_frontend_system' => $worldBestReady,
                'world_best_requires_verified_evidence_pack_for_every_run' => true,
                'world_best_requires_verified_external_receipt_for_every_external_rival_run' => true,
                'world_best_requires_decisive_lead_and_no_dimension_gaps' => true,
                'documentation_only_claim_forbidden' => true,
            ],
        ];
        $contract['proof_contract_hash'] = MissionCanonicalHash::sha256($contract);

        return $contract;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $directory = $this->evidenceDirectory($outputDirectory);
        $created = [];

        $createdTaskSpecs = [];

        foreach ($this->cases() as $case) {
            $taskSpec = $this->replayTaskSpec($case);
            $taskSpecPath = $directory.'/'.$case['id'].'/task-spec.json';
            File::ensureDirectoryExists(dirname($taskSpecPath));
            if (! File::isFile($taskSpecPath)) {
                File::put($taskSpecPath, json_encode($taskSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                $createdTaskSpecs[] = $case['id'].'/task-spec.json';
            }

            foreach ($this->systems() as $system) {
                $runDirectory = $directory.'/'.$case['id'].'/'.$system['id'];
                File::ensureDirectoryExists($runDirectory);
                $manifestPath = $runDirectory.'/manifest.json';

                if (! File::isFile($manifestPath)) {
                    File::put($manifestPath, json_encode($this->pendingManifest($case['id'], $system['id'], $taskSpec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                    $created[] = $case['id'].'/'.$system['id'].'/manifest.json';
                }
            }
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'external_rival_replay_manifest_skeleton',
            'evidence_directory_hash' => hash('sha256', $directory),
            'created_count' => count($created),
            'created_manifest_refs' => $created,
            'created_task_spec_refs' => $createdTaskSpecs,
            'task_spec_schema_version' => self::TASK_SPEC_SCHEMA_VERSION,
            'required_manifest_fields' => $this->requiredManifestFields(),
            'competitive_rubric' => [
                'schema_version' => AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION,
                'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
            ],
            'claim_policy' => [
                'template_is_not_replay_evidence' => true,
                'pending_manifests_do_not_authorize_market_claims' => true,
                'raw_prompts_or_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeRunnerKit(string $outputDirectory): array
    {
        $directory = $this->evidenceDirectory($outputDirectory);
        $template = $this->writeTemplate($directory);
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $packets = [];
        $createdEvidencePackRefs = [];

        foreach ($this->cases() as $case) {
            $taskSpec = $this->taskSpecForCase($directory, $case);
            foreach ($this->systems() as $system) {
                $evidencePackRef = $case['id'].'/'.$system['id'].'/evidence/evidence-pack.json';
                if ($this->writePendingEvidencePack($directory, $case['id'], $system['id'], $taskSpec)) {
                    $createdEvidencePackRefs[] = $evidencePackRef;
                }

                $packet = [
                    'id' => 'run_'.$case['id'].'_'.$system['id'],
                    'case_id' => $case['id'],
                    'system' => $system['id'],
                    'system_kind' => $system['kind'],
                    'task_spec_ref' => $case['id'].'/task-spec.json',
                    'manifest_ref' => $case['id'].'/'.$system['id'].'/manifest.json',
                    'evidence_pack_ref' => $evidencePackRef,
                    'execution_steps' => $this->runnerSteps($system['id']),
                    'evidence_checklist' => $this->runnerEvidenceChecklist(),
                    'scoring_policy' => [
                        'rubric_schema_version' => AtlasFrontendCompetitiveRubricService::SCHEMA_VERSION,
                        'rubric_hash' => $rubric['rubric_hash'],
                        'score_max' => $rubric['score_max'],
                        'score_breakdown_must_sum_to_total' => true,
                    ],
                    'completion_policy' => [
                        'complete_only_after_manifest_fields_are_filled' => true,
                        'evidence_pack_must_verify_before_complete_manifest' => true,
                        'same_task_spec_hash_required_across_systems_in_case' => true,
                        'external_rival_runs_require_execution_receipt' => true,
                        'score_attestation_must_verify_manifest_score' => true,
                        'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
                    ],
                ];
                $packet['run_packet_hash'] = MissionCanonicalHash::sha256($packet);
                $this->writeRunPacketHashToManifest($directory, $case['id'], $system['id'], $packet['run_packet_hash']);
                $packets[] = $packet;
            }
        }

        $payload = [
            'schema_version' => self::RUNNER_KIT_SCHEMA_VERSION,
            'status' => 'ready',
            'runner_type' => 'provider_neutral_external_rival_replay_runner',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'template_hash' => $template['template_hash'] ?? null,
            'run_packet_count' => count($packets),
            'run_packets' => $packets,
            'created_evidence_pack_refs' => $createdEvidencePackRefs,
            'commands' => [
                'inspect' => 'php artisan atlas:frontend:replay inspect --evidence='.$directory.' --json',
                'world_best_plan' => 'php artisan atlas:frontend:world-best-plan --rival-evidence='.$directory.' --json --strict',
            ],
            'claim_policy' => [
                'runner_kit_is_not_replay_evidence' => true,
                'pending_manifests_do_not_authorize_market_claims' => true,
                'world_best_requires_all_run_packets_complete' => true,
                'raw_prompts_or_customer_source_returned' => false,
            ],
        ];
        $payload['runner_kit_hash'] = MissionCanonicalHash::sha256($payload);
        File::put($directory.'/replay-runner-kit.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeEvidenceWorklist(string $evidenceDirectory, ?string $outputPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $target = $this->worklistOutputPath($directory, $outputPath);
        $payload = $this->evidenceWorklist($directory, $target);
        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeCompetitiveProofContract(string $evidenceDirectory, ?string $outputPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $target = $this->proofContractOutputPath($directory, $outputPath);
        $inspect = $this->inspect($directory);
        $contract = (array) ($inspect['competitive_proof_contract'] ?? []);
        $payload = [
            'schema_version' => self::PROOF_CONTRACT_FILE_SCHEMA_VERSION,
            'status' => (string) ($contract['status'] ?? 'unknown'),
            'file_type' => 'competitive_proof_contract_receipt',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'replay_hash' => $inspect['replay_hash'] ?? null,
            'competitive_proof_contract' => $contract,
            'summary' => [
                'total_runs' => data_get($inspect, 'summary.total_runs'),
                'external_replay_completed' => (bool) data_get($inspect, 'summary.external_replay_completed'),
                'evidence_pack_status' => data_get($inspect, 'evidence_pack_readiness.status'),
                'competitive_diagnostics_status' => data_get($inspect, 'competitive_diagnostics.status'),
            ],
            'output_ref_hash' => hash('sha256', $target),
            'write_performed' => true,
            'claim_policy' => [
                'proof_contract_file_is_not_the_underlying_evidence' => true,
                'world_best_claim_allowed' => (bool) data_get($contract, 'claim_policy.may_claim_world_best_frontend_system'),
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
            ],
        ];
        $payload['proof_contract_file_hash'] = MissionCanonicalHash::sha256($payload);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeOperatorPacket(string $evidenceDirectory, ?string $outputPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $runnerKit = $this->writeRunnerKit($directory);
        $worklist = $this->writeEvidenceWorklist($directory);
        $proofContractFile = $this->writeCompetitiveProofContract($directory);
        $target = $this->operatorPacketOutputPath($directory, $outputPath);
        $evidenceArg = '${ATLAS_FRONTEND_REPLAY_EVIDENCE}';
        $externalRunPackets = collect((array) ($runnerKit['run_packets'] ?? []))
            ->filter(fn (mixed $packet): bool => is_array($packet) && ($packet['system_kind'] ?? null) === 'external_rival')
            ->map(fn (array $packet): array => [
                'id' => (string) ($packet['id'] ?? ''),
                'case_id' => (string) ($packet['case_id'] ?? ''),
                'system' => (string) ($packet['system'] ?? ''),
                'task_spec_ref' => (string) ($packet['task_spec_ref'] ?? ''),
                'manifest_ref' => (string) ($packet['manifest_ref'] ?? ''),
                'evidence_pack_ref' => (string) ($packet['evidence_pack_ref'] ?? ''),
                'run_packet_hash' => (string) ($packet['run_packet_hash'] ?? ''),
                'execution_steps' => array_values(array_filter((array) ($packet['execution_steps'] ?? []), 'is_string')),
                'evidence_checklist' => array_values(array_filter((array) ($packet['evidence_checklist'] ?? []), 'is_string')),
                'commands' => [
                    'external_receipt_template' => 'php artisan atlas:frontend:replay external-receipt-template --evidence='.$evidenceArg.' --case='.(string) ($packet['case_id'] ?? '').' --system='.(string) ($packet['system'] ?? '').' --json',
                    'score_template' => 'php artisan atlas:frontend:replay score-template --evidence='.$evidenceArg.' --case='.(string) ($packet['case_id'] ?? '').' --system='.(string) ($packet['system'] ?? '').' --json',
                ],
            ])
            ->values()
            ->all();
        $proofContract = (array) ($proofContractFile['competitive_proof_contract'] ?? []);

        $payload = [
            'schema_version' => self::OPERATOR_PACKET_SCHEMA_VERSION,
            'status' => data_get($proofContract, 'claim_policy.may_claim_world_best_frontend_system') === true
                ? 'world_best_proof_ready'
                : 'ready_for_external_operator_replay',
            'packet_type' => 'external_rival_replay_operator_execution_packet',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'runner_kit_hash' => $runnerKit['runner_kit_hash'] ?? null,
            'worklist_hash' => $worklist['worklist_hash'] ?? null,
            'proof_contract_file_hash' => $proofContractFile['proof_contract_file_hash'] ?? null,
            'external_run_count' => count($externalRunPackets),
            'external_runs' => $externalRunPackets,
            'execution_environment' => [
                'required_env' => [
                    'ATLAS_FRONTEND_REPLAY_EVIDENCE' => 'absolute local path to the prepared rival replay evidence directory',
                ],
                'raw_absolute_path_embedded' => false,
                'evidence_directory_hash_only' => true,
            ],
            'operator_sequence' => [
                'export ATLAS_FRONTEND_REPLAY_EVIDENCE_to_the_local_replay_directory',
                'run_or_review_each_external_system_against_task_spec_ref',
                'capture_artifacts_and_fill_evidence_pack_hashes',
                'run_external_receipt_template_for_each_external_run',
                'run_score_template_for_each_run_after_rubric_review',
                'embed_verified_manifest_patches',
                'rerun_replay_inspect_and_proof_contract',
            ],
            'commands' => [
                'refresh_runner_kit' => 'php artisan atlas:frontend:replay runner-kit --output='.$evidenceArg.' --json',
                'refresh_worklist' => 'php artisan atlas:frontend:replay evidence-worklist --evidence='.$evidenceArg.' --json',
                'refresh_proof_contract' => 'php artisan atlas:frontend:replay proof-contract --evidence='.$evidenceArg.' --json',
                'inspect' => 'php artisan atlas:frontend:replay inspect --evidence='.$evidenceArg.' --json',
                'world_best_plan' => 'php artisan atlas:frontend:world-best-plan --rival-evidence='.$evidenceArg.' --json --strict',
            ],
            'claim_policy' => [
                'operator_packet_is_not_replay_evidence' => true,
                'external_provider_dispatch_not_performed_by_atlas' => true,
                'world_best_claim_allowed' => (bool) data_get($proofContract, 'claim_policy.may_claim_world_best_frontend_system'),
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
                'raw_absolute_path_embedded' => false,
            ],
        ];
        $payload['operator_packet_hash'] = MissionCanonicalHash::sha256($payload);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function verifyOperatorPacket(string $evidenceDirectory, ?string $packetPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $target = $this->operatorPacketOutputPath($directory, $packetPath);
        $blockers = [];
        $warnings = [];

        $packet = $this->readJsonFile($target);
        if ($packet === null) {
            $blockers[] = 'operator_packet_missing';
            $packet = [];
        }

        $runnerKitPath = $directory.'/replay-runner-kit.json';
        $worklistPath = $directory.'/replay-evidence-worklist.json';
        $proofContractPath = $directory.'/replay-competitive-proof-contract.json';
        $runnerKit = $this->readJsonFile($runnerKitPath) ?? [];
        $worklist = $this->readJsonFile($worklistPath) ?? [];
        $proofContract = $this->readJsonFile($proofContractPath) ?? [];

        $checks = [
            'operator_packet_present' => $packet !== [],
            'schema_version_valid' => ($packet['schema_version'] ?? null) === self::OPERATOR_PACKET_SCHEMA_VERSION,
            'operator_packet_hash_valid' => $packet !== [] && $this->embeddedHashMatches($packet, 'operator_packet_hash'),
            'runner_kit_hash_matches' => $this->referencedHashMatches($packet, 'runner_kit_hash', $runnerKit, 'runner_kit_hash'),
            'worklist_hash_matches' => $this->referencedHashMatches($packet, 'worklist_hash', $worklist, 'worklist_hash'),
            'proof_contract_file_hash_matches' => $this->referencedHashMatches($packet, 'proof_contract_file_hash', $proofContract, 'proof_contract_file_hash'),
            'external_run_count_matches' => (int) ($packet['external_run_count'] ?? -1) === count((array) ($packet['external_runs'] ?? [])),
            'external_run_count_expected' => (int) ($packet['external_run_count'] ?? 0) === 10,
            'uses_replay_evidence_env_placeholder' => $this->packetUsesReplayEvidencePlaceholder($packet),
            'raw_absolute_path_not_embedded' => ! str_contains(json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', $directory),
            'operator_packet_is_not_replay_evidence' => data_get($packet, 'claim_policy.operator_packet_is_not_replay_evidence') === true,
            'dispatch_not_performed_by_atlas' => data_get($packet, 'claim_policy.external_provider_dispatch_not_performed_by_atlas') === true,
        ];

        foreach ($checks as $id => $passed) {
            if (! $passed) {
                $blockers[] = $id.'_failed';
            }
        }

        if ($packet !== [] && data_get($packet, 'execution_environment.raw_absolute_path_embedded') !== false) {
            $warnings[] = 'operator_packet_execution_environment_does_not_explicitly_forbid_raw_path';
        }

        $payload = [
            'schema_version' => self::OPERATOR_PACKET_VERIFICATION_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'verification_type' => 'external_rival_replay_operator_packet_integrity',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'operator_packet_ref_hash' => hash('sha256', $target),
            'checks' => $checks,
            'referenced_hashes' => [
                'operator_packet_hash' => $packet['operator_packet_hash'] ?? null,
                'runner_kit_hash' => $packet['runner_kit_hash'] ?? null,
                'worklist_hash' => $packet['worklist_hash'] ?? null,
                'proof_contract_file_hash' => $packet['proof_contract_file_hash'] ?? null,
            ],
            'claim_policy' => [
                'verification_is_not_external_replay_evidence' => true,
                'world_best_claim_allowed' => false,
                'raw_absolute_path_returned' => false,
                'external_provider_dispatch_performed' => false,
            ],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
        $payload['operator_packet_verification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeCompetitiveProofBundle(string $evidenceDirectory, ?string $outputPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $target = $this->proofBundleOutputPath($directory, $outputPath);
        $operatorPacket = $this->writeOperatorPacket($directory);
        $operatorVerification = $this->verifyOperatorPacket($directory);
        $inspect = $this->inspect($directory);
        $proofContract = (array) ($inspect['competitive_proof_contract'] ?? []);
        $proofContractWorldBest = data_get($proofContract, 'claim_policy.may_claim_world_best_frontend_system') === true;
        $operatorVerified = ($operatorVerification['status'] ?? null) === 'passed';
        $externalReplayCompleted = (bool) data_get($inspect, 'summary.external_replay_completed');
        $blockers = array_values(array_unique(array_filter([
            ...($operatorVerified ? [] : ['operator_packet_verification_blocked']),
            ...($proofContractWorldBest ? [] : (array) data_get($proofContract, 'next_minimum_actions', [])),
        ], fn (mixed $item): bool => is_string($item) && $item !== '')));

        $payload = [
            'schema_version' => self::PROOF_BUNDLE_SCHEMA_VERSION,
            'status' => $operatorVerified && $proofContractWorldBest ? 'world_best_replay_proof_ready' : 'pending_external_replay_evidence',
            'bundle_type' => 'provider_safe_competitive_replay_proof_index',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'replay_hash' => $inspect['replay_hash'] ?? null,
            'operator_packet_hash' => $operatorPacket['operator_packet_hash'] ?? null,
            'operator_packet_verification_hash' => $operatorVerification['operator_packet_verification_hash'] ?? null,
            'proof_contract_hash' => data_get($proofContract, 'proof_contract_hash'),
            'competitive_proof_contract' => $proofContract,
            'operator_packet_verification' => [
                'schema_version' => $operatorVerification['schema_version'] ?? null,
                'status' => $operatorVerification['status'] ?? null,
                'checks' => $operatorVerification['checks'] ?? [],
                'blockers' => $operatorVerification['blockers'] ?? [],
            ],
            'readiness' => [
                'external_replay_completed' => $externalReplayCompleted,
                'all_runs_completed' => (bool) data_get($inspect, 'summary.all_runs_completed'),
                'evidence_pack_status' => data_get($inspect, 'evidence_pack_readiness.status'),
                'fairness_status' => data_get($inspect, 'fairness.status'),
                'competitive_diagnostics_status' => data_get($inspect, 'competitive_diagnostics.status'),
                'operator_packet_verification_status' => $operatorVerification['status'] ?? 'unknown',
            ],
            'run_manifest_index' => collect((array) ($inspect['runs'] ?? []))
                ->filter(fn (mixed $run): bool => is_array($run))
                ->map(fn (array $run): array => [
                    'case_id' => (string) ($run['case_id'] ?? ''),
                    'system' => (string) ($run['system'] ?? ''),
                    'status' => (string) ($run['status'] ?? 'unknown'),
                    'manifest_hash' => $run['manifest_hash'] ?? null,
                    'task_spec_hash' => $run['task_spec_hash'] ?? null,
                    'run_packet_hash' => $run['run_packet_hash'] ?? null,
                    'evidence_pack_verification_hash' => $run['evidence_pack_verification_hash'] ?? null,
                    'external_execution_receipt_hash' => $run['external_execution_receipt_hash'] ?? null,
                    'score_attestation_hash' => $run['score_attestation_hash'] ?? null,
                    'score_total' => $run['score_total'] ?? null,
                    'score_max' => $run['score_max'] ?? null,
                ])
                ->values()
                ->all(),
            'scoreboard' => $inspect['scoreboard'] ?? [],
            'required_next_actions' => $blockers === []
                ? ['preserve_verified_replay_evidence_and_attach_public_distribution_receipt_before_product_world_best_claim']
                : $blockers,
            'output_ref_hash' => hash('sha256', $target),
            'write_performed' => true,
            'claim_policy' => [
                'proof_bundle_is_not_raw_artifact_storage' => true,
                'raw_prompts_customer_source_tokens_urls_or_cookies_forbidden' => true,
                'external_provider_dispatch_performed' => false,
                'may_claim_external_replay_completed' => $externalReplayCompleted,
                'may_claim_world_best_replay_proof' => $operatorVerified && $proofContractWorldBest,
                'may_claim_world_best_frontend_system' => $operatorVerified && $proofContractWorldBest,
                'public_distribution_receipt_still_required_for_product_claim' => true,
            ],
        ];
        $payload['proof_bundle_hash'] = MissionCanonicalHash::sha256($payload);

        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function compileEvidenceWorklist(?string $evidenceDirectory = null): array
    {
        return $this->evidenceWorklist($this->evidenceDirectory($evidenceDirectory));
    }

    /**
     * @return array<string,mixed>
     */
    public function writeScoreAttestationTemplate(
        string $evidenceDirectory,
        string $caseId,
        string $system,
        ?string $outputPath = null,
        ?string $reviewerRefHash = null,
        ?string $scoringSurface = null,
    ): array {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $caseId = trim($caseId);
        $system = trim($system);
        $target = $this->scoreAttestationOutputPath($directory, $caseId, $system, $outputPath);
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $manifestPath = $directory.'/'.$caseId.'/'.$system.'/manifest.json';
        $blockers = [];
        $knownCaseIds = array_column($this->cases(), 'id');
        $knownSystemIds = array_column($this->systems(), 'id');

        if ($caseId === '') {
            $blockers[] = 'case_id_required';
        } elseif (! in_array($caseId, $knownCaseIds, true)) {
            $blockers[] = 'unknown_case_id';
        }
        if ($system === '') {
            $blockers[] = 'system_required';
        } elseif (! in_array($system, $knownSystemIds, true)) {
            $blockers[] = 'unknown_system_id';
        }
        if ($caseId !== '' && $system !== '' && ! File::isFile($manifestPath)) {
            $blockers[] = 'manifest_missing';
        }

        $manifest = [];
        if ($blockers === []) {
            $decoded = json_decode(File::get($manifestPath), true);
            if (! is_array($decoded)) {
                $blockers[] = 'manifest_json_invalid';
            } else {
                $manifest = $decoded;
            }
        }

        $scoreBreakdown = is_array($manifest['score_breakdown'] ?? null) ? $manifest['score_breakdown'] : [];
        $scoreTotal = is_numeric($manifest['score_total'] ?? null) ? (int) $manifest['score_total'] : null;
        $scoreMax = is_numeric($manifest['score_max'] ?? null) ? (int) $manifest['score_max'] : null;

        if ($blockers === [] && $scoreBreakdown === []) {
            $blockers[] = 'score_breakdown_required';
        }
        if ($blockers === [] && ($scoreTotal === null || $scoreMax === null)) {
            $blockers[] = 'score_total_and_score_max_required';
        }

        $evidencePack = ['issues' => ['manifest_unavailable']];
        if ($blockers === []) {
            $evidencePack = $this->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
            if (($evidencePack['issues'] ?? []) !== []) {
                $blockers = array_values(array_unique(array_merge($blockers, (array) $evidencePack['issues'])));
            }
        }

        $reviewerRefHash = trim((string) $reviewerRefHash);
        $reviewerRefHash = preg_match('/\A[a-f0-9]{64}\z/', $reviewerRefHash) ? $reviewerRefHash : null;
        $scoringSurface = trim((string) $scoringSurface);
        $scoringSurface = in_array($scoringSurface, ['manual_competitive_review', 'independent_review_panel', 'atlas_review_panel'], true)
            ? $scoringSurface
            : 'manual_competitive_review';

        $attestation = [
            'schema_version' => self::SCORE_ATTESTATION_SCHEMA_VERSION,
            'status' => 'pending_operator_approval',
            'case_id' => $caseId !== '' ? $caseId : null,
            'system' => $system !== '' ? $system : null,
            'scoring_surface' => $scoringSurface,
            'reviewer_ref_hash' => $reviewerRefHash,
            'reviewed_at' => null,
            'operator_approved' => false,
            'rubric_hash' => $rubric['rubric_hash'] ?? null,
            'score_breakdown_hash' => $scoreBreakdown !== [] ? MissionCanonicalHash::sha256($scoreBreakdown) : null,
            'score_total' => $scoreTotal,
            'score_max' => $scoreMax,
            'evidence_pack_verification_hash' => $evidencePack['verification_hash'] ?? null,
            'reviewed_manifest_hashes' => $this->scoreReviewedManifestHashes($manifest, $evidencePack['verification_hash'] ?? null),
            'notes' => 'Set status=verified, reviewed_at and operator_approved=true only after reviewing artifact refs against the rubric. Do not include raw prompts, customer source, cookies, tokens or reviewer identity.',
        ];

        $payload = [
            'schema_version' => self::SCORE_ATTESTATION_TEMPLATE_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'template_type' => 'provider_safe_score_attestation_patch',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'case_id' => $caseId,
            'system' => $system,
            'manifest_ref' => $caseId.'/'.$system.'/manifest.json',
            'manifest_hash' => File::isFile($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'score_attestation' => $attestation,
            'manifest_patch' => [
                'score_attestation' => $attestation,
            ],
            'blockers' => $blockers,
            'required_next_actions' => $blockers === []
                ? ['review_artifact_refs_against_rubric', 'set_verified_reviewed_at_and_operator_approval', 'embed_manifest_patch_score_attestation', 'rerun_replay_inspect']
                : ['fix_blockers_before_score_attestation'],
            'output_ref_hash' => hash('sha256', $target),
            'write_performed' => $blockers === [],
            'claim_policy' => [
                'score_template_is_not_evidence' => true,
                'operator_approval_required_before_complete_manifest' => true,
                'raw_prompts_customer_source_tokens_or_reviewer_identity_forbidden' => true,
                'world_best_claim_forbidden_until_inspect_passes' => true,
            ],
        ];
        $payload['score_attestation_template_hash'] = MissionCanonicalHash::sha256($payload);

        if ($blockers === []) {
            File::ensureDirectoryExists(dirname($target));
            File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeExternalExecutionReceiptTemplate(
        string $evidenceDirectory,
        string $caseId,
        string $system,
        ?string $outputPath = null,
        ?string $executionSurface = null,
    ): array {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $caseId = trim($caseId);
        $system = trim($system);
        $target = $this->externalReceiptOutputPath($directory, $caseId, $system, $outputPath);
        $manifestPath = $directory.'/'.$caseId.'/'.$system.'/manifest.json';
        $knownCaseIds = array_column($this->cases(), 'id');
        $knownSystemIds = array_column($this->systems(), 'id');
        $blockers = [];

        if ($caseId === '') {
            $blockers[] = 'case_id_required';
        } elseif (! in_array($caseId, $knownCaseIds, true)) {
            $blockers[] = 'unknown_case_id';
        }
        if ($system === '') {
            $blockers[] = 'system_required';
        } elseif (! in_array($system, $knownSystemIds, true)) {
            $blockers[] = 'unknown_system_id';
        } elseif ($system === 'atlas_frontend') {
            $blockers[] = 'external_rival_system_required';
        }
        if ($caseId !== '' && $system !== '' && ! File::isFile($manifestPath)) {
            $blockers[] = 'manifest_missing';
        }

        $manifest = [];
        if ($blockers === []) {
            $decoded = json_decode(File::get($manifestPath), true);
            if (! is_array($decoded)) {
                $blockers[] = 'manifest_json_invalid';
            } else {
                $manifest = $decoded;
            }
        }

        $evidencePack = ['issues' => ['manifest_unavailable']];
        if ($blockers === []) {
            $evidencePack = $this->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
            if (($evidencePack['issues'] ?? []) !== []) {
                $blockers = array_values(array_unique(array_merge($blockers, (array) $evidencePack['issues'])));
            }
        }

        $executionSurface = trim((string) $executionSurface);
        $executionSurface = in_array($executionSurface, ['external_rival_system', 'manual_external_replay'], true)
            ? $executionSurface
            : 'external_rival_system';

        $receipt = [
            'schema_version' => self::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION,
            'status' => 'pending_operator_approval',
            'case_id' => $caseId !== '' ? $caseId : null,
            'system' => $system !== '' ? $system : null,
            'execution_surface' => $executionSurface,
            'captured_at' => null,
            'operator_approved' => false,
            'manifest_hashes' => [
                'output_artifact_hash' => is_string($manifest['output_artifact_hash'] ?? null) ? (string) $manifest['output_artifact_hash'] : null,
                'screenshot_hashes' => is_array($manifest['screenshot_hashes'] ?? null) ? array_values($manifest['screenshot_hashes']) : [],
                'anti_slop_report_hash' => is_string($manifest['anti_slop_report_hash'] ?? null) ? (string) $manifest['anti_slop_report_hash'] : null,
                'verification_hashes' => is_array($manifest['verification_hashes'] ?? null) ? array_values($manifest['verification_hashes']) : [],
                'evidence_pack_verification_hash' => $evidencePack['verification_hash'] ?? null,
            ],
            'notes' => 'Set status=verified, captured_at and operator_approved=true only after running the external rival on the unchanged task spec and verifying artifact hashes. Do not include raw prompts, customer source, cookies, tokens, URLs or provider secrets.',
        ];

        $payload = [
            'schema_version' => self::EXTERNAL_EXECUTION_RECEIPT_TEMPLATE_SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'template_type' => 'provider_safe_external_execution_receipt_patch',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'case_id' => $caseId,
            'system' => $system,
            'manifest_ref' => $caseId.'/'.$system.'/manifest.json',
            'manifest_hash' => File::isFile($manifestPath) ? hash_file('sha256', $manifestPath) : null,
            'external_execution_receipt' => $receipt,
            'manifest_patch' => [
                'external_execution_receipt' => $receipt,
            ],
            'blockers' => $blockers,
            'required_next_actions' => $blockers === []
                ? ['run_external_rival_against_unchanged_task_spec', 'verify_manifest_hashes_match_artifacts', 'set_verified_captured_at_and_operator_approval', 'embed_manifest_patch_external_execution_receipt', 'rerun_replay_inspect']
                : ['fix_blockers_before_external_execution_receipt'],
            'output_ref_hash' => hash('sha256', $target),
            'write_performed' => $blockers === [],
            'claim_policy' => [
                'external_receipt_template_is_not_evidence' => true,
                'operator_approval_required_before_complete_manifest' => true,
                'raw_prompts_customer_source_tokens_urls_or_provider_secrets_forbidden' => true,
                'world_best_claim_forbidden_until_inspect_passes' => true,
            ],
        ];
        $payload['external_execution_receipt_template_hash'] = MissionCanonicalHash::sha256($payload);

        if ($blockers === []) {
            File::ensureDirectoryExists(dirname($target));
            File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function applyManifestPatch(string $evidenceDirectory, string $patchPath, ?string $outputPath = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $patchPath = trim($patchPath);
        $target = trim((string) $outputPath);
        $blockers = [];

        if ($patchPath === '') {
            $blockers[] = 'patch_path_required';
        } elseif (! File::isFile($patchPath)) {
            $blockers[] = 'patch_file_missing';
        }

        $patch = [];
        if ($blockers === []) {
            $decoded = json_decode((string) File::get($patchPath), true);
            if (! is_array($decoded)) {
                $blockers[] = 'patch_json_invalid';
            } else {
                $patch = $decoded;
            }
        }

        $schema = (string) ($patch['schema_version'] ?? '');
        $allowedSchemas = [
            self::SCORE_ATTESTATION_TEMPLATE_SCHEMA_VERSION,
            self::EXTERNAL_EXECUTION_RECEIPT_TEMPLATE_SCHEMA_VERSION,
        ];
        if ($blockers === [] && ! in_array($schema, $allowedSchemas, true)) {
            $blockers[] = 'unsupported_manifest_patch_schema';
        }

        $manifestRef = $this->safeManifestRef((string) ($patch['manifest_ref'] ?? ''));
        if ($blockers === [] && $manifestRef === '') {
            $blockers[] = 'manifest_ref_required';
        }

        $manifestPath = $manifestRef !== '' ? $directory.'/'.$manifestRef : '';
        if ($blockers === [] && ! File::isFile($manifestPath)) {
            $blockers[] = 'manifest_missing';
        }

        $manifest = [];
        if ($blockers === []) {
            $decoded = json_decode((string) File::get($manifestPath), true);
            if (! is_array($decoded)) {
                $blockers[] = 'manifest_json_invalid';
            } else {
                $manifest = $decoded;
            }
        }

        $currentManifestHash = $manifestPath !== '' && File::isFile($manifestPath) ? hash_file('sha256', $manifestPath) : null;
        if ($blockers === [] && ! hash_equals((string) ($patch['manifest_hash'] ?? ''), (string) $currentManifestHash)) {
            $blockers[] = 'manifest_hash_mismatch';
        }

        $manifestPatch = is_array($patch['manifest_patch'] ?? null) ? (array) $patch['manifest_patch'] : [];
        if ($blockers === [] && $manifestPatch === []) {
            $blockers[] = 'manifest_patch_required';
        }

        $allowedPatchKeys = match ($schema) {
            self::SCORE_ATTESTATION_TEMPLATE_SCHEMA_VERSION => ['score_attestation'],
            self::EXTERNAL_EXECUTION_RECEIPT_TEMPLATE_SCHEMA_VERSION => ['external_execution_receipt'],
            default => [],
        };
        $appliedKeys = array_values(array_intersect(array_keys($manifestPatch), $allowedPatchKeys));
        if ($blockers === [] && $appliedKeys === []) {
            $blockers[] = 'manifest_patch_has_no_supported_keys';
        }
        if ($blockers === [] && array_diff(array_keys($manifestPatch), $allowedPatchKeys) !== []) {
            $blockers[] = 'manifest_patch_contains_unsupported_keys';
        }
        if ($blockers === [] && $this->hasForbiddenRawFields($manifestPatch)) {
            $blockers[] = 'manifest_patch_forbidden_raw_prompt_or_source_field_present';
        }

        $caseId = (string) ($manifest['case_id'] ?? $patch['case_id'] ?? '');
        $system = (string) ($manifest['system'] ?? $patch['system'] ?? '');
        $candidate = $manifest;
        foreach ($appliedKeys as $key) {
            $candidate[$key] = $manifestPatch[$key];
        }

        $patchIssues = [];
        if ($blockers === []) {
            $evidencePack = $this->validateEvidencePackRef($candidate, $manifestPath, $caseId, $system);
            $evidencePackHash = $evidencePack['verification_hash'] ?? null;
            if (($evidencePack['issues'] ?? []) !== []) {
                $patchIssues = array_merge($patchIssues, (array) $evidencePack['issues']);
            }
            if (in_array('external_execution_receipt', $appliedKeys, true)) {
                if ($system === 'atlas_frontend') {
                    $patchIssues[] = 'external_rival_system_required';
                }
                $patchIssues = array_merge($patchIssues, $this->validateExternalExecutionReceipt($candidate, $caseId, $system, $evidencePackHash));
            }
            if (in_array('score_attestation', $appliedKeys, true)) {
                $patchIssues = array_merge($patchIssues, $this->validateScoreAttestation($candidate, $caseId, $system, $evidencePackHash));
            }
        }

        if ($patchIssues !== []) {
            $blockers = array_values(array_unique(array_merge($blockers, $patchIssues)));
        }

        $writePerformed = $blockers === [];
        if ($writePerformed) {
            File::put($manifestPath, json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $postApplyRun = $manifestPath !== '' && File::isFile($manifestPath)
            ? $this->inspectManifest($manifestPath, $caseId, $system, $this->requiredManifestFields())
            : null;

        $payload = [
            'schema_version' => self::MANIFEST_PATCH_APPLICATION_SCHEMA_VERSION,
            'status' => $writePerformed ? 'applied' : 'blocked',
            'source' => self::class,
            'patch_schema_version' => $schema ?: null,
            'evidence_directory_hash' => hash('sha256', $directory),
            'patch_path_hash' => $patchPath !== '' ? hash('sha256', $patchPath) : null,
            'manifest_ref' => $manifestRef ?: null,
            'previous_manifest_hash' => $currentManifestHash,
            'applied_manifest_hash' => $writePerformed ? hash_file('sha256', $manifestPath) : null,
            'applied_keys' => $appliedKeys,
            'post_apply_run_status' => is_array($postApplyRun) ? ($postApplyRun['status'] ?? null) : null,
            'post_apply_run_issues' => is_array($postApplyRun) ? (array) ($postApplyRun['issues'] ?? []) : [],
            'blockers' => $blockers,
            'warnings' => $writePerformed && is_array($postApplyRun) && ($postApplyRun['status'] ?? null) !== 'complete'
                ? ['manifest_patch_applied_but_run_still_incomplete']
                : [],
            'write_performed' => $writePerformed,
            'claim_policy' => [
                'manifest_patch_application_is_not_world_best_evidence' => true,
                'world_best_claim_forbidden_until_replay_inspect_passes' => true,
                'raw_prompts_customer_source_tokens_urls_or_provider_secrets_forbidden' => true,
                'manifest_hash_must_match_before_patch' => true,
            ],
        ];
        $payload['manifest_patch_application_hash'] = MissionCanonicalHash::sha256($payload);

        if ($target !== '') {
            File::ensureDirectoryExists(dirname($target));
            File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceWorklist(string $directory, ?string $target = null): array
    {
        $inspect = $this->inspect($directory);
        $requiredKinds = app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds();
        $workItems = [];
        $packsByRun = collect((array) data_get($inspect, 'evidence_pack_readiness.packs', []))
            ->filter(fn (mixed $pack): bool => is_array($pack))
            ->keyBy(fn (array $pack): string => ($pack['case_id'] ?? '').'|'.($pack['system'] ?? ''));

        foreach ((array) data_get($inspect, 'evidence_pack_readiness.packs', []) as $pack) {
            $caseId = (string) ($pack['case_id'] ?? '');
            $system = (string) ($pack['system'] ?? '');
            if ($caseId === '' || $system === '' || ($pack['status'] ?? null) === 'passed') {
                continue;
            }

            $root = $caseId.'/'.$system;
            $workItems[] = [
                'id' => 'fill_evidence_pack_'.$caseId.'_'.$system,
                'case_id' => $caseId,
                'system' => $system,
                'status' => $pack['status'] ?? 'missing',
                'blockers' => $pack['blockers'] ?? [],
                'pack_manifest_ref' => $root.'/evidence/evidence-pack.json',
                'run_manifest_ref' => $root.'/manifest.json',
                'task_spec_ref' => $caseId.'/task-spec.json',
                'artifact_slots' => array_map(fn (string $kind): array => [
                    'kind' => $kind,
                    'artifact_ref' => $root.'/evidence/artifacts/'.$kind.'.json',
                    'hash_command' => 'shasum -a 256 '.$root.'/evidence/artifacts/'.$kind.'.json',
                    'pack_sha256_field' => 'artifacts[] where kind='.$kind.'.sha256',
                ], $requiredKinds),
                'manifest_hash_mapping' => [
                    'output_artifact_hash' => 'sha256(output_artifact)',
                    'screenshot_hashes[]' => 'sha256(screenshot_set)',
                    'anti_slop_report_hash' => 'sha256(anti_slop_report)',
                    'verification_hashes[]' => 'sha256(verification_report)',
                    'run_packet_hash' => 'runner_kit.run_packets[] where case_id+system match',
                    'score_attestation.score_breakdown_hash' => 'canonical_sha256(score_breakdown)',
                ],
                'completion_steps' => [
                    'capture_real_artifact_files_under_artifact_refs',
                    'update_evidence_pack_sha256_values_from_hash_command',
                    'preserve_or_fill_run_packet_hash_from_runner_kit',
                    'mirror_required_hashes_into_run_manifest',
                    'fill_run_id_score_breakdown_score_total_score_attestation_completed_at',
                    'rerun_php_artisan_atlas_frontend_replay_inspect',
                ],
            ];
        }

        foreach ((array) ($inspect['runs'] ?? []) as $run) {
            if (! is_array($run) || ($run['status'] ?? null) === 'complete') {
                continue;
            }

            $scoreIssues = array_values(array_filter(
                (array) ($run['issues'] ?? []),
                fn (mixed $issue): bool => is_string($issue) && str_starts_with($issue, 'score_attestation_'),
            ));
            $externalReceiptIssues = array_values(array_filter(
                (array) ($run['issues'] ?? []),
                fn (mixed $issue): bool => is_string($issue) && str_starts_with($issue, 'external_execution_receipt_'),
            ));
            if ($scoreIssues === [] && $externalReceiptIssues === []) {
                continue;
            }

            $caseId = (string) ($run['case_id'] ?? '');
            $system = (string) ($run['system'] ?? '');
            $pack = (array) ($packsByRun->get($caseId.'|'.$system) ?? []);
            if (($pack['status'] ?? null) !== 'passed') {
                continue;
            }

            $root = $caseId.'/'.$system;
            if ($externalReceiptIssues !== [] && $system !== 'atlas_frontend') {
                $workItems[] = [
                    'id' => 'fill_external_execution_receipt_'.$caseId.'_'.$system,
                    'case_id' => $caseId,
                    'system' => $system,
                    'status' => $run['status'] ?? 'invalid',
                    'blockers' => $externalReceiptIssues,
                    'pack_manifest_ref' => $root.'/evidence/evidence-pack.json',
                    'run_manifest_ref' => $root.'/manifest.json',
                    'task_spec_ref' => $caseId.'/task-spec.json',
                    'run_packet_hash' => is_string($run['run_packet_hash'] ?? null) ? (string) $run['run_packet_hash'] : null,
                    'run_packet_hash_mapping' => 'manifest.run_packet_hash verified against runner_kit.run_packets[] when runner kit exists',
                    'external_execution_receipt_schema_version' => self::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION,
                    'external_execution_receipt_fields' => [
                        'schema_version',
                        'status',
                        'case_id',
                        'system',
                        'execution_surface',
                        'captured_at',
                        'operator_approved',
                        'manifest_hashes',
                    ],
                    'external_execution_receipt_hash_mapping' => [
                        'output_artifact_hash' => 'manifest.output_artifact_hash',
                        'screenshot_hashes' => 'manifest.screenshot_hashes',
                        'anti_slop_report_hash' => 'manifest.anti_slop_report_hash',
                        'verification_hashes' => 'manifest.verification_hashes',
                        'evidence_pack_verification_hash' => $pack['verification_hash'] ?? null,
                    ],
                    'allowed_execution_surfaces' => [
                        'external_rival_system',
                        'manual_external_replay',
                    ],
                    'completion_steps' => [
                        'verify_evidence_pack_passed_before_receipt',
                        'verify_run_packet_hash_matches_runner_kit_when_present',
                        'run_external_rival_against_unchanged_task_spec',
                        'run_external_receipt_template_command_for_provider_safe_manifest_patch',
                        'fill_external_execution_receipt_without_raw_prompt_source_tokens_urls_or_provider_secrets',
                        'rerun_php_artisan_atlas_frontend_replay_inspect',
                    ],
                    'commands' => [
                        'write_external_receipt_template' => 'php artisan atlas:frontend:replay external-receipt-template --evidence='.$directory.' --case='.$caseId.' --system='.$system.' --json',
                    ],
                ];
            }

            if ($scoreIssues !== []) {
                $workItems[] = [
                    'id' => 'fill_score_attestation_'.$caseId.'_'.$system,
                    'case_id' => $caseId,
                    'system' => $system,
                    'status' => $run['status'] ?? 'invalid',
                    'blockers' => $scoreIssues,
                    'pack_manifest_ref' => $root.'/evidence/evidence-pack.json',
                    'run_manifest_ref' => $root.'/manifest.json',
                    'task_spec_ref' => $caseId.'/task-spec.json',
                    'run_packet_hash' => is_string($run['run_packet_hash'] ?? null) ? (string) $run['run_packet_hash'] : null,
                    'run_packet_hash_mapping' => 'manifest.run_packet_hash verified against runner_kit.run_packets[] when runner kit exists',
                    'score_attestation_schema_version' => self::SCORE_ATTESTATION_SCHEMA_VERSION,
                    'score_attestation_fields' => [
                        'schema_version',
                        'status',
                        'case_id',
                        'system',
                        'scoring_surface',
                        'reviewer_ref_hash',
                        'reviewed_at',
                        'operator_approved',
                        'rubric_hash',
                        'score_breakdown_hash',
                        'score_total',
                        'score_max',
                        'evidence_pack_verification_hash',
                        'reviewed_manifest_hashes',
                    ],
                    'score_attestation_hash_mapping' => [
                        'rubric_hash' => data_get($inspect, 'competitive_rubric.rubric_hash'),
                        'score_breakdown_hash' => 'canonical_sha256(manifest.score_breakdown)',
                        'score_total' => 'manifest.score_total',
                        'score_max' => 'manifest.score_max',
                        'evidence_pack_verification_hash' => $pack['verification_hash'] ?? null,
                        'reviewed_manifest_hashes' => 'manifest output/screenshot/anti_slop/verification/run_packet/evidence_pack hashes',
                        'reviewer_ref_hash' => 'sha256(provider_safe_reviewer_ref)',
                    ],
                    'allowed_scoring_surfaces' => [
                        'manual_competitive_review',
                        'independent_review_panel',
                        'atlas_review_panel',
                    ],
                    'completion_steps' => [
                        'verify_evidence_pack_passed_before_scoring',
                        'verify_run_packet_hash_matches_runner_kit_when_present',
                        'review_artifact_refs_against_competitive_rubric',
                        'run_score_template_command_for_provider_safe_manifest_patch',
                        'compute_score_breakdown_hash_from_manifest_score_breakdown',
                        'fill_score_attestation_without_raw_prompt_source_or_reviewer_identity',
                        'rerun_php_artisan_atlas_frontend_replay_inspect',
                    ],
                    'commands' => [
                        'write_score_template' => 'php artisan atlas:frontend:replay score-template --evidence='.$directory.' --case='.$caseId.' --system='.$system.' --json',
                    ],
                ];
            }
        }

        $payload = [
            'schema_version' => self::EVIDENCE_WORKLIST_SCHEMA_VERSION,
            'status' => $workItems === [] ? 'ready' : 'pending',
            'worklist_type' => 'rival_replay_evidence_pack_completion',
            'source' => self::class,
            'evidence_directory_hash' => hash('sha256', $directory),
            'replay_hash' => $inspect['replay_hash'] ?? null,
            'evidence_pack_readiness' => [
                'schema_version' => data_get($inspect, 'evidence_pack_readiness.schema_version'),
                'status' => data_get($inspect, 'evidence_pack_readiness.status'),
                'summary' => data_get($inspect, 'evidence_pack_readiness.summary'),
            ],
            'work_item_count' => count($workItems),
            'work_items' => $workItems,
            'output_ref_hash' => $target !== null ? hash('sha256', $target) : null,
            'write_performed' => $target !== null,
            'commands' => [
                'inspect' => 'php artisan atlas:frontend:replay inspect --evidence='.$directory.' --json',
                'write_worklist' => 'php artisan atlas:frontend:replay evidence-worklist --evidence='.$directory.' --output=<worklist.json> --json',
                'world_best_plan' => 'php artisan atlas:frontend:world-best-plan --rival-evidence='.$directory.' --json --strict',
            ],
            'claim_policy' => [
                'worklist_is_not_evidence' => true,
                'all_artifact_files_must_be_real_and_hash_verified' => true,
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
                'world_best_claim_forbidden_until_inspect_and_public_proof_pass' => true,
            ],
        ];
        $payload['worklist_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,string>  $case
     * @return array<string,mixed>
     */
    private function taskSpecForCase(string $directory, array $case): array
    {
        $path = $directory.'/'.$case['id'].'/task-spec.json';
        if (File::isFile($path)) {
            $taskSpec = json_decode(File::get($path), true);
            if (is_array($taskSpec)) {
                return $taskSpec;
            }
        }

        return $this->replayTaskSpec($case);
    }

    /**
     * @param  array<string,mixed>  $taskSpec
     */
    private function writePendingEvidencePack(string $directory, string $caseId, string $system, array $taskSpec): bool
    {
        $evidenceDirectory = $directory.'/'.$caseId.'/'.$system.'/evidence';
        $manifestPath = $evidenceDirectory.'/evidence-pack.json';

        File::ensureDirectoryExists($evidenceDirectory.'/artifacts');
        if (File::isFile($manifestPath)) {
            return false;
        }

        File::put($manifestPath, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'replace-with-run-id',
            'case_id' => $caseId,
            'system' => $system,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? '<sha256-64-hex>',
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => '<sha256-64-hex>',
            ], app(AtlasFrontendEvidencePackVerifierService::class)->requiredArtifactKinds()),
            'notes' => 'Fill artifact files and hashes only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return true;
    }

    private function writeRunPacketHashToManifest(string $directory, string $caseId, string $system, string $runPacketHash): void
    {
        $manifestPath = $directory.'/'.$caseId.'/'.$system.'/manifest.json';
        if (! File::isFile($manifestPath)) {
            return;
        }

        $manifest = json_decode(File::get($manifestPath), true);
        if (! is_array($manifest)) {
            return;
        }

        $manifest['run_packet_hash'] = $runPacketHash;
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function runnerKitPathForManifest(string $manifestPath): string
    {
        return dirname($manifestPath, 3).'/replay-runner-kit.json';
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function cases(): array
    {
        return [
            ['id' => 'saas_dashboard_repair', 'intent' => 'Repair dense SaaS dashboard UI without losing information density.'],
            ['id' => 'ecommerce_product_page', 'intent' => 'Create production-grade commerce product page with visual proof.'],
            ['id' => 'mobile_app_onboarding', 'intent' => 'Design mobile onboarding with responsive states and accessibility.'],
            ['id' => 'design_system_migration', 'intent' => 'Migrate UI to a company design system without drift.'],
            ['id' => 'live_mode_repair_loop', 'intent' => 'Use live browser selection, preview, accept/discard and source recovery.'],
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function systems(): array
    {
        return [
            ['id' => 'atlas_frontend', 'kind' => 'local_runtime'],
            ['id' => 'pbakaus_impeccable', 'kind' => 'external_rival'],
            ['id' => 'claude_design_plugin', 'kind' => 'external_rival'],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function requiredManifestFields(): array
    {
        return [
            'case_id',
            'system',
            'status',
            'run_id',
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
    }

    /**
     * @param  array<int,string>  $requiredFields
     * @return array<string,mixed>
     */
    private function inspectManifest(string $manifestPath, string $caseId, string $system, array $requiredFields): array
    {
        if (! File::isFile($manifestPath)) {
            return $this->runPayload($caseId, $system, 'missing', ['manifest_missing'], null);
        }

        $raw = File::get($manifestPath);
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return $this->runPayload($caseId, $system, 'invalid', ['manifest_json_invalid'], hash('sha256', $raw));
        }

        $issues = [];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $manifest) || $manifest[$field] === null || $manifest[$field] === '') {
                $issues[] = 'missing_'.$field;
            }
        }

        if (($manifest['case_id'] ?? null) !== $caseId) {
            $issues[] = 'case_id_mismatch';
        }
        if (($manifest['system'] ?? null) !== $system) {
            $issues[] = 'system_mismatch';
        }
        if (($manifest['status'] ?? null) !== 'complete') {
            $issues[] = 'status_not_complete';
        }
        $issues = array_merge($issues, $this->validateTaskSpecRef($manifest, $manifestPath, $caseId));
        $issues = array_merge($issues, $this->validateRunPacketHash($manifest, $manifestPath, $caseId, $system));
        $evidencePack = $this->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
        $issues = array_merge($issues, $evidencePack['issues']);
        $issues = array_merge($issues, $this->validateExternalExecutionReceipt($manifest, $caseId, $system, $evidencePack['verification_hash'] ?? null));
        if (! is_array($manifest['screenshot_hashes'] ?? null) || $manifest['screenshot_hashes'] === []) {
            $issues[] = 'screenshot_hashes_required';
        }
        if (! is_array($manifest['verification_hashes'] ?? null) || $manifest['verification_hashes'] === []) {
            $issues[] = 'verification_hashes_required';
        }
        if ($this->hasForbiddenRawFields($manifest)) {
            $issues[] = 'forbidden_raw_prompt_or_source_field_present';
        }
        if (! is_numeric($manifest['score_total'] ?? null) || ! is_numeric($manifest['score_max'] ?? null) || (int) $manifest['score_max'] <= 0) {
            $issues[] = 'invalid_score';
        }
        if (! is_array($manifest['score_breakdown'] ?? null)) {
            $issues[] = 'score_breakdown_required';
        } elseif (is_numeric($manifest['score_total'] ?? null) && is_numeric($manifest['score_max'] ?? null)) {
            $issues = array_merge($issues, app(AtlasFrontendCompetitiveRubricService::class)->validateBreakdown(
                $manifest['score_breakdown'],
                (int) $manifest['score_total'],
                (int) $manifest['score_max'],
            ));
        }
        $issues = array_merge($issues, $this->validateScoreAttestation($manifest, $caseId, $system, $evidencePack['verification_hash'] ?? null));

        $status = $issues === [] ? 'complete' : (in_array('status_not_complete', $issues, true) ? 'pending' : 'invalid');

        return $this->runPayload($caseId, $system, $status, $issues, hash('sha256', $raw), [
            'run_id_hash' => isset($manifest['run_id']) ? hash('sha256', (string) $manifest['run_id']) : null,
            'task_spec_hash' => is_string($manifest['task_spec_hash'] ?? null) ? (string) $manifest['task_spec_hash'] : null,
            'run_packet_hash' => is_string($manifest['run_packet_hash'] ?? null) ? (string) $manifest['run_packet_hash'] : null,
            'task_spec_ref' => is_string($manifest['task_spec_ref'] ?? null) ? (string) $manifest['task_spec_ref'] : null,
            'evidence_pack_ref' => is_string($manifest['evidence_pack_ref'] ?? null) ? (string) $manifest['evidence_pack_ref'] : null,
            'evidence_pack_verification_hash' => $evidencePack['verification_hash'] ?? null,
            'external_execution_receipt_hash' => is_array($manifest['external_execution_receipt'] ?? null)
                ? MissionCanonicalHash::sha256((array) $manifest['external_execution_receipt'])
                : null,
            'score_attestation_hash' => is_array($manifest['score_attestation'] ?? null)
                ? MissionCanonicalHash::sha256((array) $manifest['score_attestation'])
                : null,
            'score_total' => is_numeric($manifest['score_total'] ?? null) ? (int) $manifest['score_total'] : null,
            'score_max' => is_numeric($manifest['score_max'] ?? null) ? (int) $manifest['score_max'] : null,
            'score_breakdown' => is_array($manifest['score_breakdown'] ?? null) ? $manifest['score_breakdown'] : null,
            'completed_at' => is_string($manifest['completed_at'] ?? null) ? $manifest['completed_at'] : null,
        ]);
    }

    /**
     * @param  array<int,string>  $issues
     * @param  array<string,mixed>|null  $extra
     * @return array<string,mixed>
     */
    private function runPayload(string $caseId, string $system, string $status, array $issues, ?string $manifestHash, ?array $extra = null): array
    {
        return array_filter([
            'case_id' => $caseId,
            'system' => $system,
            'status' => $status,
            'manifest_hash' => $manifestHash,
            'issues' => $issues,
            'run_id_hash' => $extra['run_id_hash'] ?? null,
            'task_spec_hash' => $extra['task_spec_hash'] ?? null,
            'run_packet_hash' => $extra['run_packet_hash'] ?? null,
            'task_spec_ref' => $extra['task_spec_ref'] ?? null,
            'evidence_pack_ref' => $extra['evidence_pack_ref'] ?? null,
            'evidence_pack_verification_hash' => $extra['evidence_pack_verification_hash'] ?? null,
            'external_execution_receipt_hash' => $extra['external_execution_receipt_hash'] ?? null,
            'score_attestation_hash' => $extra['score_attestation_hash'] ?? null,
            'score_total' => $extra['score_total'] ?? null,
            'score_max' => $extra['score_max'] ?? null,
            'score_breakdown' => $extra['score_breakdown'] ?? null,
            'completed_at' => $extra['completed_at'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    private function competitiveDiagnostics(array $runs): array
    {
        $cases = [];
        foreach ($this->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id']);
            $completeRuns = $caseRuns->where('status', 'complete');
            if ($completeRuns->count() < count($this->systems())) {
                $cases[] = [
                    'case_id' => $case['id'],
                    'status' => 'pending',
                    'complete_run_count' => $completeRuns->count(),
                    'required_run_count' => count($this->systems()),
                    'next_action' => 'complete_external_rival_replay_manifests',
                ];

                continue;
            }

            $atlas = $completeRuns->firstWhere('system', 'atlas_frontend');
            $rivals = $completeRuns->reject(fn (array $run): bool => $run['system'] === 'atlas_frontend');
            $bestRival = $rivals->sortByDesc(fn (array $run): int => (int) ($run['score_total'] ?? -1))->first();
            $atlasScore = (int) data_get($atlas, 'score_total', 0);
            $bestRivalScore = (int) data_get($bestRival, 'score_total', 0);
            $delta = $atlasScore - $bestRivalScore;
            $hasDecisiveMargin = $delta >= self::DECISIVE_LEAD_MINIMUM_POINTS;
            $dimensionGaps = $this->dimensionGaps(
                is_array($atlas['score_breakdown'] ?? null) ? $atlas['score_breakdown'] : [],
                is_array($bestRival['score_breakdown'] ?? null) ? $bestRival['score_breakdown'] : [],
                $case['id'],
                (string) ($bestRival['system'] ?? ''),
            );

            $cases[] = [
                'case_id' => $case['id'],
                'status' => match (true) {
                    $hasDecisiveMargin && $dimensionGaps === [] => 'atlas_leads',
                    $delta > 0 && $dimensionGaps === [] => 'atlas_leads_without_decisive_margin',
                    $delta > 0 => 'atlas_leads_with_dimension_gaps',
                    $delta === 0 => 'atlas_tied_best',
                    default => 'atlas_loses',
                },
                'atlas_score' => $atlasScore,
                'best_rival_system' => $bestRival['system'] ?? null,
                'best_rival_score' => $bestRivalScore,
                'atlas_delta_vs_best_rival' => $delta,
                'minimum_points_to_match_best_rival' => max(0, $bestRivalScore - $atlasScore),
                'minimum_points_to_lead_best_rival' => max(0, $bestRivalScore - $atlasScore + 1),
                'minimum_decisive_lead_points' => self::DECISIVE_LEAD_MINIMUM_POINTS,
                'minimum_points_to_decisive_lead' => max(0, self::DECISIVE_LEAD_MINIMUM_POINTS - $delta),
                'dimension_gap_count' => count($dimensionGaps),
                'dimension_gaps' => $dimensionGaps,
                'recommended_repair_plan_commands' => array_values(array_filter(array_map(
                    fn (array $gap): ?string => is_string($gap['repair_plan_command'] ?? null) ? (string) $gap['repair_plan_command'] : null,
                    $dimensionGaps,
                ))),
                'next_action' => match (true) {
                    $delta <= 0 => 'improve_atlas_frontend_case_until_decisive_lead',
                    $dimensionGaps !== [] => 'improve_atlas_frontend_case_until_dimension_lead',
                    ! $hasDecisiveMargin => 'improve_atlas_frontend_case_until_minimum_decisive_margin',
                    default => 'preserve_case_evidence',
                },
            ];
        }

        $losing = collect($cases)->where('status', 'atlas_loses')->count();
        $tied = collect($cases)->where('status', 'atlas_tied_best')->count();
        $weakLead = collect($cases)->where('status', 'atlas_leads_without_decisive_margin')->count();
        $dimensionGapCases = collect($cases)->where('status', 'atlas_leads_with_dimension_gaps')->count();
        $pending = collect($cases)->where('status', 'pending')->count();

        return [
            'schema_version' => 'atlas.frontend.rival_replay_competitive_diagnostics.v1',
            'status' => match (true) {
                $losing > 0 => 'atlas_needs_improvement',
                $tied > 0 => 'atlas_needs_decisive_lead',
                $dimensionGapCases > 0 => 'atlas_needs_dimension_lead',
                $weakLead > 0 => 'atlas_needs_decisive_margin',
                $pending > 0 => 'pending_replay',
                default => 'atlas_leads_all_complete_cases',
            },
            'case_count' => count($cases),
            'losing_case_count' => $losing,
            'tied_case_count' => $tied,
            'weak_lead_case_count' => $weakLead,
            'dimension_gap_case_count' => $dimensionGapCases,
            'pending_case_count' => $pending,
            'cases' => $cases,
            'claim_policy' => [
                'diagnostics_are_not_market_claim_evidence' => true,
                'world_best_requires_no_losing_cases' => true,
                'world_best_requires_no_tied_cases' => true,
                'world_best_requires_decisive_lead_each_case' => true,
                'world_best_requires_minimum_decisive_lead_points' => self::DECISIVE_LEAD_MINIMUM_POINTS,
                'world_best_requires_no_dimension_gaps_against_best_rival' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $atlasBreakdown
     * @param  array<string,mixed>  $bestRivalBreakdown
     * @return array<int,array<string,mixed>>
     */
    private function dimensionGaps(array $atlasBreakdown, array $bestRivalBreakdown, string $caseId, string $bestRivalSystem): array
    {
        $rubricDimensions = app(AtlasFrontendCompetitiveRubricService::class)->rubric()['dimensions'];
        $gaps = [];

        foreach ($rubricDimensions as $dimension) {
            $id = (string) $dimension['id'];
            $atlas = (int) ($atlasBreakdown[$id] ?? 0);
            $rival = (int) ($bestRivalBreakdown[$id] ?? 0);
            $delta = $atlas - $rival;
            if ($delta >= 0) {
                continue;
            }

            $gaps[] = [
                'dimension' => $id,
                'case_id' => $caseId,
                'atlas_score' => $atlas,
                'best_rival_system' => $bestRivalSystem,
                'best_rival_score' => $rival,
                'delta_vs_best_rival' => $delta,
                'points_to_match' => abs($delta),
                'points_to_lead' => abs($delta) + 1,
                'target_score_to_match' => $rival,
                'target_score_to_lead' => $rival + 1,
                'weight' => (int) ($dimension['weight'] ?? 0),
                'lead_possible_within_rubric' => $rival < (int) ($dimension['weight'] ?? 0),
                'next_action' => 'improve_'.$id,
                'repair_plan_command' => sprintf(
                    'php artisan atlas:frontend:repair-plan --dimension-gap=%s:%d:%d:%d:%s:%s:%d:%d --json',
                    $id,
                    abs($delta),
                    $delta,
                    $rival,
                    $caseId,
                    $bestRivalSystem,
                    $atlas,
                    (int) ($dimension['weight'] ?? 0),
                ),
            ];
        }

        return collect($gaps)
            ->sortByDesc(fn (array $gap): int => (int) $gap['points_to_match'])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    private function scoreboard(array $runs): array
    {
        $scoreboard = [];
        foreach ($runs as $run) {
            $system = (string) $run['system'];
            $scoreboard[$system] ??= ['score' => 0, 'max' => 0, 'complete_runs' => 0];
            if (($run['status'] ?? null) !== 'complete') {
                continue;
            }

            $scoreboard[$system]['score'] += (int) ($run['score_total'] ?? 0);
            $scoreboard[$system]['max'] += (int) ($run['score_max'] ?? 0);
            $scoreboard[$system]['complete_runs']++;
        }

        foreach ($scoreboard as $system => $data) {
            $scoreboard[$system]['percent'] = round(((int) $data['score'] / max(1, (int) $data['max'])) * 100, 2);
        }

        return $scoreboard;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<int,array<string,mixed>>
     */
    private function applyFairnessGates(array $runs): array
    {
        foreach ($this->cases() as $case) {
            $evaluatedIndexes = [];
            foreach ($runs as $index => $run) {
                if (
                    ($run['case_id'] ?? null) === $case['id']
                    && isset($run['task_spec_hash'])
                    && in_array(($run['status'] ?? null), ['complete', 'invalid'], true)
                ) {
                    $evaluatedIndexes[] = $index;
                }
            }

            if (count($evaluatedIndexes) < count($this->systems())) {
                continue;
            }

            $taskSpecHashes = array_values(array_unique(array_map(
                fn (int $index): string => (string) ($runs[$index]['task_spec_hash'] ?? ''),
                $evaluatedIndexes,
            )));

            if (count($taskSpecHashes) > 1) {
                foreach ($evaluatedIndexes as $index) {
                    $runs[$index] = $this->invalidateRun($runs[$index], 'task_spec_hash_mismatch_across_systems');
                }
            }
        }

        return array_values($runs);
    }

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function invalidateRun(array $run, string $issue): array
    {
        $run['status'] = 'invalid';
        $run['issues'] = array_values(array_unique(array_merge((array) ($run['issues'] ?? []), [$issue])));

        return $run;
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    private function fairnessSummary(array $runs): array
    {
        $cases = [];
        foreach ($this->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id']);
            $complete = $caseRuns->where('status', 'complete')->count();
            $invalidIssues = $caseRuns
                ->flatMap(fn (array $run): array => (array) ($run['issues'] ?? []))
                ->filter(fn (mixed $issue): bool => is_string($issue) && str_contains($issue, 'mismatch_across_systems'))
                ->values()
                ->all();

            $cases[] = [
                'case_id' => $case['id'],
                'status' => $invalidIssues !== [] ? 'failed' : ($complete === count($this->systems()) ? 'passed' : 'pending'),
                'same_task_spec_hash_across_systems' => ! in_array('task_spec_hash_mismatch_across_systems', $invalidIssues, true),
                'issues' => array_values(array_unique($invalidIssues)),
            ];
        }

        return [
            'schema_version' => 'atlas.frontend.rival_replay_fairness.v1',
            'status' => collect($cases)->every(fn (array $case): bool => $case['status'] === 'passed')
                ? 'passed'
                : (collect($cases)->contains(fn (array $case): bool => $case['status'] === 'failed') ? 'failed' : 'pending'),
            'policy' => [
                'same_task_spec_hash_required_across_systems' => true,
                'same_rubric_required_across_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
                'score_attestation_required_for_each_complete_run' => true,
            ],
            'cases' => $cases,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     */
    private function atlasWinsAllCompleteCases(array $runs): bool
    {
        foreach ($this->cases() as $case) {
            $caseRuns = collect($runs)->where('case_id', $case['id'])->where('status', 'complete');
            if ($caseRuns->count() < count($this->systems())) {
                return false;
            }

            $atlasRun = $caseRuns->firstWhere('system', 'atlas_frontend');
            $atlas = (int) data_get($atlasRun, 'score_total', -1);
            $bestRivalRun = $caseRuns
                ->reject(fn (array $run): bool => $run['system'] === 'atlas_frontend')
                ->sortByDesc(fn (array $run): int => (int) ($run['score_total'] ?? -1))
                ->first();
            $bestRival = (int) data_get($bestRivalRun, 'score_total', -1);

            if (($atlas - $bestRival) < self::DECISIVE_LEAD_MINIMUM_POINTS) {
                return false;
            }

            $dimensionGaps = $this->dimensionGaps(
                is_array($atlasRun['score_breakdown'] ?? null) ? $atlasRun['score_breakdown'] : [],
                is_array($bestRivalRun['score_breakdown'] ?? null) ? $bestRivalRun['score_breakdown'] : [],
                $case['id'],
                (string) ($bestRivalRun['system'] ?? ''),
            );

            if ($dimensionGaps !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $evidencePackReadiness
     * @return array<int,string>
     */
    private function remainingGaps(bool $allRunsCompleted, bool $atlasWinsAllCompleteCases, array $evidencePackReadiness, array $competitiveDiagnostics): array
    {
        if (! $allRunsCompleted) {
            return [
                'external_rival_replay_artifacts_required_for_world_best_claim',
                ...($evidencePackReadiness['status'] === 'ready' ? [] : ['rival_replay_evidence_packs_incomplete']),
            ];
        }

        if ($atlasWinsAllCompleteCases) {
            return [];
        }

        return array_values(array_filter([
            ((int) ($competitiveDiagnostics['losing_case_count'] ?? 0)) > 0 ? 'atlas_does_not_win_every_complete_case' : null,
            ((int) ($competitiveDiagnostics['tied_case_count'] ?? 0)) > 0 ? 'atlas_does_not_lead_every_complete_case' : null,
            ((int) ($competitiveDiagnostics['weak_lead_case_count'] ?? 0)) > 0 ? 'atlas_lead_margin_below_decisive_threshold' : null,
            ((int) ($competitiveDiagnostics['dimension_gap_case_count'] ?? 0)) > 0 ? 'atlas_has_dimension_gaps_against_best_rival' : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function validateTaskSpecRef(array $manifest, string $manifestPath, string $caseId): array
    {
        $issues = [];
        $ref = is_string($manifest['task_spec_ref'] ?? null) ? (string) $manifest['task_spec_ref'] : '';

        if ($ref !== '../task-spec.json') {
            return ['task_spec_ref_must_be_case_canonical'];
        }

        $taskSpecPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$ref;
        if (! File::isFile($taskSpecPath)) {
            return ['task_spec_ref_missing'];
        }

        $taskSpec = json_decode(File::get($taskSpecPath), true);
        if (! is_array($taskSpec)) {
            return ['task_spec_ref_json_invalid'];
        }

        if (($taskSpec['schema_version'] ?? null) !== self::TASK_SPEC_SCHEMA_VERSION) {
            $issues[] = 'task_spec_ref_schema_invalid';
        }
        if (($taskSpec['case_id'] ?? null) !== $caseId) {
            $issues[] = 'task_spec_ref_case_mismatch';
        }
        if (! is_string($taskSpec['task_spec_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $taskSpec['task_spec_hash'])) {
            $issues[] = 'task_spec_ref_hash_invalid';
        } elseif (($manifest['task_spec_hash'] ?? null) !== $taskSpec['task_spec_hash']) {
            $issues[] = 'task_spec_hash_mismatch_with_task_spec_ref';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function validateRunPacketHash(array $manifest, string $manifestPath, string $caseId, string $system): array
    {
        $runnerKitPath = $this->runnerKitPathForManifest($manifestPath);
        if (! File::isFile($runnerKitPath)) {
            return [];
        }

        $runnerKit = json_decode(File::get($runnerKitPath), true);
        if (! is_array($runnerKit)) {
            return ['runner_kit_json_invalid'];
        }

        $packet = collect((array) ($runnerKit['run_packets'] ?? []))
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['case_id'] ?? null) === $caseId
                && ($candidate['system'] ?? null) === $system);

        if (! is_array($packet)) {
            return ['run_packet_missing_from_runner_kit'];
        }

        $expected = is_string($packet['run_packet_hash'] ?? null) ? (string) $packet['run_packet_hash'] : null;
        if ($expected === null || ! preg_match('/\A[a-f0-9]{64}\z/', $expected)) {
            return ['run_packet_hash_missing_from_runner_kit'];
        }

        if (($manifest['run_packet_hash'] ?? null) !== $expected) {
            return ['run_packet_hash_mismatch'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{issues: array<int,string>, verification_hash?: string}
     */
    private function validateEvidencePackRef(array $manifest, string $manifestPath, string $caseId, string $system): array
    {
        $ref = is_string($manifest['evidence_pack_ref'] ?? null) ? (string) $manifest['evidence_pack_ref'] : '';
        if ($ref === '' || str_starts_with($ref, '/') || str_contains($ref, '..')) {
            return ['issues' => ['evidence_pack_ref_invalid']];
        }

        $packPath = dirname($manifestPath).DIRECTORY_SEPARATOR.$ref;
        if (! File::isFile($packPath)) {
            return ['issues' => ['evidence_pack_ref_missing']];
        }

        $verification = app(AtlasFrontendEvidencePackVerifierService::class)->verify($packPath);
        $issues = [];
        if (($verification['status'] ?? null) !== 'passed') {
            $issues[] = 'evidence_pack_verification_failed';
        }
        if (($verification['case_id'] ?? null) !== $caseId) {
            $issues[] = 'evidence_pack_case_mismatch';
        }
        if (($verification['system'] ?? null) !== $system) {
            $issues[] = 'evidence_pack_system_mismatch';
        }
        if (($verification['task_spec_hash'] ?? null) !== ($manifest['task_spec_hash'] ?? null)) {
            $issues[] = 'evidence_pack_task_spec_hash_mismatch';
        }

        $artifactHashes = collect((array) ($verification['artifact_results'] ?? []))
            ->filter(fn (array $artifact): bool => ($artifact['status'] ?? null) === 'present')
            ->mapWithKeys(fn (array $artifact): array => [(string) ($artifact['kind'] ?? '') => (string) ($artifact['sha256'] ?? '')])
            ->all();

        if (($manifest['output_artifact_hash'] ?? null) !== ($artifactHashes['output_artifact'] ?? null)) {
            $issues[] = 'evidence_pack_output_artifact_hash_mismatch';
        }
        if (($manifest['anti_slop_report_hash'] ?? null) !== ($artifactHashes['anti_slop_report'] ?? null)) {
            $issues[] = 'evidence_pack_anti_slop_hash_mismatch';
        }
        if (! in_array($artifactHashes['screenshot_set'] ?? null, (array) ($manifest['screenshot_hashes'] ?? []), true)) {
            $issues[] = 'evidence_pack_screenshot_hash_missing_from_manifest';
        }
        if (! in_array($artifactHashes['verification_report'] ?? null, (array) ($manifest['verification_hashes'] ?? []), true)) {
            $issues[] = 'evidence_pack_verification_hash_missing_from_manifest';
        }

        return [
            'issues' => array_values(array_unique(array_merge($issues, array_map(
                fn (string $blocker): string => 'evidence_pack_'.$blocker,
                (array) ($verification['blockers'] ?? []),
            )))),
            'verification_hash' => is_string($verification['verification_hash'] ?? null) ? $verification['verification_hash'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function validateExternalExecutionReceipt(array $manifest, string $caseId, string $system, ?string $evidencePackVerificationHash): array
    {
        $isExternalRival = $system !== 'atlas_frontend';
        $receipt = $manifest['external_execution_receipt'] ?? null;

        if (! is_array($receipt)) {
            return $isExternalRival ? ['external_execution_receipt_required'] : [];
        }

        $issues = [];
        if (($receipt['schema_version'] ?? null) !== self::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION) {
            $issues[] = 'external_execution_receipt_schema_invalid';
        }
        if (($receipt['status'] ?? null) !== 'verified') {
            $issues[] = 'external_execution_receipt_status_not_verified';
        }
        if (($receipt['case_id'] ?? null) !== $caseId) {
            $issues[] = 'external_execution_receipt_case_mismatch';
        }
        if (($receipt['system'] ?? null) !== $system) {
            $issues[] = 'external_execution_receipt_system_mismatch';
        }
        if ((bool) ($receipt['operator_approved'] ?? false) !== true) {
            $issues[] = 'external_execution_receipt_operator_approval_missing';
        }
        if (! is_string($receipt['captured_at'] ?? null) || trim((string) $receipt['captured_at']) === '') {
            $issues[] = 'external_execution_receipt_captured_at_missing';
        }
        if (! in_array((string) ($receipt['execution_surface'] ?? ''), ['external_rival_system', 'manual_external_replay'], true)) {
            $issues[] = 'external_execution_receipt_surface_invalid';
        }
        if ($this->hasForbiddenRawFields($receipt)) {
            $issues[] = 'external_execution_receipt_forbidden_raw_prompt_or_source_field_present';
        }

        $receiptHashes = (array) ($receipt['manifest_hashes'] ?? []);
        if (($receiptHashes['output_artifact_hash'] ?? null) !== ($manifest['output_artifact_hash'] ?? null)) {
            $issues[] = 'external_execution_receipt_output_artifact_hash_mismatch';
        }
        if (($receiptHashes['anti_slop_report_hash'] ?? null) !== ($manifest['anti_slop_report_hash'] ?? null)) {
            $issues[] = 'external_execution_receipt_anti_slop_hash_mismatch';
        }
        if ((array) ($receiptHashes['screenshot_hashes'] ?? []) !== (array) ($manifest['screenshot_hashes'] ?? [])) {
            $issues[] = 'external_execution_receipt_screenshot_hashes_mismatch';
        }
        if ((array) ($receiptHashes['verification_hashes'] ?? []) !== (array) ($manifest['verification_hashes'] ?? [])) {
            $issues[] = 'external_execution_receipt_verification_hashes_mismatch';
        }
        if (($receiptHashes['evidence_pack_verification_hash'] ?? null) !== $evidencePackVerificationHash) {
            $issues[] = 'external_execution_receipt_evidence_pack_verification_hash_mismatch';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function validateScoreAttestation(array $manifest, string $caseId, string $system, ?string $evidencePackVerificationHash): array
    {
        $attestation = $manifest['score_attestation'] ?? null;
        if (! is_array($attestation)) {
            return ['score_attestation_required'];
        }

        $issues = [];
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $scoreBreakdown = is_array($manifest['score_breakdown'] ?? null) ? $manifest['score_breakdown'] : [];
        $scoreBreakdownHash = MissionCanonicalHash::sha256($scoreBreakdown);

        if (($attestation['schema_version'] ?? null) !== self::SCORE_ATTESTATION_SCHEMA_VERSION) {
            $issues[] = 'score_attestation_schema_invalid';
        }
        if (($attestation['status'] ?? null) !== 'verified') {
            $issues[] = 'score_attestation_status_not_verified';
        }
        if (($attestation['case_id'] ?? null) !== $caseId) {
            $issues[] = 'score_attestation_case_mismatch';
        }
        if (($attestation['system'] ?? null) !== $system) {
            $issues[] = 'score_attestation_system_mismatch';
        }
        if (($attestation['rubric_hash'] ?? null) !== ($rubric['rubric_hash'] ?? null)) {
            $issues[] = 'score_attestation_rubric_hash_mismatch';
        }
        if (($attestation['score_breakdown_hash'] ?? null) !== $scoreBreakdownHash) {
            $issues[] = 'score_attestation_breakdown_hash_mismatch';
        }
        if (($attestation['score_total'] ?? null) !== ($manifest['score_total'] ?? null)) {
            $issues[] = 'score_attestation_total_mismatch';
        }
        if (($attestation['score_max'] ?? null) !== ($manifest['score_max'] ?? null)) {
            $issues[] = 'score_attestation_max_mismatch';
        }
        if (($attestation['evidence_pack_verification_hash'] ?? null) !== $evidencePackVerificationHash) {
            $issues[] = 'score_attestation_evidence_pack_verification_hash_mismatch';
        }
        if (($attestation['reviewed_manifest_hashes'] ?? null) !== $this->scoreReviewedManifestHashes($manifest, $evidencePackVerificationHash)) {
            $issues[] = 'score_attestation_reviewed_manifest_hashes_mismatch';
        }
        if ((bool) ($attestation['operator_approved'] ?? false) !== true) {
            $issues[] = 'score_attestation_operator_approval_missing';
        }
        if (! is_string($attestation['reviewer_ref_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $attestation['reviewer_ref_hash'])) {
            $issues[] = 'score_attestation_reviewer_ref_hash_invalid';
        }
        if (! is_string($attestation['reviewed_at'] ?? null) || trim((string) $attestation['reviewed_at']) === '') {
            $issues[] = 'score_attestation_reviewed_at_missing';
        }
        if (! in_array((string) ($attestation['scoring_surface'] ?? ''), ['manual_competitive_review', 'independent_review_panel', 'atlas_review_panel'], true)) {
            $issues[] = 'score_attestation_surface_invalid';
        }
        if ($this->hasForbiddenRawFields($attestation)) {
            $issues[] = 'score_attestation_forbidden_raw_prompt_or_source_field_present';
        }

        return $issues;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function scoreReviewedManifestHashes(array $manifest, ?string $evidencePackVerificationHash): array
    {
        return [
            'output_artifact_hash' => is_string($manifest['output_artifact_hash'] ?? null) ? (string) $manifest['output_artifact_hash'] : null,
            'screenshot_hashes' => array_values((array) ($manifest['screenshot_hashes'] ?? [])),
            'anti_slop_report_hash' => is_string($manifest['anti_slop_report_hash'] ?? null) ? (string) $manifest['anti_slop_report_hash'] : null,
            'verification_hashes' => array_values((array) ($manifest['verification_hashes'] ?? [])),
            'run_packet_hash' => is_string($manifest['run_packet_hash'] ?? null) ? (string) $manifest['run_packet_hash'] : null,
            'evidence_pack_verification_hash' => $evidencePackVerificationHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function hasForbiddenRawFields(array $manifest): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data'];

        return $this->containsForbiddenKeyRecursive($manifest, $forbidden);
    }

    /**
     * @param  array<int,string>  $forbidden
     */
    private function containsForbiddenKeyRecursive(array $payload, array $forbidden): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(Str::snake($key), $forbidden, true)) {
                return true;
            }

            if (is_array($value) && $this->containsForbiddenKeyRecursive($value, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function pendingManifest(string $caseId, string $system, array $taskSpec): array
    {
        return [
            'case_id' => $caseId,
            'system' => $system,
            'status' => 'pending',
            'run_id' => null,
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'task_spec_ref' => '../task-spec.json',
            'evidence_pack_ref' => 'evidence/evidence-pack.json',
            'output_artifact_ref' => null,
            'output_artifact_hash' => null,
            'screenshot_hashes' => [],
            'anti_slop_report_hash' => null,
            'verification_hashes' => [],
            'external_execution_receipt' => $system !== 'atlas_frontend' ? [
                'schema_version' => self::EXTERNAL_EXECUTION_RECEIPT_SCHEMA_VERSION,
                'status' => 'pending',
                'case_id' => $caseId,
                'system' => $system,
                'execution_surface' => 'external_rival_system',
                'captured_at' => null,
                'operator_approved' => false,
                'manifest_hashes' => [
                    'output_artifact_hash' => null,
                    'screenshot_hashes' => [],
                    'anti_slop_report_hash' => null,
                    'verification_hashes' => [],
                ],
                'notes' => 'Fill with verified external execution metadata only. Do not include raw prompts, customer source, cookies, tokens or provider secrets.',
            ] : null,
            'score_breakdown' => $this->pendingScoreBreakdown(),
            'score_total' => null,
            'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
            'score_attestation' => [
                'schema_version' => self::SCORE_ATTESTATION_SCHEMA_VERSION,
                'status' => 'pending',
                'case_id' => $caseId,
                'system' => $system,
                'scoring_surface' => 'manual_competitive_review',
                'reviewer_ref_hash' => null,
                'reviewed_at' => null,
                'operator_approved' => false,
                'rubric_hash' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['rubric_hash'],
                'score_breakdown_hash' => null,
                'score_total' => null,
                'score_max' => app(AtlasFrontendCompetitiveRubricService::class)->rubric()['score_max'],
                'evidence_pack_verification_hash' => null,
                'reviewed_manifest_hashes' => [
                    'output_artifact_hash' => null,
                    'screenshot_hashes' => [],
                    'anti_slop_report_hash' => null,
                    'verification_hashes' => [],
                    'run_packet_hash' => null,
                    'evidence_pack_verification_hash' => null,
                ],
                'notes' => 'Fill after reviewing evidence against the shared rubric. Do not include raw prompts, customer source, cookies, tokens or provider secrets.',
            ],
            'completed_at' => null,
            'notes' => 'Do not store raw prompts, raw customer source, cookies, tokens or provider secrets in this manifest.',
        ];
    }

    /**
     * @param  array<string,string>  $case
     * @return array<string,mixed>
     */
    private function replayTaskSpec(array $case): array
    {
        $caseId = (string) $case['id'];
        $spec = [
            'schema_version' => self::TASK_SPEC_SCHEMA_VERSION,
            'case_id' => $caseId,
            'intent' => $case['intent'],
            'surface' => 'programming.frontend',
            'systems_under_test' => array_column($this->systems(), 'id'),
            'required_viewports' => ['mobile_390', 'tablet_768', 'desktop_1440'],
            'required_states' => $this->caseStates($caseId),
            'acceptance_criteria' => $this->caseAcceptanceCriteria($caseId),
            'evidence_requirements' => [
                'output_artifact_hash',
                'screenshot_hashes',
                'evidence_pack_ref',
                'anti_slop_report_hash',
                'verification_hashes',
                'competitive_score_breakdown',
                'verified_score_attestation',
            ],
            'fairness_policy' => [
                'same_task_spec_hash_required_for_all_systems' => true,
                'same_rubric_required_for_all_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
                'score_attestation_required_for_all_complete_runs' => true,
                'raw_prompts_customer_source_tokens_or_cookies_forbidden' => true,
            ],
        ];
        $spec['task_spec_hash'] = MissionCanonicalHash::sha256($spec);

        return $spec;
    }

    /**
     * @return array<int,string>
     */
    private function caseStates(string $caseId): array
    {
        return match ($caseId) {
            'saas_dashboard_repair' => ['loaded_dense_table', 'empty_state', 'error_state', 'filtering_active'],
            'ecommerce_product_page' => ['default_product', 'variant_selected', 'cart_feedback', 'mobile_checkout_entry'],
            'mobile_app_onboarding' => ['first_step', 'permission_prompt', 'form_error', 'completion_state'],
            'design_system_migration' => ['before_component_mapping', 'after_component_mapping', 'token_exception', 'responsive_regression_check'],
            'live_mode_repair_loop' => ['element_selected', 'variant_previewed', 'variant_accepted', 'source_recovered'],
            default => ['default', 'error', 'empty', 'responsive'],
        };
    }

    /**
     * @return array<int,string>
     */
    private function caseAcceptanceCriteria(string $caseId): array
    {
        return match ($caseId) {
            'saas_dashboard_repair' => [
                'Preserve information density while fixing hierarchy, spacing and scan paths.',
                'No text overlap, console errors, inaccessible controls or viewport-specific regression.',
            ],
            'ecommerce_product_page' => [
                'Show product, price, variant choice, trust proof and conversion action without generic hero filler.',
                'Mobile and desktop states must remain shoppable and visually coherent.',
            ],
            'mobile_app_onboarding' => [
                'Guide the user through clear steps with accessible copy, controls and error recovery.',
                'Respect mobile ergonomics and avoid decorative UI that hides task progress.',
            ],
            'design_system_migration' => [
                'Use existing tokens and components unless an exception is explicitly evidenced.',
                'Prevent visual drift while preserving the product workflow.',
            ],
            'live_mode_repair_loop' => [
                'Support visual selection, variant preview, accept/discard and source recovery with audit hashes.',
                'Do not apply source patches when the file changed after prepare.',
            ],
            default => [
                'Satisfy product intent with responsive, accessible and evidence-backed frontend output.',
            ],
        };
    }

    /**
     * @return array<int,string>
     */
    private function runnerSteps(string $system): array
    {
        $dispatch = $system === 'atlas_frontend'
            ? 'Run Atlas Frontend using the referenced task spec through the normal governed frontend flow.'
            : 'Run the external rival manually or through its official workflow using the referenced task spec unchanged.';

        return [
            'Open task_spec_ref and keep the task_spec_hash unchanged.',
            $dispatch,
            'Capture output artifact ref/hash, screenshots, anti-slop report and verification hashes.',
            'Score the output with atlas.frontend.competitive_rubric.v1 without using provider brand as a score dimension.',
            'Fill manifest_ref with status=complete only after all required fields are backed by hashes or refs.',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function runnerEvidenceChecklist(): array
    {
        return [
            'task_spec_hash_matches_case_task_spec',
            'evidence_pack_ref_verified',
            'external_rival_execution_receipt_verified',
            'output_artifact_ref_present',
            'output_artifact_hash_present',
            'screenshot_hashes_present',
            'anti_slop_report_hash_present',
            'verification_hashes_present',
            'score_breakdown_present_and_valid',
            'completed_at_present',
            'no_raw_prompt_source_customer_data_tokens_or_cookies',
        ];
    }

    /**
     * @return array<string,null>
     */
    private function pendingScoreBreakdown(): array
    {
        return collect(app(AtlasFrontendCompetitiveRubricService::class)->rubric()['dimensions'])
            ->mapWithKeys(fn (array $dimension): array => [(string) $dimension['id'] => null])
            ->all();
    }

    private function evidenceDirectory(?string $directory): string
    {
        $directory = trim((string) $directory);

        return $directory !== '' ? rtrim($directory, DIRECTORY_SEPARATOR) : storage_path('app/atlas/frontend-rival-replay');
    }

    private function worklistOutputPath(string $directory, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/replay-evidence-worklist.json';
    }

    private function proofContractOutputPath(string $directory, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/replay-competitive-proof-contract.json';
    }

    private function operatorPacketOutputPath(string $directory, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/replay-operator-packet.json';
    }

    private function proofBundleOutputPath(string $directory, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/replay-competitive-proof-bundle.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (! File::isFile($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function embeddedHashMatches(array $payload, string $hashField): bool
    {
        $hash = $payload[$hashField] ?? null;
        if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
            return false;
        }

        $candidate = $payload;
        unset($candidate[$hashField]);

        return hash_equals($hash, MissionCanonicalHash::sha256($candidate));
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $referencedPayload
     */
    private function referencedHashMatches(array $packet, string $packetField, array $referencedPayload, string $referencedHashField): bool
    {
        $packetHash = $packet[$packetField] ?? null;
        $referencedHash = $referencedPayload[$referencedHashField] ?? null;

        return is_string($packetHash)
            && is_string($referencedHash)
            && preg_match('/\A[a-f0-9]{64}\z/', $packetHash)
            && hash_equals($packetHash, $referencedHash)
            && $this->embeddedHashMatches($referencedPayload, $referencedHashField);
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function packetUsesReplayEvidencePlaceholder(array $packet): bool
    {
        $encoded = json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        return str_contains($encoded, '${ATLAS_FRONTEND_REPLAY_EVIDENCE}')
            && str_contains((string) data_get($packet, 'commands.inspect', ''), '${ATLAS_FRONTEND_REPLAY_EVIDENCE}')
            && collect((array) ($packet['external_runs'] ?? []))->every(
                fn (mixed $run): bool => is_array($run)
                    && str_contains((string) data_get($run, 'commands.external_receipt_template', ''), '${ATLAS_FRONTEND_REPLAY_EVIDENCE}')
                    && str_contains((string) data_get($run, 'commands.score_template', ''), '${ATLAS_FRONTEND_REPLAY_EVIDENCE}'),
            );
    }

    private function scoreAttestationOutputPath(string $directory, string $caseId, string $system, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/'.$caseId.'/'.$system.'/score-attestation-template.json';
    }

    private function externalReceiptOutputPath(string $directory, string $caseId, string $system, ?string $outputPath): string
    {
        $outputPath = trim((string) $outputPath);

        return $outputPath !== ''
            ? $outputPath
            : $directory.'/'.$caseId.'/'.$system.'/external-execution-receipt-template.json';
    }

    private function safeManifestRef(string $ref): string
    {
        $ref = trim(str_replace('\\', '/', $ref));
        if ($ref === '' || str_starts_with($ref, '/') || str_contains($ref, '..')) {
            return '';
        }

        $ref = implode('/', array_filter(explode('/', $ref), fn (string $part): bool => $part !== ''));

        return str_ends_with($ref, '/manifest.json') ? $ref : '';
    }
}
