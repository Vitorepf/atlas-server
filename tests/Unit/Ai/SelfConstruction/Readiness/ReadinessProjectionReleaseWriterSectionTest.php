<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionReleaseWriterSection;
use Tests\TestCase;

final class ReadinessProjectionReleaseWriterSectionTest extends TestCase
{
    public function test_bound_section_rejects_dynamic_calls_to_private_mother_methods(): void
    {
        $section = (new ReadinessProjectionReleaseWriterSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class));

        $this->expectException(\BadMethodCallException::class);

        $section->stableHash(['private' => 'mother-method']);
    }

    public function test_bound_section_executes_its_writer_contract_without_reflection(): void
    {
        $contract = (new ReadinessProjectionReleaseWriterSection)
            ->setMother(app(AtlasSelfConstructionReadinessService::class))
            ->agentAutomaticDispatchSchedulerOneShotTickMutatingWriterContract();

        $this->assertArrayHasKey('schema_version', $contract);
        $this->assertArrayHasKey('agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_contract_hash', $contract);
    }

    public function test_writer_preflight_exposes_missing_release_receipt_hash_as_a_blocker(): void
    {
        $preflight = app(AtlasSelfConstructionReadinessService::class)
            ->agentAutomaticDispatchSchedulerOneShotTickWriterPreflight();

        $this->assertSame('blocked', $preflight['status']);
        $this->assertContains(
            'release_receipt_hash_provided_not_ready',
            $preflight['agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight']['blocking_reasons']
        );
        $this->assertSame(
            'repair_signed_one_shot_scheduler_tick_writer_preflight_blockers',
            $preflight['agent_automatic_dispatch_scheduler_one_shot_tick_writer_preflight']['next_required_slice']
        );
        $this->assertStringContainsString('blocked', $preflight['human_summary']);
    }
}
