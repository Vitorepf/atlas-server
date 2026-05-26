<?php

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryConflictResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryRelationsConflictVerbsTable;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — Absorcao 2 (Conflict Verbs).
 *
 * Feature tests da persistencia em atlas_memory_entry_relations
 * com colunas estendidas para os seis verbos canonicos.
 */
class AtlasMemoryConflictResolutionPersistenceTest extends TestCase
{
    use CreatesAtlasMemoryEntryRelationsConflictVerbsTable;
    use CreatesAtlasMemoryEntryTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        $this->createAtlasMemoryEntryRelationsConflictVerbsTable();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryRelationsConflictVerbsTable();
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    private function makeMemoryEntry(string $memoryType = 'preference', string $title = 'mem'): AtlasMemoryEntry
    {
        $entry = new AtlasMemoryEntry([
            'memory_type' => $memoryType,
            'scope_type' => 'global',
            'scope_id' => null,
            'title' => $title,
            'body' => "body of $title",
            'summary' => null,
            'importance' => 3,
            'priority' => 50,
            'confidence' => 0.8,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'manual',
            'source_id' => null,
            'source_label' => null,
            'status' => 'active',
            'tags' => [],
            'metadata' => [],
            'recorded_at' => now(),
        ]);

        $entry->id = (string) Str::uuid();
        $entry->save();

        return $entry;
    }

