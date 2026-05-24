<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use Illuminate\Console\Command;

/**
 * Atlas Forge Rivals · Operator Battery v2 — Canonical Entrypoint.
 *
 * The single operator-facing CLI for the v2 rivals lockdown. Replaces the
 * old fan-out of `atlas:engineering:benchmark:rivals*`,
 * `atlas:programming:rivals-forge-*` and `atlas:programming:rivals-*`
 * commands. Those continue to exist as deprecated wrappers that reference
 * this entrypoint (forward path enabled in Slice 6).
 *
 * Thirteen canonical actions, three operator confirmations gating any
 * real-provider run, five modes, three rival models, replayable evidence
 * packs, and an 18-invariant certification:
 *
 *   - doctor, setup, preflight, dry-run, plan-real, run-real,
 *     status, collect-evidence, replay, report, reset, full-smoke, audit
 *
 * Each action returns a stable JSON envelope (`schema_version =
 * atlas.forge.rivals.action_response.v1`). In Slice 0, every action except
 * `audit` returns `status = pending_slice_N` with `exit code 2` (fail-closed)
 * — the action is wired, the dispatcher reaches it, but the real handler
 * ships in a later slice.
 *
 * Provider safety contract:
 *   - No action ever dispatches a paid provider call in Slice 0.
 *   - `run-real` will require ALL THREE `--confirm-*` flags simultaneously
 *     (Slice 3) before any provider is invoked.
 *   - `audit` evaluates the v2 operator battery certification (no I/O against
 *     providers; only local artifact introspection).
 *
 * IMPORTANT: This command NEVER unlocks `external_rivals_certification`.
 * External rivals claim remains operator-approval-gated, separately tracked.
 */
class AtlasForgeRivalsCommand extends Command
{
    protected $signature = 'atlas:forge:rivals
        {action=doctor : doctor|setup|preflight|dry-run|plan-real|run-real|status|collect-evidence|evidence|replay|verify-evidence|battery-evidence|battery-verify-evidence|adjudicate|report|reset|full-smoke|run-battery|run-arena|arms|runners|models|arena-readiness|industrial-suite|industrial-execution|cases|ledger|ledger-record|decide-signal|next|resume|battery-report|matrix-report|audit}
        {--worktree-root= : Back-compat base path for isolated test worktrees}
        {--repo-root= : Back-compat source repo root used when provisioning worktrees}
        {--atlas-worktree= : Back-compat isolated Atlas Forge worktree}
        {--baseline-worktree= : Back-compat isolated rival baseline worktree}
        {--model= : Back-compat Atlas model lock: sonnet|opus}
        {--baseline-model= : Back-compat baseline model lock: sonnet|opus}
        {--case=* : Provider Arena Corpus case id (e.g. backend-pagination-off-by-one). Repeated for batch.}
        {--case-set= : Provider Arena Corpus case set (quick|release|frontend|backend|bugfix|architecture|industrial-50|industrial-100|industrial-200|ambiguous-bugs|multi-day-refactors|incident-response|product-security-migrations|statistical-repeat)}
        {--mode= : fair|full_power|power|provider_arena|provider_pure|diagnostic|replay_only|local_fake (power is alias for full_power)}
        {--atlas-model= : sonnet|opus|claude_sonnet|claude_opus|codex|auto}
        {--rival= : claude_sonnet|claude_opus|codex|auto}
        {--arm-a= : Provider Arena arm A id (atlas_forge|atlas_dev|claude_code|codex_cli|gemini_cli|cursor_cli|composer_2_5|scripted_runner|manual_runner|future_runner)}
        {--arm-b= : Provider Arena arm B id (same set as --arm-a)}
        {--arm-a-model= : Arena arm A model shorthand (e.g. sonnet, opus, codex, gpt-5.5)}
        {--arm-b-model= : Arena arm B model shorthand (e.g. sonnet, opus, codex, gpt-5.5)}
        {--task-category= : Arena task category (frontend|backend|bugfix|tests|refactor|architecture|docs|performance|security)}
        {--prompt-mode= : spec-perfect|human-normal|messy-real|enterprise-change}
        {--category= : report-only filter by task_category}
        {--difficulty= : report-only filter by difficulty band L1|L2|L3|L4|L5}
        {--mode-filter= : report-only filter by run mode (fair|full_power|local_fake)}
        {--role= : Operator role tested by the entry (builder|reviewer|repair_agent|context_scout|test_generator|architect|docs)}
        {--framework= : Optional framework/language label captured in the ledger entry (e.g. react, laravel)}
        {--provider= : Filter the ledger snapshot by provider id}
        {--preset=smoke : smoke|quick|release|full|industrial-50|industrial-100|industrial-200|ambiguous-bugs|multi-day-refactors|incident-response|product-security-migrations|statistical-repeat}
        {--source-ref= : Git ref/SHA used to provision isolated worktrees (Slice 1+)}
        {--run-id= : Run id for status/collect-evidence/replay/adjudicate/report/run-battery/run-arena}
        {--run-ids= : Comma-separated run_ids for battery-evidence/battery-verify-evidence (alt to repeated --run-id)}
        {--battery-id= : Optional explicit battery_id (default: sha8 of sorted run_ids)}
        {--reviewer= : Operator id for reset/triage (Slice 1+)}
        {--reason= : Auditable reason for reset (Slice 1+)}
        {--confirm-runbook-reviewed : Confirmation gate 1 (required for any real-provider arm)}
        {--confirm-provider-cost : Confirmation gate 2 (required for any real-provider arm)}
        {--confirm-real-provider-call : Confirmation gate 3 (required for any real-provider arm)}
        {--stage= : Evidence stage for collect-evidence/replay: pre_adjudication|final (default=final)}
        {--require-final-scorecard : Force collect-evidence/replay to require the scorecard (alias for --stage=final)}
        {--verify-mode= : verify-evidence mode: dry_run|fake_run|real_run|replay (default=replay)}
        {--output-dir= : Override evidence/report output dir}
        {--input= : Path to a JSON file with adjudication_batch_input.v1 payload (adjudicate batch mode)}
        {--output-path= : Output path for the batch scorecard.v2.json (adjudicate batch mode)}
        {--dry-run : Plan-only path for run-battery; preflight + dry-run + plan-real, never invokes provider}
        {--resume : Continue an existing battery run_id by iterating only cases still pending}
        {--json : Emit machine-readable JSON}
        {--strict : Non-zero exit on blocked status}';

