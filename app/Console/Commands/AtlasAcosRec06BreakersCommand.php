<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\Recursion\MetaLoopBreakerService;
use Illuminate\Console\Command;
use Throwable;

final class AtlasAcosRec06BreakersCommand extends Command
{
    protected $signature = 'atlas:acos:rec06-breakers
        {--series= : Optional JSON file with voi/r/m_operator series payloads}
        {--json : Emit JSON}';

    protected $description = 'REC-06 meta-loop breakers; disarmed unless real measured series are present.';

    public function handle(MetaLoopBreakerService $service): int
    {
        $payload = $service->evaluate($this->readSeries());

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return self::SUCCESS;
    }

    /** @return array<string,array<string,mixed>> */
    private function readSeries(): array
    {
        $path = $this->option('series');
        if (! is_string($path) || trim($path) === '') {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents(trim($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $series = [];
        foreach (['voi', 'r', 'm_operator'] as $key) {
            if (is_array($decoded[$key] ?? null)) {
                $series[$key] = $decoded[$key];
            }
        }

        return $series;
    }
}
