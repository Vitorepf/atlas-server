<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use PHPUnit\Framework\TestCase;

final class QualityChecksTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_all_passing_factory_produces_all_true(): void
    {
        $qc = QualityChecks::allPassing();
        $this->assertTrue($qc->allPassed());
        $this->assertSame([], $qc->failedChecks());
    }

    public function test_canonical_array_is_sorted_and_complete(): void
    {
        $qc = QualityChecks::allPassing();
        $expected = [
            'no_conflicting_file_rules', 'no_forge_or_council_leakage', 'no_hidden_benchmark_instruction',
            'no_missing_required_sections', 'no_unbounded_scope', 'provider_safe',
        ];
        $this->assertSame($expected, array_keys($qc->toCanonicalArray()));
        $this->assertCanonicalArrayKeysSorted($qc);
    }

    public function test_failed_checks_lists_only_failing_fields(): void
    {
        $qc = new QualityChecks(
            noMissingRequiredSections: true,
            noUnboundedScope: false,
            noHiddenBenchmarkInstruction: true,
            noConflictingFileRules: false,
            noForgeOrCouncilLeakage: true,
            providerSafe: true,
        );

        $this->assertFalse($qc->allPassed());
        $this->assertSame(['no_unbounded_scope', 'no_conflicting_file_rules'], $qc->failedChecks());
    }

    public function test_round_trip_via_from_array(): void
    {
        $qc = QualityChecks::allPassing();
        $rebuilt = QualityChecks::fromArray(json_decode($qc->toJson(), true));
        $this->assertHashStable($qc, $rebuilt);
        $this->assertJsonRoundtripStable($qc);
    }

    public function test_provider_safe_mirrors_underlying_field(): void
    {
        $qc = new QualityChecks(true, true, true, true, true, false);
        $this->assertFalse($qc->isProviderSafe());
    }

    public function test_hash_differs_when_any_check_flips(): void
    {
        $a = QualityChecks::allPassing();
        $b = new QualityChecks(true, true, true, true, true, false);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
