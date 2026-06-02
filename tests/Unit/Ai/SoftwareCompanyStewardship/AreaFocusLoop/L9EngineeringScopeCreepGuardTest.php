<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9EngineeringScopeCreepGuard;
use PHPUnit\Framework\TestCase;

final class L9EngineeringScopeCreepGuardTest extends TestCase
{
    private L9EngineeringScopeCreepGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new L9EngineeringScopeCreepGuard();
    }

    public function testAaeosEngineeringCandidatePasses(): void
    {
        $result = $this->guard->evaluate([
            'candidate_id' => 'deepen-spec-gate',
            'scope' => 'software_engineering',
            'description' => 'Strengthen the AAEOS spec gate inside the loop.',
        ]);

        $this->assertSame('atlas.aaeos.l9.engineering_scope_creep_guard.v1', $result['schema_version']);
        $this->assertTrue($result['in_scope']);
        $this->assertSame('software_engineering', $result['allowed_scope']);
        $this->assertSame('', $result['rejected_scope_reason']);
        $this->assertSame('', $result['forbidden_domain']);
        $this->assertSame([], $result['blockers']);
    }

    public function testEngineeringScopeTokenAlsoPasses(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'forge',
            'kind' => 'engineering_method',
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('', $result['rejected_scope_reason']);
    }

    public function testMarketingDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'candidate_id' => 'run-ad-campaigns',
            'domain' => 'marketing',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('forbidden_domain', $result['rejected_scope_reason']);
        $this->assertSame('marketing', $result['forbidden_domain']);
        $this->assertSame(['forbidden_domain:marketing'], $result['blockers']);
        $this->assertSame('software_engineering', $result['allowed_scope']);
    }

    public function testFinanceDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'finance',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('forbidden_domain', $result['rejected_scope_reason']);
        $this->assertSame('finance', $result['forbidden_domain']);
        $this->assertSame(['forbidden_domain:finance'], $result['blockers']);
    }

    public function testCyberDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'cyber',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('forbidden_domain', $result['rejected_scope_reason']);
        $this->assertSame('cyber', $result['forbidden_domain']);
        $this->assertSame(['forbidden_domain:cyber'], $result['blockers']);
    }

    public function testTradingDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'target_domain' => 'trading',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('forbidden_domain', $result['rejected_scope_reason']);
        $this->assertSame('trading', $result['forbidden_domain']);
        $this->assertSame(['forbidden_domain:trading'], $result['blockers']);
    }

    public function testExternalCompanyDomainRejects(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'external_company',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('forbidden_domain', $result['rejected_scope_reason']);
        $this->assertSame('external_company', $result['forbidden_domain']);
        $this->assertSame(['forbidden_domain:external_company'], $result['blockers']);
    }

    public function testDomainGeneratorRejectsViaToken(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'domain_generator',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('domain_generator', $result['rejected_scope_reason']);
        $this->assertSame(['domain_generator'], $result['blockers']);
    }

    public function testDomainGeneratorRejectsViaFlag(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'generates_domains' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('domain_generator', $result['rejected_scope_reason']);
        $this->assertSame(['domain_generator'], $result['blockers']);
    }

    public function testMultiCompanyOperationRejects(): void
    {
        $result = $this->guard->evaluate([
            'scope' => 'software_engineering',
            'company_count' => 3,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('multi_company_operation', $result['rejected_scope_reason']);
        $this->assertSame(['multi_company_operation'], $result['blockers']);
    }

    public function testUnrecognisedForeignDomainFailsClosed(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'logistics',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('out_of_engineering_scope', $result['rejected_scope_reason']);
        $this->assertSame('logistics', $result['forbidden_domain']);
        $this->assertSame(['out_of_engineering_scope:logistics'], $result['blockers']);
    }

    public function testForeignDomainCannotBeLaunderedBySelfAssertedEngineeringFlag(): void
    {
        // A concrete foreign-domain declaration must fail closed regardless of an
        // auxiliary `software_engineering` flag: the guard judges the declared
        // domain, never the candidate's claim about itself. Otherwise scope-creep
        // is trivially bypassed by attaching software_engineering:true.
        $result = $this->guard->evaluate([
            'domain' => 'logistics',
            'software_engineering' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('out_of_engineering_scope', $result['rejected_scope_reason']);
        $this->assertSame('logistics', $result['forbidden_domain']);
        $this->assertSame(['out_of_engineering_scope:logistics'], $result['blockers']);
    }

    public function testForeignDomainCannotBeLaunderedByScopeKindToken(): void
    {
        // Same fail-closed guarantee for the `scope_kind`/`engineering_scope`
        // affirmative tokens: they may not override an explicit foreign domain.
        $result = $this->guard->evaluate([
            'domain' => 'healthcare',
            'engineering_scope' => 'forge',
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame('out_of_engineering_scope', $result['rejected_scope_reason']);
        $this->assertSame('healthcare', $result['forbidden_domain']);
        $this->assertSame(['out_of_engineering_scope:healthcare'], $result['blockers']);
    }

    public function testBareEngineeringFlagWithNoDomainStillPasses(): void
    {
        // The affirmative engineering flag remains a valid in-scope signal when
        // NO foreign domain is declared — the fix narrows it, it does not remove
        // it.
        $result = $this->guard->evaluate([
            'candidate_id' => 'deepen-loop',
            'software_engineering' => true,
        ]);

        $this->assertTrue($result['in_scope']);
        $this->assertSame('', $result['rejected_scope_reason']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMultipleViolationsAccumulateSortedBlockers(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'trading',
            'generates_domains' => true,
            'multi_company' => true,
        ]);

        $this->assertFalse($result['in_scope']);
        $this->assertSame(
            ['domain_generator', 'forbidden_domain:trading', 'multi_company_operation'],
            $result['blockers'],
        );
        // The first matched ordered rule (forbidden domain) names the reason.
        $this->assertSame('forbidden_domain', $result['rejected_scope_reason']);
    }

    public function testBlockersIsListOfStrings(): void
    {
        $result = $this->guard->evaluate([
            'domain' => 'finance',
            'multi_company' => true,
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        $this->assertContains('forbidden_domain:finance', $result['blockers']);
        $this->assertContains('multi_company_operation', $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = [
            'domain' => 'marketing',
            'multi_company' => true,
        ];

        $first = $this->guard->evaluate($candidate);
        $second = $this->guard->evaluate($candidate);

        $this->assertSame($first, $second);
    }

    public function testAllowedScopeIsAlwaysSoftwareEngineering(): void
    {
        $passing = $this->guard->evaluate(['scope' => 'software_engineering']);
        $rejecting = $this->guard->evaluate(['domain' => 'cyber']);

        $this->assertSame('software_engineering', $passing['allowed_scope']);
        $this->assertSame('software_engineering', $rejecting['allowed_scope']);
    }
}
