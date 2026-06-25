<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactDriftDetector;
use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactValidator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Provider-free, read-only CLI for Loop phase-boundary fact contracts.
 *
 *   atlas:loop:phase:boundary schemas [--json]
 *   atlas:loop:phase:boundary validate --boundary=<key> --fact=<path> [--json]
 *   atlas:loop:phase:boundary drift --cycles=N [--json]
 */
final class AtlasLoopPhaseBoundaryCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:phase:boundary
        {action : schemas|validate|drift}
        {--boundary=}
        {--fact=}
        {--cycles=50}
        {--json}';

    /** @var string */
    protected $description = 'Loop phase-boundary FACT contracts CLI: schemas | validate | drift.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        try {
            return match ($action) {
                'schemas' => $this->doSchemas($json),
                'validate' => $this->doValidate($json),
                'drift' => $this->doDrift($json),
                default => $this->failJson('unknown_action:'.$action, $json),
            };
        } catch (Throwable $e) {
            return $this->failJson($e->getMessage(), $json);
        }
    }

    private function doSchemas(bool $json): int
    {
        $registry = new AtlasLoopPhaseBoundaryFactSchemaRegistry;
        $boundaries = $registry->boundaries();
        $payload = [];
        foreach ($boundaries as $boundary) {
            $payload[$boundary] = $registry->schemaFor($boundary);
        }
        $this->emit($json, $payload);

        return self::SUCCESS;
    }

    private function doValidate(bool $json): int
    {
        $boundary = (string) $this->option('boundary');
        $factPath = (string) $this->option('fact');
        if ($boundary === '' || $factPath === '') {
            return $this->failJson('boundary_and_fact_required', $json);
        }
        if (! is_file($factPath)) {
            return $this->failJson('fact_path_not_found:'.$factPath, $json);
        }
        $decoded = json_decode((string) @file_get_contents($factPath), true);
        if (! is_array($decoded)) {
            return $this->failJson('fact_payload_not_json_object', $json);
        }

        $validator = new AtlasLoopPhaseBoundaryFactValidator(new AtlasLoopPhaseBoundaryFactSchemaRegistry);
        $result = $validator->validate($boundary, $decoded);

        $this->emit($json, [
            'boundary' => $boundary,
            'ok' => $result->ok,
            'missing_keys' => $result->missingKeys,
            'type_mismatches' => $result->typeMismatches,
            'unknown_keys' => $result->unknownKeys,
            'reason' => $result->reason,
        ]);

        return self::SUCCESS;
    }

    private function doDrift(bool $json): int
    {
        $cycles = max(1, (int) $this->option('cycles'));
        if (app()->bound(AtlasLoopPhaseBoundaryFactDriftDetector::class)) {
            $detector = app(AtlasLoopPhaseBoundaryFactDriftDetector::class);
        } else {
            // The detector requires a ledger — when not bound in the container, return an empty
            // FACT-only envelope rather than throwing.
            $this->emit($json, ['window_cycles' => $cycles, 'drift' => [], 'note' => 'ledger_not_bound']);

            return self::SUCCESS;
        }
        $payload = $detector->detect($cycles);
        $this->emit($json, ['window_cycles' => $cycles, 'drift' => $payload]);

        return self::SUCCESS;
    }

    private function emit(bool $json, array $payload): void
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return;
        }
        $this->line(print_r($payload, true));
    }

    private function failJson(string $reason, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode(['error' => $reason], JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($reason);
        }

        return self::FAILURE;
    }
}
