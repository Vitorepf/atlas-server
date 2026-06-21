<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHermeticCommandEnvironment;
use PHPUnit\Framework\TestCase;

final class AtlasLoopHermeticCommandEnvironmentTest extends TestCase
{
    public function test_for_acceptance_preserves_false_overrides_and_stringifies_other_scalar_overrides(): void
    {
        $env = AtlasLoopHermeticCommandEnvironment::forAcceptance([
            'DB_HOST' => false,
            'ATLAS_LOOP_SAMPLE_INT' => 42,
            'ATLAS_LOOP_SAMPLE_BOOL' => true,
        ]);

        $this->assertArrayHasKey('DB_HOST', $env);
        $this->assertFalse($env['DB_HOST'], 'false overrides stay false so subprocess env unsets the variable instead of turning it into a string');
        $this->assertSame('42', $env['ATLAS_LOOP_SAMPLE_INT']);
        $this->assertSame('1', $env['ATLAS_LOOP_SAMPLE_BOOL']);
    }

    public function test_for_acceptance_ignores_invalid_extra_entries(): void
    {
        $env = AtlasLoopHermeticCommandEnvironment::forAcceptance([
            '' => 'ignored-empty-key',
            'ATLAS_LOOP_ARRAY' => ['ignored'],
            'ATLAS_LOOP_OBJECT' => (object) ['ignored' => true],
            'ATLAS_LOOP_OK' => 'kept',
        ]);

        $this->assertArrayNotHasKey('', $env);
        $this->assertArrayNotHasKey('ATLAS_LOOP_ARRAY', $env);
        $this->assertArrayNotHasKey('ATLAS_LOOP_OBJECT', $env);
        $this->assertSame('kept', $env['ATLAS_LOOP_OK']);
    }
}
