<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use Tests\TestCase;

class AtlasFrontendOutcomeMemoryServiceTest extends TestCase
{
    public function test_records_provider_safe_frontend_outcome(): void
    {
        $record = app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'failed',
            'task_type' => 'SaaS dashboard repair',
            'surface' => 'programming.frontend',
            'workspace' => '/tmp/acme',
            'company_profile_ref' => 'acme-profile',
            'drivers' => ['UX Driven', 'ATDD', 'TDD'],
            'gates' => ['visual_quality_gate', 'anti_ai_slop_detector'],
            'failed_gates' => ['visual_quality_gate'],
            'evidence_refs' => ['evidence://visual-report', 'raw_prompt://must-be-filtered'],
        ]);

        $this->assertSame('atlas.frontend.outcome_record.v1', $record['schema_version']);
        $this->assertSame('failed', $record['status']);
        $this->assertContains('ux_driven', $record['selected_drivers']);
        $this->assertContains('visual_quality_gate', $record['failed_gates']);
        $this->assertFalse((bool) data_get($record, 'effectiveness.doctrine_effective'));
        $this->assertFalse((bool) data_get($record, 'provider_policy.raw_task_or_customer_source_returned'));
        $this->assertNotContains('raw_prompt://must-be-filtered', $record['evidence_refs']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $record['record_hash']);
    }

    public function test_summarizes_jsonl_store_with_failed_gate_counts(): void
    {
        $store = sys_get_temp_dir().'/atlas-frontend-outcomes-'.bin2hex(random_bytes(4)).'.jsonl';
        $memory = app(AtlasFrontendOutcomeMemoryService::class);
        $memory->record([
            'status' => 'passed',
            'drivers' => ['ux_driven'],
            'gates' => ['frontend_execution_gate'],
            'evidence_refs' => ['receipt://one'],
        ], $store);
        $memory->record([
            'status' => 'blocked',
            'drivers' => ['ux_driven'],
            'failed_gates' => ['frontend_execution_gate'],
            'evidence_refs' => ['receipt://two'],
        ], $store);

        $summary = $memory->summarize($store);

        $this->assertSame('atlas.frontend.outcome_memory.v1', $summary['schema_version']);
        $this->assertSame(2, data_get($summary, 'records.total'));
        $this->assertSame(1, data_get($summary, 'records.passed'));
        $this->assertSame('frontend_execution_gate', data_get($summary, 'top_failed_gates.0.gate'));
        $this->assertTrue((bool) data_get($summary, 'learning_policy.promote_to_aemor_when_records_exist'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $summary['outcome_memory_hash']);
    }
}
