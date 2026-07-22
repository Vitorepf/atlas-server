<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosMaxVerifiedShareService;
use Illuminate\Console\Command;

final class AtlasAcosVerifiedShareCommand extends Command
{
    protected $signature = 'atlas:acos:verified-share
        {--days= : Window size in days; defaults to the frozen threshold}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report ACOS Max ELEV-12 verified_share from OUTC-01 outcomes and verification receipts.';

    public function handle(AcosMaxVerifiedShareService $service): int
    {
        $days = $this->option('days');
        $payload = $service->report(is_numeric($days) ? (int) $days : null);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '[atlas:acos:verified-share] %s verified=%s total=%s share=%s',
            (string) ($payload['status'] ?? 'unknown'),
            (string) data_get($payload, 'aggregate.verified_count', 0),
            (string) data_get($payload, 'aggregate.total_count', 0),
            data_get($payload, 'aggregate.verified_share') === null ? 'n/a' : (string) data_get($payload, 'aggregate.verified_share'),
        ));

        return self::SUCCESS;
    }
}
