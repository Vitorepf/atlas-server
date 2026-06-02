<?php

declare(strict_types=1);

namespace Tests\Fixtures\Aaeos;

use PHPUnit\Framework\TestCase;

/**
 * CONTROLLED FIXTURE for the B3 green-run receipt proof — NOT part of the default
 * suite. It lives under tests/Fixtures (outside the Unit/Feature testsuite dirs in
 * phpunit.xml), so `php artisan test` / the default PHPUnit run NEVER collects it and
 * its deliberately-failing method never reddens the suite.
 *
 * AtlasAaeosVerifiedRequiresGreenRunTest drives AtlasAaeosTestExecutionService at this
 * file BY PATH (+ --filter) to prove, with a REAL PHPUnit subprocess, that:
 *   - a genuinely PASSING test produces passed=true  (green receipt) and
 *   - a genuinely FAILING test produces passed=false (no green receipt).
 * That is the end-to-end honesty of the receipt path — a present test that FAILS is
 * never recorded green.
 */
final class AtlasAaeosGreenRunProofFixtureTest extends TestCase
{
    public function test_atlas_green_proof_passes(): void
    {
        $this->assertTrue(true);
    }

    public function test_atlas_green_proof_fails(): void
    {
        $this->assertTrue(false, 'intentional failure — proves a present-but-failing test is not recorded green');
    }
}
