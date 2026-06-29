<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCodeIndexReadinessBridge;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionCodeIndexReadinessBridge::verify()} at the operator surface:
 * reads a facts JSON (code_status, readiness, automatic_gate, schema_drift) and emits the code-index
 * readiness verdict — {status, passed, blockers, code_index_facts} — as deterministic facts so the loop can
 * verify the index before relying on it. Pure and read-only.
 */
final class AtlasLoopCodeIndexReadinessCommand extends Command
{
    protected $signature = 'atlas:loop:code-index-readiness {--facts=} {--json}';

    protected $description = 'Read-only: verify code-index readiness {status, passed, blockers} from supplied facts.';

    public function handle(AtlasSelfConstructionCodeIndexReadinessBridge $bridge): int
    {
        $factsPath = trim((string) $this->option('facts'));
        if ($factsPath === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'facts_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($factsPath)) {
            $this->line((string) json_encode(['status' => 'facts_not_found', 'facts' => $factsPath], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $facts = json_decode((string) file_get_contents($factsPath), true);
        if (! is_array($facts)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'facts' => $factsPath], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $bridge->verify($facts),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
