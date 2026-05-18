<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\ContinuationPack;

use App\Models\AtlasDevRunIndex;
use App\Models\AtlasLongHorizonContinuationPack;
use App\Models\AtlasProgrammingStageReceipt;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\Programming\AtlasDev\ContinuationPack\DevContinuationPackBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class DevContinuationPackBuilderTest extends TestCase
{
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLongHorizonPersistenceTables();
        $this->createDevAdjacentTables();
    }

    protected function tearDown(): void
    {
        $this->dropDevAdjacentTables();
        $this->dropLongHorizonPersistenceTables();
        parent::tearDown();
    }

    public function test_pack_created_from_healthy_dev_run_has_canonical_envelope(): void
    {
        $runId = $this->seedRunIndex(taskKind: 'debug', riskLevel: 'R2', completionState: 'running');
        $this->seedReceipt($runId, stage: 'plan', status: 'passed', evidenceRefs: ['diff:plan-001.patch']);
        $this->seedReceipt($runId, stage: 'review', status: 'passed', evidenceRefs: ['test_log:review-001.txt']);

        $pack = app(DevContinuationPackBuilder::class)->build($runId, [
            'objective' => 'corrigir bug do provider router fallback',
            'decisions' => ['dec-001'],
            'context_pack_hash' => hash('sha256', 'ctx-pack-fixture'),
            'confidence' => 0.81,
        ]);

        $this->assertSame(AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION, $pack->schema_version);
        $this->assertSame(AtlasLongHorizonCanon::SCOPE_TYPE_DEV_SESSION, $pack->scope_type);
        $this->assertSame($runId, $pack->scope_id);
        $this->assertSame('corrigir bug do provider router fallback', $pack->objective);
        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_EXECUTE, $pack->safe_resume_mode);
        $this->assertSame('continue_with_next_stage', $pack->next_safe_action);
        $this->assertSame(0.81, $pack->confidence);
        $this->assertSame(64, strlen((string) $pack->pack_hash));
        $this->assertSame(64, strlen((string) $pack->summary_hash));
    }

    public function test_pack_contains_stage_and_evidence_refs_from_receipts(): void
    {
        $runId = $this->seedRunIndex(taskKind: 'feature', riskLevel: 'R1');
        $this->seedReceipt($runId, stage: 'plan', status: 'passed', evidenceRefs: ['diff:plan.patch']);
        $this->seedReceipt($runId, stage: 'review', status: 'passed', evidenceRefs: ['lint_log:review.txt']);

        $pack = app(DevContinuationPackBuilder::class)->build($runId);

        $this->assertContains('plan', $pack->completed_tasks);
        $this->assertContains('review', $pack->completed_tasks);
        // Open tasks must contain the next canonical stage (patch onward).
        $this->assertContains('patch', $pack->open_tasks);

        // Evidence refs from both run index AND receipts surface in the pack.
        $this->assertContains('atlas_dev_run_index:'.$runId, $pack->evidence_refs);
        $this->assertContains('diff:plan.patch', $pack->evidence_refs);
        $this->assertContains('lint_log:review.txt', $pack->evidence_refs);

        // Source receipts are explicit pointers, not paraphrased.
        foreach ((array) $pack->source_receipts as $ref) {
            $this->assertStringStartsWith('stage_receipt:', (string) $ref);
        }
    }

    public function test_pack_hash_is_stable_for_equivalent_inputs(): void
    {
        $runId = $this->seedRunIndex();
        $this->seedReceipt($runId, stage: 'plan', status: 'passed');

        $builder = app(DevContinuationPackBuilder::class);
        $a = $builder->build($runId, ['objective' => 'fix bug', 'stale_after' => '2026-12-31T00:00:00Z']);
        $b = $builder->build($runId, ['objective' => 'fix bug', 'stale_after' => '2026-12-31T00:00:00Z']);

        // Persisted rows differ by uuid/id but the canonical hash recomputed
        // from the payload (excluding volatile fields) is stable.
        $payloadA = $this->payloadFor($a);
        $payloadB = $this->payloadFor($b);
        $payloadA['uuid'] = $payloadB['uuid'] = 'fixed';
        $this->assertSame(
            AtlasLongHorizonContinuationPack::canonicalPackHash($payloadA),
            AtlasLongHorizonContinuationPack::canonicalPackHash($payloadB),
        );

        // A change to the objective MUST change the hash.
        $payloadDifferent = $payloadA;
        $payloadDifferent['objective'] = 'fix something else';
        $this->assertNotSame(
            AtlasLongHorizonContinuationPack::canonicalPackHash($payloadA),
            AtlasLongHorizonContinuationPack::canonicalPackHash($payloadDifferent),
        );
    }

    public function test_failed_stage_routes_to_repair_mode(): void
    {
        $runId = $this->seedRunIndex(taskKind: 'debug', riskLevel: 'R2');
        $this->seedReceipt($runId, stage: 'plan', status: 'passed');
        $this->seedReceipt($runId, stage: 'patch', status: 'failed', evidenceRefs: ['test_log:patch-failed.txt']);

        $pack = app(DevContinuationPackBuilder::class)->build($runId);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_REPAIR, $pack->safe_resume_mode);
        $this->assertNotEmpty($pack->blockers);
        $blockerKinds = array_column((array) $pack->blockers, 'kind');
        $this->assertContains('stage_failed', $blockerKinds);
    }

    public function test_missing_evidence_yields_read_only_mode(): void
    {
        $runId = $this->seedRunIndex();
        // No stage receipts at all and run_index has empty last_receipt_hash —
        // evidence_refs only carries the run_index pointer itself, so the
        // builder degrades to read_only when the operator did not supply any.
        AtlasDevRunIndex::query()->whereKey($runId)->update(['last_receipt_hash' => null]);
        AtlasProgrammingStageReceipt::query()->where('plan_id', $runId)->delete();

        // Drop the run index pointer too so evidence_refs ends up empty.
        AtlasDevRunIndex::query()->whereKey($runId)->delete();
        // Re-create as a blocked-completion-state index so the builder sees
        // run metadata but no evidence.
        AtlasDevRunIndex::query()->create([
            'run_id' => $runId,
            'surface_id' => 'atlas_code',
            'workspace_hash' => hash('sha256', 'ws-fixture'),
            'thread_id' => null,
            'routing_decision' => 'programming',
            'task_kind' => 'feature',
            'risk_level' => 'R1',
            'completion_state' => 'running',
            'last_receipt_hash' => null,
        ]);

        $pack = app(DevContinuationPackBuilder::class)->build($runId);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_READ_ONLY, $pack->safe_resume_mode);
        $this->assertSame('consume_pack_in_read_only_mode', $pack->next_safe_action);
    }

    public function test_missing_run_index_yields_blocked_mode(): void
    {
        $pack = app(DevContinuationPackBuilder::class)->build('non-existent-run-id');

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_BLOCKED, $pack->safe_resume_mode);
        $this->assertSame('resolve_blocker_before_resume', $pack->next_safe_action);
    }

    public function test_r4_risk_escalates_to_forge(): void
    {
        $runId = $this->seedRunIndex(taskKind: 'feature', riskLevel: 'R4');
        $this->seedReceipt($runId, stage: 'plan', status: 'passed');

        $pack = app(DevContinuationPackBuilder::class)->build($runId);

        $this->assertSame(AtlasLongHorizonCanon::SAFE_RESUME_ESCALATE_TO_FORGE, $pack->safe_resume_mode);
        $this->assertSame('emit_dev_to_forge_escalation_packet', $pack->next_safe_action);
    }

    public function test_pack_does_not_depend_on_raw_chat_messages(): void
    {
        $runId = $this->seedRunIndex();
        $this->seedReceipt($runId, stage: 'plan', status: 'passed');

        $pack = app(DevContinuationPackBuilder::class)->build($runId);

        // All evidence refs are typed pointers to first-party tables / receipt
        // ids — never raw chat content.
        foreach ((array) $pack->evidence_refs as $ref) {
            $this->assertIsString($ref);
            $this->assertDoesNotMatchRegularExpression('/^message:|^chat:|^transcript:/', (string) $ref);
        }
        // The context manifest is a list of typed refs (stage receipts), never
        // a verbatim user message.
        foreach ((array) $pack->context_manifest as $entry) {
            $this->assertIsArray($entry);
            $this->assertArrayHasKey('ref', $entry);
            $this->assertStringStartsWith('stage_receipt:', (string) $entry['ref']);
        }
    }

    public function test_pack_serializes_to_stable_json_envelope(): void
    {
        $runId = $this->seedRunIndex();
        $this->seedReceipt($runId, stage: 'plan', status: 'passed');

        $pack = app(DevContinuationPackBuilder::class)->build($runId);

        $payload = $this->payloadFor($pack);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $decoded = json_decode((string) $json, true);
        $this->assertSame(AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION, $decoded['schema_version']);
        foreach ([
            'scope_type', 'scope_id', 'objective', 'state_summary', 'decisions',
            'open_tasks', 'completed_tasks', 'blockers', 'risks',
            'evidence_refs', 'context_manifest', 'safe_resume_mode',
            'next_safe_action', 'pack_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $decoded, "missing canonical key: {$key}");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadFor(AtlasLongHorizonContinuationPack $pack): array
    {
        return [
            'uuid' => $pack->uuid,
            'schema_version' => $pack->schema_version,
            'scope_type' => $pack->scope_type,
            'scope_id' => $pack->scope_id,
            'objective' => $pack->objective,
            'current_phase' => $pack->current_phase,
            'state_summary' => $pack->state_summary,
            'decisions' => $pack->decisions,
            'superseded_decisions' => $pack->superseded_decisions,
            'open_tasks' => $pack->open_tasks,
            'completed_tasks' => $pack->completed_tasks,
            'blockers' => $pack->blockers,
            'risks' => $pack->risks,
            'evidence_refs' => $pack->evidence_refs,
            'context_manifest' => $pack->context_manifest,
            'context_pack_hash' => $pack->context_pack_hash,
            'summary_hash' => $pack->summary_hash,
            'source_receipts' => $pack->source_receipts,
            'stale_after' => $pack->stale_after?->toIso8601String(),
            'safe_resume_mode' => $pack->safe_resume_mode,
            'next_safe_action' => $pack->next_safe_action,
            'human_decisions_required' => $pack->human_decisions_required,
            'confidence' => $pack->confidence,
            'pack_hash' => $pack->pack_hash,
        ];
    }

    private function seedRunIndex(
        ?string $runId = null,
        string $taskKind = 'feature',
        string $riskLevel = 'R2',
        ?string $completionState = 'running',
    ): string {
        $runId ??= 'run-'.bin2hex(random_bytes(6));
        AtlasDevRunIndex::query()->updateOrCreate(['run_id' => $runId], [
            'surface_id' => 'atlas_code',
            'workspace_hash' => hash('sha256', 'ws-fixture'),
            'thread_id' => null,
            'routing_decision' => 'programming',
            'task_kind' => $taskKind,
            'risk_level' => $riskLevel,
            'completion_state' => $completionState,
            'last_receipt_hash' => hash('sha256', 'last-receipt-fixture-'.$runId),
        ]);

        return $runId;
    }

    /**
     * @param  list<string>  $evidenceRefs
     */
    private function seedReceipt(
        string $planId,
        string $stage,
        string $status,
        array $evidenceRefs = [],
        int $attempt = 1,
    ): void {
        AtlasProgrammingStageReceipt::query()->create([
            'receipt_id' => 'r-'.hash('sha256', $planId.'|'.$stage.'|'.$attempt),
            'plan_id' => $planId,
            'parent_plan_id' => null,
            'stage' => $stage,
            'attempt' => $attempt,
            'status' => $status,
            'input_hash' => hash('sha256', $planId.'|in|'.$stage),
            'output_hash' => hash('sha256', $planId.'|out|'.$stage),
            'evidence_refs_json' => $evidenceRefs,
            'payload_json' => ['stage' => $stage, 'status' => $status],
            'validation_json' => ['valid' => true],
        ]);
    }

    private function createDevAdjacentTables(): void
    {
        $this->dropDevAdjacentTables();

        Schema::create('atlas_dev_run_index', function (Blueprint $table): void {
            $table->string('run_id', 128)->primary();
            $table->string('surface_id', 80)->index();
            $table->string('workspace_hash', 128)->index();
            $table->string('thread_id', 128)->nullable()->index();
            $table->string('routing_decision', 32);
            $table->string('task_kind', 40);
            $table->string('risk_level', 8);
            $table->string('completion_state', 32)->nullable()->index();
            $table->string('last_receipt_hash', 128)->nullable();
            $table->timestamps();
        });

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
            $table->json('evidence_refs_json');
            $table->json('payload_json');
            $table->json('validation_json');
            $table->timestamps();
        });
    }

    private function dropDevAdjacentTables(): void
    {
        Schema::dropIfExists('atlas_programming_stage_receipts');
        Schema::dropIfExists('atlas_dev_run_index');
    }
}
