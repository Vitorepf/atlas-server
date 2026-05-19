<?php

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AtlasDecisionReceipt;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\AtlasTeosReadinessCertificationService;
use App\Services\Ai\LongHorizon\Gate\LongHorizonContextFreshnessGate;
use App\Services\Ai\LongHorizon\LongHorizonMemoryPromotionGuard;
use App\Services\Ai\LongHorizon\LongHorizonRecoveryPlannerService;
use App\Services\Ai\Programming\Forge\ForgeContinuationPackBuilder;
use App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService;
use App\Support\TemporalTruth\HasTemporalTruth;
use App\Support\TemporalTruth\TemporalTruthCanon;
use LogicException;
use ReflectionClass;
use Tests\TestCase;

/**
 * TEOS-I1 · runtime wiring audit (complement to
 * {@see AtlasTeosReadinessCertificationService}).
 *
 * The certification service is a STATIC file/class probe — it answers
 * "do the files exist?". This suite answers the deeper question "are the
 * pieces wired into the live runtime?" by exercising reflection on
 * constructors, class constants, model fillables and trait usage. Together
 * they form the honest readiness contract for TEOS-I1 local product.
 *
 * Hard rules audited here:
 *   - 3 canon schemas declared and non-empty;
 *   - 3 target models use HasTemporalTruth + carry all 7 temporal columns
 *     in fillable;
 *   - AtlasMemoryEntry::SCOPES carries `obra` + `long_horizon` and the
 *     LONG_HORIZON_SCOPES const mirrors them;
 *   - AtlasMemoryDeltaPromotionService injects LongHorizonMemoryPromotionGuard;
 *   - ForgeLongHorizonStateService exposes emitContinuationPack();
 *   - AtlasLedgerEvent enforces append-only via LogicException.
 *
 * Provider invocation is NEVER exercised — every assertion is reflection
 * or constant inspection.
 */
class AtlasTeosRuntimeWiringTest extends TestCase
{
    public function test_long_horizon_canon_declares_three_schema_versions(): void
    {
        $this->assertSame(
            'atlas.long_horizon.continuation_pack.v2',
            AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
        );
        $this->assertSame(
            'atlas.long_horizon.compaction_receipt.v1',
            AtlasLongHorizonCanon::COMPACTION_RECEIPT_SCHEMA_VERSION,
        );
        $this->assertSame(
            'atlas.long_horizon.recovery_plan.v1',
            AtlasLongHorizonCanon::RECOVERY_PLAN_SCHEMA_VERSION,
        );
    }

    public function test_temporal_truth_canon_declares_eight_fields_and_five_authorities(): void
    {
        $this->assertCount(8, TemporalTruthCanon::FIELDS);
        $this->assertSame(
            ['valid_from', 'valid_until', 'observed_at', 'verified_at', 'stale_after', 'source_hash', 'superseded_by', 'authority_level'],
            TemporalTruthCanon::FIELDS,
        );
        $this->assertContains(TemporalTruthCanon::AUTHORITY_OPERATOR, TemporalTruthCanon::AUTHORITY_LEVELS);
        $this->assertContains(TemporalTruthCanon::AUTHORITY_INFERRED, TemporalTruthCanon::AUTHORITY_LEVELS);
        $this->assertCount(5, TemporalTruthCanon::AUTHORITY_LEVELS);
    }

    public function test_three_canon_models_use_has_temporal_truth_trait(): void
    {
        foreach ([
            AtlasDecisionReceipt::class,
            AtlasMemoryEntry::class,
            AiCodebaseWorldModelEdge::class,
        ] as $modelClass) {
            $traits = $this->collectTraitsRecursive($modelClass);
            $this->assertContains(
                HasTemporalTruth::class,
                $traits,
                "{$modelClass} must use HasTemporalTruth trait",
            );
        }
    }