    protected $description = 'Atlas Forge Rivals · Provider Arena Core v2 canonical entrypoint (doctor, setup, preflight, dry-run, plan-real, run-real, status, collect-evidence, evidence, replay, verify-evidence, adjudicate, report, reset, full-smoke, run-battery, run-arena, arms, models, arena-readiness, industrial-suite, industrial-execution, cases, ledger, ledger-record, decide-signal, next, audit).';

    /** @var list<string> */
    public const ACTIONS = [
        'doctor',
        'setup',
        'preflight',
        'dry-run',
        'plan-real',
        'run-real',
        'status',
        'collect-evidence',
        'evidence',
        'replay',
        'verify-evidence',
        'battery-evidence',
        'battery-verify-evidence',
        'adjudicate',
        'report',
        'reset',
        'full-smoke',
        'run-battery',
        'run-arena',
        'arms',
        'models',
        'arena-readiness',
        'industrial-suite',
        'industrial-execution',
        'cases',
        'ledger',
        'ledger-record',
        'decide-signal',
        'next',
        'resume',
        'battery-report',
        'matrix-report',
        'audit',
    ];

    /** @var array<string,int> Action -> slice that delivers its real handler. */
    public const ACTION_SLICE = [
        'doctor' => 1,
        'setup' => 1,
        'reset' => 1,
        'preflight' => 2,
        'dry-run' => 2,
        'plan-real' => 2,
        'run-real' => 3,
        'status' => 3,
        'collect-evidence' => 4,
        'evidence' => 4,
        'replay' => 4,
        'verify-evidence' => 4,
        'battery-evidence' => 4,
        'battery-verify-evidence' => 4,
        'adjudicate' => 7,
        'report' => 4,
        'full-smoke' => 5,
        'run-battery' => 7,
        'run-arena' => 8,
        'arms' => 8,
        'models' => 8,
        'arena-readiness' => 8,
        'industrial-suite' => 12,
        'industrial-execution' => 12,
        'cases' => 10,
        'ledger' => 9,
        'ledger-record' => 9,
        'decide-signal' => 9,
        'next' => 9,
        'resume' => 11,
        'battery-report' => 11,
        'matrix-report' => 11,
        'audit' => 0,
    ];

