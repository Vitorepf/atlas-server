<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUsefulPoisonRespecContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainUsefulPoisonRespecContractTest extends TestCase
{
    public function test_missing_implementation_file_is_repaired_with_scope_closure_reason(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'allowed_files' => ['tests/Unit/FooTest.php'],
            'required_implementation_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
        ]);

        self::assertTrue($result['replacement_allowed']);
        self::assertSame(['app/Foo.php'], $result['added_required_files']);
        self::assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_SCOPE_CLOSURE_REPAIRED, $result['reason']);
    }

    public function test_contradictory_acceptance_criteria_refuses_replacement(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'acceptance_contradictory' => true,
        ]);

        self::assertFalse($result['replacement_allowed']);
        self::assertSame(
            AtlasExternalBrainUsefulPoisonRespecContract::REASON_ACCEPTANCE_CONTRADICTION_UNRESOLVED,
            $result['reason'],
        );
    }

    public function test_forbidden_target_with_no_safe_path_requires_operator_action(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'forbidden_target' => true,
            'has_safe_wrapper_or_operator_path' => false,
        ]);

        self::assertFalse($result['replacement_allowed']);
        self::assertTrue($result['operator_action_required']);
        self::assertSame(
            AtlasExternalBrainUsefulPoisonRespecContract::REASON_FORBIDDEN_TARGET_NO_SAFE_PATH,
            $result['reason'],
        );
    }

    public function test_forbidden_target_with_safe_path_does_not_require_operator_action(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'forbidden_target' => true,
            'has_safe_wrapper_or_operator_path' => true,
        ]);

        self::assertTrue($result['replacement_allowed']);
        self::assertFalse($result['operator_action_required']);
    }

    public function test_no_real_value_refuses_without_operator_action(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => false,
        ]);

        self::assertFalse($result['replacement_allowed']);
        self::assertFalse($result['operator_action_required']);
        self::assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_NO_REAL_VALUE, $result['reason']);
    }

    // ── AC: replacement_plan / operator_action_required / refusal_reason ────────

    public function test_contradictory_acceptance_sets_refusal_reason_and_null_replacement_plan(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'acceptance_contradictory' => true,
        ]);

        self::assertFalse($result['replacement_allowed']);
        self::assertSame(
            AtlasExternalBrainUsefulPoisonRespecContract::REASON_ACCEPTANCE_CONTRADICTION_UNRESOLVED,
            $result['refusal_reason'],
        );
        self::assertNull($result['replacement_plan']);
    }

    public function test_forbidden_target_no_safe_path_sets_refusal_reason(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'forbidden_target' => true,
        ]);

        self::assertSame(
            AtlasExternalBrainUsefulPoisonRespecContract::REASON_FORBIDDEN_TARGET_NO_SAFE_PATH,
            $result['refusal_reason'],
        );
    }

    public function test_repaired_packet_returns_corrected_allowed_files_summary_and_plan(): void
    {
        $result = (new AtlasExternalBrainUsefulPoisonRespecContract)->evaluate([
            'real_value' => true,
            'allowed_files' => ['app/Services/Ai/Foo.php'],
            'required_implementation_files' => ['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'],
        ]);

        self::assertTrue($result['replacement_allowed']);
        self::assertNull($result['refusal_reason']);
        self::assertContains('tests/Unit/Ai/FooTest.php', $result['corrected_allowed_files']);
        self::assertNotEmpty($result['corrected_acceptance_summary']);
        self::assertSame('repair_scope_closure', $result['replacement_plan']['action']);
    }
}
