<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AcosProgramCockpitService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAcosCockpitCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:acos:cockpit
        {--scoreboard= : Override ACOS Max scoreboard path}
        {--json : Emit machine-readable JSON}';

    protected $description = 'TETO-08 read-only ACOS Max program cockpit aggregator.';

    public function handle(AcosProgramCockpitService $service): int
    {
        $payload = $service->report($this->scoreboardPath());

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->line('ACOS cockpit: '.$payload['schema_version']);
        foreach ((array) ($payload['sections'] ?? []) as $name => $section) {
            $this->line(sprintf(
                '- %s: %s (%s)',
                (string) $name,
                (string) ($section['status'] ?? 'unknown'),
                is_scalar($section['source'] ?? null) ? (string) $section['source'] : 'multiple sources',
            ));
        }

        return self::SUCCESS;
    }

    private function scoreboardPath(): ?string
    {
        $path = $this->option('scoreboard');
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return trim($path);
    }
}