    public function handle(AtlasForgeRivalsActionDispatcher $dispatcher): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        $input = [
            'worktree_root' => $this->stringOption('worktree-root'),
            'repo_root' => $this->stringOption('repo-root'),
            'atlas_worktree' => $this->stringOption('atlas-worktree'),
            'baseline_worktree' => $this->stringOption('baseline-worktree'),
            'model' => $this->stringOption('model'),
            'baseline_model' => $this->stringOption('baseline-model'),
            'cases' => (array) $this->option('case'),
            'case' => $this->firstCaseOption(),
            'case_set' => $this->stringOption('case-set'),
            'mode' => $this->stringOption('mode'),
            'atlas_model' => $this->stringOption('atlas-model'),
            'rival' => $this->stringOption('rival'),
            'arm_a' => $this->stringOption('arm-a'),
            'arm_b' => $this->stringOption('arm-b'),
            'arm_a_model' => $this->stringOption('arm-a-model'),
            'arm_b_model' => $this->stringOption('arm-b-model'),
            'task_category' => $this->stringOption('task-category'),
            'prompt_mode' => $this->stringOption('prompt-mode'),
            'category' => $this->stringOption('category'),
            'difficulty' => $this->stringOption('difficulty'),
            'mode_filter' => $this->stringOption('mode-filter'),
            'role' => $this->stringOption('role'),
            'framework' => $this->stringOption('framework'),
            'provider' => $this->stringOption('provider'),
            'preset' => $this->stringOption('preset') ?: 'smoke',
            'source_ref' => $this->stringOption('source-ref'),
            'run_id' => $this->stringOption('run-id'),
            'run_ids' => $this->stringOption('run-ids'),
            'battery_id' => $this->stringOption('battery-id'),
            'reviewer' => $this->stringOption('reviewer'),
            'reason' => $this->stringOption('reason'),
            'output_dir' => $this->stringOption('output-dir'),
            'confirmations' => [
                'runbook_reviewed' => (bool) $this->option('confirm-runbook-reviewed'),
                'provider_cost' => (bool) $this->option('confirm-provider-cost'),
                'real_provider_call' => (bool) $this->option('confirm-real-provider-call'),
            ],
            'evidence_stage' => $this->resolveEvidenceStage(),
            'verify_mode' => $this->resolveVerifyMode(),
            'input' => $this->stringOption('input'),
            'output_path' => $this->stringOption('output-path'),
            'dry_run' => (bool) $this->option('dry-run'),
            'resume' => (bool) $this->option('resume'),
            'json' => (bool) $this->option('json'),
            'strict' => (bool) $this->option('strict'),
        ];

        $response = $dispatcher->dispatch($action, $input);
        $exitCode = (int) ($response['_exit_code'] ?? $this->exitCodeFor((string) ($response['status'] ?? ''), $input['strict']));
        unset($response['_exit_code']);

        if ($input['json']) {
            $this->line(json_encode(
                $response,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->renderHuman($response);
        }

        return $exitCode;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function firstCaseOption(): ?string
    {
        $list = (array) $this->option('case');
        foreach ($list as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                return trim($entry);
            }
        }

        return null;
    }

    private function resolveEvidenceStage(): ?string
    {
        $explicit = $this->stringOption('stage');
        if ($explicit !== null) {
            return $explicit;
        }
        if ((bool) $this->option('require-final-scorecard')) {
            return 'final';
        }

        return null;
    }

    /**
     * Resolve the verifier mode. `--verify-mode` wins; falling back to
     * `--mode` for the briefing-canon form `verify-evidence --mode=real_run`.
     * Returns null when neither is set so the dispatcher applies its own
     * default (replay).
     */
    private function resolveVerifyMode(): ?string
    {
        $explicit = $this->stringOption('verify-mode');
        if ($explicit !== null) {
            return $explicit;
        }
        $fallback = $this->stringOption('mode');
        if ($fallback === null) {
            return null;
        }
        $lower = strtolower($fallback);
        if (in_array($lower, ['dry_run', 'fake_run', 'real_run', 'replay', 'integrity', 'check'], true)) {
            return $lower;
        }

        return null;
    }

    private function exitCodeFor(string $status, bool $strict): int
    {
        if (str_starts_with($status, 'pending_slice_')) {
            return 2;
        }

        return match ($status) {
            'ok' => self::SUCCESS,
            'passed' => self::SUCCESS,
            'ready_for_dry_run',
            'dry_run_passed',
            'awaiting_operator_confirmations',
            'smoke_passed',
            'audit_ready',
            'running',
            'completed' => self::SUCCESS,
            'blocked' => $strict ? self::FAILURE : self::SUCCESS,
            'invalid_missing_evidence',
            'invalid_hash_mismatch',
            'insufficient_evidence' => $strict ? self::FAILURE : self::SUCCESS,
            'error' => self::FAILURE,
            default => self::FAILURE,
        };
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function renderHuman(array $response): void
    {
        $this->components->twoColumnDetail('atlas:forge:rivals', (string) ($response['action'] ?? 'unknown'));
        $this->components->twoColumnDetail('status', (string) ($response['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('external provider call', ($response['external_provider_call'] ?? false) ? 'yes' : 'no');
        if (isset($response['pending_slice'])) {
            $this->components->twoColumnDetail('pending in slice', (string) $response['pending_slice']);
        }
        if (! empty($response['blockers'])) {
            $this->newLine();
            foreach ((array) $response['blockers'] as $b) {
                $this->warn('blocker: '.(string) $b);
            }
        }
        if (isset($response['note'])) {
            $this->line('note: '.(string) $response['note']);
        }
        if (isset($response['next_command'])) {
            $this->line('next: '.(string) $response['next_command']);
        }
    }
}
