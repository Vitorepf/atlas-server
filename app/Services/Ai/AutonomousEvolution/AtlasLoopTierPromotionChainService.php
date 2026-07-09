<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Brain\AtlasEvolutionDiaryRecorder;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\AutonomyTierPromotionDecisionEvaluator;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Obra #14 H3.2 · S49→S50→S55 — Loop autonomy tier promotion chain (implement-only).
 *
 * Facade over {@see AutonomyTierPromotionDecisionEvaluator}: consumes a REAL
 * operator decision receipt + the canonical area registry + the real runtime
 * kill switch, persists an allowed promotion to `atlas_autonomy_tier_promotions`
 * and records it on the Evidence Ledger.
 *
 * PÉTREO invariants:
 *   - The active tier stays 0 until an operator-SIGNED receipt is promoted.
 *   - NEVER invokes a provider; NEVER auto-promotes; a block persists NOTHING.
 *   - Kill switch active ⇒ activeTier() is 0 regardless of persisted promotions.
 *     The real runtime kill switch is the loop master switch
 *     ({@see AtlasLoopMasterSwitch}, ATLAS_LOOP_MASTER_ENABLED, fail-closed):
 *     master OFF ⇒ kill switch ACTIVE ⇒ tier 0. Only the operator flips it.
 */
class AtlasLoopTierPromotionChainService
{
    public const SCHEMA_VERSION = 'atlas.loop.autonomy_tier_promotion_chain.v1';

    public const PROMOTION_EVENT_SCHEMA = 'atlas.loop.autonomy_tier_promotion.v1';

    public const FIRST_MERGE_EVENT_SCHEMA = 'atlas.loop.first_merge_acid_test.v1';

    public const TABLE = 'atlas_autonomy_tier_promotions';

