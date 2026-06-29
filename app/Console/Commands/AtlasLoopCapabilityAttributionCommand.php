<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Attribution\AtlasLoopCapabilityDeltaAttributionService;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasLoopCapabilityDeltaAttributionService::attribute()} at the operator
 * surface: measures which delivery shapes actually moved capability — per shape_token: sample count, mean
 * measured net-behavior delta, Wilson lower bound on the positive-Δ rate, and a credited flag.
 *
 * Pure + read-only MEASUREMENT: it only reads the MEASURED delta (never a caller-supplied grade); it gates
 * nothing, blocks nothing, and mutates nothing.
 */
final class AtlasLoopCapabilityAttributionCommand extends Command
{
    protected $signature = 'atlas:loop:capability-attribution {--deliveries=} {--json}';

    protected $description = 'Read-only capability-delta attribution by delivery shape (honest measurement, not a gate).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('deliveries'));
        if ($raw === '') {
            return $this->refuse('capability-attribution requires --deliveries=<JSON array of deliveries or a path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->refuse('--deliveries must be a JSON array/object');
        }
        $deliveries = isset($decoded['deliveries']) && is_array($decoded['deliveries']) ? $decoded['deliveries'] : $decoded;
        if (! array_is_list($deliveries)) {
            return $this->refuse('--deliveries must be a JSON array of deliveries');
        }

        $result = app(AtlasLoopCapabilityDeltaAttributionService::class)->attribute(array_values($deliveries));

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($result['by_shape'] as $row) {
                $this->line($row['shape_token'].'  n='.$row['samples'].'  mean='.$row['mean_delta'].'  credited='.($row['credited'] ? 'yes' : 'no'));
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
