<?php

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Tests\TestCase;

class AtlasAaeosImplementationTruthServiceTest extends TestCase
{
    public function test_verified_when_all_evidence_resolves_and_claim_matches(): void
    {
        $result = $this->service()->evaluate('runtime_verified', [
            $this->res('symbol', true),
            $this->res('route', true),
            $this->res('test', true),
            $this->res('receipt', true),
        ]);

        $this->assertSame('verified', $result['computed_state']);
        $this->assertSame('verified', $result['claimed_state']);
        $this->assertFalse($result['drift']);
        $this->assertSame([], $result['unmet_evidence']);
        $this->assertSame('existence_only', $result['test_resolution']);
    }

    public function test_over_claim_drift_when_claim_exceeds_computed(): void
    {
        $result = $this->service()->evaluate('runtime_verified', [
            $this->res('symbol', true),
            $this->res('command', true),
            $this->res('test', false),
            $this->res('receipt', false),
        ]);

        $this->assertSame('partial', $result['computed_state']);
        $this->assertTrue($result['drift'], 'claiming verified with only symbol+command must be over-claim');
        $this->assertContains('needs >=1 resolved test for verified', $result['unmet_evidence']);
        $this->assertContains('needs >=1 resolved receipt (evidence file) for verified', $result['unmet_evidence']);
    }

    public function test_spec_when_nothing_resolves_and_spec_claim_has_no_drift(): void
    {
        $result = $this->service()->evaluate('spec_only', [
            $this->res('symbol', false),
            $this->res('route', false),
        ]);

        $this->assertSame('spec', $result['computed_state']);
        $this->assertFalse($result['drift']);
    }

    public function test_partial_requires_symbol_plus_wiring(): void
    {
        // symbol alone (no route/command) is not enough for partial.
        $symbolOnly = $this->service()->evaluate('spec_only', [$this->res('symbol', true)]);
        $this->assertSame('spec', $symbolOnly['computed_state']);
        $this->assertContains('needs >=1 resolved route or command for partial', $symbolOnly['unmet_evidence']);

        $symbolPlusRoute = $this->service()->evaluate('spec_only', [
            $this->res('symbol', true),
            $this->res('route', true),
        ]);
        $this->assertSame('partial', $symbolPlusRoute['computed_state']);
    }

    public function test_under_claim_is_not_drift(): void
    {
        $result = $this->service()->evaluate('spec_only', [
            $this->res('symbol', true),
            $this->res('route', true),
        ]);

        $this->assertSame('partial', $result['computed_state']);
        $this->assertFalse($result['drift']);
        $this->assertTrue($result['under_claim']);
    }

    public function test_normalize_state_maps_existing_vocabulary(): void
    {
        $service = $this->service();

        $this->assertSame('verified', $service->normalizeState('runtime_verified'));
        $this->assertSame('verified', $service->normalizeState('solid_runtime'));
        $this->assertSame('partial', $service->normalizeState('partial_runtime'));
        $this->assertSame('partial', $service->normalizeState('implemented_partial'));
        $this->assertSame('spec', $service->normalizeState('spec_only'));
        $this->assertSame('spec', $service->normalizeState('backlog_only_no_runtime'));
        $this->assertSame('spec', $service->normalizeState('north_star'));
        $this->assertSame('spec', $service->normalizeState(''));
    }

    /**
     * @return array{kind:string, ref:string, resolved:bool, matched:?string}
     */
    private function res(string $kind, bool $resolved): array
    {
        return [
            'kind' => $kind,
            'ref' => $kind.'-ref',
            'resolved' => $resolved,
            'matched' => $resolved ? 'Matched\\'.$kind : null,
        ];
    }

    private function service(): AtlasAaeosImplementationTruthService
    {
        return new AtlasAaeosImplementationTruthService(
            new AtlasAaeosImplementationEvidenceResolver,
            new CanonicalDocsFrontmatterParser,
        );
    }
}
