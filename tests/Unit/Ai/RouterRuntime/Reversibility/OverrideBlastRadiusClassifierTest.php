<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\RouterRuntime\Reversibility;

use App\Services\Ai\RouterRuntime\Reversibility\OverrideBlastRadiusClassifier;
use PHPUnit\Framework\TestCase;

final class OverrideBlastRadiusClassifierTest extends TestCase
{
    private OverrideBlastRadiusClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new OverrideBlastRadiusClassifier();
    }

    public function testLocalScopeWithNoModulesAndNoSecondaryIsLocalRankZero(): void
    {
        $result = $this->classifier->classify([
            'scope' => 'local',
            'affected_modules' => [],
            'primary_domain' => 'engineering',
            'secondary_domains' => [],
        ]);

        $this->assertSame('atlas.router.override_blast_radius.v1', $result['schema_version']);
        $this->assertSame('local', $result['blast_radius']);
        $this->assertSame(0, $result['blast_rank']);
        $this->assertFalse($result['fail_closed']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testTwoAffectedModulesIsModuleRankOne(): void
    {
        $result = $this->classifier->classify([
            'scope' => 'local',
            'affected_modules' => ['a', 'b'],
            'primary_domain' => 'engineering',
            'secondary_domains' => [],
        ]);

        $this->assertSame('module', $result['blast_radius']);
        $this->assertSame(1, $result['blast_rank']);
        $this->assertFalse($result['fail_closed']);
    }

    public function testThreeAffectedModulesIsSystemRankTwo(): void
    {
        $result = $this->classifier->classify([
            'scope' => 'local',
            'affected_modules' => ['a', 'b', 'c'],
            'primary_domain' => 'engineering',
            'secondary_domains' => [],
        ]);

        $this->assertSame('system', $result['blast_radius']);
        $this->assertSame(2, $result['blast_rank']);
    }

    public function testSecondaryDomainDifferingFromPrimaryIsSystemRankTwo(): void
    {
        $result = $this->classifier->classify([
            'scope' => 'local',
            'affected_modules' => [],
            'primary_domain' => 'y',
            'secondary_domains' => ['x'],
        ]);

        $this->assertSame('system', $result['blast_radius']);
        $this->assertSame(2, $result['blast_rank']);
    }

    public function testRadiusOrderingIsLocalLessThanModuleLessThanSystem(): void
    {
        $local = $this->classifier->rankOf('local');
        $module = $this->classifier->rankOf('module');
        $system = $this->classifier->rankOf('system');

        $this->assertSame(0, $local);
        $this->assertSame(1, $module);
        $this->assertSame(2, $system);
        $this->assertTrue($local < $module);
        $this->assertTrue($module < $system);
    }

    public function testUnrecognizedScopeFailsClosedToSystemRankTwo(): void
    {
        $result = $this->classifier->classify(['scope' => 'mystery']);

        $this->assertTrue($result['fail_closed']);
        $this->assertSame('system', $result['blast_radius']);
        $this->assertSame(2, $result['blast_rank']);
    }

    public function testRankOfUnknownRadiusFailsClosedToTwo(): void
    {
        $this->assertSame(2, $this->classifier->rankOf('bogus'));
    }
}
