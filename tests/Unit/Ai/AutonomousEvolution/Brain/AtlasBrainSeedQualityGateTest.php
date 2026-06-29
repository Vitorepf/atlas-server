<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use Tests\TestCase;

/**
 * The SEED boundary promotes the three advisory excellence flags (vague_objective, acceptance_not_runnable,
 * blind_orphan_wiring_proxy) to BLOCKING — without touching the universal inspector. Proves a vague/proxy
 * packet is refused and a clean concrete packet is admitted.
 */
final class AtlasBrainSeedQualityGateTest extends TestCase
{
    /** A packet a cold worker can implement + prove: concrete objective, real file, runnable acceptance, evidence. */
    private function cleanPacket(): array
    {
        return [
            'objective' => 'Extract the status-transition policy cluster from App\\Services\\Ai\\Foo into FooStatusPolicy.php',
            'allowed_files' => ['app/Services/Ai/Foo/FooStatusPolicy.php'],
            'scope_in' => ['app/Services/Ai/Foo'],
            'acceptance_criteria' => ['php artisan test --filter=FooStatusPolicy runs green'],
            'required_evidence' => ['tests_or_gates_result'],
            'problem' => 'Foo status transitions currently lack a credited worker-safe policy slice.',
            'expected_delta' => 'FooStatusPolicy adds a behavior change proved by the FooStatusPolicy test.',
            'value' => 'This adds a runtime test proof for Atlas autonomy and prevents proxy quota credit.',
            'duplicate_key' => 'foo-status|missing-policy|runtime-test-proof',
            'freshness_check' => 'Re-check that FooStatusPolicy.php still does not exist before editing.',
            'anti_proxy' => 'No class_exists-only, wrapper-only, formatting-only, or snapshot-empty solution counts.',
        ];
    }

    public function test_admits_a_clean_concrete_packet(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->cleanPacket());

