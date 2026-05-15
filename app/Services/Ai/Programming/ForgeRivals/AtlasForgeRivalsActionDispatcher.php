<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Console\Commands\AtlasForgeRivalsCommand;

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
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsAdjudicatorService $adjudicator,
        private readonly AtlasForgeRivalsReportService $report,
        private readonly AtlasForgeRivalsFullSmokeService $fullSmoke,
        private readonly AtlasForgeRivalsRunBatteryService $runBattery,
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
            'replay' => $this->wrap($action, $this->replay->replay($input)),
            'adjudicate' => $this->wrap($action, $this->adjudicator->adjudicate($input)),
            'report' => $this->wrap($action, $this->report->render($input)),
            'full-smoke' => $this->wrap($action, $this->fullSmoke->run($input)),
            'run-battery' => $this->wrap($action, $this->runBattery->run($input)),
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
            'smoke' => 'full-smoke',
            'reset-test-worktrees' => 'reset',
            'battery', 'run-battery-real', 'battery-run' => 'run-battery',
            'score', 'adjudicator', 'adjudication' => 'adjudicate',
            default => $action,
        };
    }
}
