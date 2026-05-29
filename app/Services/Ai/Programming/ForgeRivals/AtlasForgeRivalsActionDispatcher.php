<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsExternalEvidenceLifecycleCertification;
use App\Services\Ai\Programming\ForgeRivals\Arms\AtlasForgeRivalsArmRegistryService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsCorpusCasesActionService;
use App\Services\Ai\Programming\ForgeRivals\DeepSwe\AtlasForgeRivalsDeepSweCompatibilityService;
use App\Services\Ai\Programming\ForgeRivals\DeepSwe\AtlasForgeRivalsDeepSweResultIngestService;

/**
 * Atlas Forge Rivals · Action Dispatcher.
 *
 * The single seam that routes the 13 canonical `atlas:forge:rivals` actions
 * to their dedicated v2 handlers. Every handler returns an associative
 * payload that the response builder wraps into the stable v2 envelope.
 *
 * `audit` delegates to the OperatorBattery certification.
 * `full-smoke` chains doctor → setup → preflight → dry-run → plan-real →
 * run-real (--mode=local_fake) → status → collect-evidence → replay →
 * report → reset OFFLINE.
 *
 * Action alias map (back-compat with prior harness wrappers):
 *   setup-worktrees → setup, dryrun → dry-run, plan/quick-real-plan →
 *   plan-real, run/quick-real/run-quick-real → run-real, collect →
 *   collect-evidence, smoke → full-smoke, reset-test-worktrees → reset.
 */
