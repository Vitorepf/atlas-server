<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\Forge\Execution\ForgeObraSupervisor;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/** Run the unattended Forge lease/heartbeat supervisor once. */
final class AtlasForgeSupervisorCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:forge:supervise
        {--obra=* : Optional Obra intake ids to supervise}
        {--lease-seconds=900 : Lease renewal horizon}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Reap expired Forge leases and heartbeat every active Obra.';

    public function handle(ForgeObraSupervisor $supervisor): int
    {
        $result = $supervisor->run(
            obraIds: array_values(array_map('strval', (array) $this->option('obra'))),
            leaseSeconds: max(1, (int) $this->option('lease-seconds')),
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->components->twoColumnDetail('Forge supervisor', (string) $result['status']);
            $this->components->twoColumnDetail('Active Obras', (string) $result['active_obra_count']);
        }

        return ($result['status'] ?? '') === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
