<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\InertNewClassDeliveryGate;
use PHPUnit\Framework\TestCase;

final class InertNewClassDeliveryGateTest extends TestCase
{
    private InertNewClassDeliveryGate $gate;

    protected function setUp(): void
    {
        $this->gate = new InertNewClassDeliveryGate();
    }

    public function test_inert_only_new_class_is_blocked(): void
    {
        // The defect this gate exists for: a new class, no runtime consumer,
        // not declared pending => progress theater => BLOCK.
        $result = $this->gate->evaluate(['OrphanDecider'], [], []);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_BLOCKED_INERT, $result['cycle_usefulness_status']);
        $this->assertFalse($result['useful_runtime_wiring']);
        $this->assertSame(InertNewClassDeliveryGate::BLOCKER, $result['blocker']);
        $this->assertSame(['OrphanDecider'], $result['inert_new_classes']);
        $this->assertSame([], $result['runtime_consumed_classes']);
        $this->assertStringContainsString('OrphanDecider', $result['reason']);
    }

    public function test_consumed_new_class_is_runtime_integrated(): void
    {
        $result = $this->gate->evaluate(['WiredDecider'], ['WiredDecider'], []);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_RUNTIME_INTEGRATED, $result['cycle_usefulness_status']);
        $this->assertTrue($result['useful_runtime_wiring']);
        $this->assertNull($result['blocker']);
        $this->assertSame([], $result['inert_new_classes']);
        $this->assertSame(['WiredDecider'], $result['runtime_consumed_classes']);
    }

    public function test_explicitly_pending_class_is_library_pending_not_autonomy(): void
    {
        // Honestly declared pending-wiring library work: allowed, but NOT useful
        // autonomy. useful_runtime_wiring MUST be false so it never counts.
        $result = $this->gate->evaluate(['FuturePort'], [], ['FuturePort']);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_LIBRARY_PENDING, $result['cycle_usefulness_status']);
        $this->assertFalse($result['useful_runtime_wiring']);
        $this->assertNull($result['blocker']);
        $this->assertSame(['FuturePort'], $result['inert_new_classes']);
    }

    public function test_no_new_class_cycle_is_runtime_integrated(): void
    {
        // A pure edit/bugfix to existing runtime code introduced no new class.
        $result = $this->gate->evaluate([], [], []);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_RUNTIME_INTEGRATED, $result['cycle_usefulness_status']);
        $this->assertTrue($result['useful_runtime_wiring']);
        $this->assertNull($result['blocker']);
        $this->assertSame('no_new_product_class_introduced', $result['reason']);
    }

    public function test_mixed_consumed_and_inert_is_integrated_and_still_reports_inert(): void
    {
        $result = $this->gate->evaluate(['WiredA', 'InertB'], ['WiredA'], []);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_RUNTIME_INTEGRATED, $result['cycle_usefulness_status']);
        $this->assertTrue($result['useful_runtime_wiring']);
        $this->assertSame(['WiredA'], $result['runtime_consumed_classes']);
        $this->assertSame(['InertB'], $result['inert_new_classes']);
    }

    public function test_partially_pending_inert_still_blocks_unpending_class(): void
    {
        // Two inert new classes, only one declared pending => the other is theater => BLOCK.
        $result = $this->gate->evaluate(['DeclaredPort', 'SneakyOrphan'], [], ['DeclaredPort']);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_BLOCKED_INERT, $result['cycle_usefulness_status']);
        $this->assertFalse($result['useful_runtime_wiring']);
        $this->assertSame(InertNewClassDeliveryGate::BLOCKER, $result['blocker']);
        $this->assertEqualsCanonicalizing(['DeclaredPort', 'SneakyOrphan'], $result['inert_new_classes']);
        $this->assertStringContainsString('SneakyOrphan', $result['reason']);
    }

    public function test_consumed_list_is_intersected_with_changed_to_reject_false_claims(): void
    {
        // A consumed entry that is NOT among the changed classes must not rescue the cycle.
        $result = $this->gate->evaluate(['RealNewClass'], ['SomethingElseEntirely'], []);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_BLOCKED_INERT, $result['cycle_usefulness_status']);
        $this->assertSame([], $result['runtime_consumed_classes']);
        $this->assertSame(['RealNewClass'], $result['inert_new_classes']);
    }

    public function test_pending_list_is_intersected_with_changed(): void
    {
        $result = $this->gate->evaluate(['RealNewClass'], [], ['UnrelatedPending']);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_BLOCKED_INERT, $result['cycle_usefulness_status']);
        $this->assertSame([], $result['library_pending_classes']);
    }

    public function test_input_is_normalized_trimmed_and_deduplicated(): void
    {
        $result = $this->gate->evaluate(['  Dup ', 'Dup', '', 'Dup'], ['Dup', '  Dup  '], []);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_RUNTIME_INTEGRATED, $result['cycle_usefulness_status']);
        $this->assertSame(['Dup'], $result['changed_classes']);
        $this->assertSame(['Dup'], $result['runtime_consumed_classes']);
        $this->assertTrue($result['useful_runtime_wiring']);
    }

    public function test_result_contains_all_required_keys_and_stable_schema(): void
    {
        $result = $this->gate->evaluate(['X'], [], []);

        foreach (['schema_version', 'useful_runtime_wiring', 'changed_classes', 'runtime_consumed_classes', 'inert_new_classes', 'library_pending_classes', 'cycle_usefulness_status', 'blocker', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame('atlas.software_company_stewardship.inert_new_class_delivery_gate.v1', $result['schema_version']);
    }

    public function test_library_pending_never_reports_useful_runtime_wiring(): void
    {
        $result = $this->gate->evaluate(['P1', 'P2'], [], ['P1', 'P2']);

        $this->assertSame(InertNewClassDeliveryGate::STATUS_LIBRARY_PENDING, $result['cycle_usefulness_status']);
        $this->assertFalse($result['useful_runtime_wiring']);
    }
}
