<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexCouncilTriangulator;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexLensRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use Illuminate\Console\Command;

/**
 * CORTEX COUNCIL CLI — runs every registered lens against a subject and prints the {@see CouncilReport}.
 * Flag-gated by `atlas.cortex.council.enabled` (default false): when OFF the command exits 0 with a
 * `{"status":"disabled"}` JSON line and runs ZERO lenses (byte-identical no-op).
 */
final class AtlasLoopCortexCouncilCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cortex:council {--subject= : symbol or path identifying the subject} {--file-path= : optional file path to feed lenses} {--source-code= : optional raw php source string} {--json}';

    /** @var string */
    protected $description = 'Run all registered Cortex Council lenses against a subject and print the triangulated report.';

    public function handle(
        AtlasCortexLensRegistry $registry,
        AtlasCortexCouncilTriangulator $triangulator,
    ): int {
        if (! (bool) config('atlas.cortex.council.enabled', false)) {
            $this->line((string) json_encode(['status' => 'disabled', 'reason' => 'atlas.cortex.council.enabled=false'], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $subjectId = trim((string) $this->option('subject'));
        if ($subjectId === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'missing --subject'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $facts = [];
        $filePath = trim((string) $this->option('file-path'));
        if ($filePath !== '') {
            $facts['file_path'] = $filePath;
        }
        $source = (string) $this->option('source-code');
        if ($source !== '') {
            $facts['source_code'] = $source;
        }
        $subject = new CortexSubject($subjectId, 'cli_subject', $facts);

        $observations = [];
        foreach ($registry->all() as $lens) {
            $observations[] = $lens->observe($subject);
        }

        $report = $triangulator->triangulate($observations);
        $this->line((string) json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
