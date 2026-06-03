<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopScientificReadinessAudit;
use Illuminate\Console\Command;

final class AtlasFinanceStrategyScientificReadinessAuditCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-scientific-readiness-audit
        {--campaign-id= : Campaign id to audit; defaults to latest/running campaign}
        {--dry-run-ledger : Audit dry-run campaign storage}
        {--runtime : Include live process and STOP-switch checks through the operational audit}
        {--json : Emit machine-readable JSON}';

    protected $description = 'End-to-end readiness audit for the finance strategy scientific campaign platform.';

    public function handle(StrategyLoopScientificReadinessAudit $audit): int
    {
        $payload = $audit->audit(
            (bool) $this->option('dry-run-ledger'),
            trim((string) $this->option('campaign-id')) ?: null,
            (bool) $this->option('runtime'),
        );
        $exit = (string) ($payload['status'] ?? 'fail') === 'pass' ? self::SUCCESS : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return $exit;
        }

        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Campaign', (string) ($payload['campaign_id'] ?? 'none'));
        $this->components->twoColumnDetail('Score', (string) data_get($payload, 'score.passed').'/'.(string) data_get($payload, 'score.total'));
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }
            $this->line(sprintf(
                '%s %s — %s',
                (bool) ($check['passed'] ?? false) ? '[pass]' : '[fail]',
                (string) ($check['name'] ?? 'unknown'),
                (string) ($check['detail'] ?? ''),
            ));
        }

        return $exit;
    }
}
