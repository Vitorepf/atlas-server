<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutcomeAttributor;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolOutcomeAttributorTest extends TestCase
{
    private function svc(): AtlasExternalBrainProviderPoolOutcomeAttributor
    {
        return new AtlasExternalBrainProviderPoolOutcomeAttributor;
    }

    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'provider_id' => 'codex',
            'model_id' => 'gpt-5.5',
            'task_family' => 'gate-impl',
            'complexity_tier' => 'medium',
            'role' => 'worker',
            'prompt_scaffold' => 'scaffold-a',
            'result' => 'success',
            'tests_reported' => ['t1'],
            'evidence_refs' => ['e1'],
        ], $overrides);
    }

    // ── schema / empty ────────────────────────────────────────────────────────

    public function test_empty_outcomes_yield_empty_groups(): void
    {
        $r = $this->svc()->attribute(['outcomes' => []]);

        $this->assertSame(AtlasExternalBrainProviderPoolOutcomeAttributor::SCHEMA, $r['schema_version']);
        $this->assertSame([], $r['groups']);
        $this->assertSame(0, $r['group_count']);
        $this->assertFalse($r['mutates_queues']);
    }

    // ── AC2: outcome linked to provider class, worker type, prompt scaffold, task family, proof result ──

    public function test_groups_are_keyed_by_provider_worker_scaffold_family_and_carry_proof_result(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['prompt_scaffold' => 'scaffold-a']),
            $this->outcome(['prompt_scaffold' => 'scaffold-b']),
        ]]);

        $this->assertCount(2, $r['groups']);
        $scaffolds = array_column($r['groups'], 'prompt_scaffold');
        $this->assertContains('scaffold-a', $scaffolds);
        $this->assertContains('scaffold-b', $scaffolds);
        foreach ($r['groups'] as $group) {
            $this->assertArrayHasKey('provider_id', $group);
            $this->assertArrayHasKey('role', $group);
            $this->assertArrayHasKey('task_family', $group);
            $this->assertArrayHasKey('proof_result_summary', $group);
        }
    }

    public function test_unverified_success_does_not_count_toward_success_rate(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['tests_reported' => [], 'evidence_refs' => []]),
            $this->outcome(['tests_reported' => [], 'evidence_refs' => []]),
            $this->outcome(['tests_reported' => [], 'evidence_refs' => []]),
        ]]);

        $this->assertSame(0.0, $r['groups'][0]['success_rate']);
        $this->assertSame('mostly_unverified', $r['groups'][0]['proof_result_summary']);
    }

    // ── AC3: correlation vs likely_cause vs unknown_cause, with confidence + evidence counts ──

    public function test_low_sample_size_is_unknown_cause(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [$this->outcome()]]);

        $this->assertSame('low', $r['groups'][0]['confidence']);
        $this->assertSame('unknown_cause', $r['groups'][0]['causal_classification']);
    }

    public function test_high_confidence_with_verified_proof_is_likely_cause(): void
    {
        $outcomes = array_fill(0, 10, $this->outcome());

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertSame('high', $r['groups'][0]['confidence']);
        $this->assertSame('mostly_verified', $r['groups'][0]['proof_result_summary']);
        $this->assertSame('likely_cause', $r['groups'][0]['causal_classification']);
    }

    public function test_high_volume_without_verified_proof_is_only_correlation_not_likely_cause(): void
    {
        // 10 samples (enough for high confidence by volume) but none evidence-backed —
        // must NOT be promoted to likely_cause just because the sample size is large.
        $outcomes = array_fill(0, 10, $this->outcome(['tests_reported' => [], 'evidence_refs' => [], 'verified_success' => true]));

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertSame('mostly_unverified', $r['groups'][0]['proof_result_summary']);
        $this->assertSame('correlation', $r['groups'][0]['causal_classification']);
        $this->assertNotSame('likely_cause', $r['groups'][0]['causal_classification']);
    }

    public function test_medium_sample_size_is_correlation(): void
    {
        $outcomes = array_fill(0, 5, $this->outcome());

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertSame('medium', $r['groups'][0]['confidence']);
        $this->assertSame('correlation', $r['groups'][0]['causal_classification']);
    }

    // ── AC4: routing recommendations for Maestro assignment and model-amplifier policy ──

    public function test_routing_recommendations_present_and_apply_to_both_consumers(): void
    {
        $outcomes = array_fill(0, 10, $this->outcome());

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertArrayHasKey('routing_recommendations', $r);
        $this->assertCount(1, $r['routing_recommendations']);
        $rec = $r['routing_recommendations'][0];
        $this->assertSame(['maestro_assignment', 'model_amplifier_policy'], $rec['applies_to']);
        $this->assertSame('route_here', $rec['action']);
        $this->assertSame('likely_cause', $rec['causal_classification']);
    }

    public function test_poison_detected_forces_avoid_recommendation_regardless_of_success_rate(): void
    {
        $outcomes = array_fill(0, 9, $this->outcome());
        $outcomes[] = $this->outcome(['result' => 'poison_detected']);

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertSame('avoid', $r['routing_recommendations'][0]['action']);
    }

    public function test_low_confidence_group_gets_insufficient_data_recommendation(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [$this->outcome()]]);

        $this->assertSame('insufficient_data', $r['routing_recommendations'][0]['action']);
    }

    public function test_low_success_rate_with_established_causality_is_avoid(): void
    {
        $outcomes = array_fill(0, 10, $this->outcome(['result' => 'give_back']));

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertLessThan(0.4, $r['groups'][0]['success_rate']);
        $this->assertSame('avoid', $r['routing_recommendations'][0]['action']);
    }

    // ── AC4: routing_lessons and do_not_route_reasons in output ───────────────

    public function test_routing_lessons_present_in_output(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(),  // success → likely_cause → route_here, so routing_lessons populated
        ]]);

        $this->assertArrayHasKey('routing_lessons', $r);
        // low confidence (n=1) → no lesson emitted, but key exists
        $this->assertIsArray($r['routing_lessons']);
    }

    public function test_do_not_route_reasons_present_in_output(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(),  // success, no poison → no do_not_route
        ]]);

        $this->assertArrayHasKey('do_not_route_reasons', $r);
        $this->assertIsArray($r['do_not_route_reasons']);
    }

    public function test_do_not_route_emitted_when_poison_detected(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['result' => 'poison_detected']),
        ]]);

        $this->assertNotEmpty($r['do_not_route_reasons']);
        $this->assertStringContainsString('poison_detected', $r['do_not_route_reasons'][0]);
    }

    public function test_confidence_present_in_every_group(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['task_family' => 'a']),
            $this->outcome(['task_family' => 'b']),
        ]]);

        foreach ($r['groups'] as $group) {
            $this->assertArrayHasKey('confidence', $group);
            $this->assertContains($group['confidence'], ['low', 'medium', 'high']);
        }
    }

    // ── AC3: model_id and task_family are separate grouping dimensions ────────

    public function test_same_task_family_different_model_id_produce_separate_groups(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['model_id' => 'gpt-4', 'task_family' => 'gate-impl']),
            $this->outcome(['model_id' => 'claude-3', 'task_family' => 'gate-impl']),
        ]]);

        $this->assertCount(2, $r['groups']);
        $models = array_column($r['groups'], 'model_id');
        $this->assertContains('gpt-4', $models);
        $this->assertContains('claude-3', $models);
    }

    public function test_same_model_id_different_task_family_produce_separate_groups(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['model_id' => 'gpt-4', 'task_family' => 'gate-impl']),
            $this->outcome(['model_id' => 'gpt-4', 'task_family' => 'refactor']),
        ]]);

        $this->assertCount(2, $r['groups']);
        $families = array_column($r['groups'], 'task_family');
        $this->assertContains('gate-impl', $families);
        $this->assertContains('refactor', $families);
    }

    // ── AC2: verified_success=true overrides empty tests_reported/evidence_refs ─

    public function test_verified_success_flag_allows_evidence_backed_without_refs(): void
    {
        $r = $this->svc()->attribute(['outcomes' => [
            $this->outcome(['tests_reported' => [], 'evidence_refs' => [], 'verified_success' => true]),
        ]]);

        // verified_success=true → counted as success even without evidence_refs/tests_reported
        $this->assertSame(1.0, $r['groups'][0]['success_rate']);
    }

    // ── AC4: do_not_route_reasons for low success rate with established causality

    public function test_low_success_rate_emits_do_not_route_reason(): void
    {
        $outcomes = array_fill(0, 10, $this->outcome(['result' => 'give_back']));

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertNotEmpty($r['do_not_route_reasons']);
        $this->assertStringContainsString('low_success_rate', $r['do_not_route_reasons'][0]);
    }

    // ── AC4: routing_lessons emitted for high-confidence high-success groups

    public function test_routing_lesson_emitted_for_high_confidence_high_success(): void
    {
        $outcomes = array_fill(0, 10, $this->outcome());

        $r = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertNotEmpty($r['routing_lessons']);
        $this->assertStringContainsString('prefer', $r['routing_lessons'][0]);
    }

    public function test_attribute_is_deterministic(): void
    {
        $outcomes = [
            $this->outcome(['provider_id' => 'b-provider']),
            $this->outcome(['provider_id' => 'a-provider']),
        ];

        $a = $this->svc()->attribute(['outcomes' => $outcomes]);
        $b = $this->svc()->attribute(['outcomes' => $outcomes]);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