        self::assertTrue($result['admit'], 'a concrete, runnable, grounded packet must be admitted');
        self::assertSame([], $result['blocking']);
    }

    public function test_blocks_a_vague_objective(): void
    {
        $packet = $this->cleanPacket();
        // Short + no concrete reference token => vague_objective (advisory in the inspector, fatal here).
        $packet['objective'] = 'make it better';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('vague_objective', $result['blocking']);
    }

    public function test_blocks_a_non_runnable_acceptance(): void
    {
        $packet = $this->cleanPacket();
        $packet['acceptance_criteria'] = ['the code looks cleaner and reads nicely'];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('acceptance_not_runnable', $result['blocking']);
    }

    public function test_blocks_a_blind_orphan_wiring_proxy(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'This class is a confirmed orphan with zero production callers; wire it into the live flow of App\\Services\\Ai\\Foo';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('blind_orphan_wiring_proxy', $result['blocking']);
    }

    public function test_blocks_a_dormant_cli_arm_wrapper_proxy(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'Arm the dormant service `App\\Services\\Ai\\SelfConstruction\\FooService` at the operator surface: add a new read-only `php artisan atlas:loop:arm-foo-service` command that resolves it and prints build() as JSON (schema_version + result keys), proven by ArmFooServiceCommandTest asserting exit 0 and the JSON schema.';
        $packet['allowed_files'] = [
            'app/Console/Commands/ArmFooServiceCommand.php',
            'tests/Feature/Loop/ArmFooServiceCommandTest.php',
        ];
        $packet['scope_in'] = [
            'app/Services/Ai/SelfConstruction/FooService.php',
            ...$packet['allowed_files'],
        ];
        $packet['acceptance_criteria'] = ['php artisan test --filter=ArmFooServiceCommandTest passes'];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('dormant_cli_arm_proxy', $result['blocking']);
        self::assertFalse($result['credit']['credited']);
    }

    public function test_propagates_universal_blocking_deficiencies(): void
    {
        // empty_allowed_files is a UNIVERSAL blocking deficiency — the gate must surface it too, not just the
        // three advisory promotions.
        $packet = $this->cleanPacket();
        $packet['allowed_files'] = [];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('empty_allowed_files', $result['blocking']);
    }

    public function test_blocks_a_packet_without_credit_fields(): void
    {
        $packet = $this->cleanPacket();
        unset($packet['expected_delta']);

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('missing_credit_expected_delta', $result['blocking']);
        self::assertFalse($result['credit']['credited']);
    }

    public function test_blocks_existing_allowed_files_without_explicit_delta(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'Add a runtime status test guard to App\\Models\\AtlasLoopConfidenceSample using SampleStatusPolicy behavior.';
        $packet['allowed_files'] = ['app/Models/AtlasLoopConfidenceSample.php'];
        $packet['scope_in'] = $packet['allowed_files'];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('allowed_files_already_exist_without_delta', $result['blocking']);
    }

    public function test_existing_allowed_files_can_pass_when_the_delta_is_explicit(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'Add a runtime status test guard to App\\Models\\AtlasLoopConfidenceSample using SampleStatusPolicy behavior.';
        $packet['allowed_files'] = ['app/Models/AtlasLoopConfidenceSample.php'];
        $packet['scope_in'] = $packet['allowed_files'];
        $packet['modifies_existing_files'] = true;
        $packet['existing_file_delta'] = 'Adds credited seed quota guards to the existing model.';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertTrue($result['admit']);
        self::assertTrue($result['credit']['credited']);
    }

    public function test_blocks_test_only_singleton_microtask_without_coverage_contract(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'Add a deterministic behavior characterization test for App\\Services\\Ai\\Foo\\FooGate that pins evaluate() output contract and one boundary case.';
        $packet['allowed_files'] = ['tests/Unit/Ai/AutonomousEvolution/Characterization/FooGateCharacterizationTest.php'];
        $packet['scope_in'] = ['app/Services/Ai/Foo/FooGate.php', $packet['allowed_files'][0]];
        $packet['acceptance_criteria'] = ['php artisan test --filter=FooGateCharacterizationTest passes'];
        $packet['problem'] = 'FooGate is an untested organ and may silently regress.';
        $packet['expected_delta'] = 'A characterization test pins deterministic output and one boundary case.';
        $packet['value'] = 'Adds test proof for one autonomous invariant.';
        $packet['duplicate_key'] = 'foo-gate|untested-organ|characterization-test';
        $packet['freshness_check'] = 'Verified no FooGateCharacterizationTest exists.';
        $packet['anti_proxy'] = 'Not a wrapper; adds a behavior test.';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertFalse($result['admit']);
        self::assertContains('test_only_microtask_requires_contract', $result['blocking']);
        self::assertFalse($result['credit']['credited']);
    }

    public function test_allows_test_only_packet_with_behavior_contract_matrix(): void
    {
        $packet = $this->cleanPacket();
        $packet['objective'] = 'Add a deterministic behavior test matrix for App\\Services\\Ai\\Foo\\FooGate covering approve, reject, and malformed input decisions.';
        $packet['allowed_files'] = ['tests/Unit/Ai/AutonomousEvolution/FooGateDecisionMatrixTest.php'];
        $packet['scope_in'] = ['app/Services/Ai/Foo/FooGate.php', $packet['allowed_files'][0]];
        $packet['acceptance_criteria'] = ['php artisan test --filter=FooGateDecisionMatrixTest passes'];
        $packet['problem'] = 'FooGate decision policy lacks a regression-proof behavior matrix.';
        $packet['expected_delta'] = 'A test matrix proves approve, reject, and malformed-input branches.';
        $packet['value'] = 'Adds test proof for a runtime gate invariant that protects Atlas autonomy.';
        $packet['duplicate_key'] = 'foo-gate|decision-policy|test-matrix';
        $packet['freshness_check'] = 'Verified no FooGateDecisionMatrixTest exists.';
        $packet['anti_proxy'] = 'Not singleton characterization; covers distinct behavioral branches.';
        $packet['test_only_contract'] = [
            'target_behavior' => 'FooGate decision policy for approved, rejected, and malformed inputs.',
            'risk_if_missing' => 'A regression could silently approve unsafe work or reject valid work.',
            'min_distinct_cases' => 3,
            'cases' => [
                'approved input returns an allow decision',
                'unsafe input returns a reject decision',
                'malformed input returns a stable error decision',
            ],
        ];

        $result = (new AtlasBrainSeedQualityGate)->evaluate($packet);

        self::assertTrue($result['admit']);
        self::assertTrue($result['credit']['credited']);
    }

    public function test_fatal_advisory_constant_is_the_three_promoted_flags(): void
    {
        self::assertSame(
            ['vague_objective', 'acceptance_not_runnable', 'blind_orphan_wiring_proxy'],
            AtlasBrainSeedQualityGate::BRAIN_FATAL_ADVISORY,
        );
    }
}
