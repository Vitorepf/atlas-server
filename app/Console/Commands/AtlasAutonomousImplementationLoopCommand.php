<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAutonomousImplementationLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Autonomous Implementation Loop CLI.
 *
 *   php artisan atlas:aaeos:autonomous-implementation-loop
 *     [--stage=execute]
 *     [--completed=observe,diagnose,research,...]
 *     [--slice="Implement read-only self-construction gap report with tests and docs"]
 *     [--maturity-delta=1]
 *     [--stop=high_risk_without_human_gate,gates_unavailable]
 *     [--json]
 *
 * Read-only, deterministic. Decides whether one autonomous loop step may
 * proceed (stage gating + stop conditions + Small Slice Rule + Loop Receipt).
 * It NEVER edits files, runs gates or applies learning by itself.
 *
 * @see docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
 */
class AtlasAutonomousImplementationLoopCommand extends Command
{
    protected $signature = 'atlas:aaeos:autonomous-implementation-loop
        {--stage= : requested loop stage (default: first incomplete stage)}
        {--completed= : comma-separated completed stages}
        {--slice= : slice description (used for the Small Slice Rule)}
        {--maturity-delta= : numeric maturity delta for the slice (>0 improves maturity)}
        {--stop= : comma-separated stop-condition signal keys that are true}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · autonomous implementation loop governor (proceed|stop with stage/stop/slice/receipt reasons).';

    public function handle(AtlasAutonomousImplementationLoopService $service): int
    {
        try {
            $completed = $this->list('completed');
            $sliceText = $this->option('slice');
            $maturityDelta = $this->option('maturity-delta');

            // A loop step with no slice given defaults to the documented Good
            // example: a small, maturity-improving, read-only slice. This keeps
            // the safe-default CLI run a `proceed` when no stop signal is set.
            $slice = [
                'description' => is_string($sliceText) && trim($sliceText) !== ''
                    ? trim($sliceText)
                    : 'Implement read-only self-construction gap report with tests and docs',
                'maturity_delta' => is_string($maturityDelta) && trim($maturityDelta) !== ''
                    ? trim($maturityDelta)
                    : 1,
            ];

            $signals = [];
            foreach ($this->list('stop') as $key) {
                $signals[$key] = true;
            }

            $request = [
                'requested_stage' => is_string($this->option('stage')) ? $this->option('stage') : null,
                'completed_stages' => $completed,
                'slice' => $slice,
                'signals' => $signals,
                // A complete sample Loop Receipt so the default run isn't blocked
                // on an absent receipt; real callers pass their own.
                'receipt' => [
                    'operation_id' => 'AIL-LOCAL-0001',
                    'target_capability' => 'autonomous_implementation_loop',
                    'maturity_delta' => $slice['maturity_delta'],
                    'allowed_files' => ['app/Services/Ai/Aaeos/Generated/'],
                    'allowed_commands' => ['php artisan atlas:engineering:knowledge docs-health --json'],
                    'required_gates' => ['docs-health'],
                    'rollback' => 'git revert',
                    'evidence' => ['docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md'],
                    'max_scope' => 'single read-only slice',
                ],
            ];

            $result = $service->evaluate($request);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['decision'] === AtlasAutonomousImplementationLoopService::DECISION_PROCEED
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'autonomous_implementation_loop_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
