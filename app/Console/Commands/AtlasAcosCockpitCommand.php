<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AcosMax\AcosProgramCockpitService;
use Illuminate\Console\Command;

final class AtlasAcosCockpitCommand extends Command
{
    protected $signature = 'atlas:acos:cockpit
        {--scoreboard= : Override ACOS Max scoreboard path}
        {--json : Emit machine-readable JSON}';

    protected $description = 'TETO-08 read-only ACOS Max program cockpit aggregator.';

    public function handle(AcosProgramCockpitService $service): int
    {
        $payload = $service->report($this->scoreboardPath());

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

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