    public function __construct(
        private readonly AutonomyTierPromotionDecisionEvaluator $evaluator,
        private readonly AtlasNightShiftAreaFocusContractRegistry $registry,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * Evaluate an operator decision receipt against the real area state and
     * runtime signals. `promote` ⇒ persist + ledger event; `block` ⇒ persist
     * nothing and return the blockers. Never calls a provider.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function promote(array $receipt, string $areaId): array
    {
        $contract = $this->registry->resolve($areaId);

        $schemaOk = ($receipt['schema_version'] ?? null) === AreaFocusOperatorDecisionService::RECEIPT_SCHEMA;

        $areaState = [
            'registered' => $contract !== null,
            'active_tier' => $this->activeTier($areaId),
            'max_autonomy_tier' => (int) ($contract['max_tier_for_area'] ?? 0),
        ];

        $runtimeSignals = [
            'kill_switch_active' => $this->killSwitchActive(),
        ];

        $decision = $this->evaluator->decide($receipt, $areaState, $runtimeSignals);

        $blockers = (array) $decision['blockers'];
        if (! $schemaOk) {
            $blockers[] = 'receipt_schema_invalid';
        }

        $promoted = $decision['decision'] === 'promote' && $schemaOk;
        $tier = $promoted ? (int) $decision['active_tier'] : (int) $areaState['active_tier'];
        $receiptHash = hash('sha256', (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($promoted) {
            DB::table(self::TABLE)->insert([
                'area_id' => $areaId,
                'tier' => $tier,
                'receipt_hash' => $receiptHash,
                'operator_signed' => ($receipt['operator_signed'] ?? false) === true,
                'decided_at' => CarbonImmutable::now(),
                'receipt_payload' => json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);

            $this->recordLedger(LedgerEventType::DecisionIssued, [
                'schema_version' => self::PROMOTION_EVENT_SCHEMA,
                'area_id' => $areaId,
                'tier' => $tier,
                'receipt_hash' => $receiptHash,
                'operator_signed' => ($receipt['operator_signed'] ?? false) === true,
                'blockers' => [],
            ], $areaId);

            // DIARIO-3 — an area just graduated to a higher autonomy tier (an automation
            // graduating). Label it as `graduacao` in the Evolution Diary in the same act;
            // reversible via the tier-promotion receipt. Fail-open: never fail a promotion.
            try {
                app(AtlasEvolutionDiaryRecorder::class)->graduated(
                    'área '.$areaId.' graduada para tier '.$tier,
                    'decisão promote validada contra o estado real da área + runtime (kill-switch off); sem espera por humano',
                    'receipt '.substr($receiptHash, 0, 12),
                    'tier:'.$areaId.':'.$tier,
                );
            } catch (Throwable) {
                // never fail a promotion over the diary
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'area_id' => $areaId,
            'decision' => $promoted ? 'promote' : 'block',
            'tier' => $tier,
            'blockers' => $promoted ? [] : $blockers,
            'persisted' => $promoted,
            'receipt_hash' => $receiptHash,
        ];
    }

    /**
     * The last persisted VALID promotion tier for an area. Kill switch active
     * ⇒ 0 always. No promotion / any failure ⇒ 0 (degrade-safe, fail-closed).
     */
    public function activeTier(string $areaId): int
    {
        if ($this->killSwitchActive()) {
            return 0;
        }

        try {
            if (! DatabaseTableAvailability::has(self::TABLE)) {
                return 0;
            }

            $row = DB::table(self::TABLE)
                ->where('area_id', $areaId)
                ->where('operator_signed', true)
                ->orderByDesc('decided_at')
                ->orderByDesc('id')
                ->first();

            return $row === null ? 0 : (int) $row->tier;
        } catch (Throwable) {
            return 0; // fail-closed: any read failure means tier 0, never "assume promoted"
        }
    }

    /**
     * @return array{implemented:bool,audited:bool,tier:int,operator_signed:bool}
     */
    public function readiness(): array
    {
        $tableReady = DatabaseTableAvailability::has(self::TABLE);

        $tier = 0;
        foreach ($this->registry->registeredAreas() as $areaId) {
            $tier = max($tier, $this->activeTier($areaId));
        }

        $signed = false;
        if ($tableReady) {
            try {
                $signed = DB::table(self::TABLE)->where('operator_signed', true)->exists();
            } catch (Throwable) {
                $signed = false;
            }
        }

        return [
            'implemented' => true,
            'audited' => $tableReady,
            'tier' => $tier,
            'operator_signed' => $signed,
        ];
    }

    /**
     * S55 — record the first-merge acid-test outcome on the Evidence Ledger.
     * Pure bookkeeping: it observes an ALREADY-DECIDED outcome; it never
     * executes, approves or triggers any merge.
     *
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function recordFirstMergeOutcome(array $outcome): array
    {
        $passed = ($outcome['outcome'] ?? null) === 'merged'
            && ($outcome['merge_performed'] ?? false) === true;

        $payload = [
            'schema_version' => self::FIRST_MERGE_EVENT_SCHEMA,
            'area_id' => (string) ($outcome['area_id'] ?? ''),
            'outcome' => (string) ($outcome['outcome'] ?? 'unknown'),
            'merge_performed' => ($outcome['merge_performed'] ?? false) === true,
            'acid_test_passed' => $passed,
            'evidence_refs' => array_values((array) ($outcome['evidence_refs'] ?? [])),
        ];

        $this->recordLedger(LedgerEventType::EvidencePacked, $payload, $payload['area_id']);

        return $payload;
    }

    /**
     * The runtime kill switch: loop master OFF (the default, fail-closed)
     * counts as kill switch ACTIVE. Only the operator flips it via atlas:agents:on autonomos.
     */
    private function killSwitchActive(): bool
    {
        return ! AtlasLoopMasterSwitch::enabled();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordLedger(LedgerEventType $type, array $payload, string $areaId): void
    {
        try {
            $this->ledger->record($type, $payload, [
                'emitter_stage' => 'atlas.loop.tier_promotion_chain',
                'scope_type' => 'area',
                'scope_id' => $areaId !== '' ? $areaId : null,
            ]);
        } catch (Throwable) {
            // ledger degrade-safe: promotion state stays canonical in the table
        }
    }
}
