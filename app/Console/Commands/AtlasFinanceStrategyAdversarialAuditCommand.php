<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopAdversarialAudit;
use Illuminate\Console\Command;

final class AtlasFinanceStrategyAdversarialAuditCommand extends Command
{
    protected $signature = 'atlas:finance:strategy-adversarial-audit
        {--json : Emit machine-readable JSON}';

    protected $description = 'Dry adversarial audit for finance strategy-loop honesty and no-execution invariants.';

    public function handle(StrategyLoopAdversarialAudit $audit): int
    {
        $payload = $audit->audit();
        $exit = (string) ($payload['status'] ?? 'fail') === 'pass' ? self::SUCCESS : self::FAILURE;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return $exit;
        }

        $this->components->twoColumnDetail('Status', (string) $payload['status']);
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
