<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanVisibleTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makePlan(array $overrides = []): PlanVisible
    {
        $payload = array_replace([
            'run_id' => 'run-abc-123',
            'task_contract_hash' => 'sha256:deadbeef',
            'target_files' => ['app/Services/Foo/BarService.php'],
            'tests_to_run' => ['tests/Unit/FooTest.php'],
            'risk_band' => PlanVisible::RISK_BAND_MEDIUM,
            'proposed_diff_summary' => 'Adds method handle() returning array; updates one caller.',
            'approval_status' => PlanVisible::APPROVAL_STATUS_PENDING,
        ], $overrides);

        return PlanVisible::issue(
            runId: $payload['run_id'],
            taskContractHash: $payload['task_contract_hash'],
            targetFiles: $payload['target_files'],
            testsToRun: $payload['tests_to_run'],
            riskBand: $payload['risk_band'],
            proposedDiffSummary: $payload['proposed_diff_summary'],
            approvalStatus: $payload['approval_status'],
        );
    }

    public function test_implements_atlas_dev_schema_contract(): void
    {
        $plan = $this->makePlan();

        $this->assertInstanceOf(AtlasDevSchemaContract::class, $plan);
        $this->assertSame('atlas.dev.plan_visible.v1', $plan->schemaVersion());
        $this->assertSame('atlas.dev.plan_visible.v1', PlanVisible::SCHEMA_VERSION);
        $this->assertTrue($plan->isProviderSafe());
        $this->assertTrue($plan->isPending());
        $this->assertFalse($plan->isApproved());
        $this->assertFalse($plan->isRejected());
    }

    public function test_canonical_array_keys_are_sorted_alphabetically(): void
    {
        $plan = $this->makePlan();
        $keys = array_keys($plan->toCanonicalArray());
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        $this->assertSame($sorted, $keys, 'top-level keys must be sorted for deterministic hashing');
    }

    public function test_hash_is_deterministic_across_calls(): void
    {
        $plan = $this->makePlan();

        $this->assertSame($plan->hash(), $plan->hash());
        $this->assertSame($plan->planHash, $plan->hash());
    }

    public function test_hash_changes_when_relevant_field_changes(): void
    {
        $base = $this->makePlan();
        $diff = $this->makePlan(['risk_band' => PlanVisible::RISK_BAND_HIGH]);

        $this->assertNotSame($base->hash(), $diff->hash());
    }

    public function test_round_trip_through_canonical_array_preserves_state(): void
    {
        $plan = $this->makePlan();
        $rebuilt = PlanVisible::fromArray($plan->toCanonicalArray());

        $this->assertSame($plan->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($plan->hash(), $rebuilt->hash());
        $this->assertSame($plan->toJson(), $rebuilt->toJson());
    }

    public function test_from_array_recomputes_hash_when_plan_hash_missing(): void
    {
        $reference = $this->makePlan();
        $payload = $reference->toCanonicalArray();
        unset($payload['plan_hash']);

        $rebuilt = PlanVisible::fromArray($payload);

        $this->assertSame($reference->hash(), $rebuilt->hash());
    }

    public function test_approve_returns_new_instance_with_approved_status_and_fresh_hash(): void
    {
        $pending = $this->makePlan();
        $approved = $pending->approve();

        $this->assertNotSame($pending, $approved);
        $this->assertFalse($pending->isApproved());
        $this->assertTrue($approved->isApproved());
        $this->assertNotSame($pending->hash(), $approved->hash(), 'approval changes canonical hash');
    }

    public function test_reject_returns_new_instance_with_rejected_status(): void
    {
        $pending = $this->makePlan();
        $rejected = $pending->reject();

        $this->assertTrue($rejected->isRejected());
        $this->assertFalse($pending->isRejected());
        $this->assertNotSame($pending->hash(), $rejected->hash());
    }

    public function test_rejects_empty_run_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('run_id must not be empty');

        $this->makePlan(['run_id' => '']);
    }

    public function test_rejects_empty_task_contract_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('task_contract_hash must not be empty');

        $this->makePlan(['task_contract_hash' => '']);
    }

    public function test_rejects_invalid_risk_band(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('risk_band must be one of [low,medium,high]');

        $this->makePlan(['risk_band' => 'catastrophic']);
    }

    public function test_rejects_invalid_approval_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('approval_status must be one of [pending,approved,rejected]');

        $this->makePlan(['approval_status' => 'maybe']);
    }

    public function test_rejects_empty_target_files(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('target_files must contain at least one file');

        $this->makePlan(['target_files' => []]);
    }

    public function test_rejects_non_string_target_file(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanVisible::issue(
            runId: 'r',
            taskContractHash: 'h',
            targetFiles: ['app/Foo.php', 42],
            testsToRun: ['t.php'],
            riskBand: PlanVisible::RISK_BAND_LOW,
            proposedDiffSummary: 'x',
        );
    }

    public function test_rejects_empty_diff_summary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('proposed_diff_summary must not be empty');

        $this->makePlan(['proposed_diff_summary' => '   ']);
    }

    public function test_rejects_oversized_diff_summary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds 4000 chars');

        $this->makePlan(['proposed_diff_summary' => str_repeat('x', 4001)]);
    }

    public function test_requires_tests_when_risk_band_medium_or_high(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tests_to_run must contain at least one test when risk_band is medium or high');

        $this->makePlan([
            'risk_band' => PlanVisible::RISK_BAND_HIGH,
            'tests_to_run' => [],
        ]);
    }

    public function test_allows_empty_tests_when_risk_band_low(): void
    {
        $plan = $this->makePlan([
            'risk_band' => PlanVisible::RISK_BAND_LOW,
            'tests_to_run' => [],
        ]);

        $this->assertSame([], $plan->testsToRun);
        $this->assertSame(PlanVisible::RISK_BAND_LOW, $plan->riskBand);
    }

    public function test_canonical_array_includes_all_canonical_fields(): void
    {
        $plan = $this->makePlan();
        $canonical = $plan->toCanonicalArray();

        $this->assertSame([
            'approval_status',
            'plan_hash',
            'proposed_diff_summary',
            'risk_band',
            'run_id',
            'schema_version',
            'target_files',
            'task_contract_hash',
            'tests_to_run',
        ], array_keys($canonical));
        $this->assertSame('atlas.dev.plan_visible.v1', $canonical['schema_version']);
    }

    public function test_provider_safe_array_equals_canonical_array_by_default(): void
    {
        $plan = $this->makePlan();

        $this->assertSame($plan->toCanonicalArray(), $plan->toProviderSafeArray());
    }

    public function test_json_round_trip(): void
    {
        $plan = $this->makePlan();
        $json = $plan->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $rebuilt = PlanVisible::fromArray($decoded);

        $this->assertSame($plan->hash(), $rebuilt->hash());
    }
}
