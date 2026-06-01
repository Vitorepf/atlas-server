<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionCommandEvidenceIndexService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Self-Construction Command Evidence Index v1
 * doc. With no args it self-describes the per-command evidence index (purpose,
 * parallel safety, key_fields, failure_meaning, certifier roles) and runs
 * worked evaluations that prove the hard rules are live: every command is
 * read-only, a smuggled mutating row is rejected, a regressed projection is
 * caught, status "available" is never runtime, a misaligned pointer blocks
 * advance, and a changed replay hash is flagged non-deterministic. It NEVER
 * runs a verification command and NEVER promotes anything to runtime.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
 */
class AtlasSelfConstructionCommandEvidenceIndexCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-command-evidence-index {--json : Print machine-readable JSON}';

    protected $description = 'Describe the Self-Construction read-only command evidence index (per-command purpose, key_fields, failure_meaning, certifier roles) and run worked rule evaluations. Never runs a command, never authorizes runtime.';

    public function handle(AtlasSelfConstructionCommandEvidenceIndexService $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasSelfConstructionCommandEvidenceIndexService::SCHEMA_VERSION,
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
        $this->components->twoColumnDetail('commands_indexed', (string) $payload['command_count']);
        $this->components->twoColumnDetail('runtime_safety_certifiers', (string) count($payload['runtime_safety_certifiers']));
        $this->components->twoColumnDetail('pointer_certifiers', (string) count($payload['pointer_certifiers']));
        $this->components->twoColumnDetail('every_command_read_only', $payload['every_command_read_only'] ? 'true' : 'false');
        $this->components->twoColumnDetail('mutating_row_rejected', $payload['sample_mutating_row_rejected']['valid'] ? 'accepted' : 'rejected');
        $this->components->twoColumnDetail('projection_regressed_sample', $payload['sample_projection_regressed']['regressed'] ? 'regressed' : 'read_only');
        $this->components->twoColumnDetail('status_available_runtime', $payload['sample_status_available_not_runtime']['runtime_executing'] ? 'true' : 'false');
        $this->components->twoColumnDetail('replay_non_deterministic_sample', $payload['sample_replay_non_deterministic']['regressed'] ? 'regressed' : 'deterministic');

        return self::SUCCESS;
    }
}
