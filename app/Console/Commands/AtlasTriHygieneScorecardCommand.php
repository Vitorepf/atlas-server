<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosTriHygieneScorecardProjector;
use Illuminate\Console\Command;

final class AtlasTriHygieneScorecardCommand extends Command
{
    protected $signature = 'atlas:tri-hygiene:scorecard {--json : Machine-readable JSON}';

    protected $description = 'TRI-HYGIENE scoreboard: CLI · Gates · AutonomousEvolution';

    public function handle(AaeosTriHygieneScorecardProjector $projector): int
    {
        $card = $projector->project();
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->components->info('TRI-HYGIENE');
        $this->components->twoColumnDetail('CLI', (string) $card['cli']['score']);
        $this->components->twoColumnDetail('Gates', (string) $card['gates']['score']);
        $this->components->twoColumnDetail('AE', (string) $card['autonomous_evolution']['score']);
        $this->components->twoColumnDetail('Final', (string) $card['final']);
        $this->components->twoColumnDetail('all_ten', $card['all_ten'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
