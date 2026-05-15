<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use Illuminate\Support\Str;

/**
 * Atlas Forge Rivals · Full Smoke (offline).
 *
 * Chains every read-only action end-to-end plus a `local_fake` run-real,
 * proving the operator pipeline is wired without spending a token. Steps:
 *
 *   doctor → setup --source-ref=HEAD → preflight --mode=local_fake →
 *   dry-run → plan-real → run-real --mode=local_fake → status →
 *   collect-evidence → replay → report → reset --reason=full_smoke
 *
 * Any step that doesn't return status=ok aborts the chain and the smoke
 * result reports the failing step + accumulated phases.
 *
 * NEVER calls a real provider.
 */
final class AtlasForgeRivalsFullSmokeService
{
    public function __construct(
        private readonly AtlasForgeRivalsDoctorService $doctor,
        private readonly AtlasForgeRivalsSetupService $setup,
        private readonly AtlasForgeRivalsResetService $reset,
        private readonly AtlasForgeRivalsPreflightService $preflight,
        private readonly AtlasForgeRivalsDryRunService $dryRun,
        private readonly AtlasForgeRivalsPlanRealService $planReal,
        private readonly AtlasForgeRivalsRunRealService $runReal,
        private readonly AtlasForgeRivalsStatusService $statusService,
        private readonly AtlasForgeRivalsCollectEvidenceService $collectEvidence,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsReportService $report,
        private readonly AtlasForgeRivalsRunPathResolver $paths,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $atlasModel = (string) ($input['atlas_model'] ?? 'claude_sonnet');
        $rivalModel = (string) ($input['rival'] ?? $atlasModel);
        $preset = (string) ($input['preset'] ?? 'smoke');
        $sourceRef = (string) ($input['source_ref'] ?? 'HEAD');
        $runId = (string) ($input['run_id'] ?? 'smoke-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)));

        $sharedConfirms = [
            'runbook_reviewed' => true,
            'provider_cost' => true,
            'real_provider_call' => true,
        ];

        $phases = [];
        $failed = null;

        // 1. doctor
        $doctor = $this->doctor->check();
        $phases[] = $this->phase('doctor', $doctor);
        if ($doctor['status'] !== 'ok') {
            $failed = 'doctor';
        }

        // 2. setup
        if ($failed === null) {
            $setup = $this->setup->provision([
                'run_id' => $runId,
                'source_ref' => $sourceRef,
            ]);
            $phases[] = $this->phase('setup', $setup);
            if ($setup['status'] !== 'ok') {
                $failed = 'setup';
            } else {
                $runId = (string) ($setup['run_id'] ?? $runId);
            }
        }

        // 3. preflight (local_fake, since we want full chain w/o providers).
        // Pass the run's atlas worktree as workspace so the protocol preflight
        // can validate against a CLEAN checkout (the source repo may be dirty).
        $runPaths = $this->paths->paths($runId);
        if ($failed === null) {
            $preflight = $this->preflight->preflight([
                'mode' => 'local_fake',
                'atlas_model' => $atlasModel,
                'rival' => $rivalModel,
                'preset' => $preset,
                'workspace' => $runPaths['atlas'],
                'baseline_workspace' => $runPaths['rival'],
                'confirmations' => $sharedConfirms,
            ]);
            $phases[] = $this->phase('preflight', $preflight);
            if ($preflight['status'] !== 'ok') {
                $failed = 'preflight';
            }
        }

        // 4. dry-run
        if ($failed === null) {
            $dryRun = $this->dryRun->plan([
                'mode' => 'local_fake',
                'preset' => $preset,
                'workspace' => $runPaths['atlas'],
                'baseline_workspace' => $runPaths['rival'],
            ]);
            $phases[] = $this->phase('dry-run', $dryRun);
            if ($dryRun['status'] !== 'ok') {
                $failed = 'dry-run';
            }
        }

        // 5. plan-real
        if ($failed === null) {
            $planReal = $this->planReal->plan([
                'mode' => 'local_fake',
                'atlas_model' => $atlasModel,
                'rival' => $rivalModel,
                'preset' => $preset,
                'confirmations' => $sharedConfirms,
            ]);
            $phases[] = $this->phase('plan-real', $planReal);
            if ($planReal['status'] !== 'ok') {
                $failed = 'plan-real';
            }
        }

        // 6. run-real (local_fake)
        if ($failed === null) {
            $runReal = $this->runReal->run([
                'mode' => 'local_fake',
                'atlas_model' => $atlasModel,
                'rival' => $rivalModel,
                'preset' => $preset,
                'run_id' => $runId,
                'confirmations' => $sharedConfirms,
            ]);
            $phases[] = $this->phase('run-real', $runReal);
            if ($runReal['status'] !== 'ok') {
                $failed = 'run-real';
            } else {
                $runId = (string) ($runReal['run_id'] ?? $runId);
            }
        }

        // 7. status
        if ($failed === null) {
            $status = $this->statusService->status(['run_id' => $runId]);
            $phases[] = $this->phase('status', $status);
            // status 'completed' is the expected terminal state
            if (! in_array($status['status'] ?? null, ['ok', 'completed'], true)) {
                $failed = 'status';
            }
        }

        // 8. collect-evidence (pre_adjudication — full-smoke has no
        // adjudication step, so the scorecard never exists at this stage).
        if ($failed === null) {
            $collect = $this->collectEvidence->collect([
                'run_id' => $runId,
                'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ]);
            $phases[] = $this->phase('collect-evidence', $collect);
            if ($collect['status'] !== 'ok') {
                $failed = 'collect-evidence';
            }
        }

        // 9. replay (pre_adjudication — matches the collect stage above).
        if ($failed === null) {
            $replay = $this->replay->replay([
                'run_id' => $runId,
                'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
            ]);
            $phases[] = $this->phase('replay', $replay);
            if ($replay['status'] !== 'ok') {
                $failed = 'replay';
            }
        }

        // 10. report
        if ($failed === null) {
            $report = $this->report->render(['run_id' => $runId]);
            $phases[] = $this->phase('report', $report);
            if ($report['status'] !== 'ok') {
                $failed = 'report';
            }
        }

        // 11. reset (always run to clean up, even if a phase failed)
        $reset = $this->reset->reset([
            'run_id' => $runId,
            'reviewer' => 'full-smoke',
            'reason' => $failed === null ? 'full_smoke_completed' : 'full_smoke_aborted:'.$failed,
        ]);
        $phases[] = $this->phase('reset', $reset);

        return [
            'status' => $failed === null ? 'ok' : 'blocked',
            'run_id' => $runId,
            'phases' => $phases,
            'phases_passed' => count(array_filter($phases, static fn (array $p): bool => $p['ok'])),
            'phases_failed' => count(array_filter($phases, static fn (array $p): bool => ! $p['ok'])),
            'failed_phase' => $failed,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'note' => $failed === null
                ? 'Full chain ran in local_fake mode end-to-end without provider calls.'
                : "Full smoke aborted at phase '{$failed}'.",
            'next_command' => $failed === null
                ? 'php artisan atlas:forge:rivals audit --json'
                : "fix '{$failed}' phase and re-run: php artisan atlas:forge:rivals full-smoke --json",
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function phase(string $name, array $payload): array
    {
        $status = (string) ($payload['status'] ?? 'unknown');

        return [
            'phase' => $name,
            'status' => $status,
            'ok' => $status === 'ok' || $status === 'completed',
            'blockers' => (array) ($payload['blockers'] ?? []),
        ];
    }
}
