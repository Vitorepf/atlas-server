<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasQualityGatesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Quality Gates kernel decider CLI.
 *
 *   php artisan atlas:aaeos:quality-gates [--json]
 *
 * Read-only and deterministic. Evaluates the documented quality gates for one
 * execution and emits the verdict (pass | repair_required | evidence_required |
 * blocked | fail), the promotion state and an audit receipt. With safe defaults
 * (no persisted evidence) it demonstrates the golden rule: without evidence the
 * gate refuses to promote.
 *
 * @see docs/engineering-knowledge-base/system-graph/quality-gates.md
 * @see docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
 */
class AtlasQualityGatesCommand extends Command
{
    protected $signature = 'atlas:aaeos:quality-gates {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas kernel · quality-gates decider for one execution (pass|repair_required|evidence_required|blocked|fail).';

    public function handle(AtlasQualityGatesService $service): int
    {
        try {
            // Safe default execution: required gates ran, but no evidence was
            // persisted yet — the golden rule must yield evidence_required.
            $decision = $service->evaluate([
                'required_tests_ran' => true,
                'evidence_persisted' => false,
                'acceptance_criteria' => [],
                'review_findings' => [],
                'manual_qa' => ['required' => false],
                'postgres_gate' => ['required' => false],
                'telemetry' => [],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'quality_gates_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
