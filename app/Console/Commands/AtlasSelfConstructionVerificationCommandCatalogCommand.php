<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionVerificationCommandCatalogService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Self-Construction Verification Command
 * Catalog v1 doc. With no args it self-describes the phase sequences, the
 * safe-vs-isolated classification and runs worked evaluations that prove the
 * hard rules are live: read-only-only sequences, chain-integrity-before-replay
 * ordering, mutating-command rejection, the during-sprint avoid-list, and the
 * promotion gate. It NEVER runs a verification command and NEVER authorizes
 * promotion.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
 */
class AtlasSelfConstructionVerificationCommandCatalogCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-verification-command-catalog {--json : Print machine-readable JSON}';

    protected $description = 'Describe the Self-Construction read-only verification command catalog (phase sequences, isolation rules, promotion gate) and run worked rule evaluations. Never runs a command, never authorizes promotion.';

    public function handle(AtlasSelfConstructionVerificationCommandCatalogService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasSelfConstructionVerificationCommandCatalogService::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('phases', (string) count($payload['phases']));
        $this->components->twoColumnDetail('read_only_commands', (string) count($payload['read_only_allowed']));
        $this->components->twoColumnDetail('baseline_sequence_valid', $payload['sample_valid_sequence']['valid'] ? 'true' : 'false');
        $this->components->twoColumnDetail('replay_before_integrity_valid', $payload['sample_replay_before_integrity']['valid'] ? 'true' : 'false');
        $this->components->twoColumnDetail('mutating_sequence_valid', $payload['sample_mutating_rejected']['valid'] ? 'true' : 'false');
        $this->components->twoColumnDetail('promotion_blocked_sample', $payload['sample_promotion_blocked']['promotion_allowed'] ? 'allowed' : 'forbidden');
        $this->components->twoColumnDetail('promotion_authorized', $payload['promotion_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
