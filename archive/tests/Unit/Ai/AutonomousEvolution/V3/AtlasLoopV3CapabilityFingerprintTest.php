<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V3;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;
use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3CapabilityFingerprint;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CapabilityFingerprintParentStub
{
    public function inheritedSurface(): void {}
}

final class CapabilityFingerprintChildStub extends CapabilityFingerprintParentStub
{
    public function localSurface(): void {}
}

final class AtlasLoopV3CapabilityFingerprintTest extends TestCase
{
    public function test_fingerprint_of_real_grader_includes_declared_public_method(): void
    {
        $fingerprint = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['method_return'],
            ['app/Services/Ai/**/*.php'],
        );

        $this->assertSame(AtlasLoopV3CapabilityFingerprint::SCHEMA, $fingerprint['schema']);
        $this->assertSame(AtlasLoopWiringMaterialGrader::class, $fingerprint['fqcn']);
        $this->assertContains('validateClaim', $fingerprint['public_methods']);
    }

    public function test_fingerprint_is_deterministic(): void
    {
        $first = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['method_return', 'command_output'],
            ['tests/**/*.php', 'app/**/*.php'],
        );
        $second = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['method_return', 'command_output'],
            ['tests/**/*.php', 'app/**/*.php'],
        );

        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame($first, $second);
    }

    public function test_hash_is_order_independent_for_atom_kinds_and_path_patterns(): void
    {
        $first = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['http_response', 'method_return', 'http_response'],
            ['tests/**/*.php', 'app/**/*.php', 'tests/**/*.php'],
        );
        $second = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['method_return', 'http_response'],
            ['app/**/*.php', 'tests/**/*.php'],
        );

        $this->assertSame($first['hash'], $second['hash']);
        $this->assertSame(['http_response', 'method_return'], $first['atom_kinds']);
        $this->assertSame(['app/**/*.php', 'tests/**/*.php'], $first['path_patterns']);
    }

    public function test_unknown_fqcn_throws_documented_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown_class:Tests\\Unit\\Ai\\AutonomousEvolution\\V3\\NoSuchFingerprintPrimitive');

        AtlasLoopV3CapabilityFingerprint::of(
            'Tests\\Unit\\Ai\\AutonomousEvolution\\V3\\NoSuchFingerprintPrimitive',
            [],
            [],
        );
    }

    public function test_equivalent_requires_matching_non_null_hashes(): void
    {
        $first = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['method_return'],
            ['app/**/*.php'],
        );
        $second = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['method_return'],
            ['app/**/*.php'],
        );
        $different = AtlasLoopV3CapabilityFingerprint::of(
            AtlasLoopWiringMaterialGrader::class,
            ['http_response'],
            ['app/**/*.php'],
        );

        $this->assertTrue(AtlasLoopV3CapabilityFingerprint::equivalent($first, $second));
        $this->assertFalse(AtlasLoopV3CapabilityFingerprint::equivalent($first, $different));
        $this->assertFalse(AtlasLoopV3CapabilityFingerprint::equivalent([], $second));
        $this->assertFalse(AtlasLoopV3CapabilityFingerprint::equivalent($first, []));
        $this->assertFalse(AtlasLoopV3CapabilityFingerprint::equivalent(['hash' => null], $second));
    }

    public function test_inherited_methods_are_not_included(): void
    {
        $fingerprint = AtlasLoopV3CapabilityFingerprint::of(
            CapabilityFingerprintChildStub::class,
            [],
            [],
        );

        $this->assertSame(['localSurface'], $fingerprint['public_methods']);
        $this->assertNotContains('inheritedSurface', $fingerprint['public_methods']);
    }
}
