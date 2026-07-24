<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAaeosScorecardCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aaeos:scorecard
        {--json : Machine-readable JSON}';

    protected $description = 'Project AAEOS GOD/SOTA scorecard (read-only).';

    public function handle(AaeosScorecardProjector $projector): int
    {
        $card = $projector->project();

        if ((bool) $this->option('json')) {
            $this->jsonLine($card);

            return ((bool) ($card['god_sota'] ?? false)) ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('composite', (string) ($card['composite'] ?? '0'));
        $this->components->twoColumnDetail('god_sota', ! empty($card['god_sota']) ? 'YES' : 'NO');
        $this->components->twoColumnDetail('quarantine_imports', (string) ($card['quarantine_production_imports'] ?? '?'));

        foreach ((array) ($card['dimensions'] ?? []) as $name => $score) {
            $this->components->twoColumnDetail((string) $name, (string) $score);
        }

        return ! empty($card['god_sota']) ? self::SUCCESS : self::FAILURE;
    }
}
