<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasAucriOptimizationAuditService;
use Illuminate\Console\Command;

final class AtlasAucriOptimizeAuditCommand extends Command
{
    protected $signature = 'atlas:aucri:optimize-audit
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'Audit AUCRI optimization contracts for token reduction without quality loss.';

    public function handle(AtlasAucriOptimizationAuditService $service): int
    {
        $payload = $service->audit();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas AUCRI Optimization Audit', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('Status', (string) $payload['status']);
            $this->components->twoColumnDetail('Checks', sprintf(
                'passed=%d · critical_failed=%d · warn_failed=%d',
                (int) data_get($payload, 'summary.passed', 0),
                (int) data_get($payload, 'summary.critical_failed', 0),
                (int) data_get($payload, 'summary.warn_failed', 0),
            ));
            $this->components->twoColumnDetail('Audit hash', (string) $payload['audit_hash']);
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== 'ready') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
