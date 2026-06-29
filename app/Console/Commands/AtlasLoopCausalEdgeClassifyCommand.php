<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopCausalEdgeClassifier;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopCausalEdgeClassifier::classify()} at the operator surface: reads an FQCN,
 * its consumer FQCNs (with the members each uses) and the API change from a JSON file, and emits the
 * causal-edge classification — per consumer, the break kind (none / added_only / removed_member /
 * signature_changed) — as deterministic facts. Read-only and pure.
 */
final class AtlasLoopCausalEdgeClassifyCommand extends Command
{
    protected $signature = 'atlas:loop:causal-edge-classify {--input=} {--json}';

    protected $description = 'Read-only causal-edge classification: how an API change breaks each consumer.';

    public function handle(AtlasLoopCausalEdgeClassifier $classifier): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'input_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $payload = json_decode((string) file_get_contents($input), true);
        if (! is_array($payload)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $fqcn = trim((string) ($payload['fqcn'] ?? ''));
        if ($fqcn === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'fqcn_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $result = $classifier->classify(
            $fqcn,
            array_values((array) ($payload['consumer_fqcns'] ?? [])),
            (array) ($payload['api_change'] ?? []),
        );

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
