<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionOrganReadinessComposer;
use Tests\TestCase;

final class AtlasSelfConstructionOrganReadinessComposerTest extends TestCase
{
    private function allReady(): array
    {
        $out = [];
        foreach (AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS as $organ) {
            $out[$organ] = ['status' => AtlasSelfConstructionOrganReadinessComposer::STATUS_READY];
        }

        return $out;
    }

    public function test_all_organs_ready_yields_all_ready_true(): void
    {
        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($this->allReady());

        $this->assertTrue($verdict['all_ready']);
        $this->assertSame(AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS, $verdict['ready_organs']);
        $this->assertSame([], $verdict['blocked_organs']);
        $this->assertSame([], $verdict['missing_organs']);
        $this->assertSame([], $verdict['next_required_organs']);
    }

    public function test_missing_native_worker_is_named_in_missing_and_next_required(): void
    {
        $organs = $this->allReady();
        unset($organs['native_worker']);

        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($organs);
        $this->assertFalse($verdict['all_ready']);
        $this->assertContains('native_worker', $verdict['missing_organs']);
        $this->assertContains('native_worker', $verdict['next_required_organs']);
    }

    public function test_blocked_verification_court_surfaces_reason_and_blockers(): void
    {
        $organs = $this->allReady();
        $organs['verification_court'] = [
            'status' => AtlasSelfConstructionOrganReadinessComposer::STATUS_BLOCKED,
            'reason' => 'phpunit_exit_1',
            'blockers' => ['phpunit:test_foo'],
        ];

        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($organs);
        $this->assertFalse($verdict['all_ready']);
        $blockedById = array_column($verdict['blocked_organs'], null, 'organ');
        $this->assertArrayHasKey('verification_court', $blockedById);
        $this->assertSame('phpunit_exit_1', $blockedById['verification_court']['reason']);
        $this->assertContains('phpunit:test_foo', $blockedById['verification_court']['blockers']);
    }

    public function test_degraded_organ_is_surfaced_separately(): void
    {
        $organs = $this->allReady();
        $organs['knowledge_sync'] = [
            'status' => AtlasSelfConstructionOrganReadinessComposer::STATUS_DEGRADED,
            'reason' => 'context_pack_stale',
        ];

        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($organs);
        $this->assertFalse($verdict['all_ready']);
        $degradedById = array_column($verdict['degraded_organs'], null, 'organ');
        $this->assertArrayHasKey('knowledge_sync', $degradedById);
        $this->assertContains('knowledge_sync', $verdict['next_required_organs']);
        $this->assertNotContains('knowledge_sync', $verdict['ready_organs']);
    }

    public function test_organ_order_is_deterministic_canonical_regardless_of_input_order(): void
    {
        $shuffled = array_reverse($this->allReady());

        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($shuffled);
        $this->assertSame(AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS, $verdict['ready_organs']);
    }

    public function test_verdict_carries_no_numeric_score_field(): void
    {
        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($this->allReady());
        foreach (array_keys($verdict) as $key) {
            $this->assertStringNotContainsString('score', strtolower((string) $key));
            $this->assertStringNotContainsString('rank', strtolower((string) $key));
        }
    }

    public function test_weighted_readiness_ratio_reflects_ready_fraction(): void
    {
        $total = count(AtlasSelfConstructionOrganReadinessComposer::CANONICAL_ORGANS);
        // Block 1 organ, leave rest ready.
        $organs = $this->allReady();
        $organs['cortex'] = [
            'status' => AtlasSelfConstructionOrganReadinessComposer::STATUS_BLOCKED,
            'reason' => 'boot_fail',
            'blockers' => [],
        ];
        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($organs);

        $expected = round(($total - 1) / $total, 4);
        $this->assertArrayHasKey('readiness_ratio', $verdict);
        $this->assertEqualsWithDelta($expected, $verdict['readiness_ratio'], 0.0001);
        $this->assertSame(1.0, (new AtlasSelfConstructionOrganReadinessComposer)->compose($this->allReady())['readiness_ratio']);
    }

    public function test_top_blocker_list_is_flat_sorted_and_deduplicated(): void
    {
        $organs = $this->allReady();
        $organs['cortex'] = [
            'status' => AtlasSelfConstructionOrganReadinessComposer::STATUS_BLOCKED,
            'reason' => 'err',
            'blockers' => ['gate:foo', 'gate:bar'],
        ];
        $organs['maestro'] = [
            'status' => AtlasSelfConstructionOrganReadinessComposer::STATUS_BLOCKED,
            'reason' => 'err2',
            'blockers' => ['gate:bar', 'gate:zap'], // gate:bar is a duplicate across organs
        ];
        $verdict = (new AtlasSelfConstructionOrganReadinessComposer)->compose($organs);

        $this->assertArrayHasKey('top_blockers', $verdict);
        $this->assertSame(['gate:bar', 'gate:foo', 'gate:zap'], $verdict['top_blockers'], 'must be sorted and deduplicated');
    }

    public function test_compose_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasSelfConstructionOrganReadinessComposer;
        $this->assertSame(json_encode($svc->compose($this->allReady())), json_encode($svc->compose($this->allReady())));
    }
}
