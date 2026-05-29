<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopCycleFailureTaxonomyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract;
use Tests\TestCase;

final class PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContractTest extends TestCase
{
    public function test_dedicated_psr4_contract_file_exists(): void
    {
        $path = app_path('Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract.php');

        $this->assertFileExists($path);
        $this->assertTrue(class_exists(PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::class));
    }

    public function test_default_shape_declares_per_failure_class_remediation_hints(): void
    {
        $shape = PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::defaults()->toArray();

        $this->assertSame(
            PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::SCHEMA,
            $shape['schema_version'],
        );
        $this->assertSame(LoopCycleFailureTaxonomyService::REPORT_SCHEMA, $shape['taxonomy_report_schema']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            $shape['stack_canonical'],
        );
        $this->assertSame([
            LoopCycleFailureTaxonomyService::TIER_SETUP_NAME => 'retryable',
            LoopCycleFailureTaxonomyService::TIER_EXECUTION_NAME => 'retryable',
            LoopCycleFailureTaxonomyService::TIER_QUALITY_NAME => 'needs_repair',
            LoopCycleFailureTaxonomyService::TIER_POLICY_NAME => 'needs_authority',
            LoopCycleFailureTaxonomyService::TIER_BUDGET_NAME => 'terminal',
        ], $shape['tier_hint_by_tier_name']);
        $this->assertSame('agentic_engineering_os', $shape['area_id']);
        $this->assertSame('dev_forge', $shape['focus']);
        $this->assertSame([
            'tier' => 0,
            'tier_name' => '',
            'specific_reason' => '',
            'recovery_action' => '',
            'classified' => false,
        ], $shape['inputs']);
        $this->assertNull($shape['outputs']['remediation_hint']);
        $this->assertFalse($shape['outputs']['hint_recognized']);
        $this->assertFalse($shape['outputs']['actionable_for_runner']);
    }

    public function test_from_array_maps_quality_failure_to_needs_repair(): void
    {
        $verdict = app(LoopCycleFailureTaxonomyService::class)->classify([
            'final_status' => 'blocked',
            'blockers' => ['judge_rejected'],
        ]);

        $shape = PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::fromArray($verdict)->toArray();

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_QUALITY, $shape['inputs']['tier']);
        $this->assertSame('quality_failure', $shape['inputs']['tier_name']);
        $this->assertSame('judge_rejected', $shape['inputs']['specific_reason']);
        $this->assertSame('needs_repair', $shape['outputs']['remediation_hint']);
        $this->assertTrue($shape['outputs']['hint_recognized']);
        $this->assertTrue($shape['outputs']['actionable_for_runner']);
    }

    public function test_from_array_maps_policy_failure_to_needs_authority(): void
    {
        $shape = PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::fromArray([
            'tier' => LoopCycleFailureTaxonomyService::TIER_POLICY,
            'tier_name' => LoopCycleFailureTaxonomyService::TIER_POLICY_NAME,
            'specific_reason' => 'merge_not_performed',
            'recovery_action' => LoopCycleFailureTaxonomyService::RECOVERY_MERGE_RETRY,
            'classified' => true,
        ])->toArray();

        $this->assertSame('needs_authority', $shape['outputs']['remediation_hint']);
        $this->assertTrue($shape['outputs']['actionable_for_runner']);
    }

    public function test_from_array_maps_budget_exhausted_to_terminal(): void
    {
        $shape = PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::fromArray([
            'tier' => LoopCycleFailureTaxonomyService::TIER_BUDGET,
            'tier_name' => LoopCycleFailureTaxonomyService::TIER_BUDGET_NAME,
            'specific_reason' => 'backlog_exhausted',
            'recovery_action' => LoopCycleFailureTaxonomyService::RECOVERY_STOP_HONEST,
            'classified' => true,
        ])->toArray();

        $this->assertSame('terminal', $shape['outputs']['remediation_hint']);
        $this->assertTrue($shape['outputs']['hint_recognized']);
        $this->assertFalse($shape['outputs']['actionable_for_runner']);
    }

    public function test_from_array_maps_setup_and_execution_failures_to_retryable(): void
    {
        foreach ([
            [
                'tier' => LoopCycleFailureTaxonomyService::TIER_SETUP,
                'tier_name' => LoopCycleFailureTaxonomyService::TIER_SETUP_NAME,
                'specific_reason' => 'git_worktree_add_failed',
            ],
            [
                'tier' => LoopCycleFailureTaxonomyService::TIER_EXECUTION,
                'tier_name' => LoopCycleFailureTaxonomyService::TIER_EXECUTION_NAME,
                'specific_reason' => 'owner_runtime_provider_timeout',
            ],
        ] as $input) {
            $shape = PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::fromArray($input)->toArray();

            $this->assertSame('retryable', $shape['outputs']['remediation_hint'], $input['tier_name']);
            $this->assertTrue($shape['outputs']['actionable_for_runner'], $input['tier_name']);
        }
    }

    public function test_from_array_rejects_unknown_tier_name(): void
    {
        $shape = PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::fromArray([
            'tier' => 99,
            'tier_name' => 'unknown_failure_class',
            'specific_reason' => 'some_unmapped_reason',
        ])->toArray();

        $this->assertNull($shape['outputs']['remediation_hint']);
        $this->assertFalse($shape['outputs']['hint_recognized']);
        $this->assertFalse($shape['outputs']['actionable_for_runner']);
    }
}
