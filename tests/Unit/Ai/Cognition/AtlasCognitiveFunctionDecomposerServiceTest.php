<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use Tests\TestCase;

class AtlasCognitiveFunctionDecomposerServiceTest extends TestCase
{
    private string $log;

    private AtlasCognitiveFunctionDecomposerService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas_cog_func_decomposer_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasCognitiveFunctionDecomposerService;
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    public function test_six_axes_canon_in_fixed_order(): void
    {
        $env = $this->svc->decompose('analise isso');
        $this->assertSame(
            ['reasoning', 'retrieval', 'generation', 'code', 'vision', 'audit'],
            array_keys($env['weights'])
        );
    }

    public function test_code_axis_dominant_for_engineering_input(): void
    {
        $env = $this->svc->decompose('refatore o controller e escreva testes phpunit');
        $this->assertSame('code', $env['dominant_function']);
        $this->assertGreaterThan(0.3, $env['weights']['code']);
    }

    public function test_audit_axis_dominant_for_governance_input(): void
    {
        $env = $this->svc->decompose('audite a cartografia e verifique kernel hash');
        $this->assertSame('audit', $env['dominant_function']);
    }

    public function test_generation_axis_dominant_for_writing_input(): void
    {
        $env = $this->svc->decompose('escreva um resumo do projeto');
        $this->assertSame('generation', $env['dominant_function']);
    }

    public function test_retrieval_axis_dominant_for_search_input(): void
    {
        $env = $this->svc->decompose('busque os documentos canonicos e liste tudo');
        $this->assertSame('retrieval', $env['dominant_function']);
    }

    public function test_weights_sum_to_one(): void
    {
        $env = $this->svc->decompose('refatore o codigo');
        $sum = array_sum($env['weights']);
        $this->assertEqualsWithDelta(1.0, $sum, 0.001);
    }

    public function test_empty_input_defaults_audit(): void
    {
        $env = $this->svc->decompose('');
        $this->assertSame('audit', $env['dominant_function']);
        $this->assertSame(1.0, $env['weights']['audit']);
    }

    public function test_framework_programming_boosts_code(): void
    {
        $baseline = $this->svc->decompose('faça isso')['weights']['code'];
        $boosted = $this->svc->decompose('faça isso', ['framework' => 'programming'])['weights']['code'];
        $this->assertGreaterThan($baseline, $boosted);
    }

    public function test_framework_cartography_boosts_audit(): void
    {
        $env = $this->svc->decompose('faça isso', ['framework' => 'cartography_audit']);
        $this->assertSame('audit', $env['dominant_function']);
    }

    public function test_role_engineer_boosts_code(): void
    {
        $baseline = $this->svc->decompose('faça algo')['weights']['code'];
        $boosted = $this->svc->decompose('faça algo', ['role' => 'engineer'])['weights']['code'];
        $this->assertGreaterThan($baseline, $boosted);
    }

    public function test_determinism_same_input_same_hash(): void
    {
        $a = $this->svc->decompose('refatore o controller', ['role' => 'engineer']);
        $b = $this->svc->decompose('refatore o controller', ['role' => 'engineer']);
        $this->assertSame($a['decomposition_hash'], $b['decomposition_hash']);
    }

    public function test_jsonl_append_only(): void
    {
        $this->svc->decompose('teste 1');
        $this->svc->decompose('teste 2');
        $this->assertCount(2, $this->svc->listDecompositions());
    }

    public function test_last_decomposition_returns_latest(): void
    {
        $this->svc->decompose('primeiro');
        $latest = $this->svc->decompose('segundo escreva');
        $this->assertSame($latest['decomposition_hash'], $this->svc->lastDecomposition()['decomposition_hash']);
    }

    public function test_claim_policy_provider_safe(): void
    {
        $cp = $this->svc->claimPolicy();
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['external_rivals_certification_touched']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
    }

    public function test_envelope_carries_schema_version_and_hash(): void
    {
        $env = $this->svc->decompose('qualquer coisa');
        $this->assertSame('atlas.cognitive_function.decomposition.v1', $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['decomposition_hash']);
    }

    public function test_no_signal_input_defaults_lean_reasoning(): void
    {
        $env = $this->svc->decompose('xpto qwerty');
        $this->assertSame('reasoning', $env['dominant_function']);
        $this->assertSame(0.5, $env['weights']['reasoning']);
    }
}