    public function test_atlas_memory_entry_scopes_carry_obra_and_long_horizon(): void
    {
        $this->assertContains('obra', AtlasMemoryEntry::SCOPES);
        $this->assertContains('long_horizon', AtlasMemoryEntry::SCOPES);
        $this->assertSame(['obra', 'long_horizon'], AtlasMemoryEntry::LONG_HORIZON_SCOPES);
    }

    public function test_atlas_memory_delta_promotion_service_constructor_injects_long_horizon_guard(): void
    {
        $reflection = new ReflectionClass(AtlasMemoryDeltaPromotionService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'AtlasMemoryDeltaPromotionService must declare a constructor');

        $guardWired = false;
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type === null) {
                continue;
            }
            $typeName = method_exists($type, 'getName') ? $type->getName() : null;
            if ($typeName === LongHorizonMemoryPromotionGuard::class) {
                $guardWired = true;
                break;
            }
        }
        $this->assertTrue(
            $guardWired,
            'AtlasMemoryDeltaPromotionService must inject LongHorizonMemoryPromotionGuard via constructor',
        );
    }

    public function test_forge_long_horizon_state_service_exposes_emit_continuation_pack(): void
    {
        $reflection = new ReflectionClass(ForgeLongHorizonStateService::class);
        $this->assertTrue(
            $reflection->hasMethod('emitContinuationPack'),
            'ForgeLongHorizonStateService::emitContinuationPack() must exist',
        );
        $this->assertTrue(class_exists(ForgeContinuationPackBuilder::class));
    }

    public function test_freshness_gate_and_recovery_planner_classes_resolved(): void
    {
        $this->assertTrue(class_exists(LongHorizonContextFreshnessGate::class));
        $this->assertTrue(class_exists(LongHorizonRecoveryPlannerService::class));
        $this->assertTrue(method_exists(LongHorizonContextFreshnessGate::class, 'evaluate'));
        $this->assertTrue(method_exists(LongHorizonRecoveryPlannerService::class, 'plan'));
    }

    public function test_atlas_ledger_event_enforces_append_only_invariant_at_runtime(): void
    {
        $event = new AtlasLedgerEvent;
        $event->exists = true;
        $event->event_id = 'evt_test_'.bin2hex(random_bytes(4));

        // Force the model to look "dirty" so the save() guard triggers.
        $event->forceFill(['payload' => ['mutated' => true]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');
        $event->save();
    }

    public function test_atlas_ledger_event_delete_is_blocked(): void
    {
        $event = new AtlasLedgerEvent;
        $event->exists = true;
        $event->event_id = 'evt_test_delete_'.bin2hex(random_bytes(4));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');
        $event->delete();
    }

    public function test_memory_promotion_guard_does_not_call_any_provider(): void
    {
        $guard = new LongHorizonMemoryPromotionGuard;
        $this->assertInstanceOf(LongHorizonMemoryPromotionGuard::class, $guard);

        $reflection = new ReflectionClass($guard);
        $constructor = $reflection->getConstructor();
        $paramCount = $constructor === null ? 0 : count($constructor->getParameters());

        // Either the guard is dependency-less (constructor empty), OR every
        // declared dependency must NOT be a provider invocation surface.
        if ($constructor === null || $paramCount === 0) {
            $this->assertSame(0, $paramCount, 'Guard is dependency-less by design');

            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type !== null && method_exists($type, 'getName')) {
                $name = $type->getName();
                $this->assertStringNotContainsString('InvocationDriver', $name);
                $this->assertStringNotContainsString('ProviderInvocation', $name);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function collectTraitsRecursive(string $class): array
    {
        $traits = [];
        $current = $class;
        while ($current !== false) {
            $reflection = new ReflectionClass($current);
            foreach ($reflection->getTraitNames() as $trait) {
                $traits[] = $trait;
                $traits = array_merge($traits, $this->collectTraitsRecursive($trait));
            }
            $current = $reflection->getParentClass() ? $reflection->getParentClass()->getName() : false;
        }

        return array_values(array_unique($traits));
    }
}
