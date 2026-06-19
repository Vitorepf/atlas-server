<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LOOP-OS · FASE 5 · §K — observability over the async delivery pipeline (Slice 8 state), for the morning
 * digest. It reads atlas_loop_pipeline_state and reports the STAGE FUNNEL (how many objectives sit in each
 * stage), how many projections are in-flight (leased) vs parked, and the highest accrued-EV waiting — so the
 * operator can see where the loop is bottlenecked without reading raw logs. Read-only, fail-open (any DB
 * hiccup yields an empty-but-well-formed section, never a crash of the digest).
 */
final class AtlasLoopObservabilityDigest
{
    public const SCHEMA_VERSION = 'atlas.loop.observability_digest.v1';

    /**
     * @return array{schema_version:string, total:int, stage_funnel:array<string,int>, in_flight:int,
     *               parked:int, max_accrued_ev:float, generated_at:string}
     */
    public function section(string $campaignId): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'total' => 0,
            'stage_funnel' => [],
            'in_flight' => 0,
            'parked' => 0,
            'max_accrued_ev' => 0.0,
            'generated_at' => now()->toIso8601String(),
        ];
        if (! DatabaseTableAvailability::has(AtlasLoopDeliveryPipeline::TABLE)) {
            return $base;
        }

        try {
            $rows = DB::table(AtlasLoopDeliveryPipeline::TABLE)->where('campaign_id', $campaignId)->get();
        } catch (Throwable) {
            return $base;
        }

        $funnel = [];
        $inFlight = 0;
        $parked = 0;
        $maxEv = 0.0;
        $now = Carbon::now();
        foreach ($rows as $row) {
            $stage = (string) ($row->stage ?? 'unknown');
            $funnel[$stage] = ($funnel[$stage] ?? 0) + 1;
            if ($stage === AtlasLoopDeliveryPipeline::STAGE_PARKED) {
                $parked++;
            }
            // in-flight = a live lease (claimed by a worker, not yet expired).
            if (($row->claim_owner ?? null) !== null && $row->lease_expires_at !== null && Carbon::parse($row->lease_expires_at)->greaterThan($now)) {
                $inFlight++;
            }
            $maxEv = max($maxEv, (float) ($row->accrued_ev ?? 0.0));
        }

        return array_merge($base, [
            'total' => $rows->count(),
            'stage_funnel' => $funnel,
            'in_flight' => $inFlight,
            'parked' => $parked,
            'max_accrued_ev' => $maxEv,
        ]);
    }
}
