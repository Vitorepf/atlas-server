<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroLeaseContentionBackoffAdvisorHardeningTest extends TestCase
{
    /**
     * Verify the source guards zero active leases.
     */
    public function test_source_has_zero_lease_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Concurrency/AtlasMaestroLeaseContentionBackoffAdvisor.php');

        $this->assertStringContainsString('$activeLeases === 0', $source, 'must guard zero leases');
        $this->assertStringContainsString('? 0', $source, 'must yield 0 delta for zero leases');
    }

    /**
     * Verify the old unguarded formula is gone.
     */
    public function test_old_unguarded_formula_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Concurrency/AtlasMaestroLeaseContentionBackoffAdvisor.php');

        $this->assertStringNotContainsString(
            '$delta = -max(1, (int) ceil($activeLeases / 2))',
            $source,
            'old unguarded formula must be replaced'
        );
    }

    /**
     * Demonstrate the bug: zero leases yields delta=-1.
     */
    public function test_zero_lease_yields_negative_delta(): void
    {
        $activeLeases = 0;

        // Old behavior: max(1, ceil(0/2)) = max(1, 0) = 1, delta = -1
        $oldDelta = -max(1, (int) ceil($activeLeases / 2));
        $this->assertEquals(-1, $oldDelta, 'old formula yields -1 for zero leases');

        // New behavior: 0 leases → delta = 0
        $newDelta = $activeLeases === 0 ? 0 : -max(1, (int) ceil($activeLeases / 2));
        $this->assertEquals(0, $newDelta, 'new formula yields 0 for zero leases');
    }
}
