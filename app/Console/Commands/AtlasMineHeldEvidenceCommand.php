<?php

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasHeldEvidenceMinerService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Mine held governed evidence (stamped by atlas:ai:bridge-evidence) into
 * propose-only learning proposals. Closes the compounding loop; never promotes.
 */
class AtlasMineHeldEvidenceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:mine-held-evidence
        {--hours=24 : Look-back window in hours}
        {--min-corroboration=1 : Minimum same-kind held events to mint a proposal}
        {--json : Print machine-readable JSON}';

    protected $description = 'Mine held governed evidence into propose-only learning proposals (never promotes).';

    public function handle(AtlasHeldEvidenceMinerService $miner): int
    {
        $report = $miner->mine((int) $this->option('hours'), (int) $this->option('min-corroboration'));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        if (($report['status'] ?? '') === 'unavailable') {
            $this->warn('Evidence/learning tables unavailable — nothing mined.');

            return self::SUCCESS;
        }

        $this->info('Scanned '.$report['scanned'].' evidence event(s); '.$report['held'].' held → '.count($report['proposals']).' propose-only proposal(s).');

        return self::SUCCESS;
    }
}
