<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use Tests\TestCase;

final class AtlasBrainSeedQualityGateTest extends TestCase
{
    /**
     * Minimal packet that passes all inspector AND credit checks.
     *
     * Inspector checks:
     *   - objective > 40 chars and contains 'Atlas' (concrete token) → NOT vague
     *   - acceptance has 'artisan' → has runnable signal
     *   - objective has no orphan-wiring or dormant-CLI proxy patterns
     *   - required_evidence non-empty; allowed_files non-empty; acceptance non-empty
     *
     * Credit checks:
     *   - all 6 CREDIT_REQUIRED_FIELDS ≥ 8 chars
     *   - value/expected_delta contain 'runtime' → NOT padding
     *   - acceptance != 'tests pass' → NOT weak
     *   - allowed_files not all in tests/ → NOT test-only
     *   - allowed file does not exist on disk → no existing-file-without-delta block
     */
    private function goodPacket(array $overrides = []): array
    {
        return array_merge([
            'objective'       => 'Add AtlasRuntimeInvariantValidator to guard brain-cycle invariants at runtime entry',
            'problem'         => 'Brain cycle lacks invariant validation causing silent failures in production systems',
            'expected_delta'  => 'AtlasRuntimeInvariantValidator class added with 5 green test cases and runtime proof captured',
            'value'           => 'Prevents silent runtime failures; proof of test coverage eliminates manual review overhead',
            'duplicate_key'   => 'atlas-runtime-invariant-validator-brain-cycle-check-v1',
            'freshness_check' => 'Verified 2026-06-30 via atlas:brain:next dry-run probe, no stale flag returned by scan',
            'anti_proxy'      => 'Implements real invariant check with failing test evidence, not just scaffold or stub wire',
            'allowed_files'   => ['app/Services/Ai/NonExistentAtlasRuntimeInvariantValidator.php'],
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test --filter=AtlasRuntimeInvariantValidator exits 0 with 5 assertions',
            ],
            'required_evidence'       => ['tests_or_gates_result'],
            'modifies_existing_files' => false,
        ], $overrides);
    }

    // ── Happy path

    public function test_clean_packet_is_admitted(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket());

        $this->assertTrue($result['admit']);
        $this->assertSame([], $result['blocking']);
    }

    // ── AC2: inspector advisory deficiencies become blocking at seed boundary

    public function test_ac2_vague_objective_advisory_becomes_blocking(): void
    {
        // < 40 chars AND no concrete reference token → inspector emits vague_objective advisory
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'objective' => 'Improve code',
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('vague_objective', $result['blocking']);
    }

    public function test_ac2_acceptance_not_runnable_advisory_becomes_blocking(): void
    {
        // No runnable token (test/artisan/php /runs /executes ) → inspector emits acceptance_not_runnable
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'acceptance_criteria' => ['The implementation should be complete and fully integrated'],
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('acceptance_not_runnable', $result['blocking']);
    }

    public function test_ac2_blind_orphan_wiring_proxy_advisory_becomes_blocking(): void
    {
        // orphan justification + wire action + no disposition → blind_orphan_wiring_proxy
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'objective' => 'Wire the built-but-unused AtlasLegacyRouter into the live flow to improve routing in production',
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('blind_orphan_wiring_proxy', $result['blocking']);
    }

    public function test_ac2_scope_incoherent_advisory_does_not_appear_in_blocking(): void
    {
        // scope_in doesn't cover allowed_files → scope_incoherent advisory (NOT in BRAIN_FATAL_ADVISORY)
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'scope_in' => ['some/unrelated/path.php'],
        ]));

        $this->assertNotContains('scope_incoherent', $result['blocking']);
    }

    // ── AC3: missing credit fields → missing_credit_*

    public function test_ac3_missing_problem_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket(['problem' => '']));

        $this->assertFalse($result['admit']);
        $this->assertContains('missing_credit_problem', $result['blocking']);
    }

    public function test_ac3_missing_expected_delta_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket(['expected_delta' => '']));

        $this->assertFalse($result['admit']);
        $this->assertContains('missing_credit_expected_delta', $result['blocking']);
    }

    public function test_ac3_missing_value_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket(['value' => '']));

        $this->assertFalse($result['admit']);
        $this->assertContains('missing_credit_value', $result['blocking']);
    }

    public function test_ac3_missing_duplicate_key_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket(['duplicate_key' => '']));

        $this->assertFalse($result['admit']);
        $this->assertContains('missing_credit_duplicate_key', $result['blocking']);
    }

    public function test_ac3_missing_freshness_check_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket(['freshness_check' => '']));

        $this->assertFalse($result['admit']);
        $this->assertContains('missing_credit_freshness_check', $result['blocking']);
    }

    public function test_ac3_missing_anti_proxy_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket(['anti_proxy' => '']));

        $this->assertFalse($result['admit']);
        $this->assertContains('missing_credit_anti_proxy', $result['blocking']);
    }

    // ── AC4: deterministic structural blocks

    public function test_ac4_all_existing_allowed_files_without_delta_blocks(): void
    {
        // routes/api.php exists on disk and is not property-gated; no delta → credit blocks
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'allowed_files'           => ['routes/api.php'],
            'modifies_existing_files' => false,
            'existing_file_delta'     => '',
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('allowed_files_already_exist_without_delta', $result['blocking']);
    }

    public function test_ac4_existing_files_with_modifies_flag_does_not_trigger_that_block(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'allowed_files'           => ['routes/api.php'],
            'modifies_existing_files' => true,
            'existing_file_delta'     => 'Add atlas.routes include to wire new AtlasDomain endpoints in the router configuration',
        ]));

        $this->assertNotContains('allowed_files_already_exist_without_delta', $result['blocking']);
    }

    public function test_ac4_weak_acceptance_blocks(): void
    {
        // 'tests pass' matches the weak-acceptance regex in the gate's credit() method
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'acceptance_criteria' => ['tests pass'],
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('weak_acceptance_no_behavior_assertion', $result['blocking']);
    }

    public function test_ac4_dormant_cli_arm_proxy_objective_blocks(): void
    {
        $dormant = 'arm the dormant built-but-dormant-at-cli read-only command via php artisan atlas:loop:arm-check that prints schema_version asserting exit 0';

        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'objective' => $dormant,
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('dormant_cli_arm_proxy', $result['blocking']);
    }

    public function test_ac4_test_only_microtask_without_contract_blocks(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'allowed_files' => ['tests/Feature/Ai/SomeMissingFeatureTest.php'],
        ]));

        $this->assertFalse($result['admit']);
        $this->assertContains('test_only_microtask_requires_contract', $result['blocking']);
    }

    public function test_ac4_test_only_with_strong_contract_not_blocked_for_it(): void
    {
        $result = (new AtlasBrainSeedQualityGate)->evaluate($this->goodPacket([
            'allowed_files' => ['tests/Feature/Ai/SomeMissingFeatureTest.php'],
            'test_only_contract' => [
                'target_behavior'    => 'RuntimeInvariantValidator enforces three invariants at runtime boundary',
                'risk_if_missing'    => 'Silent failures in production go undetected without this contract guard',
                'min_distinct_cases' => 3,
                'cases' => [
                    'missing invariant check produces blocked status with reasons in result',
                    'valid invariant input returns admitted status with no blocking outcome',
                    'stale evidence triggers freshness_check blocking reason in credit audit',
                ],
            ],
        ]));

        $this->assertNotContains('test_only_microtask_requires_contract', $result['blocking']);
    }

    public function test_ac4_same_input_is_deterministic(): void
    {
        $packet = $this->goodPacket();
        $gate   = new AtlasBrainSeedQualityGate;

        $this->assertSame($gate->evaluate($packet), $gate->evaluate($packet));
    }
}
