<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeDisagreementDiagnostic;
use PHPUnit\Framework\TestCase;

/**
 * Proves the judge-disagreement diagnostic: one typed category per event, deterministic bundle_drift via
 * sha256 mismatch, and the read-only contract (verdict-in === verdict-out, only annotation added).
 */
final class AtlasLoopJudgeDisagreementDiagnosticTest extends TestCase
{
    private function diagnostic(): AtlasLoopJudgeDisagreementDiagnostic
    {
        return new AtlasLoopJudgeDisagreementDiagnostic;
    }

    /** @return array<string,mixed> */
    private function receipt(string $provider, bool $passed, string $bundle = 'B1', string $promptId = 'P1', bool $capOk = true, string $missing = ''): array
    {
        return ['provider' => $provider, 'passed' => $passed, 'bundle_sha256' => $bundle, 'prompt_id' => $promptId, 'capability_ok' => $capOk, 'missing_capability' => $missing];
    }

    public function test_bundle_drift_detected_by_sha256_mismatch(): void
    {
        $out = $this->diagnostic()->diagnose(
            ['consensus' => false],
            [$this->receipt('a', true, 'BUNDLE_X'), $this->receipt('b', false, 'BUNDLE_Y')],
        );

        $this->assertSame('bundle_drift', $out['category']);
        $this->assertSame(['BUNDLE_X', 'BUNDLE_Y'], $out['evidence']['bundle_sha256s']);
        $this->assertContains($out['category'], AtlasLoopJudgeDisagreementDiagnostic::CATEGORIES);
    }

    public function test_provider_capability_gap(): void
    {
        $out = $this->diagnostic()->diagnose(
            ['consensus' => false],
            [$this->receipt('a', true), $this->receipt('b', false, 'B1', 'P1', false, 'tool_use')],
        );

        $this->assertSame('provider_capability_gap', $out['category']);
        $this->assertSame('tool_use', $out['evidence']['capability_gaps'][0]['missing_capability']);
    }

    public function test_prompt_lensing(): void
    {
        $out = $this->diagnostic()->diagnose(
            ['consensus' => false],
            [$this->receipt('a', true, 'B1', 'PROMPT_A'), $this->receipt('b', false, 'B1', 'PROMPT_B')],
        );

        $this->assertSame('prompt_lensing', $out['category']);
    }

    public function test_genuine_semantic_split_when_everything_else_matches(): void
    {
        $out = $this->diagnostic()->diagnose(
            ['consensus' => false],
            [$this->receipt('a', true), $this->receipt('b', false)], // same bundle/prompt/cap, differing verdicts
        );

        $this->assertSame('genuine_semantic_split', $out['category']);
    }

    public function test_no_disagreement_is_undetermined(): void
    {
        $out = $this->diagnostic()->diagnose(
            ['consensus' => true],
            [$this->receipt('a', true), $this->receipt('b', true)], // both passed ⇒ no disagreement
        );

        $this->assertSame('undetermined', $out['category']);
    }

    public function test_read_only_verdict_in_equals_verdict_out(): void
    {
        $verdict = ['consensus' => false, 'reason' => 'split', 'gate_hash' => 'abc'];
        $out = $this->diagnostic()->diagnose($verdict, [$this->receipt('a', true), $this->receipt('b', false)]);

        $this->assertSame($verdict, $out['verdict'], 'the diagnostic annotates — it NEVER rewrites the verdict');
    }
}
