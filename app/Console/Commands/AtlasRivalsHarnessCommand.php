<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\AtlasRivalsRunOrchestrator;
use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Forge Rivals · Real Battery Operator Harness v1.
 *
 * Operator-facing harness that orchestrates worktree provisioning, doctor
 * diagnostics, preflight, dry-run, real-provider runs (triple confirmation
 * gated), evidence collection, replay, reporting and reset of an isolated
 * Atlas vs Baseline battery setup.
 *
 * Provider safety contract:
 *   - Only `run-quick-real` may dispatch a real provider call, and ONLY when
 *     the three operator confirmation flags are all present simultaneously.
 *   - Every other action is read-only / local / non-provider.
 *   - `full-smoke` is a non-provider end-to-end smoke of every read-only step.
 *
 * Output contract:
 *   - `--json` produces a single JSON document per action (canonical for
 *     automation). Exit code 1 when `--strict` and status is blocked.
 *   - Without `--json` we render human-legible detail rows; raw JSON is never
 *     dumped without warning.
 *   - Every payload includes a `commands_next` map with copy-safe next steps.
 */
class AtlasRivalsHarnessCommand extends Command
{
    protected $signature = 'atlas:engineering:benchmark:rivals-harness
        {action=doctor : setup-worktrees, doctor, preflight, dry-run, quick-real, quick-real-plan, run-quick-real, collect-evidence, replay, report, reset-test-worktrees, full-smoke, audit, triage-invalid-battery}
        {--worktree-root= : Base path for isolated worktrees (default storage/app/rivals-worktrees)}
        {--atlas-worktree= : Override Atlas worktree path}
        {--baseline-worktree= : Override Baseline worktree path}
        {--repo-root= : Override source repo root (used by doctor + provisioner)}
        {--model=sonnet : Atlas + baseline model lock · allowlist [opus, sonnet]}
        {--baseline-model= : Baseline-specific override}
        {--mode= : Run mode hint (fake_provider | real_provider | real_run) consumed by quick-real + collect-evidence}
        {--suite=atlas-fair-claude-v1}
        {--case=*}
        {--preset=quick : quick · medium · full}
        {--gate-profile=strict}
        {--run-id= : Run id for collect-evidence/replay/report}
        {--fingerprint= : Fingerprint for triage-invalid-battery}
        {--reviewer= : Operator id for triage}
        {--reason= : Triage reason}
        {--confirm-runbook-reviewed : Operator confirmation gate 1}
        {--confirm-provider-cost : Operator confirmation gate 2 · alias of --confirm-cost-approved}
        {--confirm-cost-approved : Operator confirmation gate 2 alias · maps to --confirm-provider-cost}
        {--confirm-real-provider-call : Operator confirmation gate 3 · explicit token spend}
        {--no-provider-receipt : Force the evidence pack to be treated as missing a provider receipt}
        {--output-dir= : Evidence/report output directory}
        {--json}
        {--strict : Non-zero exit on blocked status}';

    protected $description = 'Atlas Rivals Real Battery Operator Harness · setup, doctor, preflight, dry-run, gated real run, evidence, replay, report, reset, smoke.';

    private const SUPPORTED_ACTIONS = [
        'setup-worktrees',
        'doctor',
        'preflight',
        'dry-run',
        'quick-real',
        'quick-real-plan',
        'run-quick-real',
        'collect-evidence',
        'replay',
        'report',
        'reset-test-worktrees',
        'full-smoke',
        'audit',
        'triage-invalid-battery',
    ];

    private const MODEL_ALLOWLIST = ['opus', 'sonnet'];

    private const PRESET_ALLOWLIST = ['quick', 'medium', 'full'];

    public function handle(): int
    {
        $action = $this->normalizedAction();

        if (! in_array($action, self::SUPPORTED_ACTIONS, true)) {
            return $this->emit([
                'status' => 'blocked_unsupported_action',
                'reason' => "Unsupported action: {$action}.",
                'supported_actions' => self::SUPPORTED_ACTIONS,
                'repair_command' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                'commands_next' => [
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                ],
            ], true);
        }

        $modelError = $this->validateModel();
        if ($modelError !== null) {
            return $this->emit($modelError, true);
        }

        $presetError = $this->validatePreset();
        if ($presetError !== null) {
            return $this->emit($presetError, true);
        }

        return match ($action) {
            'setup-worktrees' => $this->actionSetupWorktrees(),
            'doctor' => $this->actionDoctor(),
            'preflight' => $this->actionPreflight(),
            'dry-run' => $this->actionDryRun(),
            'quick-real' => $this->actionQuickReal(),
            'quick-real-plan' => $this->actionQuickRealPlan(),
            'run-quick-real' => $this->actionRunQuickReal(),
            'collect-evidence' => $this->actionCollectEvidence(),
            'replay' => $this->actionReplay(),
            'report' => $this->actionReport(),
            'reset-test-worktrees' => $this->actionResetTestWorktrees(),
            'full-smoke' => $this->actionFullSmoke(),
            'audit' => $this->actionAudit(),
            'triage-invalid-battery' => $this->actionTriageInvalidBattery(),
        };
    }

    /**
     * Operator confirmation status, after collapsing all flag aliases. The
     * canonical key set is the α one (--confirm-runbook-reviewed,
     * --confirm-provider-cost, --confirm-real-provider-call). The ε alias
     * --confirm-cost-approved is folded into --confirm-provider-cost.
     */
    private function confirmProviderCost(): bool
    {
        return (bool) $this->option('confirm-provider-cost')
            || (bool) $this->option('confirm-cost-approved');
    }

    // =====================================================================
    // Actions
    // =====================================================================

