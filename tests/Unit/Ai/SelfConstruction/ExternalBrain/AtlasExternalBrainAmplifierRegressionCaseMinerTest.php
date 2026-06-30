<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierRegressionCaseMiner;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierRegressionCaseMinerTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmplifierRegressionCaseMiner
    {
        return new AtlasExternalBrainAmplifierRegressionCaseMiner;
    }

    private function failure(
        string $id,
        string $type,
        string $observed,
        string $verdict,
        string $proposal = 'Implement X to do Y in scope Z',
        string $repair = '',
        bool $providerTrace = false,
    ): array {
        return [
            'failure_id' => $id,
            'failure_type' => $type,
            'proposal_text' => $proposal,
            'observed_behavior' => $observed,
            'expected_verdict' => $verdict,
            'repair_action' => $repair,
            'has_provider_trace' => $providerTrace,
        ];
    }

    private function concreteObserved(): string
    {
        return 'Model produced a cleanup-only refactor with no behavior change when a new capability was required.';
    }

    private function mine(array $failures): array
    {
        return $this->svc()->mine(['failure_records' => $failures]);
    }

    // ── accepted cases ────────────────────────────────────────────────────────

    public function test_concrete_failure_produces_regression_case(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'proxy_risk', $this->concreteObserved(), 'rejection'),
        ]);

        $this->assertCount(1, $r['regression_cases']);
        $this->assertSame('f1', $r['regression_cases'][0]['case_id']);
        $this->assertSame('proxy_risk', $r['regression_cases'][0]['case_type']);
        $this->assertSame('rejection', $r['regression_cases'][0]['expected_verdict']);
    }

    public function test_repair_action_included_when_present(): void
    {
        $r = $this->mine([
            $this->failure('f2', 'low_leverage', $this->concreteObserved(), 'repair', repair: 'add downstream dependency'),
        ]);

        $this->assertSame('add downstream dependency', $r['regression_cases'][0]['repair_action']);
    }

    public function test_input_pattern_is_truncated_to_100_chars(): void
    {
        $longProposal = str_repeat('A', 200);
        $r = $this->mine([
            $this->failure('f3', 'proxy_risk', $this->concreteObserved(), 'rejection', proposal: $longProposal),
        ]);

        $this->assertSame(100, mb_strlen($r['regression_cases'][0]['input_pattern']));
    }

    // ── expected_verdicts ─────────────────────────────────────────────────────

    public function test_expected_verdicts_maps_failure_id_to_verdict(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'proxy_risk', $this->concreteObserved(), 'rejection'),
            $this->failure('f2', 'low_leverage', $this->concreteObserved(), 'acceptance'),
        ]);

        $this->assertSame('rejection', $r['expected_verdicts']['f1']);
        $this->assertSame('acceptance', $r['expected_verdicts']['f2']);
    }

    // ── harness_tags ──────────────────────────────────────────────────────────

    public function test_harness_tags_are_unique_failure_types(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'proxy_risk', $this->concreteObserved(), 'rejection'),
            $this->failure('f2', 'proxy_risk', $this->concreteObserved(), 'repair'),
            $this->failure('f3', 'low_leverage', $this->concreteObserved(), 'rejection'),
        ]);

        $this->assertCount(2, $r['harness_tags']);
        $this->assertContains('proxy_risk', $r['harness_tags']);
        $this->assertContains('low_leverage', $r['harness_tags']);
    }

    // ── rejection: provider trace ─────────────────────────────────────────────

    public function test_provider_trace_failure_is_rejected(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'proxy_risk', $this->concreteObserved(), 'rejection', providerTrace: true),
        ]);

        $this->assertSame([], $r['regression_cases']);
        $rejectedIds = array_column($r['rejected_failures'], 'failure_id');
        $this->assertContains('f1', $rejectedIds);

        $entry = array_filter($r['rejected_failures'], static fn ($x) => $x['failure_id'] === 'f1');
        $this->assertSame('contains_provider_trace', array_values($entry)[0]['reason']);
    }

    // ── rejection: invalid verdict ────────────────────────────────────────────

    public function test_invalid_verdict_is_rejected(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'proxy_risk', $this->concreteObserved(), 'maybe'),
        ]);

        $this->assertSame([], $r['regression_cases']);
        $entry = array_filter($r['rejected_failures'], static fn ($x) => $x['failure_id'] === 'f1');
        $this->assertSame('invalid_or_missing_verdict', array_values($entry)[0]['reason']);
    }

    // ── rejection: vague description ──────────────────────────────────────────

    public function test_short_observed_behavior_is_rejected(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'proxy_risk', 'bad output', 'rejection'),
        ]);

        $this->assertSame([], $r['regression_cases']);
        $entry = array_filter($r['rejected_failures'], static fn ($x) => $x['failure_id'] === 'f1');
        $this->assertSame('observed_behavior_too_vague', array_values($entry)[0]['reason']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_failures_returns_empty_output(): void
    {
        $r = $this->svc()->mine([]);

        $this->assertSame([], $r['regression_cases']);
        $this->assertSame([], $r['rejected_failures']);
        $this->assertSame([], $r['expected_verdicts']);
        $this->assertSame([], $r['harness_tags']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->mine([]);

        $this->assertSame(AtlasExternalBrainAmplifierRegressionCaseMiner::SCHEMA, $r['schema_version']);
    }
}
