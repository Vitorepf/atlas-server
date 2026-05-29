<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidenceBundleManifestService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsExternalExecutionPreflightService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsExternalLearningGapService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsExternalRunbookManifestValidatorService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunInventoryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsStatisticalRepeatDryRunService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsTrustedSignalGateService;
use App\Services\Ai\Programming\ForgeRivals\DeepSwe\AtlasForgeRivalsDeepSweResultIngestService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Forge Rivals external evidence lifecycle certification.
 *
 * Read-only proof that restored/portable evidence can move through the
 * operator path into ledger/Decide as advisory measured signal only.
 */
final class AtlasForgeRivalsExternalEvidenceLifecycleCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_external_evidence_lifecycle_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_external_evidence_lifecycle_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'run_inventory_available',
        'portable_bundle_verify_supports_restored_run_dir',
        'replay_supports_restored_artifact_paths',
        'trusted_signal_gate_blocks_untrusted_runs',
        'ledger_record_fail_closed_on_replay_and_evidence',
        'decide_signal_advisory_only',
        'external_rivals_remains_blocked',
        'provider_tokens_not_spent',
        'e2e_restore_to_decide_test_exists',
        'deepswe_ingest_exposes_external_lifecycle',
        'deepswe_readiness_external_runbook_available',
        'deepswe_batch_external_claim_gate_fail_closed',
        'statistical_repeat_operator_runbook_available',
        'external_execution_preflight_available',
        'external_learning_gap_available',
        'external_execution_plan_binding_batch_available',
        'external_runbook_manifest_validation_available',
        'decide_learning_operational_plan_available',
        'decide_learning_blocks_unresolved_provider_model_candidates',
    ];

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $invariants = $this->invariants($repoRoot);
        $artifacts = $this->artifacts($repoRoot);
        $missing = $this->collectMissingArtifacts($artifacts);

        $blocked = [];
        foreach ($invariants as $name => $row) {
            if (! (bool) ($row['ok'] ?? false)) {
                $blocked[] = $name.'_blocked';
            }
        }

        $status = match (true) {
            $missing !== [] => self::STATUS_MISSING_ARTIFACTS,
            $blocked !== [] => self::STATUS_BLOCKED,
            default => self::STATUS_AVAILABLE,
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'certification_key' => self::CERTIFICATION_KEY,
            'status' => $status,
            'ok' => $status === self::STATUS_AVAILABLE,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'invariants' => $invariants,
            'invariants_all_true' => $status === self::STATUS_AVAILABLE,
            'invariants_summary' => array_map(static fn (array $row): bool => (bool) ($row['ok'] ?? false), $invariants),
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique(array_merge($blocked, $missing))),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'score_or_claim_allowed' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'separated_from' => 'external_rivals_certification',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'evidence_command' => 'php artisan atlas:forge:rivals external-evidence-readiness --json',
            'next_command' => 'php artisan atlas:forge:rivals external-evidence-readiness --json',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-external-evidence-lifecycle-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-evidence-pack-replay-hardening-v2.md',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function invariants(string $repoRoot): array
    {
        $out = [];
        foreach (self::REQUIRED_INVARIANTS as $name) {
            $out[$name] = $this->evaluateInvariant($name, $repoRoot);
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function artifacts(string $repoRoot): array
    {
        return [
            'run_inventory_service' => [
                'class' => AtlasForgeRivalsRunInventoryService::class,
                'present' => class_exists(AtlasForgeRivalsRunInventoryService::class),
            ],
            'evidence_bundle_service' => [
                'class' => AtlasForgeRivalsEvidenceBundleManifestService::class,
                'present' => class_exists(AtlasForgeRivalsEvidenceBundleManifestService::class),
            ],
            'replay_service' => [
                'class' => AtlasForgeRivalsReplayService::class,
                'present' => class_exists(AtlasForgeRivalsReplayService::class),
            ],
            'trusted_signal_gate_service' => [
                'class' => AtlasForgeRivalsTrustedSignalGateService::class,
                'present' => class_exists(AtlasForgeRivalsTrustedSignalGateService::class),
            ],
            'ledger_service' => [
                'class' => AtlasForgeRivalsProviderPerformanceLedgerService::class,
                'present' => class_exists(AtlasForgeRivalsProviderPerformanceLedgerService::class),
            ],
            'decide_signal_service' => [
                'class' => AtlasForgeRivalsDecideSignalProjectionService::class,
                'present' => class_exists(AtlasForgeRivalsDecideSignalProjectionService::class),
            ],
            'canonical_command' => [
                'class' => AtlasForgeRivalsCommand::class,
                'present' => class_exists(AtlasForgeRivalsCommand::class),
            ],
            'action_dispatcher' => [
                'class' => AtlasForgeRivalsActionDispatcher::class,
                'present' => class_exists(AtlasForgeRivalsActionDispatcher::class),
            ],
            'lifecycle_e2e_test' => [
                'path' => 'tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php',
                'present' => is_file($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php'),
            ],
            'deepswe_ingest_service' => [
                'class' => AtlasForgeRivalsDeepSweResultIngestService::class,
                'present' => class_exists(AtlasForgeRivalsDeepSweResultIngestService::class),
            ],
            'statistical_repeat_dry_run_service' => [
                'class' => AtlasForgeRivalsStatisticalRepeatDryRunService::class,
                'present' => class_exists(AtlasForgeRivalsStatisticalRepeatDryRunService::class),
            ],
            'external_execution_preflight_service' => [
                'class' => AtlasForgeRivalsExternalExecutionPreflightService::class,
                'present' => class_exists(AtlasForgeRivalsExternalExecutionPreflightService::class),
            ],
            'external_learning_gap_service' => [
                'class' => AtlasForgeRivalsExternalLearningGapService::class,
                'present' => class_exists(AtlasForgeRivalsExternalLearningGapService::class),
            ],
            'external_runbook_manifest_validator_service' => [
                'class' => AtlasForgeRivalsExternalRunbookManifestValidatorService::class,
                'present' => class_exists(AtlasForgeRivalsExternalRunbookManifestValidatorService::class),
            ],
        ];
    }

    /**
     * @return array{ok:bool,status:string,description:string,check:string,evidence:list<string>}
     */
    private function evaluateInvariant(string $name, string $repoRoot): array
    {
        $command = $this->readFile($repoRoot.'/app/Console/Commands/AtlasForgeRivalsCommand.php');
        $dispatcher = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php');
        $bundle = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestService.php');
        $replay = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php');
        $trusted = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsTrustedSignalGateService.php');
        $ledger = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php');
        $decide = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php');
        $deepSweCompatibility = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweCompatibilityService.php');
        $deepSweIngest = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php');
        $statisticalRepeatDryRun = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsStatisticalRepeatDryRunService.php');
        $externalExecutionPreflight = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalExecutionPreflightService.php');
        $externalLearningGap = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalLearningGapService.php');
        $externalRunbookManifestValidator = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalRunbookManifestValidatorService.php');
        $test = $this->readFile($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php');
        $deepSweTest = $this->readFile($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php');
        $ledgerTest = $this->readFile($repoRoot.'/tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php');
        $decideTest = $this->readFile($repoRoot.'/tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionServiceTest.php');
        $commandTest = $this->readFile($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRivalsCommandTest.php');

        return match ($name) {
            'run_inventory_available' => [
                'ok' => class_exists(AtlasForgeRivalsRunInventoryService::class)
                    && in_array('runs', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($dispatcher, "'runs'")
                    && str_contains($dispatcher, 'runInventory->inventory'),
                'status' => 'available',
                'description' => 'Operators can enumerate local/restored run evidence before trusting a signal.',
                'check' => 'runs action is wired to RunInventoryService',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunInventoryService.php',
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php',
                ],
            ],
            'portable_bundle_verify_supports_restored_run_dir' => [
                'ok' => in_array('evidence-bundle-verify', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($command, 'bundle-run-dir')
                    && str_contains($bundle, 'bundle_run_dir')
                    && str_contains($bundle, 'run_dir_overridden'),
                'status' => 'available',
                'description' => 'Portable bundle verification can target a restored run directory.',
                'check' => '--bundle-run-dir reaches EvidenceBundleManifestService and marks run_dir_overridden',
                'evidence' => [
                    'app/Console/Commands/AtlasForgeRivalsCommand.php',
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceBundleManifestService.php',
                ],
            ],
            'replay_supports_restored_artifact_paths' => [
                'ok' => str_contains($replay, '$restored')
                    && str_contains($replay, 'rtrim($paths[\'base\']')
                    && str_contains($replay, 'resolveArtifactPath'),
                'status' => 'available',
                'description' => 'Replay can remap old evidence artifact paths into the restored run base.',
                'check' => 'ReplayService contains restored artifact path remapping',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php',
                ],
            ],
            'trusted_signal_gate_blocks_untrusted_runs' => [
                'ok' => in_array('trusted-signal', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($trusted, 'diagnostic_or_local_fake_run_not_trusted_signal')
                    && str_contains($trusted, 'scorecard_replay_passes_not_true')
                    && str_contains($trusted, 'replay_failed_or_missing'),
                'status' => 'available',
                'description' => 'Trusted-signal blocks local_fake/diagnostic, missing replay and bad scorecard replay before ledger.',
                'check' => 'TrustedSignalGateService has fail-closed blockers for untrusted evidence',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsTrustedSignalGateService.php',
                ],
            ],
            'ledger_record_fail_closed_on_replay_and_evidence' => [
                'ok' => str_contains($ledger, 'scorecard_replay_passes_required')
                    && str_contains($ledger, 'evidence_pack_missing_evidence')
                    && str_contains($ledger, 'evidence_artifact_hash_mismatch_at_ledger')
                    && str_contains($ledger, 'valid_for_ranking'),
                'status' => 'available',
                'description' => 'Ledger-record refuses replay-failed, missing-evidence and hash-drifted runs.',
                'check' => 'ledger fail-closed blockers are present before entries are recorded/ranked',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                ],
            ],
            'decide_signal_advisory_only' => [
                'ok' => str_contains($decide, "'advisory_only' => true")
                    && str_contains($decide, "'should_update_provider_topology' => false")
                    && str_contains($decide, "'never_changes_atlas_decide_topology' => true")
                    && str_contains($decide, "'owner_of_model_routing' => 'atlas_decide'")
                    && str_contains($decide, "'routing_effect' => 'none'"),
                'status' => 'available',
                'description' => 'Decide-signal is machine-readable provider intelligence, never routing authority.',
                'check' => 'DecideSignalProjectionService emits advisory-only topology no-op invariants',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                ],
            ],
            'external_rivals_remains_blocked' => [
                'ok' => str_contains($trusted, 'external_claim_allowed')
                    && str_contains($ledger, 'separated_from_external_rivals_certification')
                    && str_contains($decide, 'separated_from_external_rivals_certification')
                    && str_contains($this->readFile(__FILE__), "'separated_from' => 'external_rivals_certification'"),
                'status' => 'available',
                'description' => 'The external lifecycle never unlocks external_rivals_certification or external claims.',
                'check' => 'trusted-signal + ledger + decide + cert all assert blocked/separated external claim state',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsTrustedSignalGateService.php',
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                    'app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsExternalEvidenceLifecycleCertification.php',
                ],
            ],
            'provider_tokens_not_spent' => [
                'ok' => str_contains($trusted, "'external_provider_call' => false")
                    && str_contains($trusted, "'provider_tokens_spent' => false")
                    && str_contains($ledger, "'external_provider_call' => false")
                    && str_contains($ledger, "'provider_tokens_spent' => false")
                    && str_contains($decide, "'external_provider_call' => false")
                    && str_contains($decide, "'provider_tokens_spent' => false"),
                'status' => 'available',
                'description' => 'Readiness, ledger and Decide projection are read-only over local evidence.',
                'check' => 'external_provider_call=false and provider_tokens_spent=false on every lifecycle output',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsTrustedSignalGateService.php',
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php',
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                ],
            ],
            'e2e_restore_to_decide_test_exists' => [
                'ok' => str_contains($test, 'test_external_evidence_lifecycle_survives_restore_then_feeds_ledger_and_decide_signal')
                    && str_contains($test, 'evidence-bundle-verify')
                    && str_contains($test, 'trusted-signal')
                    && str_contains($test, 'ledger-record')
                    && str_contains($test, 'decide-signal'),
                'status' => 'available',
                'description' => 'A focused E2E test proves restore, strict replay, trusted-signal, ledger and decide-signal together.',
                'check' => 'MatrixRunnerTest contains external evidence lifecycle E2E coverage',
                'evidence' => [
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php',
                ],
            ],
            'deepswe_ingest_exposes_external_lifecycle' => [
                'ok' => class_exists(AtlasForgeRivalsDeepSweResultIngestService::class)
                    && str_contains($deepSweIngest, 'externalEvidenceLifecycle')
                    && str_contains($deepSweIngest, 'external_evidence_bundle_verified')
                    && str_contains($deepSweIngest, 'trusted_signal_ready')
                    && str_contains($deepSweIngest, 'can_feed_provider_performance_ledger')
                    && str_contains($deepSweTest, 'external_evidence_lifecycle'),
                'status' => 'available',
                'description' => 'DeepSWE external result ingest emits portable bundle + trusted-signal readiness before ledger/Decide use.',
                'check' => 'DeepSWE ingest service and tests expose external evidence lifecycle fields',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php',
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php',
                ],
            ],
            'deepswe_readiness_external_runbook_available' => [
                'ok' => str_contains($deepSweIngest, 'external_benchmark_claim_gate')
                    && str_contains($deepSweCompatibility, 'externalBenchmarkRunbook')
                    && str_contains($deepSweCompatibility, 'atlas.forge.rivals.deepswe_external_benchmark_runbook.v1')
                    && str_contains($deepSweCompatibility, 'minimum_50_valid_deepswe_tasks_required')
                    && str_contains($deepSweCompatibility, 'deepswe-batch-ingest')
                    && str_contains($deepSweTest, 'external_benchmark_runbook')
                    && str_contains($deepSweTest, 'ready_for_operator_review'),
                'status' => 'available',
                'description' => 'DeepSWE readiness emits a deterministic 50+ external benchmark runbook before any external execution.',
                'check' => 'DeepSWE compatibility service exposes external_benchmark_runbook with 50-task floor and batch-ingest sequence',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweCompatibilityService.php',
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php',
                ],
            ],
            'deepswe_batch_external_claim_gate_fail_closed' => [
                'ok' => str_contains($deepSweIngest, 'externalBenchmarkClaimGate')
                    && str_contains($deepSweIngest, 'atlas.forge.rivals.external_benchmark_claim_gate.v1')
                    && str_contains($deepSweIngest, 'minimum_50_successful_external_runs')
                    && str_contains($deepSweIngest, 'blocked_until_external_benchmark_evidence_complete')
                    && str_contains($deepSweIngest, 'ready_for_human_certification_external_claim_still_blocked')
                    && str_contains($deepSweIngest, "'claim_ready' => false")
                    && str_contains($deepSweIngest, "'external_claim_allowed' => false")
                    && str_contains($deepSweIngest, "'score_or_claim_allowed' => false")
                    && str_contains($deepSweIngest, "'should_update_provider_topology' => false")
                    && str_contains($deepSweIngest, "'routing_effect' => 'none'")
                    && str_contains($deepSweTest, 'external_benchmark_claim_gate')
                    && str_contains($deepSweTest, 'minimum_50_successful_external_runs')
                    && str_contains($deepSweTest, 'statistical_repeat_confidence_ready'),
                'status' => 'available',
                'description' => 'DeepSWE batch ingest exposes a fail-closed external benchmark claim gate before any strong claim.',
                'check' => 'DeepSWE batch output includes external_benchmark_claim_gate with minimum 50 runs, repeat readiness and no topology mutation',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php',
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php',
                ],
            ],
            'statistical_repeat_operator_runbook_available' => [
                'ok' => class_exists(AtlasForgeRivalsStatisticalRepeatDryRunService::class)
                    && in_array('statistical-repeat-dry-run', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($dispatcher, 'statisticalRepeatDryRun->validate')
                    && str_contains($statisticalRepeatDryRun, 'operatorRunbookSummary')
                    && str_contains($statisticalRepeatDryRun, 'atlas.forge.rivals.statistical_repeat_operator_runbook_summary.v1')
                    && str_contains($statisticalRepeatDryRun, 'writeRunbookIfRequested')
                    && str_contains($statisticalRepeatDryRun, 'real_execution_runbook_path')
                    && str_contains($statisticalRepeatDryRun, 'required_before_claim_or_atlas_decide_policy_review')
                    && str_contains($statisticalRepeatDryRun, "'external_provider_call' => false")
                    && str_contains($statisticalRepeatDryRun, "'provider_tokens_spent' => false")
                    && str_contains($ledgerTest, 'operator_runbook_summary')
                    && str_contains($ledgerTest, 'real_execution_runbook_path'),
                'status' => 'available',
                'description' => 'Statistical repeat dry-run emits an operator-safe real execution runbook summary and optional artifact before paid repetitions.',
                'check' => 'statistical-repeat-dry-run validates arena inputs, summarizes real commands, writes runbook artifact and remains provider-free',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsStatisticalRepeatDryRunService.php',
                    'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php',
                ],
            ],
            'external_execution_preflight_available' => [
                'ok' => class_exists(AtlasForgeRivalsExternalExecutionPreflightService::class)
                    && in_array('external-execution-preflight', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($dispatcher, 'externalExecutionPreflight->snapshot')
                    && str_contains($externalExecutionPreflight, 'atlas.forge.rivals.external_execution_preflight.v1')
                    && str_contains($externalExecutionPreflight, 'provider_binary_not_found')
                    && str_contains($externalExecutionPreflight, 'ready_for_real_execution_with_confirmations')
                    && str_contains($externalExecutionPreflight, "'external_provider_call' => false")
                    && str_contains($externalExecutionPreflight, "'provider_tokens_spent' => false")
                    && str_contains($externalExecutionPreflight, "'score_or_claim_allowed' => false")
                    && str_contains($commandTest, 'external-execution-preflight'),
                'status' => 'available',
                'description' => 'External execution preflight verifies provider CLI binaries and arena planning without invoking providers.',
                'check' => 'external-execution-preflight resolves configured binaries/PATH and stays advisory-only before paid execution',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalExecutionPreflightService.php',
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsCommandTest.php',
                ],
            ],
            'external_learning_gap_available' => [
                'ok' => class_exists(AtlasForgeRivalsExternalLearningGapService::class)
                    && in_array('external-learning-gap', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($dispatcher, 'externalLearningGap->report')
                    && str_contains($externalLearningGap, 'atlas.forge.rivals.external_learning_gap.v1')
                    && str_contains($externalLearningGap, 'missing_valid_evidence_count')
                    && str_contains($externalLearningGap, 'atlas_decide_learning_effect')
                    && str_contains($externalLearningGap, "'external_provider_call' => false")
                    && str_contains($externalLearningGap, "'provider_tokens_spent' => false")
                    && str_contains($externalLearningGap, "'score_or_claim_allowed' => false")
                    && str_contains($ledgerTest, 'external_learning_gap'),
                'status' => 'available',
                'description' => 'External learning gap reports missing provider/model/category/difficulty buckets for Atlas Decide learning.',
                'check' => 'external-learning-gap reads ledger coverage, emits next measurement commands and remains advisory-only',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalLearningGapService.php',
                    'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php',
                ],
            ],
            'external_execution_plan_binding_batch_available' => [
                'ok' => str_contains($command, 'plan-manifest')
                    && str_contains($deepSweIngest, 'externalExecutionPlanBinding')
                    && str_contains($deepSweIngest, 'batchExternalExecutionPlanBindingSummary')
                    && str_contains($deepSweIngest, 'atlas.forge.rivals.external_execution_plan_batch_binding.v1')
                    && str_contains($deepSweIngest, 'deepswe_batch_multiple_external_execution_plan_fingerprints')
                    && str_contains($deepSweTest, 'test_deepswe_batch_ingest_requires_all_results_to_bind_to_exported_execution_plan_manifest_when_provided')
                    && str_contains($deepSweTest, 'test_deepswe_batch_ingest_blocks_when_any_result_does_not_match_execution_plan_manifest'),
                'status' => 'available',
                'description' => 'Batch import can bind every external result to an exported reviewed execution plan manifest before matrix/ledger/Decide use.',
                'check' => 'deepswe-batch-ingest exposes external_execution_plan_binding_summary and tests both all-bound and mismatch-blocked paths',
                'evidence' => [
                    'app/Console/Commands/AtlasForgeRivalsCommand.php',
                    'app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php',
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php',
                ],
            ],
            'external_runbook_manifest_validation_available' => [
                'ok' => class_exists(AtlasForgeRivalsExternalRunbookManifestValidatorService::class)
                    && in_array('external-runbook-validate', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($dispatcher, 'externalRunbookManifestValidator->validate')
                    && str_contains($externalRunbookManifestValidator, 'atlas.forge.rivals.external_runbook_manifest_validation.v1')
                    && str_contains($externalRunbookManifestValidator, 'plan_manifest_fingerprint_mismatch')
                    && str_contains($externalRunbookManifestValidator, 'missing_bucket_real_command_missing_confirmation')
                    && str_contains($externalRunbookManifestValidator, "'external_provider_call' => false")
                    && str_contains($externalRunbookManifestValidator, "'provider_tokens_spent' => false")
                    && str_contains($externalRunbookManifestValidator, "'routing_effect' => 'none'")
                    && str_contains($commandTest, 'test_external_runbook_validate_accepts_plan_manifest_without_provider_call')
                    && str_contains($commandTest, 'test_external_runbook_validate_blocks_tampered_manifest_before_provider_call'),
                'status' => 'available',
                'description' => 'Exported external runbook manifests can be validated before any paid provider execution.',
                'check' => 'external-runbook-validate recalculates fingerprint, checks dry-run/real confirmations, claim gate and advisory-only invariants',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsExternalRunbookManifestValidatorService.php',
                    'tests/Feature/Ai/Programming/AtlasForgeRivalsCommandTest.php',
                ],
            ],
            'decide_learning_operational_plan_available' => [
                'ok' => class_exists(AtlasForgeRivalsDecideSignalProjectionService::class)
                    && in_array('decide-learning', AtlasForgeRivalsCommand::ACTIONS, true)
                    && str_contains($dispatcher, 'decideSignal->learningPacket')
                    && str_contains($decide, 'atlas.forge.rivals.atlas_decide_evidence_collection_plan.v1')
                    && str_contains($decide, 'learning_gap_commands_preview')
                    && str_contains($decide, 'arena_repeat_commands_preview')
                    && str_contains($decide, 'segmentSupportingEvidence')
                    && str_contains($decide, 'atlas.forge.rivals.segment_supporting_evidence.v1')
                    && str_contains($decide, 'external-learning-gap --provider=')
                    && str_contains($decide, 'run-arena --case-set=industrial-50')
                    && str_contains($decide, '1_check_external_learning_gap')
                    && str_contains($decide, "'external_provider_call' => false")
                    && str_contains($decide, "'provider_tokens_spent' => false")
                    && str_contains($decide, "'score_or_claim_allowed' => false")
                    && str_contains($decideTest, 'learning_gap_commands_preview')
                    && str_contains($decideTest, 'arena_repeat_commands_preview')
                    && str_contains($decideTest, 'supporting_evidence'),
                'status' => 'available',
                'description' => 'Atlas Decide learning packet includes provider-free operational gap commands and audit-ready supporting evidence by segment.',
                'check' => 'decide-learning exposes evidence_collection_plan plus segment supporting_evidence while staying advisory-only',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                    'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionServiceTest.php',
                ],
            ],
            'decide_learning_blocks_unresolved_provider_model_candidates' => [
                'ok' => class_exists(AtlasForgeRivalsDecideSignalProjectionService::class)
                    && str_contains($decide, 'candidateResolutionBlockers')
                    && str_contains($decide, 'blocked_unresolved_provider_model')
                    && str_contains($decide, 'repair_provider_model_metadata')
                    && str_contains($decide, 'provider_model_resolution_required_for_every_candidate_segment')
                    && str_contains($decide, 'provider_receipt_manifest_or_model_registry_alias_reingest')
                    && str_contains($decideTest, 'test_map_blocks_unresolved_provider_model_from_decide_model_candidates')
                    && str_contains($decideTest, 'unknown')
                    && str_contains($decideTest, 'provider_required_for_atlas_decide_learning'),
                'status' => 'available',
                'description' => 'Atlas Decide learning packet keeps unresolved provider/model evidence as repair-only, never as a model profile or preference candidate.',
                'check' => 'decide-learning blocks unresolved provider/model candidates and exposes metadata repair blockers',
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php',
                    'tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionServiceTest.php',
                ],
            ],
            default => [
                'ok' => false,
                'status' => 'unknown_invariant',
                'description' => 'Unknown external evidence lifecycle invariant: '.$name,
                'check' => '',
                'evidence' => [],
            ],
        };
    }

    /**
     * @param  array<string,array<string,mixed>>  $artifacts
     * @return list<string>
     */
    private function collectMissingArtifacts(array $artifacts): array
    {
        $missing = [];
        foreach ($artifacts as $key => $artifact) {
            if (! (bool) ($artifact['present'] ?? false)) {
                $missing[] = (string) $key.'_missing';
            }
        }

        return $missing;
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRepoRoot(array $options): string
    {
        $explicit = $options['workspace'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '' && is_dir(trim($explicit))) {
            return rtrim(trim($explicit), '/');
        }

        if (function_exists('base_path')) {
            return rtrim(base_path(), '/');
        }

        return rtrim(dirname(__DIR__, 4), '/');
    }
}
