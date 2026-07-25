<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorProfileRegistrySupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OperatorProfileRegistrySupportTest extends TestCase
{
    #[Test]
    public function profile_key_and_automation_level(): void
    {
        $this->assertSame('explicit.key', OperatorProfileRegistrySupport::profileKey('OP-001', 'explicit.key'));
        $this->assertSame('op_001.operator_profile', OperatorProfileRegistrySupport::profileKey('OP-001'));

        $levels = ['observe', 'suggest', 'auto_apply_reversible'];
        $this->assertSame('suggest', OperatorProfileRegistrySupport::automationLevel(
            'suggest',
            true,
            true,
            'observe',
            $levels,
        ));
        $this->assertSame('auto_apply_reversible', OperatorProfileRegistrySupport::automationLevel(
            null,
            true,
            true,
            'observe',
            $levels,
        ));
        $this->assertSame('observe', OperatorProfileRegistrySupport::automationLevel(
            null,
            true,
            false,
            'observe',
            $levels,
        ));
    }

    #[Test]
    public function validity_from_hint_maps_durations(): void
    {
        $this->assertSame(['temporary', 1], OperatorProfileRegistrySupport::validityFromHint('momentary'));
        $this->assertSame(['session', 8], OperatorProfileRegistrySupport::validityFromHint('scoped'));
        $this->assertSame(['permanent', null], OperatorProfileRegistrySupport::validityFromHint('durable'));
    }
}
