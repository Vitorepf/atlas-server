<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingCodeIntelligenceGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingEvidenceGate;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingGateContract;
use App\Services\Ai\Programming\Governance\ProgrammingEvidenceLedger;
use App\Services\Ai\Programming\Governance\ProgrammingGateRunner;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

/**
 * Locks down regressions for the four Codex review findings:
 *  - P0: CI gate must read `module_count` (the canonical field).
 *  - P0: Evidence Ledger must throw when persistence fails (no silent fallback).
 *  - P0: EvidenceGate must reject receipts that are not persisted in the ledger.
 *  - P1: GateRunner must treat a missing required gate as a blocking failure.
 */
class CodexFindingsRegressionTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->workspace = $this->makeProgrammingWorkspace(['app/Foo.php']);
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_p0_ci_gate_reads_canonical_module_count_field(): void
    {
        $this->app->bind(EngineeringCodeIntelligenceService::class, function (): EngineeringCodeIntelligenceService {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(): array
                {
                    // Production shape: top-level module_count, no `totals` wrapper.
                    return [
                        'status' => 'ready',
                        'table_exists' => true,
                        'module_count' => 23,
                        'symbol_count' => 39419,
                        'doc_link_count' => 61791,
                    ];
                }
            };
        });

        $gate = app(ProgrammingCodeIntelligenceGate::class);
        $workItem = $this->makeWorkItem();

        $outcome = $gate->evaluate($workItem);
        $this->assertSame('passed', $outcome->status, 'CI gate must accept the canonical module_count field.');
        $this->assertSame(23, $outcome->payload['module_count']);
    }

    public function test_p0_ci_gate_fails_when_real_index_is_empty(): void
    {
        $this->app->bind(EngineeringCodeIntelligenceService::class, function (): EngineeringCodeIntelligenceService {
            return new class extends EngineeringCodeIntelligenceService
            {
                public function __construct() {}

                public function summary(): array
                {
                    // Production-empty shape (status=empty, module_count=0).
                    return [
                        'status' => 'empty',
                        'table_exists' => true,
                        'module_count' => 0,
                        'symbol_count' => 0,
                        'doc_link_count' => 0,
                    ];
                }
            };
        });

        $gate = app(ProgrammingCodeIntelligenceGate::class);
        $outcome = $gate->evaluate($this->makeWorkItem());

        $this->assertSame('failed', $outcome->status);
        $this->assertSame('code_intelligence_empty_index', $outcome->reason);
    }

    public function test_p0_evidence_ledger_throws_when_table_is_missing(): void
    {
        Schema::dropIfExists('atlas_engineering_evidence');

        $ledger = new ProgrammingEvidenceLedger();
        $workItem = $this->makeWorkItem();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('atlas_engineering_evidence_table_missing');

        $ledger->record($workItem, [
            'command' => 'phpunit',
            'output' => 'OK',
        ]);
    }

    public function test_p0_evidence_ledger_persists_with_required_columns(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $workItem = $this->makeWorkItem(workspace: $this->workspace);

        $receipt = $ledger->record($workItem, [
            'command' => 'vendor/bin/phpunit',
            'output' => 'OK (50 tests)',
            'files' => ['app/Foo.php'],
        ]);

        $this->assertTrue($receipt['storage']['persisted']);
        $row = AtlasEngineeringEvidence::query()->firstOrFail();

        // Production schema requires task_id NOT NULL, summary NOT NULL,
        // status NOT NULL, evidence_type NOT NULL.
        $this->assertSame($workItem->id, $row->task_id, 'task_id must be non-null and bound to the work item.');
        $this->assertNotEmpty($row->summary);
        $this->assertNotEmpty($row->status);
        $this->assertNotEmpty($row->evidence_type);
        // trace_id must stay NULL because the work item code is not a UUID.
        $this->assertNull($row->trace_id);
        $this->assertSame('atlas_programming_governance', $row->source);

        // Hardening: file_hashes and output_hash must be populated.
        $this->assertNotEmpty($row->metadata['file_hashes']);
        $this->assertSame('app/Foo.php', $row->metadata['file_hashes'][0]['path']);
        $this->assertSame(64, strlen($row->metadata['file_hashes'][0]['sha256']));
        $this->assertSame(64, strlen($row->metadata['output_hash']));
    }

    public function test_p0_evidence_gate_rejects_receipts_not_persisted_in_ledger(): void
    {
        $workItem = $this->makeWorkItem();
        $workItem->forceFill([
            'evidence_refs_json' => [[
                'schema_version' => 'atlas.programming.evidence_receipt.v1',
                'command' => 'vendor/bin/phpunit',
                'output' => 'OK',
                'files' => ['app/Foo.php'],
                'storage' => ['persisted' => false, 'reason' => 'engineering_evidence_write_failed'],
            ]],
        ])->save();

        $gate = new ProgrammingEvidenceGate();
        $outcome = $gate->evaluate($workItem->refresh());

        $this->assertSame('failed', $outcome->status);
        $this->assertSame('no_verifiable_receipt', $outcome->reason);
        $this->assertSame('not_persisted_in_ledger', $outcome->payload['invalid_receipts'][0]['reason']);
    }

    public function test_p1_missing_required_gate_is_blocking_failure_not_skip(): void
    {
        // Build a runner with NO gates registered.
        $runner = new ProgrammingGateRunner([]);
        $workItem = $this->makeWorkItem();

        $summary = $runner->run($workItem, ['spec-before-code', 'evidence-required', 'completion']);

        $this->assertFalse($summary['all_green'], 'A missing required gate must NOT yield all_green.');
        $this->assertContains('spec-before-code', $summary['gates_missing_blocking']);
        $this->assertContains('spec-before-code', $summary['blocking_failures']);

        // Persisted gate run must reflect the failure status.
        $row = $workItem->gateRuns()->where('gate_name', 'spec-before-code')->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertTrue((bool) $row->blocking);
    }

    public function test_p1_missing_advisory_gate_can_stay_skipped(): void
    {
        $runner = new ProgrammingGateRunner([]);
        $workItem = $this->makeWorkItem();

        $summary = $runner->run($workItem, ['cartography-update']);

        // cartography-update is advisory in structural mode → missing is OK
        $this->assertContains('cartography-update', $summary['gates_missing']);
        $this->assertNotContains('cartography-update', $summary['gates_missing_blocking']);
    }

    public function test_full_governance_runner_with_no_gates_blocks_completion(): void
    {
        $this->app->instance(ProgrammingGateRunner::class, new ProgrammingGateRunner([]));

        /** @var ProgrammingGovernanceService $governance */
        $governance = app(ProgrammingGovernanceService::class);
        $intake = $governance->intake('Refatorar runner para suportar Forge OS', []);

        $workItem = $governance->find($intake['code']);
        $result = $governance->verify($workItem);

        $this->assertFalse($result['gate_summary']['all_green']);
        $this->assertNotEmpty($result['gate_summary']['gates_missing_blocking']);
    }

    private function makeWorkItem(?string $workspace = null): AtlasProgrammingWorkItem
    {
        return AtlasProgrammingWorkItem::query()->create([
            'code' => 'TST-'.bin2hex(random_bytes(4)),
            'intent_text' => 'Test intent',
            'intent_type' => 'feature',
            'scope_mode' => 'structural',
            'risk_level' => 'medium',
            'owner' => null,
            'workspace' => $workspace,
            'status' => 'spec_required',
            'current_stage' => 'intake',
            'placement_json' => [],
            'code_intelligence_json' => [],
            'spec_json' => [],
            'plan_json' => [],
            'tasks_json' => [],
            'evidence_refs_json' => [],
            'gaps_json' => [],
            'metadata_json' => [],
        ]);
    }
}
