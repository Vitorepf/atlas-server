<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopPlanCompletionAudit;
use Illuminate\Console\Command;

final class AtlasFinanceStrategyPlanCompletionAuditCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-plan-completion-audit
        {--campaign-id= : Campaign id to audit; defaults to latest/running campaign}
        {--dry-run-ledger : Audit dry-run campaign storage}
        {--runtime : Include live process and STOP-switch checks through nested audits}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Requirement-by-requirement completion audit for the finance strategy scientific campaign plan.';

    public function handle(StrategyLoopPlanCompletionAudit $audit): int
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
