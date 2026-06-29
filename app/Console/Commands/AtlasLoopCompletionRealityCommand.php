<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionCompletionRealityProjector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionCompletionRealityProjector::project()} at the operator surface:
 * reads a receipts JSON (verification / merge / rollback / knowledge-sync / worker_claim) and emits the
 * projected completion reality plus residual risks and missing proofs as deterministic facts. Pure and
 * read-only — it only reconciles the receipts; it completes nothing.
 */
final class AtlasLoopCompletionRealityCommand extends Command
{
    protected $signature = 'atlas:loop:completion-reality {--receipts=} {--json}';

    protected $description = 'Read-only: project the real completion state {reality, residual_risks, missing_proofs} from receipts.';

    public function handle(AtlasSelfConstructionCompletionRealityProjector $projector): int
    {
        $receiptsPath = trim((string) $this->option('receipts'));
        if ($receiptsPath === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'receipts_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($receiptsPath)) {
            $this->line((string) json_encode(['status' => 'receipts_not_found', 'receipts' => $receiptsPath], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $receipts = json_decode((string) file_get_contents($receiptsPath), true);
        if (! is_array($receipts)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'receipts' => $receiptsPath], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $projector->project($receipts),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
