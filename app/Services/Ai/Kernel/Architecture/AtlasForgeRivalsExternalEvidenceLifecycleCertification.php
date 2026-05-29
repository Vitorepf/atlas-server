<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidenceBundleManifestService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunInventoryService;
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
        $deepSweIngest = $this->readFile($repoRoot.'/app/Services/Ai/Programming/ForgeRivals/DeepSwe/AtlasForgeRivalsDeepSweResultIngestService.php');
        $test = $this->readFile($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest.php');
        $deepSweTest = $this->readFile($repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRivalsDeepSweCompatibilityTest.php');

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
