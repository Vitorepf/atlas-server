<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryConfidenceModel;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopDeliveryConfidenceModel::estimate()} at the operator surface: reads a cert's
 * measurable signals from a JSON file and emits the calibrated delivery-confidence P(correct), the threshold
 * gate verdict, the per-signal contributions, and the reasons — as deterministic facts.
 *
 * Read-only + pure: evidence-arithmetic over the supplied signals, no provider/DB/mutation. HARD ZERO — a
 * behaviour-broken delivery is never confident, whatever else holds.
 */
final class AtlasLoopDeliveryConfidenceCommand extends Command
{
    protected $signature = 'atlas:loop:delivery-confidence {--input=} {--threshold=} {--json}';

    protected $description = 'Read-only calibrated delivery-confidence estimate (P(correct) + threshold gate) from cert signals.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'delivery-confidence requires --input=<path to a readable signals JSON>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'input file is not a JSON object',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
        $signals = isset($decoded['signals']) && is_array($decoded['signals']) ? $decoded['signals'] : $decoded;

        $threshold = $this->option('threshold') !== null && trim((string) $this->option('threshold')) !== ''
            ? (float) $this->option('threshold')
            : (isset($decoded['threshold']) && is_numeric($decoded['threshold']) ? (float) $decoded['threshold'] : null);

        $estimate = app(AtlasLoopDeliveryConfidenceModel::class)->estimate($signals, $threshold);

        $facts = ['schema' => 'atlas.loop.delivery_confidence.v1'] + $estimate;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('confidence: '.$facts['confidence'].'  threshold: '.$facts['threshold'].'  passes: '.($facts['passes'] ? 'yes' : 'no'));
            foreach ($facts['reasons'] as $r) {
                $this->line('  reason: '.$r);
            }
        }

        return self::SUCCESS;
    }
}
