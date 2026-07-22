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
}
