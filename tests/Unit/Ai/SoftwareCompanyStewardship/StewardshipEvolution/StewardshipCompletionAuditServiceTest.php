<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipCompletionAuditService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipCompletionAuditServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap763_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_projection_audit_reaches_item_18_and_marks_real_owner_execution_as_weak(): void
    {
        $report = $this->service()->audit();

        $this->assertSame(StewardshipCompletionAuditService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipCompletionAuditService::STATUS_INCOMPLETE, $report['status']);
        $this->assertFalse($report['completion_claim_allowed']);
        $this->assertSame(18, $report['current_practical_number']);
        $this->assertSame(29, $report['target_practical_number']);
        $this->assertSame(29, $report['requirement_count']);
        $this->assertSame(28, $report['proven_count']);
        $this->assertSame(1, $report['weak_count']);
        $this->assertSame(0, $report['missing_count']);
        $this->assertSame('not_requested', data_get($report, 'execution_certification.status'));

        $executionRequirement = collect($report['requirements'])->firstWhere('requirement_id', 'real_dev_forge_execution_inside_isolated_sandbox');
        $this->assertSame(19, $executionRequirement['order']);
        $this->assertSame('weak', $executionRequirement['status']);
        $this->assertContains(
            'execution_stage_ap759_owner_sandbox_runner:weak',
            $executionRequirement['blockers'],
        );
    }

    public function test_execution_audit_proves_all_29_practical_requirements(): void
    {
        $report = $this->service()->audit(['include_execution_certification' => true]);

        $this->assertSame(StewardshipCompletionAuditService::STATUS_COMPLETE, $report['status']);
        $this->assertTrue($report['completion_claim_allowed']);
        $this->assertSame(29, $report['current_practical_number']);
        $this->assertSame(29, $report['target_practical_number']);
        $this->assertSame(29, $report['proven_count']);
        $this->assertSame(0, $report['weak_count']);
        $this->assertSame(0, $report['missing_count']);
        $this->assertSame('certified', data_get($report, 'projection_certification.status'));
        $this->assertSame('certified', data_get($report, 'execution_certification.status'));
        $this->assertSame('owner_command_execution_certification', data_get($report, 'execution_certification.mode'));
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $report['completion_audit_hash']);

        foreach ($report['requirements'] as $requirement) {
            $this->assertSame('proven', $requirement['status'], 'Requirement '.$requirement['order'].' must be proven.');
            $this->assertSame([], $requirement['blockers']);
        }
    }

    public function test_blocks_completion_when_ap762_projection_certification_is_not_certified(): void
    {
        $report = $this->service()->audit([
            'projection_certification' => [
                'schema_version' => 'atlas.software_company_stewardship.live_cycle_certification.v1',
                'status' => 'blocked',
                'mode' => 'projection_certification',
                'blockers' => ['forced_test_blocker'],
            ],
        ]);

        $this->assertSame(StewardshipCompletionAuditService::STATUS_BLOCKED, $report['status']);
        $this->assertFalse($report['completion_claim_allowed']);
        $this->assertContains('ap762_projection_certification_not_certified:blocked', $report['blockers']);
    }

    public function test_claim_policy_prevents_audit_from_becoming_a_parallel_runtime(): void
    {
        $report = $this->service()->audit();

        $this->assertTrue(data_get($report, 'claim_policy.certifier_only'));
        $this->assertFalse(data_get($report, 'claim_policy.creates_new_runtime'));
        $this->assertFalse(data_get($report, 'claim_policy.schedules_recurring_work'));
        $this->assertFalse(data_get($report, 'claim_policy.direct_provider_call_by_audit'));
        $this->assertFalse(data_get($report, 'claim_policy.merge_performed_by_audit'));
        $this->assertFalse(data_get($report, 'duplicate_overlap_resolution.supersedes_ap762'));
        $this->assertTrue(data_get($report, 'duplicate_overlap_resolution.reuses_ap762_live_cycle_certification'));
    }

    private function service(): StewardshipCompletionAuditService
    {
        $service = app(StewardshipCompletionAuditService::class);
        $service->setStorageRootForTesting($this->tmp.'/storage');

        return $service;
    }
}
