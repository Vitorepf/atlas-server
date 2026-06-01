<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRuntimeExecutorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Runtime Executor kernel gear.
 *
 * Demonstrates the gear on an authorized, in-scope execution request: the
 * receipt is active, the files sit inside allowed_scope, the command is
 * sanctioned, no Kernel-owned field is decided by the runtime, and an evidence
 * reference is present — so the verdict is `execute` and it hands off to the
 * quality gates. Change the request (touch a file outside scope, run an
 * unsanctioned command, or drop the evidence reference) to see the documented
 * refusals.
 *
 * @see docs/engineering-knowledge-base/system-graph/runtime-executor.md
 */
final class AtlasRuntimeExecutorCommand extends Command
{
    protected $signature = 'atlas:aaeos:runtime-executor {--json : Machine-readable JSON output}';

    protected $description = 'Gate an execution request against a signed Decision Receipt: execute only strictly inside the receipt scope, refuse scope amplification, escaping commands and runs without evidence.';

    public function handle(AtlasRuntimeExecutorService $service): int
    {
        try {
            $receipt = [
                'active' => true,
                'allowed_scope' => ['app/Services/**', 'tests/**'],
                'forbidden_scope' => ['app/Services/**/Secrets/**', '.env'],
                'allowed_commands' => ['php artisan test --filter WidgetServiceTest'],
            ];

            $request = [
                'files' => ['app/Services/Example/WidgetService.php'],
                'commands' => ['php artisan test --filter WidgetServiceTest'],
                'decided_fields' => [],
                'evidence_ref' => 'evidence://run/01HCANONICALDEMO',
            ];

            $result = $service->decide($receipt, $request);
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
