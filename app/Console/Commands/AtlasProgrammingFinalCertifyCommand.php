<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\ProgrammingRuntime\AtlasProgrammingFinalCertificationService;
use Illuminate\Console\Command;

/**
 * Internal-only final certification CLI for the Atlas Programming Runtime.
 *
 * Aggregates the 12 canonical readiness dimensions documented in
 * `docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md`
 * and emits a single deterministic JSON payload conforming to
 * `atlas.programming.runtime_final_certification.v1`.
 *
 * Hard contract: this command NEVER runs an external rivals battery. The
 * service surfaces `benchmark_status = not_run` even when every check is
 * green; promotion to external benchmark is governed by a separate flow.
 *
 * --strict makes the CLI fail-closed (non-zero exit) whenever overall_status
 * is not `green`. That mode is designed for CI / engineering gates that need
 * to refuse merge until P0/P1 blockers are resolved.
 */
final class AtlasProgrammingFinalCertifyCommand extends Command
{
    protected $signature = 'atlas:programming:final-certify
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless overall_status is green}';

    protected $description = 'Internal final certification of the Atlas Programming Runtime. NEVER runs an external rivals/benchmark battery.';

    public function handle(AtlasProgrammingFinalCertificationService $service): int
    {
        $payload = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->renderHuman($payload);
        }

        $overall = (string) ($payload['overall_status'] ?? AtlasProgrammingFinalCertificationService::STATUS_BLOCKED);

        if ((bool) $this->option('strict') && $overall !== AtlasProgrammingFinalCertificationService::STATUS_GREEN) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->line(sprintf(
            '<info>Atlas Programming Runtime — Final Certification</info> (schema %s)',
            $payload['schema_version'] ?? 'unknown',
        ));
        $this->line('Overall status: <comment>'.($payload['overall_status'] ?? 'unknown').'</comment>');
        $this->line('Benchmark status: <comment>'.($payload['benchmark_status'] ?? 'unknown').'</comment>');
        $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->newLine();
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            $status = (string) ($check['status'] ?? '');
            $tag = match ($status) {
                AtlasProgrammingFinalCertificationService::CHECK_STATUS_GREEN => '<info>PASS</info>',
                AtlasProgrammingFinalCertificationService::CHECK_STATUS_WARN => '<comment>WARN</comment>',
                default => '<error>FAIL</error>',
            };
            $this->line(sprintf(
                '%s [%s] %s — %s',
                $tag,
                $check['severity'] ?? '?',
                $check['check_id'] ?? '?',
                $check['reason'] ?? '',
            ));
        }
        if (! empty($payload['blockers'])) {
            $this->newLine();
            $this->warn('Blockers:');
            foreach ($payload['blockers'] as $blocker) {
                $this->line(sprintf(
                    '  - %s (%s) — %s',
                    $blocker['check_id'] ?? '?',
                    $blocker['severity'] ?? '?',
                    $blocker['reason'] ?? '',
                ));
            }
        }
    }
}
