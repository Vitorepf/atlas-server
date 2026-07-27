<?php

namespace App\Services\Ai\Kernel\Architecture\Scanner;

use App\Support\PeeledSource;
use Illuminate\Support\Facades\File;

class FailureGovernanceAudit
{
    public function __construct(private ScanPrimitivesSupport $primitives) {}

    /**
     * @return array<string,callable(): array<int,string>>
     */
    public function checks(): array
    {
        return [
            'ap168_productive_failure_governance_contract' => fn (): array => $this->scanProductiveFailureGovernanceContract(),
            'ap169_personal_worked_example_privacy_contract' => fn (): array => $this->scanPersonalWorkedExamplePrivacyContract(),
            'ap170_predictive_failure_governance_contract' => fn (): array => $this->scanPredictiveFailureGovernanceContract(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scanProductiveFailureGovernanceContract(): array
    {
        $flowPath = app_path('Services/Ai/Learning/ProductiveFailure/ProductiveFailureFlow.php');
        $commandPath = app_path('Console/Commands/AtlasProductiveFailureCommand.php');
        $featureTestPath = base_path('tests/Feature/Ai/Learning/AtlasProductiveFailureCommandTest.php');
        $apDocPath = base_path('docs/ap/AP-168-cognitive-productive-failure-flow.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $flow = PeeledSource::read($flowPath);
        $command = PeeledSource::read($commandPath);
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $violations = [];

        foreach ([
            'atlas.cognitive.productive_failure_flow.v1',
            'productive_failure_topic_required',
            'productive_failure_storage_unavailable',
            'run_migrations_before_productive_failure',
            'ProductiveFailurePhase1Started',
            'ProductiveFailureCompleted',
            "'operator_opt_in_required' => true",
            "'specific_topic_required' => true",
            "'empty_topic_allowed' => false",
            "'auto_schedule_allowed' => false",
            "'passive_session_allowed' => false",
            "'random_frustration_allowed' => false",
            "'requires_prediction_error_delta' => true",
            "'transfer_test_review_required' => true",
            "'allowed_surfaces_now' => ['cli_explicit']",
        ] as $token) {
            if (! str_contains($flow, $token)) {
                $violations[] = "app/Services/Ai/Learning/ProductiveFailure/ProductiveFailureFlow.php: AP-168 governance/runtime contract must be preserved [{$token}]";
            }
        }

        foreach ([
            'productive_failure_topic_required',
            'productive_failure_storage_unavailable',
            'run_migrations_before_productive_failure',
        ] as $token) {
            if (! str_contains($command.$featureTest, $token)) {
                $violations[] = "app/Console/Commands/AtlasProductiveFailureCommand.php + tests: AP-168 CLI must expose explicit topic and storage-unavailable governance [{$token}]";
            }
        }

        foreach ([
            'test_command_runs_full_productive_failure_session_with_ledger_events',
            'test_completion_is_blocked_without_prediction_error_delta',
            'test_transfer_tests_action_returns_review_only_due_proposals',
            'test_productive_failure_requires_specific_topic_to_avoid_random_frustration',
            'test_productive_failure_blocks_when_storage_is_unavailable',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/Ai/Learning/AtlasProductiveFailureCommandTest.php: AP-168 CLI/evidence/governance flow must be covered [{$token}]";
            }
        }

        foreach ([
            'Hardening note (2026-05-10)',
            'productive_failure_storage_unavailable',
            'run_migrations_before_productive_failure',
            'random_frustration_allowed=false',
            'requires_prediction_error_delta=true',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-168-cognitive-productive-failure-flow.md: AP-168 storage and prediction-error boundary must stay documented [{$token}]";
            }
        }

        foreach ([
            'AP-168 Productive Failure governance',
            'storage-backed',
            'productive_failure_sessions',
            'prediction-error delta',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-168 static scan must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanPersonalWorkedExamplePrivacyContract(): array
    {
        $redactorPath = app_path('Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactor.php');
        $extractorPath = app_path('Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExampleExtractor.php');
        $privacyGatePath = app_path('Services/Ai/Kernel/Gates/PersonalWorkedExamplePrivacySafeGate.php');
        $featureTestPath = base_path('tests/Feature/Ai/Learning/AtlasWorkedExamplePersonalExtractionCommandTest.php');
        $redactorTestPath = base_path('tests/Unit/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactorTest.php');
        $apDocPath = base_path('docs/ap/AP-169-cognitive-personal-worked-examples-generator.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $redactor = PeeledSource::read($redactorPath);
        $extractor = PeeledSource::read($extractorPath);
        $privacyGate = PeeledSource::read($privacyGatePath);
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $redactorTest = File::exists($redactorTestPath) ? File::get($redactorTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $violations = [];

        foreach ([
            'source_metadata',
            'quality_signals',
            'redactValue',
            '[redacted_email]',
            '[redacted_secret]',
            'provider_safe',
        ] as $token) {
            if (! str_contains($redactor, $token)) {
                $violations[] = "app/Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactor.php: AP-169 must redact metadata and quality signals before persistence [{$token}]";
            }
        }

        foreach ([
            'raw_content_in_ledger',
            'source_ref_hash',
            'PersonalWorkedExampleDiscardedDuplicate',
            'read_only_existing_record_preserved',
        ] as $token) {
            if (! str_contains($extractor, $token)) {
                $violations[] = "app/Services/Ai/Learning/PersonalWorkedExample/PersonalWorkedExampleExtractor.php: AP-169 Ledger summaries must stay sanitized and duplicate-safe [{$token}]";
            }
        }

        foreach ([
            'privacy_secrets_detected',
            'privacy_class_3_requires_redaction',
            'personal_worked_example_privacy_safe',
            '[a-z0-9._-]{12,}',
        ] as $token) {
            if (! str_contains($privacyGate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Gates/PersonalWorkedExamplePrivacySafeGate.php: AP-169 privacy gate must block unredacted secrets and sensitive privacy classes [{$token}]";
            }
        }

        foreach ([
            'storedMetadata',
            'storedSignals',
            'commitsecret123456',
            'assertStringNotContainsString',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/Ai/Learning/AtlasWorkedExamplePersonalExtractionCommandTest.php: AP-169 e2e must prove extraction read model is sanitized [{$token}]";
            }
        }

        foreach ([
            'source_metadata',
            'quality_signals',
            'secret-quality-token123',
            'assertStringContainsString',
        ] as $token) {
            if (! str_contains($redactorTest, $token)) {
                $violations[] = "tests/Unit/Ai/Learning/PersonalWorkedExample/PersonalWorkedExamplePrivacyRedactorTest.php: AP-169 redactor unit test must cover metadata and quality signals [{$token}]";
            }
        }

        foreach ([
            'Hardening note (2026-05-10)',
            'source_metadata',
            'quality_signals',
            'texto cru de commit/Feynman/decisao nao pode',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-169-cognitive-personal-worked-examples-generator.md: AP-169 privacy hardening boundary must stay documented [{$token}]";
            }
        }

        foreach ([
            'AP-169 Personal Worked Examples privacy',
            'read model de extracao',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-169 static scan must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return array<int,string>
     */
    private function scanPredictiveFailureGovernanceContract(): array
    {
        $flowPath = app_path('Services/Ai/Learning/PredictiveFailure/PredictiveFailureFlow.php');
        $commandPath = app_path('Console/Commands/AtlasPredictCommand.php');
        $calibrationGatePath = app_path('Services/Ai/Kernel/Gates/PredictiveFailureCalibrationBandGate.php');
        $safetyGatePath = app_path('Services/Ai/Kernel/Gates/PredictiveFailureSafetyGate.php');
        $featureTestPath = base_path('tests/Feature/Ai/Learning/AtlasPredictCommandTest.php');
        $gateTestPath = base_path('tests/Unit/Ai/Kernel/Gates/PredictiveFailureGateTest.php');
        $apDocPath = base_path('docs/ap/AP-170-cognitive-predictive-failure-insertion.md');
        $briefingPath = base_path('docs/engineering-knowledge-base/cognitive/implementation-briefing.md');
        $staticScansDocPath = base_path('docs/engineering-knowledge-base/kernel/static-scans.md');

        $flow = PeeledSource::read($flowPath);
        $command = PeeledSource::read($commandPath);
        $calibrationGate = PeeledSource::read($calibrationGatePath);
        $safetyGate = PeeledSource::read($safetyGatePath);
        $featureTest = File::exists($featureTestPath) ? File::get($featureTestPath) : '';
        $gateTest = File::exists($gateTestPath) ? File::get($gateTestPath) : '';
        $apDoc = File::exists($apDocPath) ? File::get($apDocPath) : '';
        $briefing = File::exists($briefingPath) ? File::get($briefingPath) : '';
        $staticScansDoc = File::exists($staticScansDocPath) ? File::get($staticScansDocPath) : '';
        $violations = [];

        foreach ([
            'atlas.cognitive.predictive_failure.flow.v1',
            'atlas.cognitive.predictive_failure.governance.v1',
            "'operator_opt_in_required' => true",
            "'specific_target_required' => true",
            "'empty_subject_allowed' => false",
            "'auto_schedule_allowed' => false",
            "'passive_insertion_allowed' => false",
            "'random_frustration_allowed' => false",
            "'daily_plan_auto_insert_allowed' => false",
            "'requires_calibration_band_gate' => true",
            "'requires_safety_gate' => true",
            "'requires_outcome_tracking' => true",
            "'allowed_surfaces_now' => ['cli_explicit']",
            'predictive_failure_subject_required',
            'predictive_failure_storage_unavailable',
            'run_migrations_before_predictive_failure',
            'PredictiveFailureInserted',
            'PredictiveFailureInsertionSkipped',
            'PredictiveFailurePriorUpdated',
        ] as $token) {
            if (! str_contains($flow, $token)) {
                $violations[] = "app/Services/Ai/Learning/PredictiveFailure/PredictiveFailureFlow.php: AP-170 governance/runtime contract must be preserved [{$token}]";
            }
        }

        foreach ([
            'predictive_failure_subject_required',
            'predictive_failure_insertion_id_required',
            'unknown_predict_action',
            'PredictiveFailureFlow::governanceContract()',
        ] as $token) {
            if (! str_contains($command, $token)) {
                $violations[] = "app/Console/Commands/AtlasPredictCommand.php: AP-170 CLI must require explicit target and expose governance on invalid input [{$token}]";
            }
        }

        foreach ([
            'predictive_failure_calibration_band',
            'predictive_failure_too_easy_outside_zone',
            'predictive_failure_too_hard_outside_zone',
            'predictive_failure_calibration_band_sweet',
        ] as $token) {
            if (! str_contains($calibrationGate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Gates/PredictiveFailureCalibrationBandGate.php: AP-170 calibration band gate must remain executable [{$token}]";
            }
        }

        foreach ([
            'predictive_failure_safety',
            'predictive_failure_blocked_high_cognitive_load',
            'predictive_failure_privacy_class_too_high',
            'predictive_failure_blocked_stress_state',
            'isSensitivePrivacyClass',
        ] as $token) {
            if (! str_contains($safetyGate, $token)) {
                $violations[] = "app/Services/Ai/Kernel/Gates/PredictiveFailureSafetyGate.php: AP-170 safety gate must remain executable [{$token}]";
            }
        }

        foreach ([
            'test_predict_failure_insert_resolve_and_metrics_flow',
            'test_predict_failure_is_blocked_under_high_cognitive_load',
            'test_predict_failure_requires_specific_target_to_avoid_random_frustration',
            'test_predict_resolve_requires_valid_insertion_id',
            'test_predictive_failure_flow_rejects_empty_target_even_outside_cli',
            'test_predictive_failure_flow_blocks_when_storage_is_unavailable',
            'PredictiveFailureInserted',
            'PredictiveFailureOutcomeFailure',
            'PredictiveFailureCalibrationComputed',
            'operator_opt_in_required',
            'daily_plan_auto_insert_allowed',
        ] as $token) {
            if (! str_contains($featureTest, $token)) {
                $violations[] = "tests/Feature/Ai/Learning/AtlasPredictCommandTest.php: AP-170 CLI/evidence/governance flow must be covered [{$token}]";
            }
        }

        foreach ([
            'test_calibration_gate_blocks_outside_sweet_band_and_passes_sweet_band',
            'test_safety_gate_blocks_high_load_and_sensitive_privacy',
        ] as $token) {
            if (! str_contains($gateTest, $token)) {
                $violations[] = "tests/Unit/Ai/Kernel/Gates/PredictiveFailureGateTest.php: AP-170 gates must be covered [{$token}]";
            }
        }

        foreach ([
            'Status: implemented_partial',
            'alvo explicito obrigatorio',
            'Service-level guard tambem exige alvo explicito',
            'predictive_failure_storage_unavailable',
            'random_frustration_allowed=false',
            'daily-plan/on-off/UX App-Mobile-Voice',
            'requires_rivals_learning_validation_before_default=true',
        ] as $token) {
            if (! str_contains($apDoc, $token)) {
                $violations[] = "docs/ap/AP-170-cognitive-predictive-failure-insertion.md: AP-170 must document current partial boundary [{$token}]";
            }
        }

        foreach ([
            'AP-170',
            'implemented_partial',
            'daily-plan/UX/KG maduro futuros',
        ] as $token) {
            if (! str_contains($briefing, $token)) {
                $violations[] = "docs/engineering-knowledge-base/cognitive/implementation-briefing.md: AP-170 status must stay visible to AI implementers [{$token}]";
            }
        }

        foreach ([
            'AP-170 Predictive Failure governance',
            'alvo explicito',
            'random frustration',
            'daily-plan/UX/KG maduro',
        ] as $token) {
            if (! str_contains($staticScansDoc, $token)) {
                $violations[] = "docs/engineering-knowledge-base/kernel/static-scans.md: AP-170 static scan must be documented [{$token}]";
            }
        }

        sort($violations);

        return $violations;
    }
}
