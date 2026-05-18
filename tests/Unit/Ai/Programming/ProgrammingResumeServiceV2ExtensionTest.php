<?php

namespace Tests\Unit\Ai\Programming;

use App\Models\AtlasLongHorizonContinuationPack;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Programming\ProgrammingResumeService;
use App\Services\Ai\Programming\ProgrammingStageReceiptStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TEOS-I1 / M5 — ProgrammingResumeService v2 adapter.
 *
 * The service still returns the legacy `continuation_packet` (v1) and adds
 * a parallel `continuation_pack` block shaped after
 * `atlas.long_horizon.continuation_pack.v2`. Back-compat invariants are
 * pinned by the assertions below.
 */
class ProgrammingResumeServiceV2ExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStageReceiptTable();
        $this->bootContinuationPackTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');
        Schema::dropIfExists('atlas_programming_stage_receipts');
        parent::tearDown();
    }

    public function test_legacy_response_shape_is_preserved(): void
    {
        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-legacy-1',
            parentPlanId: null,
        );

        foreach ([
            'schema_version',
            'plan_id',
            'parent_plan_id',
            'resumed',
            'previous_stage_receipt_count',
            'previous_stage_receipt_source',
            'latest_stage',
            'latest_status',
            'timeline_validation',
            'resume_allowed',
            'continuation_packet',
            'blocks_when_invalid',
            'must_load_open_brain',
            'must_preserve_prior_decisions',
        ] as $key) {
            $this->assertArrayHasKey($key, $resume, "legacy key {$key} must remain");
        }

        // v1 packet schema preserved.
        $this->assertSame('atlas.programming.continuation_packet.v1', data_get($resume, 'continuation_packet.schema_version'));
    }

    public function test_new_v2_pack_block_is_present_and_canonical(): void
    {
        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-new-1',
            parentPlanId: null,
        );

        $pack = $resume['continuation_pack'] ?? null;
        $this->assertIsArray($pack);
        $this->assertSame(AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION, $pack['schema_version']);
        foreach ([
            'source',
            'scope_type',
            'scope_id',
            'continuation_pack_id',
            'pack_hash',
            'stale_after',
            'safe_resume_mode',
            'missing_required_refs',
            'stale_refs',
            'next_safe_action',
            'human_decisions_required',
            'freshness_gate_evaluated',
            'freshness_gate_status',
        ] as $key) {
            $this->assertArrayHasKey($key, $pack, "new pack key {$key} must be present");
        }
        $this->assertSame('synthesised', $pack['source']);
        $this->assertNull($pack['continuation_pack_id']);
        $this->assertNotSame('', $pack['pack_hash']);
        $this->assertFalse($pack['freshness_gate_evaluated']);
        $this->assertSame('not_evaluated', $pack['freshness_gate_status']);
    }

    public function test_safe_resume_mode_defaults_to_execute_when_no_blockers(): void
    {
        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-safe-1',
            parentPlanId: null,
        );

        $this->assertSame(
            AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE,
            data_get($resume, 'continuation_pack.safe_resume_mode'),
        );
        $this->assertStringStartsWith('resume_stage:', (string) data_get($resume, 'continuation_pack.next_safe_action'));
        $this->assertSame([], data_get($resume, 'continuation_pack.human_decisions_required'));
    }

    public function test_safe_resume_mode_demotes_to_ask_human_when_missing_required_refs(): void
    {
        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-missing-1',
            parentPlanId: null,
            previousReceipts: [],
            context: [
                'missing_required_refs' => ['plan_receipt', 'patch_receipt'],
                'stale_refs' => ['docs/old.md'],
            ],
        );

        $pack = $resume['continuation_pack'];
        $this->assertSame(['plan_receipt', 'patch_receipt'], $pack['missing_required_refs']);
        $this->assertSame(['docs/old.md'], $pack['stale_refs']);
        $this->assertSame(
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            $pack['safe_resume_mode'],
        );
        $this->assertSame('pause_for_human_review', $pack['next_safe_action']);
        $this->assertNotEmpty($pack['human_decisions_required']);
    }

    public function test_invalid_timeline_demotes_safe_resume_mode_to_ask_human(): void
    {
        $store = app(ProgrammingStageReceiptStore::class);
        // out-of-order stages: patch then review — validator emits regression error.
        $patchReceipt = $store->make('parent-bad-timeline', null, 'patch', 1, 'passed', ['a' => 1], ['b' => 2]);
        $reviewReceipt = $store->make('parent-bad-timeline', null, 'review', 1, 'passed', ['a' => 1], ['b' => 2]);

        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-child-bad',
            parentPlanId: 'parent-bad-timeline',
            previousReceipts: [$patchReceipt, $reviewReceipt],
        );

        $this->assertFalse($resume['resume_allowed']);
        $this->assertSame(
            AtlasLongHorizonCanon::SAFE_RESUME_ASK_HUMAN,
            data_get($resume, 'continuation_pack.safe_resume_mode'),
        );
        $this->assertSame('pause_for_human_review', data_get($resume, 'continuation_pack.next_safe_action'));
    }

    public function test_pack_hash_is_stable_for_identical_state(): void
    {
        $first = app(ProgrammingResumeService::class)->state(
            planId: 'plan-stable-1',
            parentPlanId: null,
            context: ['scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN, 'scope_id' => 'dev-run-stable'],
        );
        $second = app(ProgrammingResumeService::class)->state(
            planId: 'plan-stable-1',
            parentPlanId: null,
            context: ['scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN, 'scope_id' => 'dev-run-stable'],
        );

        $this->assertSame(
            data_get($first, 'continuation_pack.pack_hash'),
            data_get($second, 'continuation_pack.pack_hash'),
        );
    }

    public function test_existing_persisted_pack_is_surfaced_with_its_pack_hash(): void
    {
        $payload = [
            'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
            'scope_id' => 'dev-run-existing',
            'objective' => 'persisted pack test',
            'state_summary' => 'pack already exists for this scope',
            'decisions' => [['key' => 'use_canon']],
            'superseded_decisions' => [],
            'open_tasks' => [],
            'completed_tasks' => [],
            'blockers' => [],
            'risks' => [],
            'evidence_refs' => ['receipt:persisted-1'],
            'context_manifest' => ['files' => []],
            'context_pack_hash' => str_repeat('a', 64),
            'summary_hash' => str_repeat('b', 64),
            'source_receipts' => ['receipt:persisted-1'],
            'stale_after' => CarbonImmutable::now()->addDay(),
            'safe_resume_mode' => AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY,
            'next_safe_action' => 'replay_evidence_without_mutation',
            'human_decisions_required' => ['confirm_replay'],
            'confidence' => 0.95,
        ];
        $packHash = AtlasLongHorizonContinuationPack::canonicalPackHash($payload + [
            'schema_version' => AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION,
        ]);

        /** @var AtlasLongHorizonContinuationPack $existing */
        $existing = AtlasLongHorizonContinuationPack::query()->create(array_merge($payload, [
            'uuid' => (string) Str::uuid(),
            'pack_hash' => $packHash,
        ]));

        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-existing-1',
            parentPlanId: null,
            context: [
                'scope_type' => AtlasLongHorizonCanon::SCOPE_TYPE_DEV_RUN,
                'scope_id' => 'dev-run-existing',
            ],
        );

        $pack = $resume['continuation_pack'];
        $this->assertSame('persisted', $pack['source']);
        $this->assertSame($existing->id, $pack['continuation_pack_id']);
        $this->assertSame($packHash, $pack['pack_hash']);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, $pack['safe_resume_mode']);
        $this->assertSame('replay_evidence_without_mutation', $pack['next_safe_action']);
        $this->assertSame(['confirm_replay'], $pack['human_decisions_required']);
        $this->assertNotNull($pack['stale_after']);
    }

    public function test_missing_continuation_pack_table_does_not_break_resume(): void
    {
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');

        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-no-pack-table',
            parentPlanId: null,
        );

        $this->assertIsArray($resume['continuation_pack']);
        $this->assertSame('synthesised', data_get($resume, 'continuation_pack.source'));
        $this->assertNotSame('', data_get($resume, 'continuation_pack.pack_hash'));
    }

    public function test_missing_and_stale_refs_are_normalised_into_arrays(): void
    {
        $resume = app(ProgrammingResumeService::class)->state(
            planId: 'plan-normalise',
            parentPlanId: null,
            context: [
                'missing_required_refs' => ['a', '', null, 123, 'a'],
                'stale_refs' => 'not_an_array',
            ],
        );

        $this->assertSame(
            ['a', '123'],
            data_get($resume, 'continuation_pack.missing_required_refs'),
        );
        $this->assertSame([], data_get($resume, 'continuation_pack.stale_refs'));
    }

    private function bootStageReceiptTable(): void
    {
        Schema::dropIfExists('atlas_programming_stage_receipts');
        Schema::create('atlas_programming_stage_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->string('plan_id', 160)->index();
            $table->string('parent_plan_id', 160)->nullable()->index();
            $table->string('stage', 40)->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 32)->index();
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->json('evidence_refs_json')->default('[]');
            $table->json('payload_json')->default('{}');
            $table->json('validation_json')->default('{}');
            $table->timestamps();
        });
    }

    private function bootContinuationPackTable(): void
    {
        Schema::dropIfExists('atlas_long_horizon_continuation_packs');
        (require database_path('migrations/2026_05_19_040000_create_atlas_long_horizon_continuation_pack_and_compaction_receipt_tables.php'))->up();
    }
}
