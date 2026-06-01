<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasTrustLedgerCanonicalService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the canonical Trust Ledger gate.
 *
 * Folds a small worked window of real event kinds into the 0..1 score and runs
 * the gate for a target autonomy level, mirroring the doc "Fluxo": event ->
 * append-only ledger -> recompute score -> gate decision. Swap the demo events,
 * the target level or the request time to see the documented block reasons
 * (score_below_threshold, stale_score, freeze).
 *
 * @see docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
 */
final class AtlasTrustLedgerCanonicalCommand extends Command
{
    protected $signature = 'atlas:aaeos:trust-ledger-canonical {--json : Machine-readable JSON output}';

    protected $description = 'Fold canonical Trust Ledger events into the 0..1 autonomy-gating score and decide an L4+ promotion.';

    public function handle(AtlasTrustLedgerCanonicalService $service): int
    {
        try {
            $asOf = '2026-06-01T12:00:00+00:00';

            // A healthy recent window: certifications and gates passing, one
            // incident resolved, a clean self-construction approval.
            $events = [
                ['kind' => 'cert_pass', 'at' => '2026-05-30T09:00:00+00:00'],
                ['kind' => 'cert_pass', 'at' => '2026-05-28T09:00:00+00:00'],
                ['kind' => 'self_construction_approved', 'at' => '2026-05-27T09:00:00+00:00'],
                ['kind' => 'incident_resolved', 'at' => '2026-05-26T09:00:00+00:00'],
                ['kind' => 'gate_pass', 'at' => '2026-05-25T09:00:00+00:00'],
                ['kind' => 'gate_fail', 'at' => '2026-05-24T09:00:00+00:00'],
            ];

            $result = [
                'schema' => AtlasTrustLedgerCanonicalService::DECISION_SCHEMA,
                'score' => $service->score($events, $asOf),
                'eligible' => $service->eligibleLevel($service->score($events, $asOf)['score']),
                'gate_l4' => $service->gate($events, 4, $asOf),
                'append_only' => [
                    'append' => $service->classifyWrite('append'),
                    'update' => $service->classifyWrite('update'),
                ],
            ];
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
