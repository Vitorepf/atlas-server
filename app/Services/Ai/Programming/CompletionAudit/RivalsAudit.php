<?php

namespace App\Services\Ai\Programming\CompletionAudit;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseRubricService;

class RivalsAudit
{
    public function __construct(
        private readonly AtlasForgeNativeRivalsCaseManifestService $forgeNativeRivalsCaseManifest,
        private readonly AtlasForgeNativeRivalsDryRunService $forgeNativeRivalsDryRun,
        private readonly AtlasRivalsOneShotEnterpriseRubricService $oneShotEnterpriseRubric,
        private readonly AtlasRivalsOneShotEnterpriseEvaluationService $oneShotEnterpriseEvaluation,
        private readonly AtlasRivalsEvidencePackService $evidencePack,
        private readonly AtlasRivalsEvidencePackVerifierService $evidencePackVerifier,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function rivalsInvalidBatteryQuarantineCovered(string $root): array
    {
        $fairPath = 'app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php';
        $wrapperPath = 'app/Console/Commands/AtlasRivalsCommand.php';
        $servicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';
        $fairSource = file_exists($root.DIRECTORY_SEPARATOR.$fairPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$fairPath) : '';
        $wrapperSource = file_exists($root.DIRECTORY_SEPARATOR.$wrapperPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$wrapperPath) : '';
        $serviceSource = file_exists($root.DIRECTORY_SEPARATOR.$servicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$servicePath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'fair_command_exists' => class_exists('App\\Console\\Commands\\AtlasEngineeringBenchmarkFairCommand'),
            'wrapper_command_exists' => class_exists('App\\Console\\Commands\\AtlasRivalsCommand'),
            'canonical_action_registered' => str_contains($fairSource, 'triage-invalid-battery'),
            'wrapper_action_registered' => str_contains($wrapperSource, 'triage-invalid-battery'),
            'requires_human_reason' => str_contains($fairSource, '--reason=<human-triage-reason>')
                || str_contains($fairSource, "'--reason'"),
            'requires_quarantine_confirmation' => str_contains($fairSource, 'confirm-invalid-battery-quarantine'),
            'declares_no_provider_call' => str_contains($fairSource, "'no_provider_call' => true"),
            'declares_no_score_admitted' => str_contains($fairSource, "'no_score_admitted' => true"),
            'declares_no_history_deleted' => str_contains($fairSource, "'no_history_deleted' => true"),
            'stores_fingerprint' => str_contains($fairSource, 'invalid_battery_fingerprint'),
            'stores_suite_triage_record' => str_contains($fairSource, 'rivals_invalid_battery_triage'),
            'stores_multiple_triage_fingerprints' => str_contains($fairSource, 'accepted_fingerprints')
                && str_contains($fairSource, 'record_count')
                && str_contains($serviceSource, 'accepted_fingerprints')
                && str_contains($serviceSource, 'accepted_record_count'),
            'service_keeps_quarantined_cases_out_of_score' => str_contains($serviceSource, 'triaged_invalid_batteries_remain_excluded_from_score')
                && str_contains($serviceSource, 'quarantined_cases_stay_out_of_win_loss_math'),
            'feature_test_covers_canonical_command' => str_contains($testSource, 'test_invalid_fair_battery_can_be_quarantined_without_admitting_score_or_deleting_history'),
            'feature_test_covers_wrapper_command' => str_contains($testSource, 'test_atlas_rivals_wrapper_can_quarantine_invalid_battery_without_provider_call'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$fairPath, $wrapperPath, $servicePath, $testPath],
            'missing_files' => collect([$fairPath, $wrapperPath, $servicePath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function rivalsHistoryTimelineCovered(string $root): array
    {
        $servicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';
        $serviceSource = file_exists($root.DIRECTORY_SEPARATOR.$servicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$servicePath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'service_exists' => class_exists('App\\Services\\Engineering\\EngineeringBenchmarkService'),
            'source_exists' => $serviceSource !== '',
            'history_timeline_schema_declared' => str_contains($serviceSource, 'atlas.fair_claude.history_timeline.v1'),
            'summary_exposes_run_count' => str_contains($serviceSource, "'run_count'"),
            'summary_exposes_comparable_case_count' => str_contains($serviceSource, "'comparable_case_count'"),
            'summary_exposes_invalid_case_count' => str_contains($serviceSource, "'invalid_case_count'"),
            'timeline_exposes_result_integrity_status' => str_contains($serviceSource, "'result_integrity_status'"),
            'timeline_exposes_score_admission' => str_contains($serviceSource, "'score_admitted'"),
            'entry_exposes_claim_winner_admission' => str_contains($serviceSource, "'claim_winner_admitted'"),
            'entry_exposes_blocking_reasons' => str_contains($serviceSource, "'blocking_reasons'"),
            'export_bundle_writes_history_timeline_file' => str_contains($serviceSource, 'history-timeline.json'),
            'export_verifier_requires_history_timeline_file' => str_contains($serviceSource, "'history-timeline.json',"),
            'feature_test_covers_timeline_schema' => str_contains($testSource, "history_timeline.schema_version', 'atlas.fair_claude.history_timeline.v1'"),
            'feature_test_covers_history_timeline_export' => str_contains($testSource, 'history-timeline.json'),
            'feature_test_covers_comparable_history' => str_contains($testSource, "history_timeline.entries.0.result_integrity_status', 'comparable_score_blocked'"),
            'feature_test_covers_invalid_history' => str_contains($testSource, "history_timeline.entries.0.result_integrity_status', 'invalid_battery_no_comparable_score'"),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$servicePath, $testPath],
            'missing_files' => collect([$servicePath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function rivalsProviderRuntimePreflightCovered(string $root): array
    {
        $fairPath = 'app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php';
        $baseCommandPath = 'app/Console/Commands/AtlasEngineeringBenchmarkCommand.php';
        $benchmarkServicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $runnerPath = 'app/Services/Engineering/EngineeringHarnessRunnerService.php';
        $controlPath = 'app/Services/Engineering/EngineeringControlRegistryService.php';
        $seedPath = 'app/Console/Commands/AtlasEngineeringBenchmarkSeedCommand.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';

        $fairSource = file_exists($root.DIRECTORY_SEPARATOR.$fairPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$fairPath) : '';
        $baseCommandSource = file_exists($root.DIRECTORY_SEPARATOR.$baseCommandPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$baseCommandPath) : '';
        $benchmarkSource = file_exists($root.DIRECTORY_SEPARATOR.$benchmarkServicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$benchmarkServicePath) : '';
        $runnerSource = file_exists($root.DIRECTORY_SEPARATOR.$runnerPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$runnerPath) : '';
        $controlSource = file_exists($root.DIRECTORY_SEPARATOR.$controlPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$controlPath) : '';
        $seedSource = file_exists($root.DIRECTORY_SEPARATOR.$seedPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$seedPath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'fair_command_exists' => class_exists('App\\Console\\Commands\\AtlasEngineeringBenchmarkFairCommand'),
            'base_command_accepts_provider_timeout' => str_contains($baseCommandSource, '--provider-timeout='),
            'benchmark_forwards_provider_timeout' => str_contains($benchmarkSource, 'provider_timeout_seconds'),
            'runner_applies_provider_timeout' => str_contains($runnerSource, "'timeout_seconds' =>")
                && str_contains($runnerSource, "providerOptions['timeout_seconds']"),
            'fair_runbook_includes_provider_timeout' => str_contains($fairSource, '--provider-timeout=')
                && str_contains($fairSource, '600'),
            'runtime_preflight_declared' => str_contains($fairSource, 'laravel_runtime_preflight_required'),
            'blocks_missing_vendor_autoload' => str_contains($fairSource, 'atlas_workspace_vendor_autoload_missing')
                && str_contains($fairSource, 'claude_code_baseline_vendor_autoload_missing'),
            'blocks_missing_env' => str_contains($fairSource, 'atlas_workspace_env_missing')
                && str_contains($fairSource, 'claude_code_baseline_env_missing'),
            'binary_detector_trims_and_resolves_path' => str_contains($fairSource, 'trim($binary)')
                && str_contains($fairSource, 'realpath($binary)'),
            'pint_uses_high_memory' => str_contains($controlSource, 'memory_limit=1024M')
                && str_contains($controlSource, 'vendor/bin/pint --test'),
            'fair_replay_case_has_strict_scope' => str_contains($seedSource, "'strict_file_scope' => true")
                && str_contains($seedSource, 'fair_benchmark_replay_packet_integrity')
                && str_contains($seedSource, 'app/Services/Engineering/EngineeringBenchmarkService.php'),
            'feature_test_covers_runtime_preflight' => str_contains($testSource, 'test_fair_claude_runbook_blocks_laravel_workspaces_without_runtime_artifacts_before_provider_spend'),
            'feature_test_covers_high_memory_pint' => str_contains($testSource, 'test_laravel_pint_control_uses_high_memory_php_invocation'),
            'feature_test_covers_strict_replay_scope' => str_contains($testSource, 'strict_file_scope')
                && str_contains($testSource, 'fair_benchmark_replay_packet_integrity'),
        ];

        $files = [$fairPath, $baseCommandPath, $benchmarkServicePath, $runnerPath, $controlPath, $seedPath, $testPath];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => $files,
            'missing_files' => collect($files)
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function rivalsExperimentValidityContractCovered(string $root): array
    {
        $servicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';
        $serviceSource = file_exists($root.DIRECTORY_SEPARATOR.$servicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$servicePath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'service_exists' => class_exists('App\\Services\\Engineering\\EngineeringBenchmarkService'),
            'source_exists' => $serviceSource !== '',
            'experiment_validity_schema_declared' => str_contains($serviceSource, 'atlas.fair_claude.experiment_validity.v1'),
            'same_case_snapshot_required' => str_contains($serviceSource, "'same_case_snapshot_required' => true"),
            'equivalent_initial_state_required' => str_contains($serviceSource, "'equivalent_initial_state_required' => true"),
            'same_acceptance_gates_required' => str_contains($serviceSource, "'same_acceptance_gates_required' => true"),
            'no_provider_specific_case_filtering' => str_contains($serviceSource, "'no_provider_specific_case_filtering' => true"),
            'external_variables_cannot_decide_winner' => str_contains($serviceSource, "'non_evaluated_variables_cannot_decide_winner' => true"),
            'external_variables_only_block_comparability' => str_contains($serviceSource, "'non_evaluated_variables_can_only_block_comparability' => true"),
            'invalid_cases_excluded_from_score' => str_contains($serviceSource, "'invalid_cases_excluded_from_win_loss_math' => true"),
            'export_verifier_requires_experiment_validity' => str_contains($serviceSource, 'experiment_validity_schema_invalid')
                && str_contains($serviceSource, 'claim_markdown_experiment_validity_missing'),
            'feature_test_covers_experiment_validity_schema' => str_contains($testSource, "result_integrity.experiment_validity.schema_version', 'atlas.fair_claude.experiment_validity.v1'"),
            'feature_test_covers_export_semantic_check' => str_contains($testSource, 'semantic_checks.claim_markdown_contains_experiment_validity'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$servicePath, $testPath],
            'missing_files' => collect([$servicePath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * Rivals One-Shot Enterprise Evaluation certification.
     *
     * Reports the canonical rubric + evaluation infrastructure for scoring
     * one-shot enterprise deliveries. This block is **diagnostic only** —
     * it never changes `completion_allowed`, never unblocks
     * `external_rivals_certification`, and never promotes the Rivals claim.
     *
     * @return array<string,mixed>
     */
    public function rivalsOneShotEnterpriseEvaluationCertification(string $workspace): array
    {
        $rubric = $this->oneShotEnterpriseRubric->rubric();
        $caseManifest = $this->forgeNativeRivalsCaseManifest->manifest(null);
        $dryRun = $this->forgeNativeRivalsDryRun->dryRun(['workspace' => $workspace]);
        $replayManifest = $dryRun['replay_manifest'] ?? data_get($dryRun, 'planned.replay_manifest');
        $fixtureEvaluation = $this->oneShotEnterpriseEvaluation->evaluate([
            'replay_manifest' => $replayManifest,
            'case_manifest' => $caseManifest,
            'evidence_pack' => [
                'preflight_status' => data_get($dryRun, 'planned.preflight_status'),
                'canonical_docs_consulted' => array_values((array) data_get($dryRun, 'preflight.checks.canonical_docs.present_docs', [])),
                'canonical_docs_required' => true,
                'tests_present' => null,
            ],
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);

        $docPath = $workspace.DIRECTORY_SEPARATOR.'docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md';
        $docAvailable = is_file($docPath);

        $rubricAvailable = ($rubric['schema_version'] ?? null) === AtlasRivalsOneShotEnterpriseRubricService::SCHEMA_VERSION
            && (int) ($rubric['score_weights_total'] ?? 0) === 100;
        $evaluationServiceAvailable = class_exists(AtlasRivalsOneShotEnterpriseEvaluationService::class)
            && ($fixtureEvaluation['schema_version'] ?? null) === AtlasRivalsOneShotEnterpriseEvaluationService::SCHEMA_VERSION;
        $localFixturePassed = ! in_array(
            $fixtureEvaluation['grade'] ?? AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID,
            [AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID],
            true,
        );

        $dimensionIds = array_map(static fn (array $d): string => (string) $d['id'], (array) $rubric['scoring_dimensions']);
        $evaluates = static fn (string $id): bool => in_array($id, $dimensionIds, true);

        $missingArtifacts = [];
        if (! $rubricAvailable) {
            $missingArtifacts[] = 'rubric_missing_or_invalid';
        }
        if (! $evaluationServiceAvailable) {
            $missingArtifacts[] = 'evaluation_service_missing';
        }
        if (! $docAvailable) {
            $missingArtifacts[] = 'doc_missing';
        }

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            (int) ($rubric['score_weights_total'] ?? 0) !== 100 => 'blocked',
            (bool) ($fixtureEvaluation['external_provider_call'] ?? true) === true => 'blocked',
            (bool) ($fixtureEvaluation['promotes_external_rivals_claim'] ?? true) === true => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.programming.rivals_one_shot_enterprise_evaluation_certification.v1',
            'rubric_id' => AtlasRivalsOneShotEnterpriseRubricService::RUBRIC_ID,
            'status' => $status,
            'rubric_available' => $rubricAvailable,
            'evaluation_service_available' => $evaluationServiceAvailable,
            'doc_available' => $docAvailable,
            'score_dimensions_count' => (int) ($rubric['score_dimensions_count'] ?? 0),
            'score_weights_total' => (int) ($rubric['score_weights_total'] ?? 0),
            'primary_objective' => AtlasRivalsOneShotEnterpriseRubricService::PRIMARY_OBJECTIVE,
            'speed_is_secondary' => true,
            'time_cannot_compensate_quality' => true,
            'quality_can_compensate_time' => true,
            'evaluates_business_rule_alignment' => $evaluates('business_rule_alignment'),
            'evaluates_canonical_documentation_adherence' => $evaluates('canonical_documentation_adherence'),
            'evaluates_one_shot_completeness' => $evaluates('one_shot_completeness'),
            'evaluates_functional_correctness' => $evaluates('functional_correctness'),
            'evaluates_tests_and_risk_coverage' => $evaluates('real_tests_and_risk_coverage'),
            'evaluates_enterprise_architecture' => $evaluates('enterprise_architecture'),
            'evaluates_forge_governance' => $evaluates('forge_governance'),
            'evaluates_operational_safety' => $evaluates('operational_safety'),
            'evaluates_implementation_quality' => $evaluates('implementation_quality'),
            'evaluates_operator_experience' => $evaluates('operator_experience'),
            'evaluates_observability_and_evidence' => $evaluates('observability_and_evidence'),
            'evaluates_human_intervention_load' => $evaluates('autonomy_and_intervention_load'),
            'evaluates_time_and_cost_efficiency' => $evaluates('time_and_cost_efficiency'),
            'local_fixture_evaluation_passed' => $localFixturePassed,
            'local_fixture_evaluation' => [
                'schema_version' => $fixtureEvaluation['schema_version'] ?? null,
                'status' => $fixtureEvaluation['status'] ?? null,
                'grade' => $fixtureEvaluation['grade'] ?? null,
                'diagnostic_score' => $fixtureEvaluation['diagnostic_score'] ?? null,
                'max_score' => $fixtureEvaluation['max_score'] ?? null,
                'hard_fails' => $fixtureEvaluation['hard_fails'] ?? [],
                'claim_ready' => (bool) ($fixtureEvaluation['claim_ready'] ?? false),
            ],
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'synthetic_scores_allowed' => false,
            'evidence' => [
                'rubric_command' => 'php artisan atlas:programming:rivals-one-shot-evaluate --json',
                'evaluate_command' => 'php artisan atlas:programming:rivals-one-shot-evaluate --case=<id> --json --strict',
                'doc_path' => 'docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md',
            ],
            'missing_artifacts' => $missingArtifacts,
            'note' => 'Esse bloco e diagnostico. Nao muda completion_allowed e nao libera external_rivals_certification. Score externo real continua dependente de bateria provider aprovada e validada.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Rivals Evidence Pack certification.
     *
     * Reports the infrastructure that lets operators produce structured,
     * replayable evidence packs (with workspace hashes, replay manifest
     * hashes, patch diff, optional test/quality runs) and verify them
     * locally — without provider dispatch. Diagnostic only.
     *
     * @return array<string,mixed>
     */
    public function rivalsEvidencePackCertification(string $workspace): array
    {
        $docPath = $workspace.DIRECTORY_SEPARATOR.'docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md';
        $docAvailable = is_file($docPath);

        $packServiceAvailable = class_exists(AtlasRivalsEvidencePackService::class);
        $verifierServiceAvailable = class_exists(AtlasRivalsEvidencePackVerifierService::class);

        $fixturePack = null;
        $fixtureVerification = null;
        $packSchemaCorrect = false;
        $replayManifestHashAvailable = false;
        $missingEvidenceReported = false;
        $verifierBlocksFakeEvidence = false;
        if ($packServiceAvailable && $verifierServiceAvailable) {
            try {
                $fixturePack = $this->evidencePack->generate(['workspace' => $workspace]);
                $fixtureVerification = $this->evidencePackVerifier->verify($fixturePack);
                $packSchemaCorrect = ($fixturePack['schema_version'] ?? null) === AtlasRivalsEvidencePackService::SCHEMA_VERSION;
                $replayManifestHashAvailable = is_string(data_get($fixturePack, 'replay_manifest.hash'))
                    && strlen((string) data_get($fixturePack, 'replay_manifest.hash')) === 64;
                $missingEvidenceReported = is_array($fixturePack['missing_evidence'] ?? null);

                $fakePack = $fixturePack;
                $fakePack['tests'] = ['present' => true, 'source' => null, 'log_hash' => null];
                $fakeVerification = $this->evidencePackVerifier->verify($fakePack);
                $verifierBlocksFakeEvidence = ($fakeVerification['status'] ?? null) === 'blocked';
            } catch (\Throwable $e) {
                $fixtureVerification = ['status' => 'blocked', 'blockers' => ['fixture_pack_generation_failed:'.$e->getMessage()]];
            }
        }

        $noProviderCall = $packSchemaCorrect && ($fixturePack['external_provider_call'] ?? null) === false;

        $missingArtifacts = [];
        if (! $docAvailable) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $packServiceAvailable) {
            $missingArtifacts[] = 'pack_service_missing';
        }
        if (! $verifierServiceAvailable) {
            $missingArtifacts[] = 'verifier_service_missing';
        }
        if (! $packSchemaCorrect) {
            $missingArtifacts[] = 'pack_schema_invalid';
        }
        if (! $verifierBlocksFakeEvidence) {
            $missingArtifacts[] = 'verifier_does_not_block_fake_evidence';
        }

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $noProviderCall => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.programming.rivals_evidence_pack_certification.v1',
            'status' => $status,
            'evidence_pack_service_available' => $packServiceAvailable,
            'verifier_service_available' => $verifierServiceAvailable,
            'schema_available' => $packSchemaCorrect,
            'doc_available' => $docAvailable,
            'replay_manifest_hash_available' => $replayManifestHashAvailable,
            'patch_diff_supported' => true,
            'test_log_supported' => true,
            'quality_log_supported' => true,
            'missing_evidence_reported' => $missingEvidenceReported,
            'verifier_blocks_fake_evidence' => $verifierBlocksFakeEvidence,
            'no_provider_call' => $noProviderCall,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'synthetic_scores_allowed' => false,
            'evidence' => [
                'command' => 'php artisan atlas:programming:rivals-evidence-pack --json',
                'strict_command' => 'php artisan atlas:programming:rivals-evidence-pack --json --strict',
                'integrated_command' => 'php artisan atlas:programming:rivals-one-shot-evaluate --with-evidence-pack --json',
                'doc_path' => 'docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md',
            ],
            'fixture_pack_summary' => $fixturePack === null ? null : [
                'schema_version' => $fixturePack['schema_version'] ?? null,
                'evidence_pack_id' => $fixturePack['evidence_pack_id'] ?? null,
                'workspace_clean' => (bool) data_get($fixturePack, 'workspace.clean', false),
                'replay_manifest_present' => (bool) data_get($fixturePack, 'replay_manifest.present', false),
                'replay_manifest_hash_truncated' => substr((string) data_get($fixturePack, 'replay_manifest.hash', ''), 0, 16),
                'missing_evidence' => $fixturePack['missing_evidence'] ?? [],
                'external_provider_call' => (bool) ($fixturePack['external_provider_call'] ?? true),
                'claim_ready' => (bool) ($fixturePack['claim_ready'] ?? true),
            ],
            'fixture_verification_status' => $fixtureVerification['status'] ?? null,
            'missing_artifacts' => $missingArtifacts,
            'note' => 'Evidence Pack e diagnostico local. Nao muda completion_allowed e nao libera external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }
}