final class AtlasForgeRivalsActionDispatcher
{
    public function __construct(
        private readonly AtlasForgeRivalsResponseBuilder $responses,
        private readonly AtlasForgeRivalsDoctorService $doctor,
        private readonly AtlasForgeRivalsSetupService $setup,
        private readonly AtlasForgeRivalsResetService $reset,
        private readonly AtlasForgeRivalsPreflightService $preflight,
        private readonly AtlasForgeRivalsDryRunService $dryRun,
        private readonly AtlasForgeRivalsPlanRealService $planReal,
        private readonly AtlasForgeRivalsRunRealService $runReal,
        private readonly AtlasForgeRivalsStatusService $status,
        private readonly AtlasForgeRivalsCollectEvidenceService $collectEvidence,
        private readonly AtlasForgeRivalsEvidenceBundleManifestService $evidenceBundle,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsEvidencePackVerifierService $evidenceVerifier,
        private readonly AtlasForgeRivalsBatteryEvidenceService $batteryEvidence,
        private readonly AtlasForgeRivalsBatteryReplayVerifierService $batteryReplayVerifier,
        private readonly AtlasForgeRivalsAdjudicatorService $adjudicator,
        private readonly AtlasForgeRivalsReportService $report,
        private readonly AtlasForgeRivalsFullSmokeService $fullSmoke,
        private readonly AtlasForgeRivalsRunBatteryService $runBattery,
        private readonly AtlasForgeRivalsArenaRunService $arenaRun,
        private readonly AtlasForgeRivalsArmRegistryService $armRegistry,
        private readonly AtlasForgeRivalsProviderModelRegistryService $modelRegistry,
        private readonly AtlasForgeRivalsProviderArenaReadinessService $arenaReadiness,
        private readonly AtlasForgeRivalsIndustrialBenchmarkSuiteService $industrialSuite,
        private readonly AtlasForgeRivalsIndustrialExecutionSuiteService $industrialExecution,
        private readonly AtlasForgeRivalsDeepSweCompatibilityService $deepSwe,
        private readonly AtlasForgeRivalsDeepSweResultIngestService $deepSweIngest,
        private readonly AtlasForgeRivalsProviderPerformanceLedgerService $ledger,
        private readonly AtlasForgeRivalsDecideSignalProjectionService $decideSignal,
        private readonly AtlasForgeRivalsCorpusCasesActionService $corpusCases,
        private readonly AtlasForgeRivalsRunInventoryService $runInventory,
        private readonly AtlasForgeRivalsTrustedSignalGateService $trustedSignal,
        private readonly AtlasForgeRivalsExternalEvidenceLifecycleCertification $externalEvidenceLifecycleCertification,
        private readonly AtlasForgeRivalsNextService $nextAdvisor,
        private readonly AtlasForgeRivalsBatteryReportService $batteryReport,
        private readonly AtlasForgeRivalsMatrixReportService $matrixReport,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function dispatch(string $action, array $input): array
    {
        $action = $this->normalizeActionAlias(strtolower(trim($action)));
        if (! in_array($action, AtlasForgeRivalsCommand::ACTIONS, true)) {
            return $this->responses->unknownAction($action, AtlasForgeRivalsCommand::ACTIONS);
        }

        return match ($action) {
            'doctor' => $this->wrap($action, $this->doctor->check()),
            'setup' => $this->wrap($action, $this->setup->provision($input)),
            'reset' => $this->wrap($action, $this->reset->reset($input)),
            'preflight' => $this->wrap($action, $this->preflight->preflight($input)),
            'dry-run' => $this->wrap($action, $this->dryRun->plan($input)),
            'plan-real' => $this->wrap($action, $this->planReal->plan($input)),
            'run-real' => $this->wrap($action, $this->runReal->run($input)),
            'status' => $this->wrap($action, $this->status->status($input)),
            'collect-evidence' => $this->wrap($action, $this->collectEvidence->collect($input)),
            'evidence' => $this->wrap($action, $this->collectEvidence->collect($input)),
            'evidence-bundle' => $this->wrap($action, $this->evidenceBundle->manifest($input)),
            'evidence-bundle-verify' => $this->wrap($action, $this->evidenceBundle->verify($input)),
            'replay' => $this->wrap($action, $this->replay->replay($input)),
            'verify-evidence' => $this->wrap($action, $this->evidenceVerifier->verify($this->prepareVerifyInput($input))),
            'battery-evidence' => $this->wrap($action, $this->batteryEvidence->aggregate($this->prepareBatteryInput($input))),
            'battery-verify-evidence' => $this->wrap($action, $this->batteryReplayVerifier->verify($this->prepareBatteryInput($input))),
            'adjudicate' => $this->wrap($action, $this->adjudicator->adjudicate($input)),
            'report' => $this->wrap($action, $this->report->render($input)),
            'full-smoke' => $this->wrap($action, $this->fullSmoke->run($input)),
            'run-battery' => $this->wrap($action, $this->runBattery->run($input)),
            'run-arena' => $this->wrap($action, $this->arenaRun->run($input)),
            'arms' => $this->wrap($action, $this->armsSnapshot()),
            'models' => $this->wrap($action, $this->modelsSnapshot()),
            'arena-readiness' => $this->wrap($action, $this->arenaReadiness->snapshot($input)),
            'industrial-suite' => $this->wrap($action, $this->industrialSuite->snapshot($input)),
            'industrial-execution' => $this->wrap($action, $this->industrialExecution->readiness($input)),
            'deepswe' => $this->wrap($action, $this->deepSwe->inspect($input)),
            'deepswe-import' => $this->wrap('deepswe', $this->deepSwe->inspect($input)),
            'deepswe-ingest' => $this->wrap($action, $this->deepSweIngest->ingest($input)),
            'deepswe-batch-ingest' => $this->wrap($action, $this->deepSweIngest->ingestBatch($input)),
            'cases' => $this->wrap($action, $this->corpusCases->handle($input)),
            'runs' => $this->wrap($action, $this->runInventory->inventory($input)),
            'trusted-signal' => $this->wrap($action, $this->trustedSignal->inspect($input)),
            'external-evidence-readiness' => $this->wrap($action, $this->externalEvidenceLifecycleSnapshot($input)),
            'ledger' => $this->wrap($action, $this->ledger->snapshot($input)),
            'ledger-record' => $this->wrap($action, $this->ledger->record($input)),
            'decide-signal' => $this->wrap($action, $this->decideSignal->project($input)),
            'decide-map' => $this->wrap($action, $this->decideSignal->map($input)),
            'next' => $this->wrap($action, $this->nextAdvisor->next($input)),
            'resume' => $this->wrap(
                'run-battery',
                $this->runBattery->run(array_replace($input, ['resume' => true])),
            ),
            'battery-report' => $this->wrap($action, $this->batteryReport->render($input)),
            'matrix-report' => $this->wrap($action, $this->matrixReport->render($this->prepareBatteryInput($input))),
            'audit' => $this->responses->audit($action),
            default => $this->responses->unknownAction($action, AtlasForgeRivalsCommand::ACTIONS),
        };
    }

    /**
     * Wrap a service payload into the stable v2 envelope. Service payloads
     * carry a `status` field (ok|blocked|...) that propagates verbatim.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function wrap(string $action, array $payload): array
    {
        $status = (string) ($payload['status'] ?? AtlasForgeRivalsResponseBuilder::STATUS_OK);
        if ($status === AtlasForgeRivalsResponseBuilder::STATUS_OK) {
            return $this->responses->ok($action, $payload);
        }
        if ($status === AtlasForgeRivalsResponseBuilder::STATUS_BLOCKED) {
            return array_replace(
                $this->responses->blocked(
                    $action,
                    (array) ($payload['blockers'] ?? []),
                    (string) ($payload['next_command'] ?? '')
                ),
                $payload
            );
        }

        return $this->responses->ok($action, ['status' => $status] + $payload);
    }

    private function normalizeActionAlias(string $action): string
    {
        return match ($action) {
            'setup-worktrees' => 'setup',
            'dryrun' => 'dry-run',
            'plan', 'quick-real-plan' => 'plan-real',
            'run', 'quick-real', 'run-quick-real' => 'run-real',
            'collect' => 'collect-evidence',
            'bundle', 'run-bundle', 'evidence-export', 'export-evidence', 'portable-evidence' => 'evidence-bundle',
            'bundle-verify', 'verify-bundle', 'evidence-export-verify', 'verify-portable-evidence' => 'evidence-bundle-verify',
            'verify', 'evidence-verify', 'verify-pack', 'verify-evidence-pack' => 'verify-evidence',
            'battery-pack', 'aggregate-evidence', 'battery-collect-evidence' => 'battery-evidence',
            'battery-verify', 'battery-replay', 'verify-battery', 'multi-case-verify' => 'battery-verify-evidence',
            'report-matrix', 'matrix', 'final-report' => 'matrix-report',
            'smoke' => 'full-smoke',
            'reset-test-worktrees' => 'reset',
            'battery', 'run-battery-real', 'battery-run' => 'run-battery',
            'score', 'adjudicator', 'adjudication' => 'adjudicate',
            'arena', 'run-arena-real', 'arena-run', 'provider-arena' => 'run-arena',
            'list-arms', 'registry', 'runners' => 'arms',
            'list-models', 'provider-models', 'model-registry' => 'models',
            'provider-arena-readiness', 'arena-ready', 'readiness', 'rivals-ready' => 'arena-readiness',
            'industrial-readiness', 'industrial-benchmark', 'industrial-benchmark-suite' => 'industrial-suite',
            'industrial-execution-readiness', 'industrial-execution-suite', 'industrial-ready' => 'industrial-execution',
            'deep-swe', 'deepswe-readiness', 'deepswe-import', 'harbor', 'harbor-import' => 'deepswe',
            'deep-swe-ingest', 'deepswe-result', 'harbor-result', 'pier-ingest', 'pier-result' => 'deepswe-ingest',
            'deep-swe-batch-ingest', 'deepswe-batch', 'harbor-batch', 'pier-batch-ingest', 'pier-batch' => 'deepswe-batch-ingest',
            'runs-inventory', 'inventory', 'list-runs', 'available-runs' => 'runs',
            'signal-gate', 'trusted-signal-gate', 'ledger-readiness', 'provider-signal-readiness' => 'trusted-signal',
            'external-evidence', 'external-evidence-lifecycle', 'portable-evidence-readiness', 'restored-evidence-readiness' => 'external-evidence-readiness',
            'performance-ledger', 'ledger-snapshot' => 'ledger',
            'record-ledger', 'absorb-scorecard' => 'ledger-record',
            'decide', 'signal', 'decide-signal-projection' => 'decide-signal',
            'model-intelligence', 'model-map', 'decide-model-map', 'provider-intelligence-map' => 'decide-map',
            default => $action,
        };
    }

    /**
     * Map the operator CLI input to the verifier service input. Honors the
     * dedicated `--verify-mode` flag; falls back to `--mode` for the canon
     * form `verify-evidence --mode=real_run` documented in the briefing.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function prepareVerifyInput(array $input): array
    {
        $mode = null;
        if (is_string($input['verify_mode'] ?? null) && trim($input['verify_mode']) !== '') {
            $mode = trim($input['verify_mode']);
        } elseif (is_string($input['mode'] ?? null) && trim($input['mode']) !== '') {
            $maybe = strtolower(trim($input['mode']));
            if (in_array($maybe, ['dry_run', 'fake_run', 'real_run', 'replay', 'integrity', 'check'], true)) {
                $mode = $maybe;
            }
        }

        return [
            'run_id' => $input['run_id'] ?? null,
            'mode' => $mode,
            'evidence_stage' => $input['evidence_stage'] ?? null,
        ];
    }

    /**
     * Map operator CLI input to the battery aggregator / verifier input.
     * Honors `--run-ids` (CSV) plus repeated `--run-id`, propagates
     * `--battery-id`, `--output-path`, `--stage` and `--verify-mode`.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function prepareBatteryInput(array $input): array
    {
        $mode = null;
        if (is_string($input['verify_mode'] ?? null) && trim($input['verify_mode']) !== '') {
            $mode = trim($input['verify_mode']);
        } elseif (is_string($input['mode'] ?? null) && trim($input['mode']) !== '') {
            $maybe = strtolower(trim($input['mode']));
            if (in_array($maybe, ['dry_run', 'fake_run', 'real_run', 'replay', 'integrity', 'check'], true)) {
                $mode = $maybe;
            }
        }

        return [
            'run_id' => $input['run_id'] ?? null,
            'run_ids' => $input['run_ids'] ?? null,
            'battery_id' => $input['battery_id'] ?? null,
            'output_path' => $input['output_path'] ?? null,
            'evidence_stage' => $input['evidence_stage'] ?? null,
            'mode' => $mode,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function armsSnapshot(): array
    {
        $snapshot = $this->armRegistry->snapshot();

        return array_replace($snapshot, [
            'status' => 'ok',
            'next_command' => 'php artisan atlas:forge:rivals run-arena --arm-a=<id> --arm-b=<id> --task-category=<cat> --mode=local_fake --json',
            'external_provider_call' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function externalEvidenceLifecycleSnapshot(array $input): array
    {
        $snapshot = $this->externalEvidenceLifecycleCertification->evaluate($input);
        $certStatus = (string) ($snapshot['status'] ?? '');
        $actionStatus = $certStatus === AtlasForgeRivalsExternalEvidenceLifecycleCertification::STATUS_AVAILABLE
            ? 'ok'
            : $certStatus;

        return array_replace($snapshot, [
            'status' => $actionStatus,
            'certification_status' => $certStatus,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function modelsSnapshot(): array
    {
        return array_replace($this->modelRegistry->snapshot(), [
            'next_command' => 'php artisan atlas:forge:rivals run-arena --arm-a=claude_code --arm-a-model=opus --arm-b=codex_cli --arm-b-model=gpt-5.5 --mode=provider_arena --dry-run --json',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
        ]);
    }
}