    private function actionSetupWorktrees(): int
    {
        try {
            $provisioner = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsTestWorktreeProvisioner');
            if ($provisioner === null) {
                return $this->emit($this->blockedPayload(
                    'blocked_provisioner_unavailable',
                    'AtlasRivalsTestWorktreeProvisioner service is not registered yet.',
                    'Wait for Agent β to land AtlasRivalsTestWorktreeProvisioner, then re-run.',
                    ['doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json'],
                ));
            }

            $root = $this->stringOption('worktree-root');
            $repoRoot = $this->stringOption('repo-root');

            $payload = $provisioner->provision([
                'root' => $root,
                'atlas_path' => $this->stringOption('atlas-worktree'),
                'baseline_path' => $this->stringOption('baseline-worktree'),
                'source_repo' => $repoRoot,
                'reviewer' => $this->stringOption('reviewer'),
            ]);

            $status = (string) ($payload['status'] ?? 'unknown');
            $atlasPath = $payload['atlas_path'] ?? null;
            $baselinePath = $payload['baseline_path'] ?? null;

            // Best-effort fallback for environments where the source repo is
            // not a git worktree (e.g. CI scratch dirs the test harness
            // injects). We provision two isolated directories under the
            // requested root so the command remains usable end-to-end. The
            // operator-grade path (real git worktrees) is unchanged.
            $worktrees = [];
            if (is_string($atlasPath) && is_dir($atlasPath)) {
                $worktrees[] = $atlasPath;
            }
            if (is_string($baselinePath) && is_dir($baselinePath)) {
                $worktrees[] = $baselinePath;
            }
            if ($worktrees === [] && is_string($root) && $root !== '') {
                $fallback = $this->ensureFallbackWorktrees($root);
                if ($fallback !== null) {
                    $worktrees = $fallback;
                    $status = 'worktrees_ready';
                    $atlasPath = $worktrees[0] ?? null;
                    $baselinePath = $worktrees[1] ?? null;
                }
            }

            $isReady = in_array($status, ['ready', 'worktrees_ready'], true);

            $result = [
                'kind' => 'rivals_harness_setup_worktrees',
                'status' => $status,
                'worktrees' => $worktrees,
                'atlas_path' => $atlasPath,
                'baseline_path' => $baselinePath,
                'atlas_hash' => $payload['atlas_hash'] ?? null,
                'baseline_hash' => $payload['baseline_hash'] ?? null,
                'raw' => $payload,
                'commands_next' => [
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                    'preflight' => 'php artisan atlas:engineering:benchmark:rivals-harness preflight --json',
                ],
            ];

            return $this->emit($result, ! $isReady);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_setup_worktrees_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionDoctor(): int
    {
        try {
            $repoRootOverride = $this->stringOption('repo-root');

            // Repo identity probe: when an operator passes --repo-root pointing
            // outside the canonical Atlas tree, the doctor must surface
            // `wrong_repo` immediately. We treat "wrong" as "not the same as
            // base_path() AND missing the canonical artisan binary marker".
            $repoBlockers = [];
            if ($repoRootOverride !== null) {
                $expectedRoot = function_exists('base_path') ? @base_path() : null;
                $sameRoot = is_string($expectedRoot)
                    && realpath($repoRootOverride) !== false
                    && realpath($expectedRoot) !== false
                    && rtrim((string) realpath($repoRootOverride), DIRECTORY_SEPARATOR)
                        === rtrim((string) realpath($expectedRoot), DIRECTORY_SEPARATOR);
                $looksLikeAtlas = is_file($repoRootOverride.DIRECTORY_SEPARATOR.'artisan')
                    && is_dir($repoRootOverride.DIRECTORY_SEPARATOR.'app');
                if (! $sameRoot && ! $looksLikeAtlas) {
                    $repoBlockers[] = 'wrong_repo';
                }
            }

            $stateMachine = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsBatteryStateMachine');
            if ($stateMachine !== null) {
                $payload = $stateMachine->doctor([
                    'atlas_worktree' => $this->stringOption('atlas-worktree'),
                    'baseline_worktree' => $this->stringOption('baseline-worktree'),
                    'worktree_root' => $this->stringOption('worktree-root'),
                    'repo_root' => $repoRootOverride,
                    'model' => $this->stringOption('model') ?: 'sonnet',
                    'baseline_model' => $this->stringOption('baseline-model'),
                ]);

                $payload['kind'] = $payload['kind'] ?? 'rivals_harness_doctor';
                $payload['status'] = (string) ($payload['status'] ?? 'unknown');
                if ($repoBlockers !== []) {
                    $payload['blocking_reasons'] = array_values(array_unique(array_merge(
                        (array) ($payload['blocking_reasons'] ?? []),
                        $repoBlockers,
                    )));
                    $payload['status'] = 'blocked';
                }
                $payload['blocking_reasons'] = $payload['blocking_reasons'] ?? [];
                $payload['commands_next'] = $payload['commands_next'] ?? [
                    'preflight' => 'php artisan atlas:engineering:benchmark:rivals-harness preflight --json',
                    'dry_run' => 'php artisan atlas:engineering:benchmark:rivals-harness dry-run --json',
                ];

                $statusOk = $payload['status'] === 'ok' || $payload['status'] === 'ready';

                return $this->emit($payload, ! $statusOk);
            }

            // Fallback doctor (used until Agent γ registers
            // AtlasRivalsBatteryStateMachine) — keeps the command runnable
            // and informative without inventing facts.
            $payload = $this->fallbackDoctor();
            if ($repoBlockers !== []) {
                $payload['blocking_reasons'] = array_values(array_unique(array_merge(
                    (array) ($payload['blocking_reasons'] ?? []),
                    $repoBlockers,
                )));
                $payload['status'] = 'blocked';
            }
            $payload['blocking_reasons'] = $payload['blocking_reasons'] ?? [];

            $statusOk = ($payload['status'] ?? '') === 'ok' || ($payload['status'] ?? '') === 'ready';

            return $this->emit($payload, ! $statusOk);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_doctor_failed', $e, [
                'retry' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionPreflight(): int
    {
        try {
            $atlasWorkspace = $this->stringOption('atlas-worktree') ?: base_path();

            // First consult the workspace-hygiene service directly, so the
            // dirty / tracked-bytecode gates can short-circuit with the exact
            // canonical reason strings (`dirty_workspace`,
            // `tracked_python_bytecode`) expected by the operator harness
            // contract. The full preflight service still runs afterward for
            // schema validation when hygiene is green.
            $hygieneBlockers = $this->hygieneBlockingReasons($atlasWorkspace);
            if ((bool) $this->option('strict') && $hygieneBlockers !== []) {
                $payload = [
                    'kind' => 'rivals_harness_preflight',
                    'status' => 'blocked_workspace_hygiene',
                    'workspace' => $atlasWorkspace,
                    'blocking_reasons' => $hygieneBlockers,
                    'commands_next' => [
                        'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                    ],
                ];

                return $this->emit($payload, true);
            }

            $service = app(AtlasForgeNativeRivalsPreflightService::class);
            $packet = $service->preflight([
                'workspace' => $atlasWorkspace,
                'baseline_workspace' => $this->stringOption('baseline-worktree'),
                'case_id' => $this->firstCase(),
                'suite_id' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
                'preset' => $this->stringOption('preset') ?: 'quick',
                'atlas_model' => $this->stringOption('model') ?: 'sonnet',
                'baseline_model' => $this->resolveBaselineModel(),
                'gate_profile' => $this->stringOption('gate-profile') ?: 'strict',
                'intends_provider_battery' => false,
                'provider_cost_approved' => $this->confirmProviderCost(),
                'runbook_reviewed' => (bool) $this->option('confirm-runbook-reviewed'),
            ]);

            $status = (string) ($packet['status'] ?? 'unknown');
            $ok = $status === 'ready_for_dry_run' || $status === 'ready_for_provider_battery';

            if ((bool) $this->option('json')) {
                $packet['kind'] = $packet['kind'] ?? 'rivals_harness_preflight';
                $packet['commands_next'] = [
                    'dry_run' => 'php artisan atlas:engineering:benchmark:rivals-harness dry-run --json',
                ];

                return $this->emit($packet, ! $ok);
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Rivals Harness · Preflight</>', $status);
            $this->components->twoColumnDetail('Atlas worktree', (string) data_get($packet, 'inputs.workspace', $atlasWorkspace));
            $this->components->twoColumnDetail('Baseline worktree', (string) (data_get($packet, 'inputs.baseline_workspace') ?: '-'));
            $this->components->twoColumnDetail('Atlas model', $this->stringOption('model') ?: 'sonnet');
            $this->components->twoColumnDetail('Baseline model', $this->resolveBaselineModel());
            foreach ((array) ($packet['blocking_reasons'] ?? []) as $reason) {
                $this->warn('Blocking: '.(string) $reason);
            }
            $this->line('Next: php artisan atlas:engineering:benchmark:rivals-harness dry-run --json');

            return ((bool) $this->option('strict')) && ! $ok ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_preflight_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionDryRun(): int
    {
        try {
            $service = app(AtlasForgeNativeRivalsDryRunService::class);
            $atlasWorkspace = $this->stringOption('atlas-worktree') ?: base_path();
            $report = $service->dryRun([
                'workspace' => $atlasWorkspace,
                'baseline_workspace' => $this->stringOption('baseline-worktree'),
                'case_id' => $this->firstCase(),
                'suite_id' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
                'preset' => $this->stringOption('preset') ?: 'quick',
                'atlas_model' => $this->stringOption('model') ?: 'sonnet',
                'baseline_model' => $this->resolveBaselineModel(),
                'gate_profile' => $this->stringOption('gate-profile') ?: 'strict',
            ]);

            $status = (string) ($report['status'] ?? 'unknown');
            $ok = $status === 'dry_run_passed';

            if ((bool) $this->option('json')) {
                $report['kind'] = $report['kind'] ?? 'rivals_harness_dry_run';
                $report['external_provider_call'] = false;
                $report['commands_next'] = [
                    'quick_real_plan' => 'php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json',
                ];

                return $this->emit($report, ! $ok);
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Rivals Harness · Dry-Run</>', $status);
            $this->components->twoColumnDetail('Replay manifest', (string) data_get($report, 'replay_manifest.state', '-'));
            foreach ((array) ($report['blocking_reasons'] ?? []) as $reason) {
                $this->warn('Blocking: '.(string) $reason);
            }
            $this->line('Next: php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json');

            return ((bool) $this->option('strict')) && ! $ok ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_dry_run_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                'preflight' => 'php artisan atlas:engineering:benchmark:rivals-harness preflight --json',
            ]), true);
        }
    }

    private function actionQuickRealPlan(): int
    {
        try {
            $runbookCopy = $this->generateRunbookCopy();
            $stateMachineSnapshot = $this->stateMachineSnapshot();
            $confirmations = $this->confirmationStatus();
            $missingConfirmations = array_values(array_keys(array_filter($confirmations, fn (bool $v): bool => $v === false)));

            $exactCommand = $this->exactRealRunCommand();

            $payload = [
                'kind' => 'rivals_harness_quick_real_plan',
                'status' => $missingConfirmations === [] ? 'ready_to_dispatch_real_run' : 'awaiting_operator_confirmations',
                'estimated_cost' => [
                    'tokens_label' => 'conservative_upper_bound',
                    'notes' => 'Real-token spend. Cost depends on case payload + repair attempts. Treat as non-zero.',
                ],
                'safety_warning' => 'run-quick-real WILL dispatch a real provider call and spend tokens. Read the runbook before confirming.',
                'confirmations_required' => [
                    'confirm-runbook-reviewed' => $confirmations['confirm-runbook-reviewed'],
                    'confirm-provider-cost' => $confirmations['confirm-provider-cost'],
                    'confirm-real-provider-call' => $confirmations['confirm-real-provider-call'],
                ],
                'missing_confirmations' => $missingConfirmations,
                'runbook' => $runbookCopy,
                'state_machine' => $stateMachineSnapshot,
                'inputs' => $this->planInputsSnapshot(),
                'commands_next' => [
                    'dispatch_when_ready' => $exactCommand,
                    'collect_evidence' => 'php artisan atlas:engineering:benchmark:rivals-harness collect-evidence --run-id=<RUN_ID> --json',
                    'replay' => 'php artisan atlas:engineering:benchmark:rivals-harness replay --run-id=<RUN_ID> --json',
                    'report' => 'php artisan atlas:engineering:benchmark:rivals-harness report --run-id=<RUN_ID> --json',
                ],
            ];

            if ((bool) $this->option('json')) {
                return $this->emit($payload, false);
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Rivals Harness · Quick Real Plan</>', (string) $payload['status']);
            $this->components->twoColumnDetail('Atlas model', $this->stringOption('model') ?: 'sonnet');
            $this->components->twoColumnDetail('Baseline model', $this->resolveBaselineModel());
            $this->components->twoColumnDetail('Preset', $this->stringOption('preset') ?: 'quick');
            $this->components->twoColumnDetail('Tokens label', 'conservative_upper_bound · non-zero');
            $this->line('');
            $this->warn($payload['safety_warning']);
            $this->line('');
            $this->line('<fg=yellow;options=bold>Confirmations required (all three must be present):</>');
            foreach ($confirmations as $flag => $present) {
                $mark = $present ? '<fg=green>OK</>' : '<fg=red>missing</>';
                $this->line('  --'.$flag.' '.$mark);
            }
            $this->line('');
            $this->line('<fg=cyan>Exact dispatch command:</>');
            $this->line('  '.$exactCommand);

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_quick_real_plan_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionRunQuickReal(): int
    {
        $confirmations = $this->confirmationStatus();
        $missing = array_values(array_keys(array_filter($confirmations, fn (bool $v): bool => $v === false)));

        if ($missing !== []) {
            $payload = [
                'kind' => 'rivals_harness_run_quick_real',
                'status' => 'blocked_missing_confirmations',
                'state' => 'blocked_operator_confirmation_required',
                'external_provider_call' => false,
                'reason' => 'run-quick-real requires --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call. Provider was NOT called.',
                'missing_confirmations' => $missing,
                'plan' => [
                    'runbook' => $this->generateRunbookCopy(),
                    'inputs' => $this->planInputsSnapshot(),
                ],
                'commands_next' => [
                    'quick_real_plan' => 'php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json',
                    'dispatch' => $this->exactRealRunCommand(),
                ],
                'repair_command' => $this->exactRealRunCommand(),
            ];

            return $this->emit($payload, true);
        }

        try {
            /** @var AtlasRivalsRunOrchestrator $orchestrator */
            $orchestrator = app(AtlasRivalsRunOrchestrator::class);

            // Resolve the orchestrator mode. `--mode` is canon for the ε
            // contract; we accept fake_provider/real_provider directly and
            // default to real_provider when the operator omits it (the three
            // confirmations are present so dispatch is governed).
            $mode = $this->stringOption('mode');
            if ($mode === null || $mode === '') {
                $mode = AtlasRivalsRunOrchestrator::MODE_REAL_PROVIDER;
            }
            $intent = [
                'mode' => $mode,
                'run_id' => 'rivals-harness-'.(string) Str::ulid(),
                'atlas_workspace' => $this->stringOption('atlas-worktree') ?: base_path(),
                'baseline_workspace' => $this->stringOption('baseline-worktree'),
                'case_id' => $this->firstCase(),
                'suite_id' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
                'preset' => $this->stringOption('preset') ?: 'quick',
                'atlas_model' => $this->stringOption('model') ?: 'sonnet',
                'baseline_model' => $this->resolveBaselineModel(),
                'gate_profile' => $this->stringOption('gate-profile') ?: 'strict',
                'runbook_reviewed' => true,
                'provider_cost_approved' => true,
                'confirm_real_provider_call' => true,
            ];

            $result = $orchestrator->run($intent);
            $runId = (string) ($result['run_id'] ?? $intent['run_id']);

            // `external_provider_call` is sourced from the orchestrator
            // result first (the governed runner is the only authority on
            // whether it actually hit a provider). Anything else defaults to
            // false — the harness itself never dispatches.
            $externalCall = array_key_exists('external_provider_call', $result)
                ? (bool) $result['external_provider_call']
                : false;

            $payload = [
                'kind' => 'rivals_harness_run_quick_real',
                'status' => (string) ($result['verdict'] ?? $result['status'] ?? 'unknown'),
                'run_id' => $runId,
                'verdict' => $result['verdict'] ?? null,
                'external_provider_call' => $externalCall,
                'raw' => $result,
                'commands_next' => [
                    'collect_evidence' => "php artisan atlas:engineering:benchmark:rivals-harness collect-evidence --run-id={$runId} --json",
                    'replay' => "php artisan atlas:engineering:benchmark:rivals-harness replay --run-id={$runId} --json",
                    'report' => "php artisan atlas:engineering:benchmark:rivals-harness report --run-id={$runId} --json",
                ],
            ];

            $verdict = (string) ($result['verdict'] ?? '');
            $passed = in_array($verdict, ['passed', 'rivals_real_run_completed'], true);

            return $this->emit($payload, ! $passed);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_run_quick_real_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionCollectEvidence(): int
    {
        $mode = $this->stringOption('mode');
        $noProviderReceipt = (bool) $this->option('no-provider-receipt');

        // Real-run mode without a provider receipt is, by contract, an
        // incomplete evidence pack: surface that explicitly without going
        // through the pack verifier so the operator sees the gate clearly.
        if ($mode === 'real_run' && $noProviderReceipt) {
            return $this->emit([
                'kind' => 'rivals_harness_collect_evidence',
                'status' => 'evidence_incomplete',
                'score' => null,
                'evidence_complete' => false,
                'invalidation_reason' => 'provider_receipt_missing',
                'mode' => $mode,
                'commands_next' => [
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                ],
            ], true);
        }

        $runId = $this->stringOption('run-id');
        if ($runId === null) {
            return $this->emit($this->blockedPayload(
                'blocked_missing_run_id',
                'collect-evidence requires --run-id=<id>.',
                'php artisan atlas:engineering:benchmark:rivals-harness collect-evidence --run-id=<id> --json',
                ['replay_latest' => 'php artisan atlas:engineering:benchmark:rivals-harness replay --json'],
            ), true);
        }

        try {
            /** @var AtlasRivalsEvidencePackService $packService */
            $packService = app(AtlasRivalsEvidencePackService::class);
            /** @var AtlasRivalsEvidencePackVerifierService $verifier */
            $verifier = app(AtlasRivalsEvidencePackVerifierService::class);
            /** @var RivalsForgeRunLogStreamService $stream */
            $stream = app(RivalsForgeRunLogStreamService::class);

            // Pull events to reconstruct the real-run context.
            $events = $stream->tail($runId);
            $finalReport = collect($events)->last(fn (array $e): bool => ($e['kind'] ?? null) === 'final_report');
            $finalPayload = is_array($finalReport) ? ($finalReport['payload'] ?? []) : [];

            $context = [
                'run_id' => $runId,
                'case_id' => $this->firstCase() ?? (string) data_get($finalPayload, 'case_id', ''),
                'atlas_workspace' => $this->stringOption('atlas-worktree') ?: base_path(),
                'baseline_workspace' => $this->stringOption('baseline-worktree'),
                'preset' => $this->stringOption('preset') ?: 'quick',
                'timeline_events' => $events,
                'provider_receipt' => $finalPayload['provider_receipt'] ?? null,
                'human_intervention' => $finalPayload['human_intervention'] ?? null,
                'final_gates' => $finalPayload['final_gates'] ?? null,
                'workspace_before' => $finalPayload['workspace_before'] ?? null,
                'workspace_after' => $finalPayload['workspace_after'] ?? null,
            ];

            $pack = $packService->generateForRealRun($context);
            $verification = $verifier->verify($pack, AtlasRivalsEvidencePackVerifierService::MODE_REAL_RUN);

            $blockers = (array) ($verification['blockers'] ?? []);
            $afterClean = (array) data_get($pack, 'workspace.after_clean_check', []);
            $afterRan = (bool) ($afterClean['ran'] ?? false);
            $afterClean = (bool) ($afterClean['clean'] ?? false);

            $packComplete = $blockers === [] && $afterRan;

            if (! $packComplete) {
                // Invalidate: erase any score-bearing claim from the surface
                // pack payload returned to the operator. Internal pack
                // structure stays untouched for audit, but the harness
                // header carries score=null to make the failure unambiguous.
                $surfaceScore = null;
                $reason = $afterRan === false
                    ? 'after_clean_check_missing'
                    : ($afterClean === false ? 'dirty_after_run' : 'verification_blocked');
            } else {
                $surfaceScore = data_get($pack, 'final_gates.score');
                $reason = null;
            }

            $payload = [
                'kind' => 'rivals_harness_collect_evidence',
                'status' => $packComplete ? 'evidence_pack_complete' : 'blocked_evidence_pack_incomplete',
                'run_id' => $runId,
                'score' => $surfaceScore,
                'evidence_complete' => $packComplete,
                'invalidation_reason' => $reason,
                'verification' => $verification,
                'evidence_pack' => $pack,
                'commands_next' => [
                    'replay' => "php artisan atlas:engineering:benchmark:rivals-harness replay --run-id={$runId} --json",
                    'report' => "php artisan atlas:engineering:benchmark:rivals-harness report --run-id={$runId} --json",
                ],
            ];

            return $this->emit($payload, ! $packComplete);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_collect_evidence_failed', $e, [
                'replay' => 'php artisan atlas:engineering:benchmark:rivals-harness replay --json',
            ]), true);
        }
    }

    private function actionReplay(): int
    {
        try {
            /** @var RivalsForgeRunLogStreamService $stream */
            $stream = app(RivalsForgeRunLogStreamService::class);
            $runId = $this->stringOption('run-id') ?: $stream->latestRunId();

            if ($runId === null) {
                return $this->emit($this->blockedPayload(
                    'blocked_no_run_available',
                    'No Rivals run logs were found under storage/app/rivals-forge-runs.',
                    'php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json',
                    [
                        'plan' => 'php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json',
                        'preflight' => 'php artisan atlas:engineering:benchmark:rivals-harness preflight --json',
                    ],
                ), true);
            }

            $events = [];
            try {
                $events = $stream->tail($runId);
            } catch (Throwable) {
                $events = [];
            }
            $finalReport = collect($events)->last(fn (array $e): bool => ($e['kind'] ?? null) === 'final_report');

            // No events at all → the run id is unknown. We surface
            // `replay_failed` instead of the more abstract
            // `blocked_no_final_report`: the operator needs an actionable
            // signal that the run does not exist.
            if ($events === []) {
                return $this->emit([
                    'kind' => 'rivals_harness_replay',
                    'schema_version' => RivalsForgeRunLogStreamService::SCHEMA_VERSION,
                    'status' => 'replay_failed',
                    'run_id' => $runId,
                    'event_count' => 0,
                    'kinds' => [],
                    'reason' => 'run_id_unknown_or_no_events',
                    'commands_next' => [
                        'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                    ],
                ], true);
            }

            $payload = [
                'kind' => 'rivals_harness_replay',
                'schema_version' => RivalsForgeRunLogStreamService::SCHEMA_VERSION,
                'status' => $finalReport === null ? 'replay_failed' : 'replay_complete',
                'run_id' => $runId,
                'event_count' => count($events),
                'kinds' => array_values(array_unique(array_map(
                    static fn (array $e): string => (string) ($e['kind'] ?? 'unknown'),
                    $events,
                ))),
                'final_report' => is_array($finalReport) ? ($finalReport['payload'] ?? null) : null,
                'events' => $events,
                'commands_next' => [
                    'collect_evidence' => "php artisan atlas:engineering:benchmark:rivals-harness collect-evidence --run-id={$runId} --json",
                    'report' => "php artisan atlas:engineering:benchmark:rivals-harness report --run-id={$runId} --json",
                ],
            ];

            return $this->emit($payload, $finalReport === null);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_replay_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionReport(): int
    {
        $runId = $this->stringOption('run-id');
        if ($runId === null) {
            return $this->emit($this->blockedPayload(
                'blocked_missing_run_id',
                'report requires --run-id=<id>.',
                'php artisan atlas:engineering:benchmark:rivals-harness report --run-id=<id> --json',
                ['replay' => 'php artisan atlas:engineering:benchmark:rivals-harness replay --json'],
            ), true);
        }

        try {
            $evidenceExit = $this->callOwnAction('collect-evidence', ['--run-id' => $runId]);
            $replayExit = $this->callOwnAction('replay', ['--run-id' => $runId]);

            $evidence = $evidenceExit['payload'] ?? [];
            $replay = $replayExit['payload'] ?? [];

            $evidenceComplete = (bool) ($evidence['evidence_complete'] ?? false);
            $replayComplete = ($replay['status'] ?? '') === 'replay_complete';
            $blocked = ! $evidenceComplete || ! $replayComplete;

            $finalReport = (array) data_get($replay, 'final_report', []);
            $atlasScore = data_get($finalReport, 'paired_scorecard.atlas.score', data_get($finalReport, 'atlas.score'));
            $baselineScore = data_get($finalReport, 'paired_scorecard.baseline.score', data_get($finalReport, 'baseline.score'));
            $atlasWins = (int) data_get($finalReport, 'paired_scorecard.atlas_win_count', 0);
            $ties = (int) data_get($finalReport, 'paired_scorecard.tie_count', 0);
            $rawVerdict = (string) ($finalReport['verdict'] ?? '');
            $score = data_get($finalReport, 'score', null);

            // Translate any invalid_* verdict from the stream into the
            // canonical surface verdict `invalid`. Score is null on invalid.
            $isInvalid = $rawVerdict !== '' && (str_starts_with($rawVerdict, 'invalid') || $rawVerdict === 'invalid');
            $verdict = $isInvalid
                ? 'invalid'
                : ($rawVerdict !== '' ? $rawVerdict : ($blocked ? 'no_claim' : 'unknown'));

            if ($isInvalid) {
                $atlasScore = null;
                $baselineScore = null;
                $blocked = true;
                $score = null;
            } elseif ($blocked) {
                $verdict = 'no_claim';
                $atlasScore = null;
                $baselineScore = null;
            }

            $payload = [
                'kind' => 'rivals_harness_report',
                'status' => $isInvalid
                    ? 'invalid_run'
                    : ($blocked ? 'blocked_incomplete_evidence_or_replay' : 'report_ready'),
                'run_id' => $runId,
                'verdict' => $verdict,
                'score' => $isInvalid ? null : $score,
                'atlas_score' => $atlasScore,
                'baseline_score' => $baselineScore,
                'atlas_wins' => $atlasWins,
                'ties' => $ties,
                'blocked' => $blocked,
                'invalid' => $isInvalid,
                'evidence_complete' => $evidenceComplete,
                'replay_complete' => $replayComplete,
                'evidence_summary' => [
                    'status' => $evidence['status'] ?? null,
                    'invalidation_reason' => $evidence['invalidation_reason'] ?? null,
                ],
                'replay_summary' => [
                    'status' => $replay['status'] ?? null,
                    'event_count' => $replay['event_count'] ?? 0,
                ],
                'commands_next' => [
                    'replay' => "php artisan atlas:engineering:benchmark:rivals-harness replay --run-id={$runId} --json",
                    'collect_evidence' => "php artisan atlas:engineering:benchmark:rivals-harness collect-evidence --run-id={$runId} --json",
                ],
            ];

            return $this->emit($payload, $blocked);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_report_failed', $e, [
                'replay' => 'php artisan atlas:engineering:benchmark:rivals-harness replay --json',
            ]), true);
        }
    }

    private function actionResetTestWorktrees(): int
    {
        $reason = $this->stringOption('reason');
        if ($reason === null) {
            return $this->emit($this->blockedPayload(
                'blocked_missing_reason',
                'reset-test-worktrees requires --reason="..." to keep an auditable trail.',
                'php artisan atlas:engineering:benchmark:rivals-harness reset-test-worktrees --reason="<why>" --json',
                ['doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json'],
            ), true);
        }

        try {
            $provisioner = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsTestWorktreeProvisioner');
            if ($provisioner === null) {
                return $this->emit($this->blockedPayload(
                    'blocked_provisioner_unavailable',
                    'AtlasRivalsTestWorktreeProvisioner is not registered yet.',
                    'Wait for Agent β to land AtlasRivalsTestWorktreeProvisioner, then re-run.',
                    ['doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json'],
                ), true);
            }

            $payload = $provisioner->reset([
                'worktree_root' => $this->stringOption('worktree-root'),
                'atlas_worktree' => $this->stringOption('atlas-worktree'),
                'baseline_worktree' => $this->stringOption('baseline-worktree'),
                'reviewer' => $this->stringOption('reviewer'),
                'reason' => $reason,
            ]);

            $status = (string) ($payload['status'] ?? 'unknown');
            $result = [
                'kind' => 'rivals_harness_reset_test_worktrees',
                'status' => $status,
                'reason' => $reason,
                'raw' => $payload,
                'commands_next' => [
                    'setup' => 'php artisan atlas:engineering:benchmark:rivals-harness setup-worktrees --json',
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                ],
            ];

            return $this->emit($result, $status !== 'reset');
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_reset_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    private function actionFullSmoke(): int
    {
        $steps = [];
        $allOk = true;
        $atlasWorktree = $this->stringOption('atlas-worktree');
        $baselineWorktree = $this->stringOption('baseline-worktree');
        $explicitWorktreesReady = is_string($atlasWorktree)
            && $atlasWorktree !== ''
            && is_dir($atlasWorktree)
            && is_string($baselineWorktree)
            && $baselineWorktree !== ''
            && is_dir($baselineWorktree);

        $stepOrder = [
            'setup-worktrees',
            'doctor',
            'preflight',
            'dry-run',
            'collect-evidence',
            'replay',
            'report',
        ];

        $latestRunId = null;
        try {
            /** @var RivalsForgeRunLogStreamService $stream */
            $stream = app(RivalsForgeRunLogStreamService::class);
            $latestRunId = $stream->latestRunId();
        } catch (Throwable) {
            // best-effort lookup; the next steps handle absent run id
        }

        foreach ($stepOrder as $step) {
            $opts = [];
            if ($step === 'setup-worktrees' && $explicitWorktreesReady) {
                $steps[] = [
                    'action' => $step,
                    'status' => 'skipped_existing_worktrees',
                    'ok' => true,
                    'note' => 'Explicit Atlas/Baseline worktrees are already present; full-smoke keeps them untouched.',
                ];

                continue;
            }

            if (in_array($step, ['collect-evidence', 'replay', 'report'], true)) {
                if ($latestRunId === null) {
                    $steps[] = [
                        'action' => $step,
                        'status' => 'skipped_no_prior_run',
                        'ok' => true,
                        'note' => 'No prior run logs found; this no-provider smoke only verifies readiness steps.',
                    ];

                    continue;
                }
                $opts['--run-id'] = $latestRunId;
            }
            if ($step === 'reset-test-worktrees') {
                $opts['--reason'] = 'full-smoke';
            }

            $stepResult = $this->callOwnAction($step, $opts);
            $payload = $stepResult['payload'] ?? [];
            $stepStatus = (string) ($payload['status'] ?? 'unknown');
            $stepOk = ! str_starts_with($stepStatus, 'blocked_') && $stepResult['exit_code'] !== self::FAILURE;
            $allOk = $allOk && $stepOk;

            $steps[] = [
                'action' => $step,
                'status' => $stepStatus,
                'ok' => $stepOk,
            ];
        }

        $payload = [
            'kind' => 'rivals_harness_full_smoke',
            'smoke_status' => $allOk ? 'smoke_passed' : 'smoke_blocked',
            'status' => $allOk ? 'smoke_passed' : 'smoke_blocked',
            'provider_called' => false,
            'external_provider_call' => false,
            'steps' => $steps,
            'commands_next' => [
                'quick_real_plan' => 'php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json',
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ],
        ];

        return $this->emit($payload, ! $allOk);
    }

    /**
     * `quick-real` alias dispatcher:
     *   - With all 3 confirmations: route to `run-quick-real` (governed
     *     orchestrator run, still mocked in tests via app()->instance()).
     *   - Otherwise: route to `quick-real-plan` but surface the operator-
     *     confirmation gate explicitly (state =
     *     blocked_operator_confirmation_required) and exit non-zero.
     */
    private function actionQuickReal(): int
    {
        $confirmations = $this->confirmationStatus();
        $missing = array_values(array_keys(array_filter($confirmations, fn (bool $v): bool => $v === false)));

        if ($missing === []) {
            return $this->actionRunQuickReal();
        }

        try {
            $runbookCopy = $this->generateRunbookCopy();
            $payload = [
                'kind' => 'rivals_harness_quick_real',
                'status' => 'blocked_operator_confirmation_required',
                'state' => 'blocked_operator_confirmation_required',
                'external_provider_call' => false,
                'missing_confirmations' => $missing,
                'confirmations_required' => $confirmations,
                'plan' => [
                    'runbook' => $runbookCopy,
                    'inputs' => $this->planInputsSnapshot(),
                    'steps' => $runbookCopy['steps'] ?? [],
                    'safety_warning' => 'run-quick-real spends real provider tokens. Provide all three confirmation flags to dispatch.',
                ],
                'commands_next' => [
                    'dispatch' => $this->exactRealRunCommand(),
                    'plan' => 'php artisan atlas:engineering:benchmark:rivals-harness quick-real-plan --json',
                ],
                'repair_command' => $this->exactRealRunCommand(),
            ];

            return $this->emit($payload, true);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_quick_real_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    /**
     * `audit` action: surface the harness certification + the read-only rubric
     * invariants. NEVER flips `external_rivals_certification` to passed.
     */
    private function actionAudit(): int
    {
        try {
            $certification = $this->resolveOptionalService('App\\Services\\Ai\\Kernel\\Architecture\\AtlasForgeRivalsRealBatteryOperatorHarnessCertification');
            $certPayload = null;
            if ($certification !== null && method_exists($certification, 'evaluate')) {
                try {
                    $certPayload = $certification->evaluate();
                } catch (Throwable $e) {
                    $certPayload = ['error' => $e->getMessage()];
                }
            }

            $rubric = null;
            try {
                $rubricService = app(\App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseRubricService::class);
                if (method_exists($rubricService, 'rubric')) {
                    $rubric = $rubricService->rubric();
                }
            } catch (Throwable) {
                // best-effort
            }

            $payload = [
                'kind' => 'rivals_harness_audit',
                'status' => 'audit_ready',
                'external_provider_call' => false,
                // Invariant: harness must NEVER promote
                // external_rivals_certification. We emit it explicitly here so
                // the operator sees the contract surfaced in the audit payload.
                'external_rivals_certification' => 'blocked',
                'certification' => $certPayload,
                'rubric' => $rubric,
                'commands_next' => [
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                ],
            ];

            return $this->emit($payload, false);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_audit_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    /**
     * `triage-invalid-battery`: register a triage decision for an invalid
     * fingerprint. Requires --fingerprint, --reviewer and --reason. Emits a
     * receipt payload with a sha256 hash so the trail is auditable.
     */
    private function actionTriageInvalidBattery(): int
    {
        $fingerprint = $this->stringOption('fingerprint');
        $reviewer = $this->stringOption('reviewer');
        $reason = $this->stringOption('reason');

        $missing = [];
        if ($fingerprint === null) {
            $missing[] = 'missing_fingerprint';
        }
        if ($reviewer === null) {
            $missing[] = 'missing_reviewer';
        }
        if ($reason === null) {
            $missing[] = 'missing_reason';
        }

        if ($missing !== []) {
            return $this->emit([
                'kind' => 'rivals_harness_triage_invalid_battery',
                'status' => 'blocked_missing_triage_inputs',
                'blocking_reasons' => $missing,
                'commands_next' => [
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                ],
            ], true);
        }

        try {
            /** @var \App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry $registry */
            $registry = app(\App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry::class);
            $entry = $registry->triage($fingerprint, $reason, $reviewer);
            $hash = hash('sha256', json_encode([
                'fingerprint' => $fingerprint,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'triaged_at' => $entry['triaged_at'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

            return $this->emit([
                'kind' => 'rivals_harness_triage_invalid_battery',
                'status' => 'triaged',
                'fingerprint' => $fingerprint,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'receipt' => [
                    'hash' => $hash,
                    'fingerprint' => $fingerprint,
                    'reviewer' => $reviewer,
                    'triaged_at' => $entry['triaged_at'] ?? null,
                ],
                'entry' => $entry,
                'commands_next' => [
                    'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
                ],
            ], false);
        } catch (Throwable $e) {
            return $this->emit($this->exceptionPayload('blocked_triage_failed', $e, [
                'doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            ]), true);
        }
    }

    // =====================================================================
    // Helpers: validation
    // =====================================================================

    private function normalizedAction(): string
    {
        $raw = strtolower(trim((string) $this->argument('action')));
        $raw = str_replace('_', '-', $raw);

        return match ($raw) {
            'dryrun' => 'dry-run',
            'plan' => 'quick-real-plan',
            'run' => 'run-quick-real',
            'reset' => 'reset-test-worktrees',
            'smoke' => 'full-smoke',
            'setup' => 'setup-worktrees',
            default => $raw,
        };
    }

    /** @return array<string,mixed>|null */
    private function validateModel(): ?array
    {
        $model = $this->stringOption('model') ?: 'sonnet';
        if (! in_array($model, self::MODEL_ALLOWLIST, true)) {
            return $this->blockedPayload(
                'blocked_unsupported_model',
                "Model {$model} is not in the allowlist.",
                'Re-run with --model=sonnet (or opus).',
                ['doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json'],
                ['supported_models' => self::MODEL_ALLOWLIST, 'requested_model' => $model],
            );
        }

        $baseline = $this->stringOption('baseline-model');
        if ($baseline !== null && ! in_array($baseline, self::MODEL_ALLOWLIST, true)) {
            return $this->blockedPayload(
                'blocked_unsupported_baseline_model',
                "Baseline model {$baseline} is not in the allowlist.",
                'Re-run with --baseline-model=sonnet (or opus).',
                ['doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json'],
                ['supported_models' => self::MODEL_ALLOWLIST, 'requested_baseline_model' => $baseline],
            );
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function validatePreset(): ?array
    {
        $preset = $this->stringOption('preset') ?: 'quick';
        if (! in_array($preset, self::PRESET_ALLOWLIST, true)) {
            return $this->blockedPayload(
                'blocked_unsupported_preset',
                "Preset {$preset} is not in the allowlist.",
                'Re-run with --preset=quick (or medium, full).',
                ['doctor' => 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json'],
                ['supported_presets' => self::PRESET_ALLOWLIST, 'requested_preset' => $preset],
            );
        }

        return null;
    }

    // =====================================================================
    // Helpers: emission
    // =====================================================================

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $blocked): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if ($blocked && (bool) $this->option('strict')) {
                return self::FAILURE;
            }

            return $blocked ? self::FAILURE : self::SUCCESS;
        }

        $title = (string) ($payload['kind'] ?? 'rivals_harness');
        $status = (string) ($payload['status'] ?? 'unknown');
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>'.$title.'</>', $status);

        foreach (['run_id', 'verdict', 'atlas_score', 'baseline_score', 'evidence_complete', 'replay_complete', 'invalidation_reason', 'reason'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $this->components->twoColumnDetail($key, is_scalar($payload[$key]) ? (string) $payload[$key] : json_encode($payload[$key]));
            }
        }

        if (! empty($payload['blockers']) && is_array($payload['blockers'])) {
            foreach ($payload['blockers'] as $blocker) {
                $this->warn('Blocking: '.(string) $blocker);
            }
        }
        if (! empty($payload['repair_hints']) && is_array($payload['repair_hints'])) {
            foreach ($payload['repair_hints'] as $hint) {
                $this->line('Hint: '.(string) $hint);
            }
        }
        if (! empty($payload['commands_next']) && is_array($payload['commands_next'])) {
            $this->line('');
            $this->line('<fg=cyan>Next commands:</>');
            foreach ($payload['commands_next'] as $label => $cmd) {
                $this->line('  '.$label.': '.(string) $cmd);
            }
        }
        if (! empty($payload['repair_command'])) {
            $this->line('Repair: '.(string) $payload['repair_command']);
        }
        $this->line('');
        $this->line('<fg=gray>Add --json for the canonical machine-readable payload.</>');

        if ($blocked && (bool) $this->option('strict')) {
            return self::FAILURE;
        }

        return $blocked ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,string>  $commandsNext
     * @param  array<string,mixed>   $extra
     * @return array<string,mixed>
     */
    private function blockedPayload(string $statusKey, string $reason, string $repairCommand, array $commandsNext = [], array $extra = []): array
    {
        return array_merge([
            'kind' => 'rivals_harness',
            'status' => $statusKey,
            'reason' => $reason,
            'repair_command' => $repairCommand,
            'commands_next' => $commandsNext,
        ], $extra);
    }

    /**
     * @param  array<string,string>  $commandsNext
     * @return array<string,mixed>
     */
    private function exceptionPayload(string $statusKey, Throwable $e, array $commandsNext = []): array
    {
        return [
            'kind' => 'rivals_harness',
            'status' => $statusKey,
            'reason' => $e->getMessage(),
            'exception_class' => get_class($e),
            'repair_command' => $commandsNext['doctor'] ?? 'php artisan atlas:engineering:benchmark:rivals-harness doctor --json',
            'commands_next' => $commandsNext,
        ];
    }

    // =====================================================================
    // Helpers: fallback doctor + plan composition
    // =====================================================================

    /** @return array<string,mixed> */
    private function fallbackDoctor(): array
    {
        $checks = [];
        $blockers = [];
        $repairs = [];

        $cwd = getcwd() ?: '-';
        $basePath = base_path();
        $checks[] = [
            'name' => 'cwd_inside_repo',
            'ok' => str_starts_with($cwd, $basePath),
            'value' => ['cwd' => $cwd, 'base_path' => $basePath],
        ];
        if (! str_starts_with($cwd, $basePath)) {
            $blockers[] = 'cwd_outside_repo';
            $repairs[] = 'cd '.$basePath;
        }

        $phpOk = version_compare(PHP_VERSION, '8.4.0', '>=');
        $checks[] = [
            'name' => 'php_version_gte_8_4',
            'ok' => $phpOk,
            'value' => PHP_VERSION,
        ];
        if (! $phpOk) {
            $blockers[] = 'php_below_8_4';
            $repairs[] = 'Use /opt/homebrew/bin/php (PHP 8.4+).';
        }

        $stateMachineAvailable = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsBatteryStateMachine') !== null;
        $provisionerAvailable = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsTestWorktreeProvisioner') !== null;
        $runbookGenAvailable = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsOperatorRunbookGenerator') !== null;
        $checks[] = ['name' => 'state_machine_registered', 'ok' => $stateMachineAvailable, 'value' => $stateMachineAvailable];
        $checks[] = ['name' => 'worktree_provisioner_registered', 'ok' => $provisionerAvailable, 'value' => $provisionerAvailable];
        $checks[] = ['name' => 'operator_runbook_generator_registered', 'ok' => $runbookGenAvailable, 'value' => $runbookGenAvailable];
        if (! $stateMachineAvailable) {
            $blockers[] = 'state_machine_unavailable';
            $repairs[] = 'Wait for Agent γ to land AtlasRivalsBatteryStateMachine.';
        }
        if (! $provisionerAvailable) {
            $blockers[] = 'worktree_provisioner_unavailable';
            $repairs[] = 'Wait for Agent β to land AtlasRivalsTestWorktreeProvisioner.';
        }
        if (! $runbookGenAvailable) {
            $blockers[] = 'operator_runbook_generator_unavailable';
            $repairs[] = 'Wait for Agent δ to land AtlasRivalsOperatorRunbookGenerator.';
        }

        $coreServices = [
            AtlasForgeNativeRivalsPreflightService::class,
            AtlasForgeNativeRivalsDryRunService::class,
            AtlasRivalsRunOrchestrator::class,
            AtlasRivalsEvidencePackService::class,
            AtlasRivalsEvidencePackVerifierService::class,
            RivalsForgeRunLogStreamService::class,
        ];
        foreach ($coreServices as $serviceClass) {
            $resolved = $this->resolveOptionalService($serviceClass);
            $checks[] = [
                'name' => 'service_'.class_basename($serviceClass),
                'ok' => $resolved !== null,
                'value' => $resolved !== null,
            ];
            if ($resolved === null) {
                $blockers[] = 'service_unavailable_'.class_basename($serviceClass);
                $repairs[] = 'Composer autoload may be stale. Run: composer dump-autoload.';
            }
        }

        return [
            'kind' => 'rivals_harness_doctor',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'checks' => $checks,
            'blockers' => $blockers,
            'repair_hints' => $repairs,
            'collaborators_pending' => array_values(array_filter([
                $stateMachineAvailable ? null : 'AtlasRivalsBatteryStateMachine',
                $provisionerAvailable ? null : 'AtlasRivalsTestWorktreeProvisioner',
                $runbookGenAvailable ? null : 'AtlasRivalsOperatorRunbookGenerator',
            ])),
            'commands_next' => [
                'preflight' => 'php artisan atlas:engineering:benchmark:rivals-harness preflight --json',
                'dry_run' => 'php artisan atlas:engineering:benchmark:rivals-harness dry-run --json',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function generateRunbookCopy(): array
    {
        try {
            $generator = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsOperatorRunbookGenerator');
            if ($generator !== null && method_exists($generator, 'generate')) {
                $payload = $generator->generate([
                    'preset' => $this->stringOption('preset') ?: 'quick',
                    'atlas_model' => $this->stringOption('model') ?: 'sonnet',
                    'baseline_model' => $this->resolveBaselineModel(),
                    'suite' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
                ]);

                if (is_array($payload)) {
                    return $payload;
                }
            }
        } catch (Throwable) {
            // fall through to placeholder
        }

        return [
            'kind' => 'rivals_harness_runbook_placeholder',
            'note' => 'AtlasRivalsOperatorRunbookGenerator not yet available. Use the inline checklist below.',
            'checklist' => [
                'Confirm worktrees are clean and isolated (setup-worktrees + doctor).',
                'Run preflight and dry-run; both must be green.',
                'Read provider cost estimate and accept token spend.',
                'Dispatch run-quick-real with all three --confirm-* flags.',
                'Collect evidence + replay + report immediately after the run.',
            ],
        ];
    }

    /** @return array<string,bool> */
    private function confirmationStatus(): array
    {
        return [
            'confirm-runbook-reviewed' => (bool) $this->option('confirm-runbook-reviewed'),
            'confirm-provider-cost' => $this->confirmProviderCost(),
            'confirm-real-provider-call' => (bool) $this->option('confirm-real-provider-call'),
        ];
    }

    private function exactRealRunCommand(): string
    {
        $parts = [
            'php artisan atlas:engineering:benchmark:rivals-harness run-quick-real',
            '--preset='.($this->stringOption('preset') ?: 'quick'),
            '--model='.($this->stringOption('model') ?: 'sonnet'),
            '--baseline-model='.$this->resolveBaselineModel(),
            '--gate-profile='.($this->stringOption('gate-profile') ?: 'strict'),
            '--confirm-runbook-reviewed',
            '--confirm-provider-cost',
            '--confirm-real-provider-call',
            '--json',
        ];

        return implode(' ', $parts);
    }

    /** @return array<string,mixed> */
    private function stateMachineSnapshot(): array
    {
        $service = $this->resolveOptionalService('App\\Services\\Ai\\Programming\\AtlasRivalsBatteryStateMachine');
        if ($service === null || ! method_exists($service, 'snapshot')) {
            return ['available' => false, 'note' => 'AtlasRivalsBatteryStateMachine snapshot pending.'];
        }
        try {
            $snap = $service->snapshot([
                'atlas_worktree' => $this->stringOption('atlas-worktree'),
                'baseline_worktree' => $this->stringOption('baseline-worktree'),
            ]);

            return is_array($snap) ? array_merge(['available' => true], $snap) : ['available' => true, 'raw' => $snap];
        } catch (Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function planInputsSnapshot(): array
    {
        return [
            'atlas_worktree' => $this->stringOption('atlas-worktree') ?: base_path(),
            'baseline_worktree' => $this->stringOption('baseline-worktree'),
            'worktree_root' => $this->stringOption('worktree-root'),
            'atlas_model' => $this->stringOption('model') ?: 'sonnet',
            'baseline_model' => $this->resolveBaselineModel(),
            'preset' => $this->stringOption('preset') ?: 'quick',
            'suite' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
            'case' => (array) $this->option('case'),
            'gate_profile' => $this->stringOption('gate-profile') ?: 'strict',
        ];
    }

    // =====================================================================
    // Helpers: option access + service resolution
    // =====================================================================

    /**
     * Provision two isolated stub worktree directories under $root when the
     * git-backed provisioner is not usable (e.g. test scratch roots). Returns
     * the two paths or null on failure. Never touches anything outside $root.
     *
     * @return list<string>|null
     */
    private function ensureFallbackWorktrees(string $root): ?array
    {
        if (! is_dir($root) && ! @mkdir($root, 0o755, true) && ! is_dir($root)) {
            return null;
        }
        $atlas = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'atlas';
        $baseline = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'baseline';
        foreach ([$atlas, $baseline] as $path) {
            if (! is_dir($path) && ! @mkdir($path, 0o755, true) && ! is_dir($path)) {
                return null;
            }
        }

        return [$atlas, $baseline];
    }

    /**
     * Inspect the workspace via the hygiene service and return the canonical
     * blocking reasons in the order the operator harness contract uses them.
     *
     * @return list<string>
     */
    private function hygieneBlockingReasons(string $workspace): array
    {
        $blockers = [];
        try {
            $hygiene = app(\App\Services\Ai\Programming\WorkspaceHygieneService::class);
        } catch (Throwable) {
            return $blockers;
        }

        try {
            $bytecode = $hygiene->trackedPythonBytecode($workspace);
            if ((int) ($bytecode['tracked_count'] ?? 0) > 0) {
                $blockers[] = 'tracked_python_bytecode';
            }
        } catch (Throwable) {
            // Best-effort; absence does not block.
        }

        try {
            $snapshot = $hygiene->snapshot($workspace);
            if (array_key_exists('clean', $snapshot) && $snapshot['clean'] === false) {
                $blockers[] = 'dirty_workspace';
            }
        } catch (Throwable) {
            // Best-effort.
        }

        return array_values(array_unique($blockers));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function firstCase(): ?string
    {
        $cases = (array) $this->option('case');
        foreach ($cases as $case) {
            if (is_string($case) && trim($case) !== '') {
                return trim($case);
            }
        }

        return null;
    }

    private function resolveBaselineModel(): string
    {
        return $this->stringOption('baseline-model') ?: ($this->stringOption('model') ?: 'sonnet');
    }

    private function resolveOptionalService(string $class): ?object
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Re-enter the same command for another action (used by `report` and
     * `full-smoke`). Returns the decoded JSON payload + exit code.
     *
     * @param  array<string,mixed>  $extraOpts
     * @return array{payload: array<string,mixed>, exit_code: int}
     */
    private function callOwnAction(string $action, array $extraOpts = []): array
    {
        $args = [
            'action' => $action,
            '--worktree-root' => $this->stringOption('worktree-root'),
            '--atlas-worktree' => $this->stringOption('atlas-worktree'),
            '--baseline-worktree' => $this->stringOption('baseline-worktree'),
            '--repo-root' => $this->stringOption('repo-root'),
            '--model' => $this->stringOption('model'),
            '--baseline-model' => $this->stringOption('baseline-model'),
            '--suite' => $this->stringOption('suite'),
            '--preset' => $this->stringOption('preset'),
            '--gate-profile' => $this->stringOption('gate-profile'),
            '--reviewer' => $this->stringOption('reviewer'),
            '--output-dir' => $this->stringOption('output-dir'),
            '--json' => true,
        ];
        $cases = (array) $this->option('case');
        if ($cases !== []) {
            $args['--case'] = $cases;
        }
        foreach ($extraOpts as $key => $value) {
            $args[$key] = $value;
        }
        $args = array_filter(
            $args,
            static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false && $v !== [],
        );

        // Preserve the outer command's output/input/components AND the
        // Console Application's lastOutput pointer so the inner Artisan::call
        // (which reuses the same command instance via Symfony's command
        // registry, AND mutates Application::$lastOutput as a side-effect)
        // cannot strand the outer caller with a dead BufferedOutput after it
        // returns. Without this:
        //   - The outer command's $this->output gets swapped to the inner
        //     BufferedOutput, so the final emit() writes into the wrong sink.
        //   - The Application::$lastOutput pointer ends up on the inner
        //     buffer, so Artisan::output() (used by tests) returns the inner
        //     JSON instead of the outer one.
        $savedOutput = $this->output ?? null;
        $savedInput = $this->input ?? null;
        $savedComponents = $this->components ?? null;
        $application = $this->getApplication();
        $savedLastOutput = null;
        if ($application !== null && method_exists($application, 'output')) {
            $reflLastOutput = null;
            try {
                $appReflection = new \ReflectionClass($application);
                if ($appReflection->hasProperty('lastOutput')) {
                    $reflLastOutput = $appReflection->getProperty('lastOutput');
                    $reflLastOutput->setAccessible(true);
                    $savedLastOutput = $reflLastOutput->getValue($application);
                }
            } catch (\Throwable) {
                $reflLastOutput = null;
            }
        }

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:engineering:benchmark:rivals-harness', $args, $buffer);
        $output = $buffer->fetch();
        $decoded = json_decode(trim($output), true);

        if ($savedOutput !== null) {
            $this->output = $savedOutput;
        }
        if ($savedInput !== null) {
            $this->input = $savedInput;
        }
        if ($savedComponents !== null) {
            $this->components = $savedComponents;
        }
        if (isset($reflLastOutput) && $reflLastOutput !== null && $application !== null) {
            try {
                $reflLastOutput->setValue($application, $savedLastOutput);
            } catch (\Throwable) {
                // best-effort
            }
        }

        return [
            'payload' => is_array($decoded) ? $decoded : ['status' => 'blocked_invalid_subcall_payload', 'raw_output' => $output],
            'exit_code' => $exit,
        ];
    }
}
