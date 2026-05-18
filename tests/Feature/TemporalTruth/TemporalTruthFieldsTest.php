<?php

namespace Tests\Feature\TemporalTruth;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AtlasDecisionReceipt;
use App\Models\AtlasMemoryEntry;
use App\Support\TemporalTruth\TemporalTruthCanon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * TEOS-I1 / M2 — Temporal Truth Fields end-to-end coverage across all 3
 * target tables (atlas_decision_receipts, atlas_memory_entries,
 * ai_codebase_world_model_edges).
 *
 * Verifies:
 *  - 8 nullable fields persist + cast as immutable_datetime / string.
 *  - Scopes `current` / `stale` / `superseded` / `authorityLevel` filter
 *    correctly across all 3 models.
 *  - Legacy rows (NULL temporal fields) remain visible to `current()` and
 *    invisible to `stale()` / `superseded()`.
 *  - AtlasMemoryEntry's legacy `superseded_by_id` is honoured as canonical
 *    by the `superseded()` scope.
 */
class TemporalTruthFieldsTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchemas();
    }

    protected function tearDown(): void
    {
        $this->dropSchemas();
        parent::tearDown();
    }

    public function test_decision_receipt_persists_and_casts_temporal_fields(): void
    {
        $base = CarbonImmutable::parse('2026-05-18T12:00:00Z');
        $receipt = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'valid_from' => $base,
            'valid_until' => $base->addDays(7),
            'observed_at' => $base->subHour(),
            'verified_at' => $base,
            'stale_after' => $base->addDays(14),
            'source_hash' => str_repeat('a', 64),
            'superseded_by' => null,
            'authority_level' => TemporalTruthCanon::AUTHORITY_OPERATOR,
        ]));

        $reloaded = AtlasDecisionReceipt::query()->find($receipt->id);

        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->valid_from);
        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->valid_until);
        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->observed_at);
        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->verified_at);
        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->stale_after);
        $this->assertSame(str_repeat('a', 64), $reloaded->source_hash);
        $this->assertNull($reloaded->superseded_by);
        $this->assertSame(TemporalTruthCanon::AUTHORITY_OPERATOR, $reloaded->authority_level);
    }

    public function test_decision_receipt_current_scope_filters_expired_and_superseded(): void
    {
        $now = CarbonImmutable::parse('2026-05-18T12:00:00Z');

        $active = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'valid_from' => $now->subHour(),
            'valid_until' => $now->addDays(1),
        ]));
        $expired = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'valid_until' => $now->subHour(),
        ]));
        $future = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'valid_from' => $now->addHour(),
        ]));
        $superseder = AtlasDecisionReceipt::query()->create($this->seedReceipt());
        $superseded = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'superseded_by' => $superseder->id,
        ]));
        $legacy = AtlasDecisionReceipt::query()->create($this->seedReceipt());

        $current = AtlasDecisionReceipt::query()->current($now)->pluck('id')->all();

        $this->assertContains($active->id, $current);
        $this->assertContains($superseder->id, $current);
        $this->assertContains($legacy->id, $current, 'legacy row must remain visible to current()');
        $this->assertNotContains($expired->id, $current);
        $this->assertNotContains($future->id, $current);
        $this->assertNotContains($superseded->id, $current);
    }

    public function test_decision_receipt_stale_and_superseded_scopes(): void
    {
        $now = CarbonImmutable::parse('2026-05-18T12:00:00Z');

        $stale = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'stale_after' => $now->subHour(),
        ]));
        $fresh = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'stale_after' => $now->addHour(),
        ]));
        $superseder = AtlasDecisionReceipt::query()->create($this->seedReceipt());
        $superseded = AtlasDecisionReceipt::query()->create($this->seedReceipt([
            'superseded_by' => $superseder->id,
        ]));

        $staleIds = AtlasDecisionReceipt::query()->stale($now)->pluck('id')->all();
        $this->assertContains($stale->id, $staleIds);
        $this->assertNotContains($fresh->id, $staleIds);

        $supersededIds = AtlasDecisionReceipt::query()->superseded()->pluck('id')->all();
        $this->assertContains($superseded->id, $supersededIds);
        $this->assertNotContains($superseder->id, $supersededIds);
    }

    public function test_decision_receipt_authority_level_scope_filters_by_exact_level(): void
    {
        AtlasDecisionReceipt::query()->create($this->seedReceipt(['authority_level' => TemporalTruthCanon::AUTHORITY_OPERATOR]));
        AtlasDecisionReceipt::query()->create($this->seedReceipt(['authority_level' => TemporalTruthCanon::AUTHORITY_SYSTEM]));
        AtlasDecisionReceipt::query()->create($this->seedReceipt(['authority_level' => TemporalTruthCanon::AUTHORITY_AUTOMATION]));

        $operatorOnly = AtlasDecisionReceipt::query()
            ->authorityLevel(TemporalTruthCanon::AUTHORITY_OPERATOR)
            ->get();

        $this->assertCount(1, $operatorOnly);
        $this->assertSame(TemporalTruthCanon::AUTHORITY_OPERATOR, $operatorOnly->first()->authority_level);
    }

    public function test_memory_entry_persists_temporal_fields_and_legacy_superseded_id_drives_superseded_scope(): void
    {
        $base = CarbonImmutable::parse('2026-05-18T12:00:00Z');
        $entry = AtlasMemoryEntry::query()->create($this->seedMemoryEntry([
            'valid_from' => $base,
            'stale_after' => $base->addDays(7),
            'source_hash' => str_repeat('b', 64),
            'authority_level' => TemporalTruthCanon::AUTHORITY_SYSTEM,
        ]));

        $reloaded = AtlasMemoryEntry::query()->find($entry->id);
        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->valid_from);
        $this->assertInstanceOf(CarbonImmutable::class, $reloaded->stale_after);
        $this->assertSame(str_repeat('b', 64), $reloaded->source_hash);
        $this->assertSame(TemporalTruthCanon::AUTHORITY_SYSTEM, $reloaded->authority_level);

        $superseder = AtlasMemoryEntry::query()->create($this->seedMemoryEntry());
        $supersededLegacy = AtlasMemoryEntry::query()->create($this->seedMemoryEntry([
            // Use the legacy column — TEOS trait must treat it as canonical.
            'superseded_by_id' => $superseder->id,
        ]));

        $supersededIds = AtlasMemoryEntry::query()->superseded()->pluck('id')->all();
        $this->assertContains($supersededLegacy->id, $supersededIds);
        $this->assertNotContains($superseder->id, $supersededIds);

        // `current()` must exclude both new-style and legacy-superseded rows.
        $current = AtlasMemoryEntry::query()->current()->pluck('id')->all();
        $this->assertNotContains($supersededLegacy->id, $current);
        $this->assertContains($entry->id, $current);
    }

    public function test_world_model_edge_persists_temporal_fields_and_scopes_filter(): void
    {
        $now = CarbonImmutable::parse('2026-05-18T12:00:00Z');
        $worldModelId = $this->seedWorldModel();

        $active = AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $worldModelId,
            'from_node_id' => 'node:a',
            'to_node_id' => 'node:b',
            'edge_type' => 'depends_on',
            'metadata' => [],
            'valid_from' => $now->subHour(),
            'valid_until' => $now->addDay(),
            'stale_after' => $now->addDays(30),
            'source_hash' => str_repeat('c', 64),
            'authority_level' => TemporalTruthCanon::AUTHORITY_AUTOMATION,
        ]);
        $expired = AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $worldModelId,
            'from_node_id' => 'node:c',
            'to_node_id' => 'node:d',
            'edge_type' => 'depends_on',
            'valid_until' => $now->subHour(),
        ]);
        $superseder = AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $worldModelId,
            'from_node_id' => 'node:e',
            'to_node_id' => 'node:f',
            'edge_type' => 'depends_on',
        ]);
        $superseded = AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $worldModelId,
            'from_node_id' => 'node:g',
            'to_node_id' => 'node:h',
            'edge_type' => 'depends_on',
            'superseded_by' => $superseder->id,
        ]);
        $legacy = AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $worldModelId,
            'from_node_id' => 'node:i',
            'to_node_id' => 'node:j',
            'edge_type' => 'depends_on',
        ]);

        // Cast verification
        $reloadedActive = AiCodebaseWorldModelEdge::query()->find($active->id);
        $this->assertInstanceOf(CarbonImmutable::class, $reloadedActive->valid_from);
        $this->assertInstanceOf(CarbonImmutable::class, $reloadedActive->stale_after);
        $this->assertSame(str_repeat('c', 64), $reloadedActive->source_hash);

        $current = AiCodebaseWorldModelEdge::query()->current($now)->pluck('id')->all();
        $this->assertContains($active->id, $current);
        $this->assertContains($legacy->id, $current);
        $this->assertContains($superseder->id, $current);
        $this->assertNotContains($expired->id, $current);
        $this->assertNotContains($superseded->id, $current);

        $supersededIds = AiCodebaseWorldModelEdge::query()->superseded()->pluck('id')->all();
        $this->assertContains($superseded->id, $supersededIds);
        $this->assertNotContains($legacy->id, $supersededIds);

        $byAuthority = AiCodebaseWorldModelEdge::query()
            ->authorityLevel(TemporalTruthCanon::AUTHORITY_AUTOMATION)
            ->get();
        $this->assertCount(1, $byAuthority);
        $this->assertSame($active->id, $byAuthority->first()->id);
    }

    public function test_legacy_rows_with_null_temporal_fields_remain_backwards_compatible(): void
    {
        $legacyReceipt = AtlasDecisionReceipt::query()->create($this->seedReceipt());
        $legacyMemory = AtlasMemoryEntry::query()->create($this->seedMemoryEntry());
        $legacyEdge = AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $this->seedWorldModel(),
            'from_node_id' => 'node:legacy:from',
            'to_node_id' => 'node:legacy:to',
            'edge_type' => 'depends_on',
        ]);

        $now = CarbonImmutable::parse('2030-01-01T00:00:00Z');

        $this->assertNull($legacyReceipt->valid_from);
        $this->assertNull($legacyReceipt->stale_after);
        $this->assertNull($legacyReceipt->source_hash);
        $this->assertNull($legacyReceipt->authority_level);
        $this->assertNull($legacyMemory->valid_from);
        $this->assertNull($legacyMemory->stale_after);
        $this->assertNull($legacyEdge->valid_from);

        // legacy rows show up in current() at any reference instant.
        $this->assertContains(
            $legacyReceipt->id,
            AtlasDecisionReceipt::query()->current($now)->pluck('id')->all(),
        );
        $this->assertContains(
            $legacyMemory->id,
            AtlasMemoryEntry::query()->current($now)->pluck('id')->all(),
        );
        $this->assertContains(
            $legacyEdge->id,
            AiCodebaseWorldModelEdge::query()->current($now)->pluck('id')->all(),
        );

        // Legacy rows must NOT appear in stale()/superseded().
        $this->assertNotContains(
            $legacyReceipt->id,
            AtlasDecisionReceipt::query()->stale($now)->pluck('id')->all(),
        );
        $this->assertNotContains(
            $legacyReceipt->id,
            AtlasDecisionReceipt::query()->superseded()->pluck('id')->all(),
        );
    }

    public function test_temporal_truth_canon_declares_required_fields(): void
    {
        $this->assertSame(
            ['valid_from', 'valid_until', 'observed_at', 'verified_at', 'stale_after', 'source_hash', 'superseded_by', 'authority_level'],
            TemporalTruthCanon::FIELDS,
        );
        foreach (TemporalTruthCanon::DATETIME_FIELDS as $field) {
            $this->assertSame('immutable_datetime', TemporalTruthCanon::casts()[$field] ?? null);
        }
        $this->assertContains(TemporalTruthCanon::AUTHORITY_OPERATOR, TemporalTruthCanon::AUTHORITY_LEVELS);
        $this->assertContains(TemporalTruthCanon::AUTHORITY_INFERRED, TemporalTruthCanon::AUTHORITY_LEVELS);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function seedReceipt(array $overrides = []): array
    {
        return array_merge([
            'receipt_id' => 'rcpt_'.substr(hash('sha256', (string) Str::uuid()), 0, 24),
            'operation_id' => (string) Str::uuid(),
            'spec_id' => null,
            'plan_id' => null,
            'autonomy_level' => 'L1_assisted',
            'allowed_actions_json' => [],
            'forbidden_actions_json' => [],
            'allowed_files_json' => [],
            'forbidden_files_json' => [],
            'required_gates_json' => [],
            'task_ids_json' => [],
            'context_pack_refs_json' => [],
            'input_hash' => hash('sha256', 'input:'.Str::uuid()),
            'output_hash' => hash('sha256', 'output:'.Str::uuid()),
            'signed_at' => CarbonImmutable::now(),
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function seedMemoryEntry(array $overrides = []): array
    {
        return array_merge([
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'body' => 'temporal truth field smoke memory',
        ], $overrides);
    }

    private function seedWorldModel(): string
    {
        return AiCodebaseWorldModel::query()->create([
            'model_id' => 'aewm_temporal_truth_'.substr(hash('sha256', (string) Str::uuid()), 0, 12),
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => [],
            'risks' => [],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', (string) Str::uuid()),
        ])->id;
    }

    private function bootSchemas(): void
    {
        $this->dropSchemas();
        $this->createAtlasMemoryEntryTable();
        $this->bootDecisionReceiptsTable();
        $this->bootWorldModelTables();
    }

    private function dropSchemas(): void
    {
        Schema::dropIfExists('atlas_decision_receipts');
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');
        $this->dropAtlasMemoryEntryTable();
    }

    private function bootDecisionReceiptsTable(): void
    {
        Schema::create('atlas_decision_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_id', 64)->unique();
            $table->uuid('operation_id');
            $table->uuid('spec_id')->nullable();
            $table->uuid('plan_id')->nullable();
            $table->string('autonomy_level', 32);
            $table->json('allowed_actions_json')->default('[]');
            $table->json('forbidden_actions_json')->default('[]');
            $table->json('allowed_files_json')->default('[]');
            $table->json('forbidden_files_json')->default('[]');
            $table->json('required_gates_json')->default('[]');
            $table->json('task_ids_json')->default('[]');
            $table->json('context_pack_refs_json')->default('[]');
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->string('signature', 128)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            // TEOS-I1 / M2 temporal fields.
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('stale_after')->nullable()->index();
            $table->string('source_hash', 64)->nullable();
            $table->uuid('superseded_by')->nullable()->index();
            $table->string('authority_level', 40)->nullable()->index();
            $table->timestamps();
        });
    }

    private function bootWorldModelTables(): void
    {
        Schema::create('ai_codebase_world_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('goal_record_id')->nullable();
            $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.codebase_world_model.v1');
            $table->string('model_id', 120)->unique();
            $table->string('scope', 160)->default('atlas-server');
            $table->string('status', 40)->default('built');
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('receipt')->nullable();
            $table->string('model_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_codebase_world_model_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('world_model_id');
            $table->string('node_id', 160);
            $table->string('node_type', 80);
            $table->string('path', 500)->nullable();
            $table->string('flow_id', 80)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_codebase_world_model_edges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('world_model_id');
            $table->string('from_node_id', 160);
            $table->string('to_node_id', 160);
            $table->string('edge_type', 80);
            $table->json('metadata')->nullable();
            $table->timestamps();
            // TEOS-I1 / M2 temporal fields.
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('stale_after')->nullable()->index();
            $table->string('source_hash', 64)->nullable();
            $table->uuid('superseded_by')->nullable()->index();
            $table->string('authority_level', 40)->nullable()->index();
        });
    }
}
