<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRubricComplianceGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopRubricComplianceGate} at the operator surface for the first time: reads a
 * draft idea text from --file and emits the deterministic admission verdict (ok, structural reasons such as
 * missing_probe_block / insufficient_resolved_evidence, plus advisory warnings) as JSON. Read-only: no
 * queue/DB/provider/process — it only reads the draft file and probes the repo for cited evidence paths.
 */
final class AtlasLoopRubricCheckCommand extends Command
{
    protected $signature = 'atlas:loop:rubric-check {--file=} {--json}';

    protected $description = 'Read-only idea-draft admission rubric check (structural reasons + advisory warnings).';

    public function handle(AtlasLoopRubricComplianceGate $gate): int
    {
        $filePath = trim((string) $this->option('file'));
        if ($filePath === '' || ! is_file($filePath) || ! is_readable($filePath)) {
            $this->line('usage_error: rubric-check requires a readable --file=<path>');

            return self::FAILURE;
        }

        $verdict = $gate->admit((string) file_get_contents($filePath), base_path());
        $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
