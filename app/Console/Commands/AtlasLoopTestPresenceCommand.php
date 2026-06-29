<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTestPresenceOracle;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopTestPresenceOracle::hasTest()} at the operator surface: reports whether a
 * source path has at least one observed coverage edge in the persistent test-coverage ledger.
 *
 * Read-only: a pure ledger read. Fail-open by contract — when the DB/table is unavailable it reports
 * has_test=true, preserving the safe degradation path (no spurious untested→tested signal on infra failure).
 */
final class AtlasLoopTestPresenceCommand extends Command
{
    protected $signature = 'atlas:loop:test-presence {--file=} {--json}';

    protected $description = 'Read-only test-presence check for a source path (observed coverage edge in the ledger).';

    public function handle(): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'test-presence requires --file=<source path>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $hasTest = app(AtlasLoopTestPresenceOracle::class)->hasTest($file);

        $facts = [
            'schema' => 'atlas.loop.test_presence.v1',
            'file' => $file,
            'has_test' => $hasTest,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line($file.': '.($hasTest ? 'tested' : 'UNTESTED'));
        }

        return self::SUCCESS;
    }
}
