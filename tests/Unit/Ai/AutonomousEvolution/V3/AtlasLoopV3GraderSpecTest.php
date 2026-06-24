<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderRegistry;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3GraderSpec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A conforming stub grader. */
final class GraderSpecStubValid
{
    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function validateClaim(array $claim, string $repoRoot): array
    {
        return ['material' => false, 'reason' => 'x'];
    }
}

/** Missing the required validateClaim method. */
final class GraderSpecStubMissingMethod
{
    public function somethingElse(): void {}
}

/** validateClaim throws — must be fail-closed. */
final class GraderSpecStubThrows
{
    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function validateClaim(array $claim, string $repoRoot): array
    {
        throw new RuntimeException('boom');
    }
}

/** Verdict is missing the 'reason' key. */
final class GraderSpecStubMissingVerdictKey
{
    /** @param array<string,mixed> $claim @return array<string,mixed> */
    public function validateClaim(array $claim, string $repoRoot): array
    {
        return ['material' => false];
    }
}

/**
 * Proves the V3 grader spec + registry: the spec validates the real in-tree ruler, names missing methods,
 * is fail-closed on a throwing grader, requires the verdict keys; the registry is a pure lookup over exactly
 * one installed grader.
 */
final class AtlasLoopV3GraderSpecTest extends TestCase
{
    public function test_validates_the_real_wiring_material_grader(): void
    {
        $result = AtlasLoopV3GraderSpec::validate(AtlasLoopWiringMaterialGrader::class);

        $this->assertTrue($result['ok'], 'the in-tree ruler must satisfy the contract; violations: '.implode(',', $result['violations']));
        $this->assertSame([], $result['violations']);
    }

    public function test_conforming_stub_passes(): void
    {
        $result = AtlasLoopV3GraderSpec::validate(GraderSpecStubValid::class);

        $this->assertTrue($result['ok']);
    }

    public function test_missing_validate_claim_is_rejected_with_method_named(): void
    {
        $result = AtlasLoopV3GraderSpec::validate(GraderSpecStubMissingMethod::class);

        $this->assertFalse($result['ok']);
        $this->assertContains('missing_or_non_public_method:validateClaim', $result['violations']);
    }

    public function test_throwing_validate_claim_is_fail_closed(): void
    {
        $result = AtlasLoopV3GraderSpec::validate(GraderSpecStubThrows::class);

        $this->assertFalse($result['ok'], 'a throwing grader is NEVER ok=true');
    }

    public function test_verdict_missing_required_key_fails(): void
    {
        $result = AtlasLoopV3GraderSpec::validate(GraderSpecStubMissingVerdictKey::class);

        $this->assertFalse($result['ok']);
        $this->assertContains('verdict_missing_key:reason', $result['violations']);
    }

    public function test_registry_is_a_pure_lookup_over_one_installed_grader(): void
    {
        $registry = new AtlasLoopV3GraderRegistry;

        $this->assertSame(['wiring_material'], $registry->claimClasses());
        $this->assertSame(AtlasLoopWiringMaterialGrader::class, $registry->fqcnFor('wiring_material'));
        $this->assertTrue($registry->isInstalled('wiring_material'));
        $this->assertFalse($registry->isInstalled('Wiring_Material'), 'case-sensitive');
        $this->assertNull($registry->fqcnFor('nope'));

        // pure lookup: returns a class-string FQCN, never an instance.
        $this->assertIsString($registry->fqcnFor('wiring_material'));
    }
}
