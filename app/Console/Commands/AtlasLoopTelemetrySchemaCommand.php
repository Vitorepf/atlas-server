<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryFactSchemaRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Arms the dormant pure {@see AtlasLoopTelemetryFactSchemaRegistry::schemaFor()} at the operator surface:
 * looks up the telemetry fact schema for a kind (claim / lease / serve / report / merge) and emits it —
 * schema_id, required_keys, key_types, forbidden_keys — as deterministic facts. Pure (io=0), read-only. An
 * unknown kind is surfaced as an error.
 */
final class AtlasLoopTelemetrySchemaCommand extends Command
{
    protected $signature = 'atlas:loop:telemetry-schema {--kind=} {--json}';

    protected $description = 'Read-only: look up the telemetry fact schema for a kind.';

    public function handle(AtlasLoopTelemetryFactSchemaRegistry $registry): int
    {
        $kind = trim((string) $this->option('kind'));
        if ($kind === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'kind_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        try {
            $schema = $registry->schemaFor($kind);
        } catch (InvalidArgumentException $e) {
            $this->line((string) json_encode(['status' => 'unknown_kind', 'reason' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            ['schema' => 'atlas.loop.telemetry_schema.v1', 'kind' => $kind, 'telemetry_schema' => $schema],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
