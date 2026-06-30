<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierRegressionCaseMiner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierRegressionCaseMinerTest extends TestCase
{
    private AtlasExternalBrainAmplifierRegressionCaseMiner $miner;

    protected function setUp(): void
    {
        $this->miner = new AtlasExternalBrainAmplifierRegressionCaseMiner;
    }

    private function failure(
        string $id,
        string $type       = 'give_back',
        string $trigger    = 'proxy_shape',
        string $rejection  = 'reject_proxy',
        string $reason     = '',
        string $severity   = 'low',
        bool   $provTrace  = false,
    ): array {
        return [
            'failure_id'          => $id,
            'failure_type'        => $type,
            'trigger_shape'       => $trigger,
            'expected_rejection'  => $rejection,
            'heldout_reason'      => $reason,
            'severity'            => $severity,
            'has_provider_trace'  => $provTrace,
        ];
    }

    private function mine(array $failures): array
    {
        return $this->miner->mine(['failure_records' => $failures]);
    }

    // ── Schema / output keys ──────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->miner->mine([]);

        $this->assertSame(AtlasExternalBrainAmplifierRegressionCaseMiner::SCHEMA, $r['schema_version']);
        foreach (['promoted_cases', 'rejected_candidates', 'heldout_suite_updates', 'promotion_blockers'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    // ── AC: repeated failure → promoted ───────────────────────────────────────

    public function test_repeated_failure_is_promoted(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'give_back', 'proxy_shape'),
            $this->failure('f2', 'give_back', 'proxy_shape'),
        ]);

        $this->assertCount(1, $r['promoted_cases']);
        $this->assertSame('f1', $r['promoted_cases'][0]['failure_id']);
    }

    public function test_repeated_failure_heldout_reason_is_repeated_failure(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'poison', 'empty_spec'),
            $this->failure('f2', 'poison', 'empty_spec'),
        ]);

        $this->assertSame('repeated_failure', $r['promoted_cases'][0]['heldout_reason']);
    }

    public function test_singleton_below_threshold_not_promoted(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'give_back', 'proxy_shape', severity: 'low'),
        ]);

        $this->assertEmpty($r['promoted_cases']);
        $this->assertCount(1, $r['rejected_candidates']);
        $this->assertSame('below_threshold', $r['rejected_candidates'][0]['rejection_reason']);
    }

    // ── AC: severe singleton → promoted ───────────────────────────────────────

    public function test_severe_critical_singleton_is_promoted(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'false_green', 'ci_bypass', severity: 'critical'),
        ]);

        $this->assertCount(1, $r['promoted_cases']);
    }

    public function test_severe_high_singleton_is_promoted(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'overfit', 'single_example', severity: 'high'),
        ]);

        $this->assertCount(1, $r['promoted_cases']);
        $this->assertSame('severe_singleton', $r['promoted_cases'][0]['heldout_reason']);
    }

    // ── AC: promoted case fields ──────────────────────────────────────────────

    public function test_promoted_case_has_required_fields(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'low_compounding', 'unit_only', 'expect_rejection', 'stale_proof', 'critical'),
        ]);

        $case = $r['promoted_cases'][0];
        foreach (['failure_id', 'failure_type', 'trigger_shape', 'expected_rejection', 'heldout_reason'] as $k) {
            $this->assertArrayHasKey($k, $case, "Missing field: {$k}");
        }
        $this->assertSame('low_compounding',  $case['failure_type']);
        $this->assertSame('unit_only',        $case['trigger_shape']);
        $this->assertSame('expect_rejection', $case['expected_rejection']);
        $this->assertSame('stale_proof',      $case['heldout_reason']);
    }

    public function test_explicit_heldout_reason_beats_default(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'poison', 'shape_a', reason: 'custom_reason', severity: 'critical'),
        ]);

        $this->assertSame('custom_reason', $r['promoted_cases'][0]['heldout_reason']);
    }

    // ── AC: dedup ─────────────────────────────────────────────────────────────

    public function test_dedup_three_identical_failures_produce_one_case(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'template_farm', 'clone_shape'),
            $this->failure('f2', 'template_farm', 'clone_shape'),
            $this->failure('f3', 'template_farm', 'clone_shape'),
        ]);

        $this->assertCount(1, $r['promoted_cases']);
        $this->assertSame('f1', $r['promoted_cases'][0]['failure_id']);
    }

    public function test_different_triggers_are_not_deduped(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'give_back', 'shape_a'),
            $this->failure('f2', 'give_back', 'shape_a'),
            $this->failure('f3', 'give_back', 'shape_b'),
            $this->failure('f4', 'give_back', 'shape_b'),
        ]);

        $this->assertCount(2, $r['promoted_cases']);
    }

    // ── AC: provider trace → promotion_blockers ───────────────────────────────

    public function test_provider_trace_goes_to_promotion_blockers(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'poison', 'trace_shape', provTrace: true),
        ]);

        $this->assertEmpty($r['promoted_cases']);
        $this->assertEmpty($r['rejected_candidates']);
        $this->assertCount(1, $r['promotion_blockers']);
        $this->assertSame('f1', $r['promotion_blockers'][0]['failure_id']);
        $this->assertSame('contains_provider_trace', $r['promotion_blockers'][0]['blocker']);
    }

    // ── AC: heldout_suite_updates ─────────────────────────────────────────────

    public function test_heldout_suite_updates_counts_are_correct(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'give_back',    'shape_a', severity: 'critical'),
            $this->failure('f2', 'poison',       'shape_b', severity: 'low'),
            $this->failure('f3', 'false_green',  'shape_c', severity: 'low'),
        ]);

        $upd = $r['heldout_suite_updates'];
        $this->assertSame(1, $upd['promoted_count']);
        $this->assertSame(2, $upd['rejected_count']);
    }

    public function test_heldout_suite_updates_unique_failure_types(): void
    {
        $r = $this->mine([
            $this->failure('f1', 'give_back', 'shape_a'),
            $this->failure('f2', 'give_back', 'shape_a'),
            $this->failure('f3', 'poison',    'shape_b'),
            $this->failure('f4', 'poison',    'shape_b'),
        ]);

        $types = $r['heldout_suite_updates']['unique_failure_types'];
        $this->assertCount(2, $types);
        $this->assertContains('give_back', $types);
        $this->assertContains('poison',    $types);
    }

    // ── AC: all 8 supported failure types ─────────────────────────────────────

    public function test_all_supported_failure_types_are_accepted(): void
    {
        $failures = [];
        foreach (AtlasExternalBrainAmplifierRegressionCaseMiner::SUPPORTED_FAILURE_TYPES as $i => $type) {
            $failures[] = $this->failure("f{$i}a", $type, "trigger_{$i}", severity: 'critical');
        }

        $r = $this->mine($failures);

        $this->assertCount(8, $r['promoted_cases']);
        foreach (AtlasExternalBrainAmplifierRegressionCaseMiner::SUPPORTED_FAILURE_TYPES as $type) {
            $found = array_filter($r['promoted_cases'], fn ($c) => $c['failure_type'] === $type);
            $this->assertNotEmpty($found, "Missing promoted case for type: {$type}");
        }
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_input_returns_empty_output(): void
    {
        $r = $this->miner->mine([]);

        $this->assertSame([], $r['promoted_cases']);
        $this->assertSame([], $r['rejected_candidates']);
        $this->assertSame([], $r['promotion_blockers']);
        $this->assertSame(0, $r['heldout_suite_updates']['promoted_count']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $failures = [
            $this->failure('f1', 'give_back', 'proxy_shape'),
            $this->failure('f2', 'give_back', 'proxy_shape'),
        ];

        $this->assertSame(json_encode($this->mine($failures)), json_encode($this->mine($failures)));
    }
}
