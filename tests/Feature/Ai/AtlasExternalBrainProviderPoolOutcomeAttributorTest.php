<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutcomeAttributor;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolOutcomeAttributorTest extends TestCase
{
    private function evidencedRow(array $overrides = []): array
    {
        return array_merge([
            'provider_id' => 'codex',
            'model_id' => 'gpt-5.5',
            'task_family' => 'service_layer',
            'complexity_tier' => 'small',
            'role' => 'muscle',
            'result' => 'success',
            'tests_reported' => ['phpunit tests/FooTest.php'],
            'evidence_refs' => ['evidence-1'],
            'cost_usd' => 0.10,
        ], $overrides);
    }

    public function test_aggregates_success_rate_by_full_grouping_key(): void
    {
        $rows = array_fill(0, 10, $this->evidencedRow());

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $this->assertSame(1, $result['group_count']);
        $group = $result['groups'][0];
        $this->assertSame('codex', $group['provider_id']);
        $this->assertSame('gpt-5.5', $group['model_id']);
        $this->assertSame('service_layer', $group['task_family']);
        $this->assertSame('small', $group['complexity_tier']);
        $this->assertSame('muscle', $group['role']);
        $this->assertSame(10, $group['sample_size']);
        $this->assertSame(1.0, $group['success_rate']);
        $this->assertSame(1.0, $group['evidence_quality']);
        $this->assertSame('high', $group['confidence']);
        $this->assertSame(0.10, $group['median_cost']);
        $this->assertFalse($result['mutates_queues']);
    }

    public function test_self_reported_success_without_evidence_is_discounted(): void
    {
        $rows = array_fill(0, 10, $this->evidencedRow([
            'result' => 'success',
            'tests_reported' => [],
            'evidence_refs' => [],
        ]));

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $group = $result['groups'][0];
        $this->assertSame(0.0, $group['success_rate'], 'self-reported success without evidence must not count');
        $this->assertSame(0.0, $group['evidence_quality']);
    }

    public function test_verified_success_flag_overrides_evidence_inference(): void
    {
        $rows = array_fill(0, 5, $this->evidencedRow([
            'tests_reported' => [],
            'evidence_refs' => [],
            'verified_success' => true,
        ]));

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $this->assertSame(1.0, $result['groups'][0]['success_rate']);
    }

    public function test_confidence_is_low_below_three_samples_regardless_of_evidence(): void
    {
        $rows = array_fill(0, 2, $this->evidencedRow());

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $this->assertSame('low', $result['groups'][0]['confidence']);
        $this->assertSame([], $result['routing_lessons'], 'low confidence groups never produce a routing lesson');
    }

    public function test_confidence_is_medium_when_evidence_quality_is_weak_even_with_large_sample(): void
    {
        $rows = array_merge(
            array_fill(0, 3, $this->evidencedRow()),
            array_fill(0, 12, $this->evidencedRow(['tests_reported' => [], 'evidence_refs' => [], 'result' => 'give_back'])),
        );

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $group = $result['groups'][0];
        $this->assertSame(15, $group['sample_size']);
        $this->assertLessThan(0.5, $group['evidence_quality']);
        $this->assertSame('medium', $group['confidence']);
    }

    public function test_poison_detected_produces_do_not_route_reason_regardless_of_success_rate(): void
    {
        $rows = array_merge(
            array_fill(0, 8, $this->evidencedRow()),
            array_fill(0, 2, $this->evidencedRow(['result' => 'poison_detected'])),
        );

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $group = $result['groups'][0];
        $this->assertGreaterThan(0.0, $group['poison_detection_rate']);
        $this->assertNotEmpty($result['do_not_route_reasons']);
        $this->assertStringContainsString('poison_detected_present', $result['do_not_route_reasons'][0]);
    }

    public function test_low_success_rate_with_sufficient_confidence_produces_do_not_route_reason(): void
    {
        $rows = array_merge(
            array_fill(0, 2, $this->evidencedRow()),
            array_fill(0, 8, $this->evidencedRow(['result' => 'failed'])),
        );

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $group = $result['groups'][0];
        $this->assertSame(0.2, $group['success_rate']);
        $this->assertNotSame('low', $group['confidence']);
        $this->assertNotEmpty($result['do_not_route_reasons']);
        $this->assertStringContainsString('low_success_rate', $result['do_not_route_reasons'][0]);
    }

    public function test_high_success_rate_with_confidence_produces_routing_lesson(): void
    {
        $rows = array_fill(0, 10, $this->evidencedRow());

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $this->assertNotEmpty($result['routing_lessons']);
        $this->assertStringContainsString('prefer', $result['routing_lessons'][0]);
    }

    public function test_distinct_dims_form_distinct_groups(): void
    {
        $rows = [
            $this->evidencedRow(['provider_id' => 'codex']),
            $this->evidencedRow(['provider_id' => 'hermes']),
        ];

        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => $rows]);

        $this->assertSame(2, $result['group_count']);
    }

    public function test_attribute_never_mutates_anything_and_returns_plain_facts(): void
    {
        $result = (new AtlasExternalBrainProviderPoolOutcomeAttributor)->attribute(['outcomes' => []]);

        $this->assertSame(0, $result['group_count']);
        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['routing_lessons']);
        $this->assertSame([], $result['do_not_route_reasons']);
        $this->assertFalse($result['mutates_queues']);
    }
}
