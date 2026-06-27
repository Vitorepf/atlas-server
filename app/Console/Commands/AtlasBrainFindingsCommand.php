<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPlanAdviser;
use Illuminate\Console\Command;

/**
 * BRAIN FINDINGS — reference dumper for the adviser's CODE→PATH table. Lets operator see every
 * known finding code and which portfolio path the adviser would route it to. Read-only. Pétreo.
 */
final class AtlasBrainFindingsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:findings {--by-path : group codes under their portfolio path} {--filter-path= : keep only codes routed to one path} {--csv : emit CSV (code,path) instead of JSON} {--json} {--raw}';

    /** @var string */
    protected $description = 'Dump the adviser code→path mapping for every known doctor finding.';

    public function handle(): int
    {
        $map = AtlasBrainPlanAdviser::CODE_TO_PATH;
        $rows = [];
        foreach ($map as $code => $path) {
            $rows[] = ['code' => $code, 'recommended_path' => $path];
        }
        usort($rows, static fn (array $a, array $b): int => $a['code'] <=> $b['code']);

        $filter = trim((string) ($this->option('filter-path') ?? ''));
        if ($filter !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['recommended_path'] === $filter));
        }

        if ($this->option('csv')) {
            $this->line('code,recommended_path');
            foreach ($rows as $r) {
                $this->line($r['code'].','.$r['recommended_path']);
            }

            return self::SUCCESS;
        }

        $payload = ['count' => count($rows), 'mapping' => $rows];

        if ($this->option('by-path')) {
            $grouped = [];
            foreach ($map as $code => $path) {
                $grouped[$path] ??= [];
                $grouped[$path][] = (string) $code;
            }
            foreach ($grouped as &$codes) {
                sort($codes);
            }
            unset($codes);
            ksort($grouped);
            $payload['by_path'] = $grouped;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (! $this->option('raw')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->line((string) json_encode($payload, $flags));

        return self::SUCCESS;
    }
}
