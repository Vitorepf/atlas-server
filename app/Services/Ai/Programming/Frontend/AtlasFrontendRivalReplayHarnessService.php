<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Frontend\RivalReplay\RivalReplayDiagnosticsSection;
use App\Services\Ai\Programming\Frontend\RivalReplay\RivalReplaySupport;
use App\Services\Ai\Programming\Frontend\RivalReplay\RivalReplayTemplateSection;
use App\Services\Ai\Programming\Frontend\RivalReplay\RivalReplayValidationSection;
use Illuminate\Support\Facades\File;

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

    public function __construct(
        private readonly RivalReplaySupport $support,
        private readonly RivalReplayValidationSection $validationSection,
        private readonly RivalReplayDiagnosticsSection $diagnosticsSection,
        private readonly RivalReplayTemplateSection $templateSection,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(?string $evidenceDirectory = null): array
    {
        $directory = $this->evidenceDirectory($evidenceDirectory);
        $cases = $this->support->cases();
        $systems = $this->support->systems();
        $requiredFields = $this->requiredManifestFields();
        $rubric = app(AtlasFrontendCompetitiveRubricService::class)->rubric();
        $runs = [];
        foreach ($cases as $case) {
            foreach ($systems as $system) {
                $manifestPath = $directory.'/'.$case['id'].'/'.$system['id'].'/manifest.json';
                $run = $this->validationSection->inspectManifest($manifestPath, $case['id'], $system['id'], $requiredFields);
                $runs[] = $run;
            }
        }

        $runs = $this->diagnosticsSection->applyFairnessGates($runs);
        $complete = collect($runs)->where('status', 'complete')->count();
        $invalid = collect($runs)->where('status', 'invalid')->count();
        $missing = count($runs) - $complete - $invalid;
        $externalRuns = array_values(array_filter($runs, fn (array $run): bool => $run['system'] !== 'atlas_frontend'));
        $externalReplayCompleted = $externalRuns !== [] && collect($externalRuns)->every(fn (array $run): bool => $run['status'] === 'complete');
        $allRunsCompleted = $runs !== [] && collect($runs)->every(fn (array $run): bool => $run['status'] === 'complete');
        $scoreboard = $this->diagnosticsSection->scoreboard($runs);
        $fairness = $this->diagnosticsSection->fairnessSummary($runs);
        $evidencePackReadiness = $this->diagnosticsSection->evidencePackReadiness($directory);
        $atlasWinsAllCompleteCases = $this->diagnosticsSection->atlasWinsAllCompleteCases($runs);
        $competitiveDiagnostics = $this->diagnosticsSection->competitiveDiagnostics($runs);

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
            'competitive_proof_contract' => $this->diagnosticsSection->competitiveProofContract(
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
            'remaining_gaps' => $this->diagnosticsSection->remainingGaps($allRunsCompleted, $atlasWinsAllCompleteCases, $evidencePackReadiness, $competitiveDiagnostics),
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $directory = $this->evidenceDirectory($outputDirectory);
        $created = [];

        $createdTaskSpecs = [];

        foreach ($this->support->cases() as $case) {
            $taskSpec = $this->templateSection->replayTaskSpec($case);
            $taskSpecPath = $directory.'/'.$case['id'].'/task-spec.json';
            File::ensureDirectoryExists(dirname($taskSpecPath));
            if (! File::isFile($taskSpecPath)) {
                File::put($taskSpecPath, json_encode($taskSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                $createdTaskSpecs[] = $case['id'].'/task-spec.json';
            }

            foreach ($this->support->systems() as $system) {
                $runDirectory = $directory.'/'.$case['id'].'/'.$system['id'];
                File::ensureDirectoryExists($runDirectory);
                $manifestPath = $runDirectory.'/manifest.json';

                if (! File::isFile($manifestPath)) {
                    File::put($manifestPath, json_encode($this->templateSection->pendingManifest($case['id'], $system['id'], $taskSpec), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
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

        foreach ($this->support->cases() as $case) {
            $taskSpec = $this->templateSection->taskSpecForCase($directory, $case);
            foreach ($this->support->systems() as $system) {
                $evidencePackRef = $case['id'].'/'.$system['id'].'/evidence/evidence-pack.json';
                if ($this->templateSection->writePendingEvidencePack($directory, $case['id'], $system['id'], $taskSpec)) {
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
                    'execution_steps' => $this->templateSection->runnerSteps($system['id']),
                    'evidence_checklist' => $this->templateSection->runnerEvidenceChecklist(),
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
                $this->templateSection->writeRunPacketHashToManifest($directory, $case['id'], $system['id'], $packet['run_packet_hash']);
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
        $knownCaseIds = array_column($this->support->cases(), 'id');
        $knownSystemIds = array_column($this->support->systems(), 'id');

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
            $evidencePack = $this->validationSection->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
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
            'reviewed_manifest_hashes' => $this->support->scoreReviewedManifestHashes($manifest, $evidencePack['verification_hash'] ?? null),
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
                ? ['review_artifact_refs_against_rubric', 'set_verified_reviewed_at_and_operator_approval', 'apply_manifest_patch_with_atlas_frontend_replay_apply_patch', 'rerun_replay_inspect']
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
        $knownCaseIds = array_column($this->support->cases(), 'id');
        $knownSystemIds = array_column($this->support->systems(), 'id');
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
            $evidencePack = $this->validationSection->validateEvidencePackRef($manifest, $manifestPath, $caseId, $system);
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
                ? ['run_external_rival_against_unchanged_task_spec', 'verify_manifest_hashes_match_artifacts', 'set_verified_captured_at_and_operator_approval', 'apply_manifest_patch_with_atlas_frontend_replay_apply_patch', 'rerun_replay_inspect']
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
        if ($blockers === [] && $this->support->hasForbiddenRawFields($manifestPatch)) {
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
            $evidencePack = $this->validationSection->validateEvidencePackRef($candidate, $manifestPath, $caseId, $system);
            $evidencePackHash = $evidencePack['verification_hash'] ?? null;
            if (($evidencePack['issues'] ?? []) !== []) {
                $patchIssues = array_merge($patchIssues, (array) $evidencePack['issues']);
            }
            if (in_array('external_execution_receipt', $appliedKeys, true)) {
                if ($system === 'atlas_frontend') {
                    $patchIssues[] = 'external_rival_system_required';
                }
                $patchIssues = array_merge($patchIssues, $this->validationSection->validateExternalExecutionReceipt($candidate, $caseId, $system, $evidencePackHash));
            }
            if (in_array('score_attestation', $appliedKeys, true)) {
                $patchIssues = array_merge($patchIssues, $this->validationSection->validateScoreAttestation($candidate, $caseId, $system, $evidencePackHash));
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
            ? $this->validationSection->inspectManifest($manifestPath, $caseId, $system, $this->requiredManifestFields())
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
                        'apply_manifest_patch_with_atlas_frontend_replay_apply_patch',
                        'rerun_php_artisan_atlas_frontend_replay_inspect',
                    ],
                    'commands' => [
                        'write_external_receipt_template' => 'php artisan atlas:frontend:replay external-receipt-template --evidence='.$directory.' --case='.$caseId.' --system='.$system.' --json',
                        'apply_manifest_patch' => 'php artisan atlas:frontend:replay apply-patch --evidence='.$directory.' --patch=<filled-template.json> --json',
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
                        'apply_manifest_patch_with_atlas_frontend_replay_apply_patch',
                        'rerun_php_artisan_atlas_frontend_replay_inspect',
                    ],
                    'commands' => [
                        'write_score_template' => 'php artisan atlas:frontend:replay score-template --evidence='.$directory.' --case='.$caseId.' --system='.$system.' --json',
                        'apply_manifest_patch' => 'php artisan atlas:frontend:replay apply-patch --evidence='.$directory.' --patch=<filled-template.json> --json',
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
