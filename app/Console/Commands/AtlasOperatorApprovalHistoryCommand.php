<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Autonomy\OperatorApprovalHistoryMeter;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasOperatorApprovalHistoryCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:operator-approval-history
        {--days= : Optional lookback window in days}
        {--json : Emit JSON}';

    protected $description = 'MULTN15-02 read-only ask-vs-act approval history by action class and risk.';

    public function handle(OperatorApprovalHistoryMeter $meter): int
    {
        $days = $this->option('days');
        $payload = $meter->report(is_numeric($days) ? (int) $days : null);

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
