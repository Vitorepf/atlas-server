<?php

namespace Tests\Feature\Sdd;

use App\Models\AtlasAcceptanceCriteria;
use App\Models\AtlasOperation;
use App\Models\AtlasPlan;
use App\Models\AtlasRequirement;
use App\Models\AtlasSddDriftReport;
use App\Models\AtlasSddTask;
use App\Models\AtlasSpec;
use App\Models\AtlasSpecTraceability;
use App\Services\Ai\Programming\Sdd\SpecDriftDetector;
use Tests\Concerns\CreatesAtlasSddTables;
use Tests\TestCase;

class SpecDriftDetectorTest extends TestCase
{
    use CreatesAtlasSddTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasSddTables();
    }

    protected function tearDown(): void
    {
        $this->dropAtlasSddTables();
        parent::tearDown();
    }

    public function test_pass_when_spec_is_clean(): void
    {
        $spec = $this->makeApprovedSpecWithFullTraceability();
        $report = (new SpecDriftDetector())->inspect($spec);

        $this->assertSame('pass', $report['status']);
        $this->assertSame('atlas.sdd_drift.v1', $report['schema_version']);
        $this->assertSame([], $report['findings']);
        $this->assertSame('none', $report['recommended_action']);
    }

    public function test_fail_when_requirement_has_no_acceptance_criteria(): void
    {
        $spec = $this->makeMinimalSpec();
        AtlasRequirement::query()->create([
            'spec_id' => $spec->id, 'code' => 'R-1', 'text' => 'must work',
            'priority' => 'must', 'status' => 'open', 'metadata_json' => [],
        ]);

        $report = (new SpecDriftDetector())->inspect($spec);
        $this->assertSame('fail', $report['status']);
        $this->assertSame('requirement_without_acceptance', $report['findings'][0]['type']);
    }

    public function test_warn_when_requirement_lacks_traceability(): void
    {
        $spec = $this->makeMinimalSpec();
        $req = AtlasRequirement::query()->create([
            'spec_id' => $spec->id, 'code' => 'R-1', 'text' => 'must work',
            'priority' => 'must', 'status' => 'open', 'metadata_json' => [],
        ]);
        AtlasAcceptanceCriteria::query()->create([
            'requirement_id' => $req->id, 'code' => 'AC-1',
            'when' => 'request', 'then' => 'response', 'metadata_json' => [],
        ]);

        $report = (new SpecDriftDetector())->inspect($spec);
        $this->assertSame('warn', $report['status']);
        $types = array_column($report['findings'], 'type');
        $this->assertContains('requirement_without_traceability', $types);
    }

    public function test_fail_when_approved_spec_has_no_plan(): void
    {
        $spec = $this->makeMinimalSpec(status: 'approved');

        $report = (new SpecDriftDetector())->inspect($spec);
        $types = array_column($report['findings'], 'type');
        $this->assertContains('approved_spec_without_plan', $types);
    }

    public function test_persists_drift_report_row(): void
    {
        $spec = $this->makeApprovedSpecWithFullTraceability();
        (new SpecDriftDetector())->inspect($spec, source: 'unit-test');

        $row = AtlasSddDriftReport::query()->where('spec_id', $spec->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('pass', $row->status);
        $this->assertSame('unit-test', $row->source);
        $this->assertSame('atlas.sdd_drift.v1', $row->detector_version);
    }

    public function test_evidence_missing_for_implemented_requirement_marked_fail(): void
    {
        $spec = $this->makeApprovedSpecWithFullTraceability();
        $req = $spec->requirements()->first();
        $req->update(['status' => 'implemented']);
        // Strip evidence_event_id from traceability rows
        AtlasSpecTraceability::query()->where('spec_id', $spec->id)->update(['evidence_event_id' => null]);

        $report = (new SpecDriftDetector())->inspect($spec);
        $types = array_column($report['findings'], 'type');
        $this->assertContains('evidence_missing_for_implemented_requirement', $types);
        $this->assertSame('fail', $report['status']);
    }

    private function makeMinimalSpec(string $status = 'draft'): AtlasSpec
    {
        $op = AtlasOperation::query()->create([
            'raw_input' => 'x', 'status' => 'routed', 'risk_level' => 'low',
            'domain' => 'programming', 'routing_metadata_json' => [],
        ]);

        return AtlasSpec::query()->create([
            'operation_id' => $op->id, 'title' => 't', 'type' => 'feature',
            'status' => $status, 'version' => 1, 'risk_level' => 'low',
            'content_hash' => str_repeat('a', 64), 'content_json' => [],
        ]);
    }

    private function makeApprovedSpecWithFullTraceability(): AtlasSpec
    {
        $spec = $this->makeMinimalSpec(status: 'approved');
        AtlasPlan::query()->create([
            'spec_id' => $spec->id, 'content_hash' => str_repeat('b', 64),
            'content_json' => [], 'target_files_json' => ['app/Foo.php'],
            'forbidden_files_json' => [], 'hot_file_ownership_json' => [],
            'test_plan_json' => [], 'rollback_plan_json' => [], 'status' => 'draft',
        ]);
        $req = AtlasRequirement::query()->create([
            'spec_id' => $spec->id, 'code' => 'R-1', 'text' => 'requirement',
            'priority' => 'must', 'status' => 'open', 'metadata_json' => [],
        ]);
        AtlasAcceptanceCriteria::query()->create([
            'requirement_id' => $req->id, 'code' => 'AC-1',
            'when' => 'request', 'then' => 'ok', 'metadata_json' => [],
        ]);
        $task = AtlasSddTask::query()->create([
            'plan_id' => $spec->plans->first()->id, 'spec_id' => $spec->id,
            'code' => 'T-01', 'type' => 'implement', 'title' => 'implement',
            'depends_on_json' => [], 'allowed_files_json' => ['app/Foo.php'],
            'forbidden_files_json' => [], 'acceptance_refs_json' => ['AC-1'],
            'status' => 'pending', 'order_index' => 1,
        ]);
        AtlasSpecTraceability::query()->create([
            'spec_id' => $spec->id, 'requirement_id' => $req->id,
            'task_id' => $task->id, 'file_path' => 'app/Foo.php',
            'evidence_event_id' => $spec->id, // any non-null UUID for this test
            'link_type' => 'implements', 'confidence' => 'confirmed',
            'metadata_json' => [],
        ]);

        return $spec->refresh();
    }
}
