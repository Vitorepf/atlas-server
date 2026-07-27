<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Engineering\CodeMap\ZoneCodeMapBuilder;
use Illuminate\Console\Command;

/**
 * Generate and verify the per-zone CODEMAPs of app/Services/Ai.
 *
 * --verify is the gate: it exits non-zero when a map is missing or drifted, so
 * the corpus can never silently outgrow its own navigation again.
 */
class AtlasCodemapCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:codemap
        {--write : Rebuild every zone CODEMAP on disk}
        {--verify : Exit 1 when any zone CODEMAP is missing or drifted}
        {--json : Machine-readable JSON}';

    protected $description = 'Derive per-zone CODEMAPs for app/Services/Ai (public façade → navigation target).';

    public function handle(): int
    {
        $builder = new ZoneCodeMapBuilder(base_path());
        $maps = $builder->build();

        $written = [];
        $missing = [];
        $drifted = [];

        foreach ($maps as $relative => $markdown) {
            $absolute = base_path($relative);
            $current = is_file($absolute) ? (string) file_get_contents($absolute) : null;

            if ($this->option('write')) {
                if ($current !== $markdown) {
                    file_put_contents($absolute, $markdown);
                    $written[] = $relative;
                }

                continue;
            }

            if ($current === null) {
                $missing[] = $relative;
            } elseif ($current !== $markdown) {
                $drifted[] = $relative;
            }
        }

        $facades = 0;
        foreach ($maps as $markdown) {
            $facades += substr_count($markdown, "\n| ") - 1; // minus the header separator row
        }

        $ok = $this->option('verify') ? ($missing === [] && $drifted === []) : true;

        $payload = [
            'schema' => 'atlas.codemap.zone_report.v1',
            'zones' => count($maps),
            'facades' => $facades,
            'written' => $written,
            'missing' => $missing,
            'drifted' => $drifted,
            'ok' => $ok,
        ];

        if ($this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->line('zones='.count($maps).' facades='.$facades);
            foreach ($written as $path) {
                $this->line('written '.$path);
            }
            foreach ($missing as $path) {
                $this->line('MISSING '.$path);
            }
            foreach ($drifted as $path) {
                $this->line('DRIFTED '.$path);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
