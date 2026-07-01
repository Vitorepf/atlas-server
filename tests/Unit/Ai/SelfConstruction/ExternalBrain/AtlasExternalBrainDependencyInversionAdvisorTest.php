<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDependencyInversionAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDependencyInversionAdvisorTest extends TestCase
{
    private function advisor(): AtlasExternalBrainDependencyInversionAdvisor
    {
        return new AtlasExternalBrainDependencyInversionAdvisor;
    }

    public function test_safe_inversion_case(): void
    {
        $r = $this->advisor()->advise([
            'stable_abstraction_exists' => true,
            'consumer_proof_available' => true,
            'contract_tests_exist' => true,
        ]);

        $this->assertSame('invert_dependency', $r['recommendation']);
        $this->assertSame([], $r['required_prework']);
    }

    public function test_missing_contract_hold_case(): void
    {
        $r = $this->advisor()->advise([
            'stable_abstraction_exists' => true,
            'consumer_proof_available' => true,
            'contract_tests_exist' => false,
        ]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertSame(['add_contract_tests'], $r['required_prework']);
    }

    public function test_missing_abstraction_hold_case(): void
    {
        $r = $this->advisor()->advise([
            'consumer_proof_available' => true,
            'contract_tests_exist' => true,
        ]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertContains('define_stable_abstraction', $r['required_prework']);
    }

    public function test_all_missing_lists_all_prework(): void
    {
        $r = $this->advisor()->advise([]);

        $this->assertSame('hold', $r['recommendation']);
        $this->assertCount(3, $r['required_prework']);
    }

    public function test_schema_present(): void
    {
        $r = $this->advisor()->advise([]);

        $this->assertSame(AtlasExternalBrainDependencyInversionAdvisor::SCHEMA, $r['schema']);
    }
}
