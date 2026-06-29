<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackReshapeStrategy;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasMaestroGiveBackReshapeStrategy::propose()} at the operator surface: from a
 * repeatedly given-back task's evidence, proposes how to reshape its scope into a deliverable one (drop
 * forbidden / scope-mismatch entries, anchor on the missing-symbol trace, keep inside the original domain
 * envelope) — or a CLEAR none when no reshape applies. Pure and read-only.
 *
 * --evidence accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopReshapeProposeCommand extends Command
{
    protected $signature = 'atlas:loop:reshape-propose {--evidence=} {--json}';

    protected $description = 'Read-only: propose how to reshape a given-back task into a deliverable one (or none).';

    public function handle(AtlasMaestroGiveBackReshapeStrategy $strategy): int
    {
        $evidenceOption = $this->option('evidence');
        if ($evidenceOption === null || trim((string) $evidenceOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'evidence_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $evidenceOption) ? (string) file_get_contents((string) $evidenceOption) : (string) $evidenceOption;
        $evidence = json_decode($raw, true);
        if (! is_array($evidence)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'evidence' => (string) $evidenceOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $proposal = $strategy->propose($evidence);

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.reshape_propose.v1'] + ($proposal !== null ? $proposal->toArray() : ['empty' => true, 'allowed_files' => [], 'confidence' => 'none', 'rationale' => []]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
