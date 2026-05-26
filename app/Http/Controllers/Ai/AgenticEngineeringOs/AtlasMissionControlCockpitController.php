<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\AgenticEngineeringOs;

use App\Http\Controllers\Controller;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atlas Mission Control Cockpit HTTP controller (AP-702).
 *
 * GET /atlas-code/aaeos/cockpit?intent=<id>
 *
 * Returns the canonical `atlas.aaeos.mission_control_cockpit.v1`
 * snapshot. Phase 1 of this controller does NOT yet query the
 * Evidence Ledger to materialize the envelopes for a real intent;
 * it returns a BASELINE snapshot (the 17-phase scaffold with empty
 * inputs) so the Desktop surface can render structure even before
 * any work runs. A follow-up AP will wire the Evidence Ledger reader.
 *
 * Read-only. Provider-safe. Polling-friendly.
 */
final class AtlasMissionControlCockpitController extends Controller
{
    public function __construct(
        private readonly AtlasMissionControlCockpitService $cockpit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $intentId = trim((string) $request->query('intent', ''));
        if ($intentId === '') {
            $intentId = 'baseline-'.substr(hash('sha256', (string) gmdate('Y-m-d')), 0, 8);
        }

        $autonomy = (string) $request->query('autonomy', 'L1');

        $snapshot = $this->cockpit->snapshot(
            intentId: $intentId,
            phaseEnvelopes: [],
            gateSignals: [],
            exceptionReceipts: [],
            autonomyLevel: $autonomy,
        );

        return response()->json([
            'cockpit' => $snapshot,
            'baseline' => true,
            'note' => 'Baseline snapshot. Evidence Ledger ingestion is a follow-up AP.',
        ]);
    }
}
