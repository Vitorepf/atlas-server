<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneVerificationCourt;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasProjectLaneVerificationCourt::adjudicate()} at the operator surface: reads
 * a project lane's verification input from JSON and emits the court's verdict (pass | hold | blocked), the
 * blockers, and the proof summary — WITHOUT trusting worker self-report.
 *
 * Pure + read-only: identical input ⇒ byte-identical envelope. No provider/DB/mutation.
 */
final class AtlasLoopLaneVerdictCommand extends Command
{
    protected $signature = 'atlas:loop:lane-verdict {--input=} {--json}';

    protected $description = 'Read-only project-lane verification verdict (pass|hold|blocked) from verification evidence.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('input'));
        if ($raw === '') {
            return $this->refuse('lane-verdict requires --input=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }

        $input = json_decode($raw, true);
        if (! is_array($input)) {
            return $this->refuse('--input must be a JSON object');
        }

        $envelope = app(AtlasProjectLaneVerificationCourt::class)->adjudicate($input);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('verdict: '.$envelope['verdict'].'  passed: '.($envelope['passed'] ? 'yes' : 'no'));
            foreach ($envelope['blockers'] as $b) {
                $this->line('  blocker: '.$b);
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
