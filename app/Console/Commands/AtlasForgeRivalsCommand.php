<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

class AtlasForgeRivalsCommand extends Command
{
    protected $signature = 'atlas:forge:rivals
        {action=doctor : setup, doctor, preflight, dry-run, plan-real, run-real, collect-evidence, replay, report, reset, smoke, audit}
        {--worktree-root= : Base path for isolated test worktrees}
        {--repo-root= : Source repo root used when provisioning worktrees}
        {--atlas-worktree= : Isolated Atlas Forge worktree}
        {--baseline-worktree= : Isolated rival baseline worktree}
        {--model=sonnet : Atlas model lock; currently sonnet or opus}
        {--baseline-model= : Rival baseline model lock; defaults to --model}
        {--suite=atlas-fair-claude-v1}
        {--case=*}
        {--preset=quick : quick, medium, full}
        {--gate-profile=strict}
        {--run-id= : Run id for collect-evidence/replay/report}
        {--reviewer= : Operator id for reset/triage}
        {--reason= : Auditable reason for reset/triage}
        {--confirm-runbook-reviewed : Confirmation gate 1}
        {--confirm-provider-cost : Confirmation gate 2}
        {--confirm-real-provider-call : Confirmation gate 3; required before any provider dispatch}
        {--output-dir= : Evidence/report output directory}
        {--json}
        {--strict : Non-zero exit on blocked status}';

    protected $description = 'Canonical Atlas Forge Rivals entrypoint backed by the governed Rivals harness.';

    /** @var array<string,string> */
    private const ACTION_MAP = [
        'setup' => 'setup-worktrees',
        'setup-worktrees' => 'setup-worktrees',
        'doctor' => 'doctor',
        'preflight' => 'preflight',
        'dry-run' => 'dry-run',
        'dryrun' => 'dry-run',
        'plan' => 'quick-real-plan',
        'plan-real' => 'quick-real-plan',
        'quick-real-plan' => 'quick-real-plan',
        'run' => 'run-quick-real',
        'run-real' => 'run-quick-real',
        'run-quick-real' => 'run-quick-real',
        'collect' => 'collect-evidence',
        'collect-evidence' => 'collect-evidence',
        'replay' => 'replay',
        'report' => 'report',
        'reset' => 'reset-test-worktrees',
        'reset-test-worktrees' => 'reset-test-worktrees',
        'smoke' => 'full-smoke',
        'full-smoke' => 'full-smoke',
        'audit' => 'audit',
    ];

    public function handle(): int
    {
        $requestedAction = strtolower(trim((string) $this->argument('action')));
        $harnessAction = self::ACTION_MAP[$requestedAction] ?? null;

        if ($harnessAction === null) {
            $payload = [
                'schema_version' => 'atlas.forge.rivals.canonical_entrypoint.v1',
                'status' => 'blocked_unsupported_action',
                'requested_action' => $requestedAction,
                'supported_actions' => array_keys(self::ACTION_MAP),
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $args = [
            'action' => $harnessAction,
            '--worktree-root' => $this->stringOption('worktree-root'),
            '--repo-root' => $this->stringOption('repo-root'),
            '--atlas-worktree' => $this->stringOption('atlas-worktree'),
            '--baseline-worktree' => $this->stringOption('baseline-worktree'),
            '--model' => $this->stringOption('model') ?: 'sonnet',
            '--baseline-model' => $this->stringOption('baseline-model'),
            '--suite' => $this->stringOption('suite') ?: 'atlas-fair-claude-v1',
            '--case' => (array) $this->option('case'),
            '--preset' => $this->stringOption('preset') ?: 'quick',
            '--gate-profile' => $this->stringOption('gate-profile') ?: 'strict',
            '--run-id' => $this->stringOption('run-id'),
            '--reviewer' => $this->stringOption('reviewer'),
            '--reason' => $this->stringOption('reason'),
            '--confirm-runbook-reviewed' => (bool) $this->option('confirm-runbook-reviewed'),
            '--confirm-provider-cost' => (bool) $this->option('confirm-provider-cost'),
            '--confirm-real-provider-call' => (bool) $this->option('confirm-real-provider-call'),
            '--output-dir' => $this->stringOption('output-dir'),
            '--json' => (bool) $this->option('json'),
            '--strict' => (bool) $this->option('strict'),
        ];

        $args = array_filter(
            $args,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false && $value !== [],
        );

        $buffer = new BufferedOutput;
        $exit = Artisan::call('atlas:engineering:benchmark:rivals-harness', $args, $buffer);
        $output = $buffer->fetch();

        if (trim($output) !== '') {
            $this->output->write($output);
        }

        return $exit;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
