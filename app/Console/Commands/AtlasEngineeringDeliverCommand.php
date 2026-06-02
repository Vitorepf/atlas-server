<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use Illuminate\Console\Command;

/**
 * Operator entrypoint for a REAL code delivery — a provider turns a
 * natural-language goal into a syntax-verified artifact in an isolated
 * sandbox, certified for review (never merged to the working repo).
 *
 * This is the real path that replaces the fixture diff in
 * AtlasRealEngineeringExecutionKernelService::executePatch.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-engineering-run-conductor.md
 */
class AtlasEngineeringDeliverCommand extends Command
{
    protected $signature = 'atlas:engineering:deliver
        {goal : Natural-language code goal}
        {--provider=codex_cli : Provider that generates the code (read-only)}
        {--model= : Optional model alias/id}
        {--target-file=AtlasGeneratedSnippet.php : Sandbox-relative artifact path}
        {--timeout=120 : Per-provider timeout seconds}
        {--verify-run : Ask for + run self-tests (hardened sandbox); certify only if they pass}
        {--json : Emit the delivery envelope as JSON}';

    protected $description = 'Real code delivery: a provider generates a syntax-verified artifact in an isolated sandbox, certified for review (never merged).';

    public function handle(AtlasLiveCodeDeliveryService $delivery): int
    {
        $model = (string) $this->option('model');

        $envelope = $delivery->deliver((string) $this->argument('goal'), [
            'provider' => (string) $this->option('provider'),
            'model' => $model !== '' ? $model : null,
            'target_file' => (string) $this->option('target-file'),
            'timeout_seconds' => (int) $this->option('timeout'),
            'verify_run' => (bool) $this->option('verify-run'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf(
                'Delivery [%s] provider=%s target=%s certified=%s',
                (string) ($envelope['status'] ?? 'unknown'),
                (string) ($envelope['provider'] ?? '?'),
                (string) ($envelope['target_file'] ?? '?'),
                ($envelope['certified'] ?? false) ? 'yes' : 'no',
            ));
            $syntax = $envelope['syntax_check'] ?? null;
            if (is_array($syntax)) {
                $this->line('  syntax: '.(($syntax['ok'] ?? false) ? 'ok' : 'FAILED'));
            }
            if (isset($envelope['blocked_reason'])) {
                $this->line('  blocked: '.(string) $envelope['blocked_reason']);
            }
            if (isset($envelope['sandbox_path'])) {
                $this->line('  artifact: '.(string) $envelope['sandbox_path'].' (review-only, not merged)');
            }
        }

        return ($envelope['certified'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
