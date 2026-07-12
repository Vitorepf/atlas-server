<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryConstitutionReadinessManifest;
use PHPUnit\Framework\TestCase;

final class QualityFoundryConstitutionReadinessManifestTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'live_evidence_refs' => ['test://parity', 'test://mutation'],
            'parity_proven' => true, 'mutation_suite_executed' => true, 'mutation_survivors' => [], 'claim_authority' => 'rivals',
        ], $overrides);
    }

    public function test_live_parity_and_zero_survivors_produce_honest_implemented_state(): void
    {
        $result = (new QualityFoundryConstitutionReadinessManifest)->evaluate($this->valid());

        self::assertSame('implemented_not_cutover_ready', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertFalse($result['claim_eligible']);
        self::assertSame(64, strlen($result['readiness_hash']));
    }

    public function test_missing_refs_or_mutation_survivor_blocks_manifest(): void
    {
        $result = (new QualityFoundryConstitutionReadinessManifest)->evaluate($this->valid([
            'live_evidence_refs' => [], 'mutation_survivors' => ['mutant-1'],
        ]));

        self::assertSame('blocked', $result['status']);
        self::assertContains('live_evidence_refs_missing', $result['blockers']);
        self::assertContains('mutation_survivors_present', $result['blockers']);
    }

    public function test_non_rivals_authority_cannot_make_constitution_ready(): void
    {
        $result = (new QualityFoundryConstitutionReadinessManifest)->evaluate($this->valid(['claim_authority' => 'kernel']));

        self::assertSame('blocked', $result['status']);
        self::assertContains('claim_authority_not_rivals', $result['blockers']);
        self::assertFalse($result['comparative_claims_allowed']);
    }
}
