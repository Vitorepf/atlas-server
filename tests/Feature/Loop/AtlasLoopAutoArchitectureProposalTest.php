<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoArchitectureProposalService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ArchitectureEvolutionProposalAdmissionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasEngineeringCodeTables;
use Tests\TestCase;

final class AtlasLoopAutoArchitectureProposalTest extends TestCase
{
    use CreatesAtlasEngineeringCodeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasEngineeringCodeTables();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        $this->dropAtlasEngineeringCodeTables();
        parent::tearDown();
    }

    public function test_auto_architecture_proposes_structural_refactor_from_code_graph_without_apply(): void
    {
        $this->seedHotspotModule(fileCount: 44, symbolCount: 260, testCount: 0);

        $payload = app(AtlasLoopAutoArchitectureProposalService::class)->propose([
            'candidate_limit' => 3,
            'min_file_count' => 20,
            'min_symbol_count' => 120,
        ]);

        $this->assertSame(AtlasLoopAutoArchitectureProposalService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_for_operator_review', $payload['status']);
        $this->assertSame('atlas-loop-large-runtime', data_get($payload, 'top_candidate.module_slug'));
        $this->assertContains('large_module_file_count', data_get($payload, 'top_candidate.reasons'));
        $this->assertContains('large_module_symbol_count', data_get($payload, 'top_candidate.reasons'));
        $this->assertSame(AtlasLoopAutoArchitectureProposalService::PROPOSAL_ENVELOPE_SCHEMA, data_get($payload, 'proposal_envelope.schema'));
        $this->assertSame(ArchitectureEvolutionProposalAdmissionService::STATUS_PENDING_HUMAN_REVIEW, data_get($payload, 'admission.admission_status'));
        $this->assertContains(ArchitectureEvolutionProposalAdmissionService::BLOCKER_MISSING_OPERATOR_SIGNATURE, data_get($payload, 'admission.blockers'));
        $this->assertContains(ArchitectureEvolutionProposalAdmissionService::BLOCKER_MISSING_ARCHITECT_SIGNATURE, data_get($payload, 'admission.blockers'));
        $this->assertFalse((bool) data_get($payload, 'admission.apply_side_effect'));
        $this->assertFalse((bool) data_get($payload, 'admission.can_apply'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.code_workspace_mutated'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.merged_to_main'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
    }

    public function test_auto_architecture_parks_idempotent_backlog_proposal_for_review(): void
    {
        $this->seedHotspotModule(fileCount: 38, symbolCount: 220, testCount: 1);

        $first = app(AtlasLoopAutoArchitectureProposalService::class)->propose([
            'create_proposal' => true,
            'min_file_count' => 20,
            'min_symbol_count' => 120,
        ]);
        $second = app(AtlasLoopAutoArchitectureProposalService::class)->propose([
            'create_proposal' => true,
            'min_file_count' => 20,
            'min_symbol_count' => 120,
        ]);

        $proposalId = (string) data_get($first, 'created_backlog_proposal.proposal_id');
        $this->assertSame('parked_for_operator_review', $first['status']);
        $this->assertStringStartsWith('prop_', $proposalId);
        $this->assertSame(AtlasSelfImprovementProposalBacklogService::STATUS_PENDING_HUMAN_REVIEW, data_get($first, 'created_backlog_proposal.evaluated_status'));
        $this->assertNull(data_get($first, 'created_backlog_proposal.linked_obra_id'));
        $this->assertFalse((bool) data_get($first, 'created_backlog_proposal.external_provider_call'));
        $created = app(AtlasSelfImprovementProposalBacklogService::class)->getProposal($proposalId);
        $this->assertSame('hermes_cli', data_get($created, 'proposal_packet.provider_topology_recommendation.preferred_primary_runtime'));
        $this->assertSame('gpt-5.5', data_get($created, 'proposal_packet.provider_topology_recommendation.preferred_model'));
        $this->assertFalse((bool) data_get($first, 'claim_policy.obra_created'));
        $this->assertSame($proposalId, data_get($second, 'created_backlog_proposal.proposal_id'));
        $this->assertTrue((bool) data_get($second, 'created_backlog_proposal.reused'));
        Storage::disk('local')->assertExists('atlas/loop/auto-architecture/proposal-index.json');
    }

    public function test_auto_architecture_can_record_operator_review_without_admitting_apply(): void
    {
        $this->seedHotspotModule(fileCount: 38, symbolCount: 220, testCount: 1);

        $payload = app(AtlasLoopAutoArchitectureProposalService::class)->propose([
            'create_proposal' => true,
            'operator_review' => 'operator reviewed L6-4 structural proposal for test fixture',
            'min_file_count' => 20,
            'min_symbol_count' => 120,
        ]);

        $this->assertTrue((bool) data_get($payload, 'operator_review.recorded'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
        $this->assertSame(ArchitectureEvolutionProposalAdmissionService::STATUS_PENDING_HUMAN_REVIEW, data_get($payload, 'admission.admission_status'));
        $this->assertNotContains(ArchitectureEvolutionProposalAdmissionService::BLOCKER_MISSING_OPERATOR_SIGNATURE, data_get($payload, 'admission.blockers'));
        $this->assertContains(ArchitectureEvolutionProposalAdmissionService::BLOCKER_MISSING_ARCHITECT_SIGNATURE, data_get($payload, 'admission.blockers'));
        $this->assertFalse((bool) data_get($payload, 'admission.can_apply'));
    }

    public function test_auto_architecture_blocks_when_code_graph_tables_are_missing(): void
    {
        $this->dropAtlasEngineeringCodeTables();

        $payload = app(AtlasLoopAutoArchitectureProposalService::class)->propose();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('table_missing:atlas_engineering_code_modules', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
    }

    public function test_auto_architecture_command_writes_receipt_and_strict_needs_operator_review(): void
    {
        $this->seedHotspotModule(fileCount: 38, symbolCount: 220, testCount: 1);
        $receipt = storage_path('framework/testing/l6-4-auto-architecture-test.json');

        $exit = Artisan::call('atlas:loop:auto-architecture', [
            '--create-proposal' => true,
            '--write-receipt' => true,
            '--receipt-path' => $receipt,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('parked_for_operator_review', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.completion_claim_allowed'));
        $this->assertFileExists($receipt);

        @unlink($receipt);
    }

    private function seedHotspotModule(int $fileCount, int $symbolCount, int $testCount): void
    {
        $moduleId = (string) Str::uuid();
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => $moduleId,
            'slug' => 'atlas-loop-large-runtime',
            'name' => 'Atlas Loop Large Runtime',
            'layer' => 'runtime',
            'root_path' => 'app/Services/Ai/AutonomousEvolution',
            'primary_language' => 'php',
            'status' => 'active',
            'owner' => 'autonomous-evolution',
            'description' => 'Fixture module with structural pressure.',
            'docs_status' => 'undocumented',
            'file_count' => $fileCount,
            'symbol_count' => $symbolCount,
            'route_count' => 0,
            'command_count' => 14,
            'migration_count' => 0,
            'test_count' => $testCount,
            'source_hash' => hash('sha256', 'fixture-source'),
            'docs_hash' => null,
            'tags_json' => json_encode(['loop', 'runtime']),
            'related_docs_json' => json_encode(['docs/engineering-knowledge-base/atlas-unified-evolution-loop.md']),
            'related_tests_json' => json_encode(['tests/Feature/Loop/AtlasLoopAutoArchitectureProposalTest.php']),
            'metadata' => json_encode([]),
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 0; $i < 12; $i++) {
            DB::table('atlas_engineering_code_symbols')->insert($this->symbolRow($moduleId, 'class', 'FixtureClass'.$i, $i));
        }
        for ($i = 0; $i < 80; $i++) {
            DB::table('atlas_engineering_code_symbols')->insert($this->symbolRow($moduleId, 'method', 'method'.$i, $i + 20));
        }
        DB::table('atlas_engineering_doc_links')->insert([
            'id' => (string) Str::uuid(),
            'knowledge_item_id' => null,
            'module_id' => $moduleId,
            'symbol_id' => null,
            'link_type' => 'owner_doc',
            'status' => 'current',
            'canonical_path' => 'docs/engineering-knowledge-base/atlas-unified-evolution-loop.md',
            'target_path' => 'app/Services/Ai/AutonomousEvolution',
            'doc_hash' => hash('sha256', 'doc'),
            'target_hash' => hash('sha256', 'target'),
            'link_hash' => hash('sha256', 'link-'.$moduleId),
            'metadata' => json_encode([]),
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function symbolRow(string $moduleId, string $type, string $name, int $line): array
    {
        return [
            'id' => (string) Str::uuid(),
            'module_id' => $moduleId,
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => 'app/Services/Ai/AutonomousEvolution/'.$name.'.php',
            'line_start' => max(1, $line),
            'line_end' => max(1, $line + 1),
            'language' => 'php',
            'signature' => $name.'()',
            'namespace' => 'App\\Services\\Ai\\AutonomousEvolution',
            'parent_symbol' => null,
            'visibility' => 'public',
            'status' => 'active',
            'docs_status' => 'undocumented',
            'source_hash' => hash('sha256', $type.$name),
            'related_doc_ids_json' => json_encode([]),
            'metadata' => json_encode([]),
            'indexed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
