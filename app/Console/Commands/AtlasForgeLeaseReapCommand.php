<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\Forge\ForgeScopeReservationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/** Reclaims expired Forge scope leases while preserving fencing history. */
final class AtlasForgeLeaseReapCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:forge:reap-leases {--json : Emit machine-readable JSON}';

    protected $description = 'Reap expired Forge scope leases through the canonical reservation owner.';

    public function handle(ForgeScopeReservationService $reservations): int
    {
        $result = [
            'schema' => 'atlas.forge.lease_reap.v1',
            'status' => 'ok',
            'result' => $reservations->reapExpired(),
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->components->twoColumnDetail('Forge leases reaped', (string) $result['result']['reaped_count']);
        }

        return self::SUCCESS;
    }
}
