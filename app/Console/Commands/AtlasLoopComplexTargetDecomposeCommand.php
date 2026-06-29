<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComplexTargetDecomposer;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopComplexTargetDecomposer::decompose()} at the operator surface: reads a target
 * file and emits ONE proposed sub-refactor atom per method whose cyclomatic complexity clears the loop's
 * material bar (worst-method-first), as deterministic facts. Read-only: it only reads the file and runs the
 * pure decomposer — it never enqueues, never mutates. Empty when the file is unparseable or nothing qualifies.
 */
final class AtlasLoopComplexTargetDecomposeCommand extends Command
{
    protected $signature = 'atlas:loop:complex-target-decompose {--file=} {--json}';

    protected $description = 'Read-only: decompose a complex file into material per-method sub-refactor objectives.';

    public function handle(AtlasLoopComplexTargetDecomposer $decomposer): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'file_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $absolute = str_starts_with($file, '/') ? $file : base_path($file);
        if (! is_file($absolute)) {
            $this->line((string) json_encode(['status' => 'file_not_found', 'file' => $file], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $atoms = $decomposer->decompose($file, (string) file_get_contents($absolute));

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.complex_target_decompose.v1',
            'target_path' => ltrim($file, '/'),
            'subtarget_count' => count($atoms),
            'subtargets' => $atoms,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
