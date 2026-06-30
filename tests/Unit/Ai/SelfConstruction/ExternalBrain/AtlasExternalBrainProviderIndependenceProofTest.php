<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderIndependenceProof;
use Tests\TestCase;

final class AtlasExternalBrainProviderIndependenceProofTest extends TestCase
{
    private function proof(): AtlasExternalBrainProviderIndependenceProof
    {
        return new AtlasExternalBrainProviderIndependenceProof();
    }

    private function fullyCoveredPhase(string $phase, array $overrides = []): array
    {
        return array_merge([
            'phase'                    => $phase,
            'has_local_evidence_path'  => true,
            'has_scaffold_fallback'    => true,
            'has_benchmark_coverage'   => true,
            'has_rollback_path'        => true,
        ], $overrides);
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->proof()->prove([]);

        $this->assertSame(AtlasExternalBrainProviderIndependenceProof::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->proof()->prove([]);

        foreach (['schema', 'independent', 'provider_required_phases',
                  'fallback_coverage', 'optional_frontier_accelerators', 'missing_proofs'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── independent = true ───────────────────────────────────────────────────

    public function test_independent_when_all_phases_fully_covered(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                $this->fullyCoveredPhase('task_origination'),
                $this->fullyCoveredPhase('task_validation'),
                $this->fullyCoveredPhase('rollback'),
                $this->fullyCoveredPhase('learning'),
            ],
        ]);

        $this->assertTrue($result['independent']);
        $this->assertEmpty($result['provider_required_phases']);
        $this->assertEmpty($result['missing_proofs']);
    }

    // ── independent = false when provider required ────────────────────────────

    public function test_not_independent_when_requires_live_provider(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                $this->fullyCoveredPhase('task_origination', ['requires_live_provider' => true]),
            ],
        ]);

        $this->assertFalse($result['independent']);
        $phases = array_column($result['provider_required_phases'], 'phase');
        $this->assertContains('task_origination', $phases);
    }

    public function test_not_independent_when_requires_manual_provider_selection(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                $this->fullyCoveredPhase('rollback', ['requires_manual_provider_selection' => true]),
            ],
        ]);

        $this->assertFalse($result['independent']);
        $phases = array_column($result['provider_required_phases'], 'phase');
        $this->assertContains('rollback', $phases);
    }

    public function test_not_independent_when_has_provider_specific_traces(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                $this->fullyCoveredPhase('learning', ['has_provider_specific_traces' => true]),
            ],
        ]);

        $this->assertFalse($result['independent']);
        $phases = array_column($result['provider_required_phases'], 'phase');
        $this->assertContains('learning', $phases);
    }

    // ── provider_required_phases reasons ─────────────────────────────────────

    public function test_reasons_list_all_failures_for_a_phase(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                $this->fullyCoveredPhase('task_validation', [
                    'requires_live_provider'             => true,
                    'requires_manual_provider_selection' => true,
                ]),
            ],
        ]);

        $phaseEntry = current(array_filter(
            $result['provider_required_phases'],
            fn ($p) => $p['phase'] === 'task_validation',
        ));
        $this->assertContains('requires_live_provider', $phaseEntry['reasons']);
        $this->assertContains('requires_manual_provider_selection', $phaseEntry['reasons']);
    }

    // ── missing_proofs ────────────────────────────────────────────────────────

    public function test_not_independent_when_coverage_missing(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                [
                    'phase'                   => 'task_origination',
                    'has_local_evidence_path' => false,
                    'has_scaffold_fallback'   => true,
                    'has_benchmark_coverage'  => true,
                    'has_rollback_path'       => true,
                ],
            ],
        ]);

        $this->assertFalse($result['independent']);
        $this->assertNotEmpty($result['missing_proofs']);
    }

    public function test_missing_proofs_lists_absent_coverage_keys(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                [
                    'phase'                   => 'rollback',
                    'has_local_evidence_path' => false,
                    'has_scaffold_fallback'   => false,
                    'has_benchmark_coverage'  => true,
                    'has_rollback_path'       => true,
                ],
            ],
        ]);

        $entry = current(array_filter($result['missing_proofs'], fn ($m) => $m['phase'] === 'rollback'));
        $this->assertContains('has_local_evidence_path', $entry['missing_coverage']);
        $this->assertContains('has_scaffold_fallback', $entry['missing_coverage']);
        $this->assertNotContains('has_benchmark_coverage', $entry['missing_coverage']);
    }

    // ── fallback_coverage ─────────────────────────────────────────────────────

    public function test_fallback_coverage_reflects_input_flags(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                [
                    'phase'                   => 'task_validation',
                    'has_local_evidence_path' => true,
                    'has_scaffold_fallback'   => false,
                    'has_benchmark_coverage'  => true,
                    'has_rollback_path'       => false,
                ],
            ],
        ]);

        $coverage = $result['fallback_coverage']['task_validation'];
        $this->assertTrue($coverage['local_evidence']);
        $this->assertFalse($coverage['scaffold']);
        $this->assertTrue($coverage['benchmark']);
        $this->assertFalse($coverage['rollback']);
    }

    // ── optional_frontier_accelerators ───────────────────────────────────────

    public function test_optional_accelerators_preserved_per_phase(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [
                $this->fullyCoveredPhase('learning', [
                    'optional_frontier_accelerators' => ['gpt-5', 'claude-fable'],
                ]),
            ],
        ]);

        $this->assertSame(['gpt-5', 'claude-fable'], $result['optional_frontier_accelerators']['learning']);
    }

    public function test_optional_accelerators_empty_by_default(): void
    {
        $result = $this->proof()->prove([
            'proof_claims' => [$this->fullyCoveredPhase('rollback')],
        ]);

        $this->assertSame([], $result['optional_frontier_accelerators']['rollback']);
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_claims_yields_independent_true(): void
    {
        $result = $this->proof()->prove(['proof_claims' => []]);

        $this->assertTrue($result['independent']);
        $this->assertEmpty($result['provider_required_phases']);
        $this->assertEmpty($result['missing_proofs']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'proof_claims' => [
                $this->fullyCoveredPhase('task_origination'),
                $this->fullyCoveredPhase('task_validation', ['requires_live_provider' => true]),
            ],
        ];

        $this->assertSame($this->proof()->prove($input), $this->proof()->prove($input));
    }
}
