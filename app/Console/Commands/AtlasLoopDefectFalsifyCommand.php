<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Defect\AtlasLoopDefectFalsificationGate;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopDefectFalsificationGate::admit()} at the operator surface: falsifies a
 * defect candidate so only REPRODUCED defects become tasks — admitted ONLY when it carries a red_command that
 * actually failed on the current tree (red_observed) and the fix moves behavior in the right direction.
 *
 * Pure + read-only + fail-closed: a candidate without a reproducing red_command is never admitted; emits the
 * named blocking reasons. No provider/DB/mutation.
 */
final class AtlasLoopDefectFalsifyCommand extends Command
{
    protected $signature = 'atlas:loop:defect-falsify {--candidate=} {--json}';

    protected $description = 'Read-only defect falsification gate: admit a candidate only when it is reproduced-RED.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('candidate'));
        if ($raw === '') {
            return $this->refuse('defect-falsify requires --candidate=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $candidate = json_decode($raw, true);
        if (! is_array($candidate)) {
            return $this->refuse('--candidate must be a JSON object');
        }

        $verdict = app(AtlasLoopDefectFalsificationGate::class)->admit($candidate);

        if ($this->option('json')) {
            $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('admitted: '.($verdict['admitted'] ? 'yes' : 'no'));
            foreach ($verdict['blocking_reasons'] as $r) {
                $this->line('  blocking: '.$r);
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
