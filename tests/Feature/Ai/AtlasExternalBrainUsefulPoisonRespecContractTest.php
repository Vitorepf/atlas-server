<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainUsefulPoisonRespecContract;
use Tests\TestCase;

final class AtlasExternalBrainUsefulPoisonRespecContractTest extends TestCase
{
    private function contract(): AtlasExternalBrainUsefulPoisonRespecContract
    {
        return new AtlasExternalBrainUsefulPoisonRespecContract;
    }

    public function test_packet_without_real_value_is_refused_and_never_requeued(): void
    {
        $result = $this->contract()->evaluate([
            'real_value' => false,
            'forbidden_target' => false,
            'acceptance_contradictory' => false,
        ]);

        $this->assertFalse($result['replacement_allowed']);
        $this->assertFalse($result['operator_action_required']);
        $this->assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_NO_REAL_VALUE, $result['reason']);
        $this->assertSame([], $result['added_required_files']);
    }

    public function test_forbidden_target_without_safe_path_is_refused_with_operator_action_required(): void
    {
        $result = $this->contract()->evaluate([
            'real_value' => true,
            'forbidden_target' => true,
            'has_safe_wrapper_or_operator_path' => false,
        ]);

        $this->assertFalse($result['replacement_allowed']);
        $this->assertTrue($result['operator_action_required']);
        $this->assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_FORBIDDEN_TARGET_NO_SAFE_PATH, $result['reason']);
    }

    public function test_forbidden_target_with_safe_path_is_not_refused_on_that_ground(): void
    {
        $result = $this->contract()->evaluate([
            'real_value' => true,
            'forbidden_target' => true,
            'has_safe_wrapper_or_operator_path' => true,
        ]);

        $this->assertTrue($result['replacement_allowed']);
    }

    public function test_contradictory_acceptance_is_refused_without_operator_action(): void
    {
        $result = $this->contract()->evaluate([
            'real_value' => true,
            'forbidden_target' => false,
            'acceptance_contradictory' => true,
        ]);

        $this->assertFalse($result['replacement_allowed']);
        $this->assertFalse($result['operator_action_required']);
        $this->assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_ACCEPTANCE_CONTRADICTION_UNRESOLVED, $result['reason']);
    }

    public function test_missing_required_implementation_files_are_returned_as_added_required_files_with_replacement_allowed(): void
    {
        $result = $this->contract()->evaluate([
            'real_value' => true,
            'forbidden_target' => false,
            'acceptance_contradictory' => false,
            'allowed_files' => ['app/Foo/Bar.php'],
            'required_implementation_files' => ['app/Foo/Bar.php', 'app/Foo/BarTest.php'],
        ]);

        $this->assertTrue($result['replacement_allowed']);
        $this->assertFalse($result['operator_action_required']);
        $this->assertSame(['app/Foo/BarTest.php'], $result['added_required_files']);
        $this->assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_SCOPE_CLOSURE_REPAIRED, $result['reason']);
    }

    public function test_complete_scope_closure_allows_replacement_with_no_added_files(): void
    {
        $result = $this->contract()->evaluate([
            'real_value' => true,
            'forbidden_target' => false,
            'acceptance_contradictory' => false,
            'allowed_files' => ['app/Foo/Bar.php', 'app/Foo/BarTest.php'],
            'required_implementation_files' => ['app/Foo/Bar.php', 'app/Foo/BarTest.php'],
        ]);

        $this->assertTrue($result['replacement_allowed']);
        $this->assertSame([], $result['added_required_files']);
        $this->assertSame(AtlasExternalBrainUsefulPoisonRespecContract::REASON_NO_REPAIR_NEEDED, $result['reason']);
    }
}