    public function test_judge_persists_row_with_canonical_columns_filled(): void
    {
        $source = $this->makeMemoryEntry('preference', 'Auth via JWT');
        $target = $this->makeMemoryEntry('preference', 'Auth via sessions');

        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge((string) $source->id, (string) $target->id, 'compatible', [
            'actor' => 'agent',
            'model' => 'claude-sonnet-4-7',
            'confidence' => 0.85,
            'reason' => 'Ambos sao auth methods, podem coexistir em escopos diferentes.',
            'evidence_refs' => ['evidence-uuid-1'],
        ]);

        $this->assertTrue($result['ok'], 'judge deve retornar ok=true para verdict valido low-risk.');
        $this->assertSame('verdict_recorded', $result['status']);
        $this->assertSame('compatible', $result['verdict']);
        $this->assertNotNull($result['relation_id']);
        $this->assertFalse($result['should_escalate']);

        $row = DB::table('atlas_memory_entry_relations')->where('id', $result['relation_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame('compatible', $row->relation_type);
        $this->assertSame('agent', $row->marked_by_actor);
        $this->assertSame('claude-sonnet-4-7', $row->marked_by_model);
        $this->assertSame('judged', $row->judgment_status);
        $this->assertSame('atlas.memory.relation_verdict.v1', $row->verdict_schema_version);
        $this->assertSame(['evidence-uuid-1'], json_decode($row->evidence_refs, true));
    }

    public function test_judge_blocks_agent_on_high_risk_visible_verdict_without_bypass(): void
    {
        $source = $this->makeMemoryEntry('decision', 'Use Postgres');
        $target = $this->makeMemoryEntry('decision', 'Use MySQL');

        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge((string) $source->id, (string) $target->id, 'supersedes', [
            'actor' => 'agent',
            'confidence' => 0.95,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('requires_human_escalation', $result['status']);
        $this->assertTrue($result['should_escalate']);
        $this->assertContains('visible_verdict_on_high_risk_memory_type', $result['escalation_reasons']);

        $count = DB::table('atlas_memory_entry_relations')->count();
        $this->assertSame(0, $count);
    }

    public function test_judge_allows_human_actor_on_high_risk_visible_verdict(): void
    {
        $source = $this->makeMemoryEntry('decision', 'Use Postgres');
        $target = $this->makeMemoryEntry('decision', 'Use MySQL');

        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge((string) $source->id, (string) $target->id, 'supersedes', [
            'actor' => 'human',
            'confidence' => 0.95,
            'reason' => 'Postgres venceu a discussao em 2026-05-20.',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('verdict_recorded', $result['status']);
        $this->assertTrue($result['should_escalate'], 'escalation flag continua true; mas humano pode bypassar.');
    }

    public function test_judge_allows_bypass_when_explicitly_requested(): void
    {
        $source = $this->makeMemoryEntry('architecture', 'Use REST');
        $target = $this->makeMemoryEntry('architecture', 'Use GraphQL');

        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge((string) $source->id, (string) $target->id, 'conflicts_with', [
            'actor' => 'atlas',
            'confidence' => 0.8,
            'allow_escalation_bypass' => true,
            'reason' => 'Decisao tomada por governance Atlas autorizada.',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('verdict_recorded', $result['status']);
    }

    public function test_not_conflict_from_agent_is_audited_without_persisting(): void
    {
        $source = $this->makeMemoryEntry('preference', 'A');
        $target = $this->makeMemoryEntry('preference', 'B');

        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge((string) $source->id, (string) $target->id, 'not_conflict', [
            'actor' => 'agent',
            'confidence' => 0.9,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('audited_no_insert', $result['status']);
        $this->assertNull($result['relation_id']);

        $count = DB::table('atlas_memory_entry_relations')->count();
        $this->assertSame(0, $count, 'not_conflict de agent nao persiste row.');
    }

    public function test_not_conflict_from_human_persists(): void
    {
        $source = $this->makeMemoryEntry('preference', 'A');
        $target = $this->makeMemoryEntry('preference', 'B');

        $service = new AtlasMemoryConflictResolutionService;

        $result = $service->judge((string) $source->id, (string) $target->id, 'not_conflict', [
            'actor' => 'human',
            'confidence' => 1.0,
            'reason' => 'Auditoria humana: nao ha conflito.',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('verdict_recorded', $result['status']);
        $this->assertNotNull($result['relation_id']);
    }

    public function test_judge_upserts_existing_row_on_repeat_call(): void
    {
        $source = $this->makeMemoryEntry('preference', 'A');
        $target = $this->makeMemoryEntry('preference', 'B');

        $service = new AtlasMemoryConflictResolutionService;

        $first = $service->judge((string) $source->id, (string) $target->id, 'related', [
            'actor' => 'agent',
            'confidence' => 0.7,
            'reason' => 'inicial',
        ]);

        $second = $service->judge((string) $source->id, (string) $target->id, 'related', [
            'actor' => 'human',
            'confidence' => 0.95,
            'reason' => 'humano refinou',
        ]);

        $this->assertSame($first['relation_id'], $second['relation_id'], 'mesmo trio = mesmo id (UNIQUE constraint phase 1).');
        $this->assertSame('verdict_updated', $second['status']);

        $row = DB::table('atlas_memory_entry_relations')->where('id', $second['relation_id'])->first();
        $this->assertSame('human', $row->marked_by_actor);
        $this->assertEqualsWithDelta(0.95, (float) $row->confidence, 0.001);
    }

    public function test_related_conflicts_returns_only_visible_verdicts(): void
    {
        $a = $this->makeMemoryEntry('preference', 'A');
        $b = $this->makeMemoryEntry('preference', 'B');
        $c = $this->makeMemoryEntry('preference', 'C');

        $service = new AtlasMemoryConflictResolutionService;

        $service->judge((string) $a->id, (string) $b->id, 'related', ['actor' => 'agent', 'confidence' => 0.9]);
        $service->judge((string) $a->id, (string) $c->id, 'conflicts_with', ['actor' => 'human', 'confidence' => 0.9]);

        $conflicts = $service->relatedConflicts((string) $a->id);

        $this->assertCount(1, $conflicts, 'apenas verdict visible aparece em relatedConflicts.');
        $this->assertSame('conflicts_with', $conflicts[0]['verdict']);
        $this->assertSame((string) $c->id, $conflicts[0]['partner_id']);
    }

    public function test_latest_verdict_returns_canonical_envelope(): void
    {
        $source = $this->makeMemoryEntry('preference', 'A');
        $target = $this->makeMemoryEntry('preference', 'B');

        $service = new AtlasMemoryConflictResolutionService;

        $service->judge((string) $source->id, (string) $target->id, 'compatible', [
            'actor' => 'agent',
            'model' => 'claude-haiku-4-5',
            'confidence' => 0.88,
            'reason' => 'check',
            'evidence_refs' => ['e1', 'e2'],
        ]);

        $latest = $service->latestVerdict((string) $source->id, (string) $target->id);

        $this->assertNotNull($latest);
        $this->assertSame('compatible', $latest['verdict']);
        $this->assertSame('agent', $latest['marked_by_actor']);
        $this->assertSame('claude-haiku-4-5', $latest['marked_by_model']);
        $this->assertEqualsWithDelta(0.88, $latest['confidence'], 0.001);
        $this->assertSame(['e1', 'e2'], $latest['evidence_refs']);
    }
}
