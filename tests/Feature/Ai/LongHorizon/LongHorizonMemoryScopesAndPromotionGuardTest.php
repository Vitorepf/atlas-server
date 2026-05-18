<?php

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiMemoryDelta;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\LongHorizon\LongHorizonMemoryPromotionGuard;
use App\Services\Ai\LongHorizon\LongHorizonMemoryPromotionRefusedException;
use App\Support\TemporalTruth\TemporalTruthCanon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * TEOS-I1 — Memory Scopes (obra/long_horizon) + Long-Horizon Promotion Guard.
 *
 * Verifies:
 *  - the new scopes are present in the canon SCOPES set;
 *  - legacy scopes (`global`, `project`, ...) promote without the guard
 *    blocking, preserving backwards compatibility;
 *  - promotion into `obra` / `long_horizon` requires evidence_refs AND an
 *    operator review decision;
 *  - LAMI-originated deltas cannot bypass the proposal/review surface;
 *  - inferred-authority deltas demand explicit operator review.
 */
class LongHorizonMemoryScopesAndPromotionGuardTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private AtlasMemoryDeltaPromotionService $promotion;

    private LongHorizonMemoryPromotionGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createMemoryDeltaTable();
        $this->promotion = app(AtlasMemoryDeltaPromotionService::class);
        $this->guard = new LongHorizonMemoryPromotionGuard;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_canon_scopes_include_obra_and_long_horizon(): void
    {
        $this->assertContains('obra', AtlasMemoryEntry::SCOPES);
        $this->assertContains('long_horizon', AtlasMemoryEntry::SCOPES);
        $this->assertSame(['obra', 'long_horizon'], AtlasMemoryEntry::LONG_HORIZON_SCOPES);
    }

    public function test_legacy_scopes_remain_in_canon_set(): void
    {
        foreach (['global', 'project', 'task', 'engineering_run', 'workspace', 'user', 'session'] as $legacy) {
            $this->assertContains($legacy, AtlasMemoryEntry::SCOPES, "legacy scope {$legacy} must remain");
        }
    }

    public function test_guard_allows_legacy_global_scope_without_review(): void
    {
        $delta = $this->seedDelta(scope: 'global', evidence: []);
        $reasons = $this->guard->evaluate($delta);
        $this->assertSame([], $reasons, 'guard must NOT block legacy scopes — backwards compatibility');
    }

    public function test_legacy_scope_promotion_succeeds(): void
    {
        $delta = $this->seedDelta(scope: 'global', status: 'accepted');
        $entry = $this->promotion->promote($delta);
        $this->assertSame('global', $entry->scope_type);
    }

    public function test_obra_scope_promotion_without_evidence_is_blocked(): void
    {
        $delta = $this->seedDelta(scope: 'global', evidence: [], status: 'accepted');
        try {
            $this->promotion->promote($delta, ['scope_type' => 'obra']);
            $this->fail('Promotion into obra without evidence must throw');
        } catch (LongHorizonMemoryPromotionRefusedException $e) {
            $this->assertStringContainsString(
                LongHorizonMemoryPromotionRefusedException::REASON_MISSING_EVIDENCE_REFS,
                $e->getMessage(),
            );
            $this->assertStringContainsString(
                LongHorizonMemoryPromotionRefusedException::REASON_MISSING_OPERATOR_REVIEW,
                $e->getMessage(),
            );
        }
        $this->assertDatabaseMissing('atlas_memory_entries', ['scope_type' => 'obra']);
    }

    public function test_long_horizon_scope_without_operator_review_is_blocked_even_with_evidence(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'plan', 'ref' => 'spec://x']],
            status: 'accepted',
        );
        try {
            $this->promotion->promote($delta, ['scope_type' => 'long_horizon']);
            $this->fail('Promotion into long_horizon without operator review must throw');
        } catch (LongHorizonMemoryPromotionRefusedException $e) {
            $this->assertStringContainsString(
                LongHorizonMemoryPromotionRefusedException::REASON_MISSING_OPERATOR_REVIEW,
                $e->getMessage(),
            );
        }
        $this->assertDatabaseMissing('atlas_memory_entries', ['scope_type' => 'long_horizon']);
    }

    public function test_obra_scope_with_evidence_and_operator_review_approved_promotes(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'plan', 'ref' => 'spec://forge_obra'], ['kind' => 'work_packet_receipts', 'ref' => 'wpr://1']],
            status: 'accepted',
        );

        $entry = $this->promotion->promote($delta, [
            'scope_type' => 'obra',
            'scope_id' => 'forge-obra-001',
            'operator_review_decision' => 'approved',
        ]);

        $this->assertSame('obra', $entry->scope_type);
        $this->assertSame('forge-obra-001', $entry->scope_id);
    }

    public function test_long_horizon_scope_via_proposal_id_promotes(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'continuation_pack', 'ref' => 'pack://obra:001']],
            status: 'accepted',
        );

        $entry = $this->promotion->promote($delta, [
            'scope_type' => 'long_horizon',
            'scope_id' => 'long-horizon-001',
            'operator_review_proposal_id' => 'review-proposal-uuid-123',
        ]);

        $this->assertSame('long_horizon', $entry->scope_type);
    }

    public function test_lami_originated_delta_cannot_promote_directly_into_long_horizon(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'lami_candidate', 'ref' => 'lami:c:42']],
            status: 'accepted',
        );

        try {
            $this->promotion->promote($delta, [
                'scope_type' => 'obra',
                'operator_review_decision' => 'approved',
                'source_type' => 'local_agent_ingestion',
            ]);
            $this->fail('LAMI raw delta must not promote into long_horizon directly');
        } catch (LongHorizonMemoryPromotionRefusedException $e) {
            $this->assertStringContainsString(
                LongHorizonMemoryPromotionRefusedException::REASON_LOCAL_AGENT_RAW_BLOCKED,
                $e->getMessage(),
            );
        }
    }

    public function test_lami_origin_can_promote_when_review_completed_flag_is_set(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'lami_candidate', 'ref' => 'lami:c:42']],
            status: 'accepted',
        );

        $entry = $this->promotion->promote($delta, [
            'scope_type' => 'obra',
            'operator_review_decision' => 'approved',
            'source_type' => 'local_agent_ingestion',
            'lami_review_completed' => true,
        ]);

        $this->assertSame('obra', $entry->scope_type);
    }

    public function test_inferred_authority_requires_operator_review_for_long_horizon(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'plan', 'ref' => 'spec://heuristic']],
            status: 'accepted',
        );

        try {
            $this->promotion->promote($delta, [
                'scope_type' => 'obra',
                'authority_level' => TemporalTruthCanon::AUTHORITY_INFERRED,
            ]);
            $this->fail('inferred authority into obra must require operator review');
        } catch (LongHorizonMemoryPromotionRefusedException $e) {
            $this->assertStringContainsString(
                LongHorizonMemoryPromotionRefusedException::REASON_MISSING_OPERATOR_REVIEW,
                $e->getMessage(),
            );
            $this->assertStringContainsString(
                LongHorizonMemoryPromotionRefusedException::REASON_LOW_AUTHORITY_REQUIRES_REVIEW,
                $e->getMessage(),
            );
        }
    }

    public function test_unknown_authority_level_is_blocked(): void
    {
        $delta = $this->seedDelta(
            scope: 'global',
            evidence: [['kind' => 'plan', 'ref' => 'spec://x']],
            status: 'accepted',
        );

        $reasons = $this->guard->evaluate($delta, [
            'scope_type' => 'obra',
            'operator_review_decision' => 'approved',
            'authority_level' => 'pinky_promise',
        ]);
        $this->assertContains(
            LongHorizonMemoryPromotionRefusedException::REASON_UNKNOWN_AUTHORITY_LEVEL,
            $reasons,
        );
    }

    public function test_guard_evaluate_returns_empty_for_non_long_horizon_scope(): void
    {
        $delta = $this->seedDelta(scope: 'global');
        $this->assertSame([], $this->guard->evaluate($delta, ['scope_type' => 'project']));
        $this->assertSame([], $this->guard->evaluate($delta, ['scope_type' => 'task']));
    }

    public function test_guard_resolves_scope_from_delta_when_not_overridden(): void
    {
        $delta = $this->seedDelta(scope: 'obra:1234');
        // no evidence + no review → guard fires
        $reasons = $this->guard->evaluate($delta);
        $this->assertNotEmpty($reasons);
        $this->assertSame('obra', $this->guard->resolveScope($delta, []));
    }

    /**
     * @param  array<int,mixed>  $evidence
     */
    private function seedDelta(
        string $scope = 'global',
        array $evidence = [['kind' => 'note', 'ref' => 'auto']],
        string $status = 'accepted',
    ): AiMemoryDelta {
        return AiMemoryDelta::query()->create([
            'id' => (string) Str::uuid(),
            'source_workspace' => '/tmp/forge-obra-smoke',
            'type' => 'decision',
            'claim' => 'TEOS-I1 long-horizon guard smoke claim',
            'evidence' => $evidence,
            'scope' => $scope,
            'confidence' => 0.82,
            'requires_confirmation' => true,
            'status' => $status,
        ]);
    }

    private function createMemoryDeltaTable(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable()->index();
            $table->timestamp('promoted_at')->nullable()->index();
            $table->timestamps();
        });
    }
}
