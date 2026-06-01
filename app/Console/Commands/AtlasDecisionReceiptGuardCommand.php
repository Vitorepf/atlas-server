<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDecisionReceiptGuardService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Decision Receipt execution-boundary guard.
 *
 * Demonstrates the gate on a complete, in-scope receipt: the target file sits
 * inside allowed_scope and outside forbidden_scope, so the verdict is
 * `proceed`. Change the receipt or target to see the documented stops.
 *
 * @see docs/engineering-knowledge-base/system-graph/decision-receipt.md
 */
final class AtlasDecisionReceiptGuardCommand extends Command
{
    protected $signature = 'atlas:aaeos:decision-receipt-guard {--json : Machine-readable JSON output}';

    protected $description = 'Gate a target file against a Decision Receipt: stop unless a complete, unexpired receipt allows the path.';

    public function handle(AtlasDecisionReceiptGuardService $service): int
    {
        try {
            $receipt = [
                'decision_id' => 'dec_01HCANONICALDEMO',
                'trace_id' => 'trace_01HCANONICALDEMO',
                'obra' => 'atlas-kernel',
                'domain' => 'engineering',
                'flow' => 'engineering.refactor',
                'provider' => 'atlas.decide',
                'fallback' => 'atlas.decide.secondary',
                'confidence' => 0.92,
                'budget' => ['tokens' => 20000],
                'autonomy' => 'governed',
                'allowed_scope' => ['app/Services/**', 'tests/**'],
                'forbidden_scope' => ['app/Services/**/Secrets/**', '.env'],
                'rollback' => 'git restore --staged --worktree -- app/Services',
                'required_gates' => ['docs-health', 'unit-tests'],
            ];

            $result = $service->decide($receipt, 'app/Services/Example/WidgetService.php');
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
